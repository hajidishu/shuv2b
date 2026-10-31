<?php

namespace Tests\P1;

use App\Http\Controllers\V1\Guest\CaptchaController;
use App\Http\Controllers\V1\Passport\AuthController;
use App\Http\Controllers\V1\Passport\CommController;
use App\Http\Requests\Admin\ConfigSave;
use App\Http\Requests\Passport\AuthRegister;
use App\Http\Requests\Passport\CommSendEmailVerify;
use App\Models\User;
use App\Services\CaptchaService;
use App\Services\ImageCaptchaService;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Factory;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FixtureImageCaptcha extends ImageCaptchaService
{
    protected function generateCode(): string { return 'A1B2C3'; }
}

class ImageCaptchaTest extends DatabaseTestCase
{
    private $service;
    protected function setUp(): void
    {
        parent::setUp();
        if (!ImageCaptchaService::available()) $this->markTestSkipped('PHP GD is required for image CAPTCHA tests');
        config(['v2board.captcha_provider' => 'image']);
        \Illuminate\Support\Facades\RateLimiter::swap(new RateLimiter(app('cache')));
        $this->service = new FixtureImageCaptcha();
    }
    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }
    private function request(array $data = []): Request { return Request::create('/', 'POST', $data, [], [], ['REMOTE_ADDR' => '192.0.2.10']); }
    private function challenge(string $purpose = 'register', array $extra = []): array { return $this->service->create($this->request(array_merge(['purpose' => $purpose], $extra))); }
    private function answer(array $challenge, string $code = 'a1b2c3'): Request { return $this->request(['captcha_data' => $challenge['id'] . ':' . $code]); }
    private function rejected(callable $fn, int $status = 422): void
    {
        try { $fn(); $this->fail('Expected CAPTCHA rejection'); }
        catch (HttpException $error) { $this->assertSame($status, $error->getStatusCode()); }
    }
    public function testPublicChallengeIsPngAndOnlyHmacIsCached(): void
    {
        $data = $this->challenge();
        $this->assertSame(['id', 'image', 'expires_in'], array_keys($data));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $data['id']);
        $bytes = base64_decode(substr($data['image'], strlen('data:image/png;base64,')), true);
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($bytes, 0, 8));
        $this->assertSame([180, 56], array_slice(getimagesizefromstring($bytes), 0, 2));
        $record = Cache::get('IMAGE_CAPTCHA_' . $data['id']);
        $this->assertSame('register', $record['purpose']);
        $this->assertArrayNotHasKey('code', $record);
        $this->assertStringNotContainsString('A1B2C3', json_encode($record));
        $this->assertSame(120, $data['expires_in']);
    }
    public function testCaseInsensitiveCodeIsAcceptedOnceThroughTheUnifiedProvider(): void
    {
        $request = $this->answer($this->challenge(), 'a1B2c3');
        (new CaptchaService())->verify($request);
        $this->rejected(fn() => (new CaptchaService())->verify($request));
    }
    public function testIncorrectCodeConsumesTheChallenge(): void
    {
        $challenge = $this->challenge();
        $this->rejected(fn() => $this->service->verify($this->answer($challenge, '000000'), 'register'));
        $this->rejected(fn() => $this->service->verify($this->answer($challenge), 'register'));
    }
    public function testRegistrationAndEmailChallengesCannotBeExchanged(): void
    {
        $request = $this->answer($this->challenge('email'));
        $this->rejected(fn() => (new CaptchaService())->verify($request));
        $request = $this->answer($this->challenge('email'));
        (new CaptchaService())->verify($request, 'email');
        $this->rejected(fn() => (new CaptchaService())->verify($request, 'email'));
    }
    public function testExpiryIsEnforcedEvenIfACacheStoreReturnsAnOldRecord(): void
    {
        $data = $this->challenge();
        $key = 'IMAGE_CAPTCHA_' . $data['id'];
        $record = Cache::get($key); $record['expires_at'] = now()->timestamp - 1; Cache::put($key, $record, 120);
        $this->rejected(fn() => $this->service->verify($this->answer($data), 'register'));
        $this->assertNull(Cache::get($key));
    }
    public function testRefreshInvalidatesPreviousChallengeAndIssuesANewOne(): void
    {
        $old = $this->challenge(); $fresh = $this->challenge('register', ['previous_id' => $old['id']]);
        $this->assertNotSame($old['id'], $fresh['id']);
        $this->rejected(fn() => $this->service->verify($this->answer($old), 'register'));
        $this->service->verify($this->answer($fresh), 'register');
    }
    public function testRefreshDoesNotInvalidateTheSeparateEmailChallenge(): void
    {
        $email = $this->challenge('email');
        $this->challenge('register', ['previous_id' => $email['id']]);
        $this->service->verify($this->answer($email), 'email');
        $this->assertNull(Cache::get('IMAGE_CAPTCHA_' . $email['id']));
    }
    public function testUnsupportedPurposesAndMalformedIdentifiersAreRejected(): void
    {
        foreach ([[], ['purpose' => 'login'], ['purpose' => []], ['purpose' => 'register', 'previous_id' => []], ['purpose' => 'register', 'previous_id' => '../key']] as $data) {
            $this->rejected(fn() => $this->service->create($this->request($data)));
        }
        foreach ([null, [], 12, '', str_repeat('x', 1000), str_repeat('a', 64) . ':ABCDEF', str_repeat('b', 64) . ':ＡＢＣ１２３'] as $token) {
            $this->rejected(fn() => $this->service->verify($this->request(['captcha_data' => $token]), 'register'));
        }
    }
    public function testGenerationIsRateLimitedAndDoesNotNeedCookies(): void
    {
        for ($i = 0; $i < 20; $i++) $this->challenge();
        $this->rejected(fn() => $this->challenge(), 429);
    }
    public function testVerificationIsRateLimited(): void
    {
        for ($i = 0; $i < 60; $i++) $this->rejected(fn() => $this->service->verify($this->request(), 'register'));
        $this->rejected(fn() => $this->service->verify($this->request(), 'register'), 429);
    }
    public function testDisabledProviderHasNoChallengeEndpointAndNoRemoteSecretLeaks(): void
    {
        $settings = CaptchaService::publicSettings();
        $this->assertSame('image', $settings['captcha_provider']);
        $this->assertSame(1, $settings['is_captcha']);
        $this->assertSame(0, $settings['is_recaptcha']);
        $this->assertNull($settings['captcha_site_key']);
        config(['v2board.captcha_provider' => 'none']);
        $this->rejected(fn() => $this->challenge(), 404);
    }
    public function testMissingAppKeyFailsClosedWithoutCachingAnAnswer(): void
    {
        config(['app.key' => '']);
        $this->rejected(fn() => $this->challenge(), 503);
    }
    public function testChallengeResponsesAreNeverCacheable(): void
    {
        $response = (new CaptchaController())->create($this->request(['purpose' => 'email']), $this->service);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('no-cache', $response->headers->get('Pragma'));
    }
    public function testImageModeNeedsNoThirdPartyKeys(): void
    {
        $input = ['captcha_provider' => 'image'];
        $r = new ConfigSave(); $r->merge($input);
        $v = (new Factory(app('translator'), app()))->make($input, $r->rules()); $r->withValidator($v);
        $this->assertFalse($v->fails());
    }
    public function testDirectRegistrationAndEmailCannotSkipImageCaptcha(): void
    {
        $r = new AuthRegister(); $r->merge(['email' => 'image@example.test', 'password' => 'fixture-password', 'captcha_provider' => 'none']);
        $e = new CommSendEmailVerify(); $e->merge(['email' => 'image@example.test', 'captcha_provider' => 'none']);
        $this->rejected(fn() => (new AuthController())->register($r));
        $this->rejected(fn() => (new CommController())->sendEmailVerify($e));
        $this->assertSame(0, User::count());
    }
    public function testIndependentEmailAndRegistrationChallengesWorkEndToEnd(): void
    {
        DB::getSchemaBuilder()->table('v2_user', function (Blueprint $table) { $table->integer('last_login_at')->nullable(); });
        $e = new CommSendEmailVerify(); $e->merge(['email' => 'image@example.test', 'captcha_data' => $this->answer($this->challenge('email'))->input('captcha_data')]);
        $this->assertTrue((new CommController())->sendEmailVerify($e)->getData(true)['data']);
        $r = new AuthRegister(); $r->merge(['email' => 'image@example.test', 'password' => 'fixture-password', 'captcha_data' => $this->answer($this->challenge())->input('captcha_data')]);
        $this->assertArrayHasKey('auth_data', (new AuthController())->register($r)->getData(true)['data']);
    }
    public function testTwoProcessesCannotConsumeTheSameCode(): void
    {
        if (!function_exists('pcntl_fork')) $this->markTestSkipped('pcntl is required for the cross-process cache test');
        $dir = sys_get_temp_dir() . '/v2board-image-captcha-' . bin2hex(random_bytes(8));
        $files = new Filesystem();
        Cache::swap(new Repository(new FileStore($files, $dir)));
        \Illuminate\Support\Facades\RateLimiter::swap(new RateLimiter(Cache::getFacadeRoot()));
        try {
            $challenge = $this->challenge(); $children = [];
            for ($i = 0; $i < 2; $i++) {
                $pid = pcntl_fork();
                if ($pid === -1) $this->fail('fork failed');
                if ($pid === 0) {
                    try { $this->service->verify($this->answer($challenge), 'register'); exit(0); }
                    catch (HttpException $error) { exit([422 => 42, 503 => 43, 429 => 44][$error->getStatusCode()] ?? 99); }
                }
                $children[] = $pid;
            }
            $results = [];
            foreach ($children as $pid) { pcntl_waitpid($pid, $status); $results[] = pcntl_wexitstatus($status); }
            sort($results);
            $this->assertSame(0, $results[0]);
            // Challenge-lock or IP rate-lock contention can fail closed as busy/limited.
            $this->assertContains($results[1], [42, 43, 44]);
            $this->assertNull(Cache::get('IMAGE_CAPTCHA_' . $challenge['id']));
        } finally { $files->deleteDirectory($dir); }
    }
}
