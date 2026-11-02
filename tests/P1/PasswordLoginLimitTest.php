<?php

namespace Tests\P1;

use App\Models\User;
use App\Http\Controllers\V1\Passport\AuthController;
use App\Http\Requests\Passport\AuthLogin;
use App\Services\AuthService;
use App\Services\EmailAddress;
use App\Services\PasswordLoginLimiter;
use Illuminate\Http\Request;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;
use Carbon\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PasswordLoginLimitTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
        parent::setUp();
    }

    private function limiter(): PasswordLoginLimiter { return new PasswordLoginLimiter(); }
    private function failCount(string $identity): string { return 'PASSWORD_LOGIN_ERRORS_V2:' . $identity; }
    private function reject(callable $operation, int $status = 500): void
    {
        try { $operation(); $this->fail('Expected request to be rejected'); }
        catch (HttpException $error) { $this->assertSame($status, $error->getStatusCode()); }
    }
    private function request(string $remote = '192.0.2.10', ?string $forwarded = null): Request
    {
        $server = ['REMOTE_ADDR' => $remote];
        if ($forwarded !== null) $server['HTTP_X_FORWARDED_FOR'] = $forwarded;
        return Request::create('/', 'POST', [], [], [], $server);
    }

    public function testLimitIsOnByDefaultAndExplicitlyCanBeDisabled()
    {
        $user = $this->user(['password' => password_hash('Correct-password', PASSWORD_DEFAULT)]);
        config(['v2board.password_limit_count' => 2, 'v2board.password_limit_expire' => 60]);
        $settings = config('v2board');
        unset($settings['password_limit_enable']);
        config(['v2board' => $settings]);
        $limiter = $this->limiter();
        $this->assertFalse($limiter->verify($user, $user->email, 'wrong-password'));
        $this->assertFalse($limiter->verify($user, $user->email, 'wrong-password'));
        $this->reject(function () use ($limiter, $user) { $limiter->verify($user, $user->email, 'wrong-password'); });
        config(['v2board.password_limit_enable' => 0]);
        $this->assertFalse($limiter->verify($user, $user->email, 'wrong-password'));
        $this->assertSame(2, (int)Cache::get($this->failCount('user:' . $user->id)));
        $this->assertTrue($limiter->verify($user, $user->email, 'Correct-password'));
    }

    public function testEmailBucketsNormalizeCaseAndIdnaAndUnknownEmailsAreCanonical()
    {
        config(['v2board.password_limit_count' => 1]); $limiter = $this->limiter();
        $idn = function_exists('idn_to_ascii') ? "Owner@\u{00E9}xample.test" : 'Owner@example.test';
        $ascii = function_exists('idn_to_ascii') ? 'owner@xn--xample-9ua.test' : 'owner@example.test';
        $this->assertFalse($limiter->verify(null, EmailAddress::key($idn), 'wrong-password'));
        $this->reject(function () use ($limiter, $ascii) { $limiter->verify(null, EmailAddress::key($ascii), 'wrong-password'); });
        $unknownKey = $this->failCount('email:' . hash('sha256', EmailAddress::key($ascii)));
        $this->assertSame(1, (int)Cache::get($unknownKey));
        $user = $this->user(['email' => 'CaseOwner@example.test', 'password' => password_hash('Correct-password', PASSWORD_DEFAULT)]);
        $this->assertFalse($limiter->verify($user, 'caseowner@example.test', 'wrong-password'));
        $this->reject(function () use ($limiter, $user) { $limiter->verify($user, 'CASEOWNER@example.test', 'wrong-password'); });
        $this->assertSame(1, (int)Cache::get($this->failCount('user:' . $user->id)));
    }

    public function testControllerAppliesThrottleBeforeCredentialVerification()
    {
        $user = $this->user(['password' => password_hash('Correct-password', PASSWORD_DEFAULT), 'token' => 'test-token', 'uuid' => 'test-uuid']);
        $request = new AuthLogin();
        $request->server->set('REMOTE_ADDR', '192.0.2.44');
        $request->merge(['email' => $user->email, 'password' => 'wrong-password']);
        $this->reject(function () use ($request) { (new AuthController())->login($request); });
        $key = 'PASSWORD_LOGIN_RATE_V1:ip:' . hash('sha256', '192.0.2.44');
        $this->assertSame(1, (int)Cache::get($key));
        $this->assertSame(1, (int)Cache::get($this->failCount('user:' . $user->id)));
    }

    public function testControllerLoginResolvesEmailCaseInsensitivelyAndBlocksAtThreshold()
    {
        $user = $this->user(['password' => password_hash('Correct-password', PASSWORD_DEFAULT), 'token' => 'test-token', 'uuid' => 'test-uuid']);
        $request = new AuthLogin();
        $request->server->set('REMOTE_ADDR', '192.0.2.45');
        $request->merge(['email' => strtoupper($user->email), 'password' => 'Correct-password']);
        $response = (new AuthController())->login($request)->getData(true);
        $authData = AuthService::decryptAuthData($response['data']['auth_data']);
        $this->assertSame((int)$user->id, (int)$authData['id']);

        config(['v2board.password_limit_count' => 1]);
        $bad = new AuthLogin();
        $bad->server->set('REMOTE_ADDR', '192.0.2.46');
        $bad->merge(['email' => strtoupper($user->email), 'password' => 'wrong-password']);
        $this->reject(function () use ($bad) { (new AuthController())->login($bad); });
        $this->reject(function () use ($bad) { (new AuthController())->login($bad); });
    }

    public function testEmailChangeDoesNotResetAccountBucket()
    {
        config(['v2board.password_limit_count' => 2]); $limiter = $this->limiter();
        $user = $this->user(['password' => password_hash('Correct-password', PASSWORD_DEFAULT)]);
        $this->assertFalse($limiter->verify($user, $user->email, 'bad-password'));
        User::whereKey($user->id)->update(['email' => 'changed@example.test']);
        $updated = $user->fresh();
        $this->assertFalse($limiter->verify($updated, 'changed@example.test', 'bad-password'));
        $this->reject(function () use ($limiter, $updated) { $limiter->verify($updated, 'changed@example.test', 'Correct-password'); });
        $this->assertSame(2, (int)Cache::get($this->failCount('user:' . $user->id)));
    }

    public function testSuccessfulVerificationClearsFailureBucket()
    {
        config(['v2board.password_limit_count' => 2]); $limiter = $this->limiter();
        $user = $this->user(['password' => password_hash('Correct-password', PASSWORD_DEFAULT)]);
        $this->assertFalse($limiter->verify($user, $user->email, 'bad-password'));
        $this->assertTrue($limiter->verify($user, $user->email, 'Correct-password'));
        $this->assertNull(Cache::get($this->failCount('user:' . $user->id)));
    }

    public function testFailureBucketExpiresAfterConfiguredWindow()
    {
        config(['v2board.password_limit_count' => 1, 'v2board.password_limit_expire' => 1]);
        $limiter = $this->limiter(); $user = $this->user(['password' => password_hash('Correct-password', PASSWORD_DEFAULT)]);
        $this->assertFalse($limiter->verify($user, $user->email, 'bad-password'));
        Carbon::setTestNow(Carbon::now()->addMinutes(2));
        try {
            $this->assertFalse($limiter->verify($user, $user->email, 'bad-password'));
            $this->assertSame(1, (int)Cache::get($this->failCount('user:' . $user->id)));
        } finally { Carbon::setTestNow(); }
    }

    public function testThrottleEnforcesIpLimitAndIgnoresUntrustedForwardedAddress()
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
        $limiter = $this->limiter(); $first = $this->request('192.0.2.10', '198.51.100.1');
        for ($i = 0; $i < 60; $i++) $limiter->throttle($first);
        $this->reject(function () use ($limiter, $first) { $limiter->throttle($first); }, 429);
        $secondIp = $this->request('192.0.2.11', '198.51.100.1');
        $limiter->throttle($secondIp); // Same spoofed XFF does not merge untrusted clients.
    }

    public function testThrottleEnforcesGlobalLimitAcrossIpAddresses()
    {
        $limiter = $this->limiter();
        for ($i = 0; $i < 1200; $i++) $limiter->throttle($this->request('192.0.2.' . (($i % 200) + 1)));
        $this->reject(function () use ($limiter) { $limiter->throttle($this->request('203.0.113.8')); }, 429);
    }

    public function testForwardedClientIpIsUsedWhenProxyIsExplicitlyTrusted()
    {
        Request::setTrustedProxies(['192.0.2.10'], Request::HEADER_X_FORWARDED_FOR);
        $limiter = $this->limiter();
        $trusted = $this->request('192.0.2.10', '198.51.100.77');
        $this->assertSame('198.51.100.77', $trusted->ip());
        $limiter->throttle($trusted);
    }

    public function testConcurrentRequestsDoNotLoseSharedCacheIncrements()
    {
        $directory = sys_get_temp_dir() . '/v2board-login-limit-' . bin2hex(random_bytes(8));
        mkdir($directory);
        $workers = [];
        $count = 8;
        try {
            Cache::swap(new Repository(new FileStore(new Filesystem(), $directory)));
            $phpArgs = json_decode(getenv('P1_WORKER_PHP_ARGS') ?: '[]', true);
            for ($i = 0; $i < $count; $i++) {
                $workers[] = new Process(array_merge([PHP_BINARY], $phpArgs, [__DIR__ . '/PasswordLoginLimitWorker.php', $directory, (string)$i]));
                $workers[$i]->setTimeout(15);
                $workers[$i]->start();
            }
            $deadline = microtime(true) + 10;
            while (count(glob($directory . '/*.ready')) !== $count) {
                foreach ($workers as $worker) if (!$worker->isRunning()) $this->fail($worker->getOutput() . $worker->getErrorOutput());
                if (microtime(true) > $deadline) $this->fail('Concurrent limiter workers did not reach barrier');
                usleep(10000);
            }
            file_put_contents($directory . '/go', 'go');
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getOutput() . $worker->getErrorOutput());
            }
            $key = 'PASSWORD_LOGIN_RATE_V1:ip:' . hash('sha256', '192.0.2.99');
            $this->assertSame($count, (int)Cache::get($key));

            file_put_contents($directory . '/go', '');
            foreach ($workers as $worker) if ($worker->isRunning()) $worker->stop();
            $workers = [];
            $count = 8;
            @unlink($directory . '/go');
            foreach (glob($directory . '/*.ready') as $ready) unlink($ready);
            for ($i = 0; $i < $count; $i++) {
                $workers[] = new Process(array_merge([PHP_BINARY], $phpArgs, [__DIR__ . '/PasswordLoginLimitWorker.php', $directory, (string)$i, 'verify']));
                $workers[$i]->setTimeout(15);
                $workers[$i]->start();
            }
            $deadline = microtime(true) + 10;
            while (count(glob($directory . '/*.ready')) !== $count) {
                foreach ($workers as $worker) if (!$worker->isRunning()) $this->fail($worker->getOutput() . $worker->getErrorOutput());
                if (microtime(true) > $deadline) $this->fail('Concurrent verify workers did not reach barrier');
                usleep(10000);
            }
            file_put_contents($directory . '/go', 'go');
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getOutput() . $worker->getErrorOutput());
            }
            $this->assertSame($count, (int)Cache::get($this->failCount('user:987654')));
        } finally {
            foreach ($workers as $worker) if ($worker->isRunning()) $worker->stop();
            $this->removeTree($directory);
            @rmdir($directory);
        }
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) return;
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
}
