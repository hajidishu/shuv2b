<?php

namespace Tests\P1;

use App\Http\Controllers\V1\Guest\CommController as GuestController;
use App\Http\Controllers\V1\Passport\AuthController;
use App\Http\Controllers\V1\Passport\CommController;
use App\Http\Requests\Admin\ConfigSave;
use App\Http\Requests\Passport\AuthRegister;
use App\Http\Requests\Passport\CommSendEmailVerify;
use App\Jobs\SendEmailJob;
use App\Models\User;
use App\Services\CaptchaService;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Factory;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CaptchaTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::swap(new HttpFactory());
        \Illuminate\Support\Facades\RateLimiter::swap(new RateLimiter(app('cache')));
    }

    private function turnstile(): void
    {
        config(['v2board.captcha_provider' => 'turnstile', 'v2board.turnstile_site_key' => 'fixture-public-site-key', 'v2board.turnstile_secret_key' => 'fixture-private-secret']);
    }

    private function rejected(callable $operation, int $status = 422): void
    {
        try { $operation(); $this->fail('CAPTCHA should reject this request'); }
        catch (HttpException $error) { $this->assertSame($status, $error->getStatusCode()); }
    }

    public function testLegacyRecaptchaConfigurationAndRequestFieldStillWork(): void
    {
        config(['v2board.recaptcha_enable' => 1, 'v2board.recaptcha_key' => 'fixture-google-secret', 'v2board.recaptcha_site_key' => 'fixture-google-site']);
        Http::fake(function ($request) {
            $this->assertSame('https://www.google.com/recaptcha/api/siteverify', $request->url());
            return Http::response(['success' => true]);
        });
        (new CaptchaService())->verify(Request::create('/', 'POST', ['recaptcha_data' => 'fixture-google-token']));
        $settings = CaptchaService::publicSettings();
        $this->assertSame('recaptcha', $settings['captcha_provider']);
        $this->assertSame(1, $settings['is_recaptcha']);
        Http::assertSentCount(1);
    }

    public function testTurnstileUsesSavedProviderSecretAndTrustedRequestIp(): void
    {
        $this->turnstile();
        Http::fake(function ($request) {
            $this->assertSame('https://challenges.cloudflare.com/turnstile/v0/siteverify', $request->url());
            $this->assertSame('fixture-private-secret', $request['secret']);
            $this->assertSame('fixture-turnstile-token', $request['response']);
            $this->assertSame('192.0.2.42', $request['remoteip']);
            return Http::response(['success' => true]);
        });
        $request = Request::create('/', 'POST', ['captcha_provider' => 'none', 'secret' => 'attacker-supplied', 'captcha_data' => 'fixture-turnstile-token'], [], [], ['REMOTE_ADDR' => '192.0.2.42', 'HTTP_X_FORWARDED_FOR' => '198.51.100.8']);
        (new CaptchaService())->verify($request);
        Http::assertSentCount(1);
    }

    public function testMissingMalformedAndOversizedTokensNeverCallTheProvider(): void
    {
        $this->turnstile(); Http::fake();
        foreach ([null, '', [], 123, str_repeat('t', 2049)] as $token) {
            $this->rejected(function () use ($token) { (new CaptchaService())->verify(Request::create('/', 'POST', ['captcha_data' => $token])); });
        }
        Http::assertNothingSent();
    }

    public function testExpiredAndReusedTokensAndNonBooleanSuccessAreRejected(): void
    {
        $this->turnstile();
        foreach ([['success' => false, 'error-codes' => ['timeout-or-duplicate']], ['success' => 'true'], []] as $payload) {
            Http::swap(new HttpFactory()); Http::fake(function () use ($payload) { return Http::response($payload); });
            $this->rejected(function () { (new CaptchaService())->verify(Request::create('/', 'POST', ['captcha_data' => 'fixture-expired-token'])); });
        }
    }

    public function testProviderOutageInvalidJsonAndExceptionsFailClosed(): void
    {
        $this->turnstile();
        foreach ([fn() => Http::response(['success' => true], 503), fn() => Http::response('<html>outage</html>'), function () { throw new \RuntimeException('fixture network timeout'); }] as $reply) {
            Http::swap(new HttpFactory()); Http::fake($reply);
            $this->rejected(function () { (new CaptchaService())->verify(Request::create('/', 'POST', ['captcha_data' => 'fixture-token'])); }, 503);
        }
    }

    public function testDisabledProviderOverridesOldGoogleFlagAndUnknownProviderFailsClosed(): void
    {
        Http::fake(); config(['v2board.captcha_provider' => 'none', 'v2board.recaptcha_enable' => 1]);
        (new CaptchaService())->verify(Request::create('/'));
        config(['v2board.captcha_provider' => 'unknown']);
        $this->rejected(function () { (new CaptchaService())->verify(Request::create('/')); }, 503);
        Http::assertNothingSent();
    }

    public function testPublicConfigurationNeverContainsThePrivateKey(): void
    {
        $this->turnstile();
        $data = (new GuestController())->config()->getData(true)['data'];
        $this->assertSame('turnstile', $data['captcha_provider']);
        $this->assertSame('fixture-public-site-key', $data['captcha_site_key']);
        $this->assertSame(1, $data['is_captcha']);
        $this->assertSame(0, $data['is_recaptcha']);
        $this->assertStringNotContainsString('fixture-private-secret', json_encode($data));
    }

    public function testRegisterAndEmailCannotBypassValidation(): void
    {
        $this->turnstile(); Http::fake(fn() => Http::response(['success' => false]));
        $register = new AuthRegister(); $register->merge(['email' => 'fixture@example.test', 'password' => 'fixture-password', 'captcha_data' => 'fixture-rejected']);
        $email = new CommSendEmailVerify(); $email->merge(['email' => 'fixture@example.test', 'captcha_data' => 'fixture-rejected']);
        $this->rejected(function () use ($register) { (new AuthController())->register($register); });
        $this->rejected(function () use ($email) { (new CommController())->sendEmailVerify($email); });
        $this->assertSame(0, User::count());
        Environment::$queue->assertNotPushed(SendEmailJob::class);
        Http::assertSentCount(2);
    }

    public function testEmailAndRegistrationCanUseSeparateSuccessfulTokens(): void
    {
        $this->turnstile(); Http::fake(fn() => Http::response(['success' => true]));
        DB::getSchemaBuilder()->table('v2_user', function (Blueprint $table) { $table->integer('last_login_at')->nullable(); });
        $email = new CommSendEmailVerify(); $email->merge(['email' => 'fixture@example.test', 'captcha_data' => 'fixture-mail-token']);
        $this->assertTrue((new CommController())->sendEmailVerify($email)->getData(true)['data']);
        Environment::$queue->assertPushed(SendEmailJob::class, 1);
        $register = new AuthRegister(); $register->merge(['email' => 'fixture@example.test', 'password' => 'fixture-password', 'captcha_data' => 'fixture-register-token']);
        $this->assertArrayHasKey('auth_data', (new AuthController())->register($register)->getData(true)['data']);
        Http::assertSentCount(2);
    }

    public function testSettingsRequireKeysForTheChosenProviderAndRejectUnknownProviders(): void
    {
        foreach ([['captcha_provider' => 'turnstile'], ['captcha_provider' => 'unsupported'], ['captcha_provider' => 'recaptcha']] as $input) {
            $request = new ConfigSave(); $request->merge($input);
            $factory = new Factory(app('translator'), app());
            $validator = $factory->make($input, $request->rules()); $request->withValidator($validator);
            $this->assertTrue($validator->fails());
        }
        $input = ['captcha_provider' => 'turnstile', 'turnstile_site_key' => 'fixture-site', 'turnstile_secret_key' => 'fixture-secret'];
        $request = new ConfigSave(); $request->merge($input);
        $validator = (new Factory(app('translator'), app()))->make($input, $request->rules()); $request->withValidator($validator);
        $this->assertFalse($validator->fails());
    }
}
