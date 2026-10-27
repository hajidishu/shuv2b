<?php

namespace Tests\P1;

use App\Models\User;
use App\Services\AccountSecurityService;
use App\Services\AuthService;
use App\Services\GoogleIdentitySchema;
use App\Services\GoogleIdentityService;
use App\Services\GoogleSettings;
use App\Services\GoogleTokenVerifier;
use App\Services\PasskeyService;
use App\Utils\CacheKey;
use Firebase\JWT\JWT;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Factory;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Validation\ValidationException;

class GoogleIdentityTest extends DatabaseTestCase
{
    private const CLIENT = '123456789012-fixture.apps.googleusercontent.com';
    private const ORIGIN = 'https://panel.example.test';
    private static $private;
    private $service;

    protected function setUp(): void
    {
        parent::setUp();
        DB::getSchemaBuilder()->dropIfExists('v2_social_identity');
        (new GoogleIdentitySchema())->ensure();
        DB::getSchemaBuilder()->table('v2_user', function (Blueprint $t) { $t->integer('last_login_at')->nullable(); });
        DB::getSchemaBuilder()->dropIfExists('v2_invite_code');
        DB::getSchemaBuilder()->create('v2_invite_code', function (Blueprint $t) {
            $t->increments('id'); $t->string('code')->unique(); $t->integer('status')->default(0); $t->integer('user_id')->nullable();
            $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
        });
        config(['v2board.google_login_enabled' => 1, 'v2board.google_register_enabled' => 1,
            'v2board.google_client_id' => self::CLIENT, 'v2board.google_origins' => [self::ORIGIN]]);
        if (!self::$private) self::$private = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $rsa = openssl_pkey_get_details(self::$private)['rsa'];
        Http::swap(new Factory());
        Http::fake(function ($request) use ($rsa) {
            $this->assertSame('https://www.googleapis.com/oauth2/v3/certs', $request->url());
            return Http::response(['keys' => [['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => 'fixture-key',
                'n' => PasskeyService::encode($rsa['n']), 'e' => PasskeyService::encode($rsa['e'])]]], 200, ['Cache-Control' => 'public, max-age=3600']);
        });
        $this->service = new GoogleIdentityService(new GoogleTokenVerifier());
    }

    protected function tearDown(): void
    {
        DB::getSchemaBuilder()->dropIfExists('v2_social_identity');
        DB::getSchemaBuilder()->dropIfExists('v2_invite_code');
        parent::tearDown();
    }

    private function request(array $data = [], ?string $jwt = null, string $origin = self::ORIGIN): Request
    {
        $request = Request::create('/api/v1/passport/google/verify', 'POST', $data, [], [], ['REMOTE_ADDR' => '192.0.2.17', 'HTTP_ORIGIN' => $origin]);
        if ($jwt) $request->headers->set('Authorization', $jwt);
        return $request;
    }

    private function account(array $values = []): array
    {
        $user = $this->user(array_merge(['password' => password_hash('fixture-password', PASSWORD_DEFAULT),
            'token' => bin2hex(random_bytes(16)), 'uuid' => bin2hex(random_bytes(16))], $values))->fresh();
        return [$user, (new AuthService($user))->generateAuthData($this->request())['auth_data']];
    }

    private function token(array $options, array $changes = []): string
    {
        return JWT::encode(array_merge(['iss' => 'https://accounts.google.com', 'aud' => self::CLIENT,
            'sub' => 'fixture-google-subject', 'email' => 'google-user@gmail.com', 'email_verified' => true,
            'iat' => time(), 'exp' => time() + 3600, 'nonce' => $options['nonce']], $changes), self::$private, 'RS256', 'fixture-key');
    }

    private function loginPayload(array $changes = []): array
    {
        $nonce = PasskeyService::encode(random_bytes(32));
        $options = $this->service->options($this->request(['client_nonce' => $nonce]));
        return ['client_nonce' => $nonce, 'transaction_id' => $options['transaction_id'], 'credential' => $this->token($options, $changes)];
    }

    private function pending(array $changes = []): array
    {
        $payload = $this->loginPayload($changes);
        return [$this->service->login($this->request($payload)), $payload['client_nonce']];
    }

    private function register(array $changes = [], array $form = []): array
    {
        [$pending, $nonce] = $this->pending($changes);
        return $this->service->register($this->request(array_merge(['registration_token' => $pending['registration_token'],
            'client_nonce' => $nonce, 'password' => 'new-site-password'], $form)));
    }

    private function rejected(callable $fn, int $status = 422): void
    {
        try { $fn(); $this->fail('Expected a rejected Google operation'); }
        catch (HttpException $error) { $this->assertSame($status, $error->getStatusCode()); }
        catch (ValidationException $error) { $this->assertSame($status, $error->status); }
    }

    private function bind(array $account, array $claims = []): array
    {
        [$user, $jwt] = $account;
        $nonce = PasskeyService::encode(random_bytes(32));
        $options = $this->service->options($this->request(['client_nonce' => $nonce, 'password' => 'fixture-password'], $jwt), true);
        return $this->service->bind($this->request(['client_nonce' => $nonce, 'transaction_id' => $options['transaction_id'], 'credential' => $this->token($options, $claims)], $jwt));
    }

    public function testFeatureIsDisabledByDefaultAndRejectsOtherOrigins(): void
    {
        $controller = new \App\Http\Controllers\V1\Passport\GoogleController($this->service);
        $this->assertStringContainsString('no-store', $controller->config()->headers->get('Cache-Control'));
        config(['v2board.google_login_enabled' => 0]);
        $this->assertFalse(GoogleSettings::publicSettings()['google_login_enabled']);
        $this->assertNull(GoogleSettings::publicSettings()['google_client_id']);
        $this->rejected(fn() => $this->service->options($this->request(['client_nonce' => str_repeat('a', 43)])));
        config(['v2board.google_login_enabled' => 1]);
        $this->rejected(fn() => $this->service->options($this->request(['client_nonce' => str_repeat('a', 43)], null, 'https://other.example.test')), 403);
        Http::assertNothingSent();
    }

    public function testGoogleRegistrationRequiresARealSitePasswordAndKeepsGoogleEmailImmutable(): void
    {
        [$pending, $nonce] = $this->pending();
        $this->assertSame('registration_required', $pending['status']);
        $this->assertSame(0, User::count());
        $payload = ['registration_token' => $pending['registration_token'], 'client_nonce' => $nonce];
        $this->rejected(fn() => $this->service->register($this->request($payload)));
        $result = $this->service->register($this->request($payload + ['password' => 'new-site-password', 'email' => 'attacker@example.test']));
        $user = User::firstOrFail();
        $this->assertSame('google-user@gmail.com', $user->email);
        $this->assertTrue(password_verify('new-site-password', $user->password));
        $this->assertSame((int)$user->id, (int)AuthService::decryptAuthData($result['auth']['auth_data'])['id']);
        $login = new \App\Http\Requests\Passport\AuthLogin();
        $login->merge(['email' => $user->email, 'password' => 'new-site-password']);
        $passwordLogin = (new \App\Http\Controllers\V1\Passport\AuthController())->login($login)->getData(true)['data'];
        $this->assertSame((int)$user->id, (int)AuthService::decryptAuthData($passwordLogin['auth_data'])['id']);
        $this->rejected(fn() => $this->service->register($this->request($payload + ['password' => 'new-site-password'])));
        $this->assertSame(1, User::count());
    }

    public function testMatchingEmailRequiresExplicitBindingAndDoesNotCreateASession(): void
    {
        [$user] = $this->account(['email' => 'google-user@gmail.com', 'balance' => 7654]);
        $before = (new AuthService($user))->getSessions();
        [$result] = $this->pending();
        $this->assertSame(['status' => 'link_required', 'email' => $user->email], $result);
        $this->assertSame(0, DB::table('v2_social_identity')->count());
        $this->assertSame($before, (new AuthService($user))->getSessions());
        $this->assertSame(7654, (int)$user->fresh()->balance);
    }

    public function testBindingDifferentEmailPreservesAccountAndSubIdentifiesFutureLogins(): void
    {
        $account = $this->account(['email' => 'site-owner@example.test', 'balance' => 12345]);
        [$user] = $account; $before = $user->getAttributes();
        $this->assertTrue($this->bind($account)['success']);
        foreach (['email', 'password', 'uuid', 'token', 'balance'] as $name) $this->assertSame($before[$name], $user->fresh()->$name);
        $result = $this->service->login($this->request($this->loginPayload(['email' => 'renamed-google@gmail.com'])));
        $this->assertSame('authenticated', $result['status']);
        $this->assertSame((int)$user->id, (int)AuthService::decryptAuthData($result['auth']['auth_data'])['id']);
        $this->assertSame('site-owner@example.test', $user->fresh()->email);
        $this->assertSame('renamed-google@gmail.com', $this->service->listing($this->request([], $account[1]))['identity']['email']);
    }

    public function testBindingRequiresFreshLocalIdentityAndAnActiveSession(): void
    {
        [$user, $jwt] = $this->account();
        $nonce = str_repeat('a', 43);
        $this->rejected(fn() => $this->service->options($this->request(['client_nonce' => $nonce, 'password' => 'wrong'], $jwt), true));
        $this->rejected(fn() => $this->service->options($this->request(['client_nonce' => $nonce]), true), 403);
        $options = $this->service->options($this->request(['client_nonce' => $nonce, 'password' => 'fixture-password'], $jwt), true);
        (new AuthService($user))->removeAllSession();
        $this->rejected(fn() => $this->service->bind($this->request(['client_nonce' => $nonce, 'transaction_id' => $options['transaction_id'], 'credential' => $this->token($options)], $jwt)), 403);
        $this->assertSame(0, DB::table('v2_social_identity')->count());
    }

    public function testOneGoogleIdentityCannotBeReassignedToAnotherUser(): void
    {
        $a = $this->account(); $b = $this->account();
        $this->bind($a);
        $this->rejected(fn() => $this->bind($b), 409);
        $this->assertSame((int)$a[0]->id, (int)DB::table('v2_social_identity')->first()->user_id);
    }

    public function testUnlinkRevokesGoogleAndQuickLoginSessionsButKeepsPasswordLogin(): void
    {
        [$user, $passwordJwt] = $account = $this->account();
        $this->bind($account);
        $google = $this->service->login($this->request($this->loginPayload()))['auth']['auth_data'];
        $quickCode = AuthService::issueQuickLoginCode($google);
        $quickJwt = AuthService::redeemQuickLoginCode($this->request(), $quickCode)['auth_data'];
        $this->rejected(fn() => $this->service->unbind($this->request(['password' => 'wrong'], $google)));
        $result = $this->service->unbind($this->request(['password' => 'fixture-password'], $google));
        $this->assertTrue($result['sign_out']);
        $this->assertFalse(AuthService::decryptAuthData($google));
        $this->assertFalse(AuthService::decryptAuthData($quickJwt));
        $this->assertNotFalse(AuthService::decryptAuthData($passwordJwt));
        $this->assertSame(0, DB::table('v2_social_identity')->count());
    }

    public function testAccountRecoveryRemovesGoogleBindingAndAllSessions(): void
    {
        [$user, $jwt] = $account = $this->account(); $this->bind($account);
        AccountSecurityService::recover($user);
        $this->assertFalse(AuthService::decryptAuthData($jwt));
        $this->assertSame(0, DB::table('v2_social_identity')->count());
    }

    public function testBannedUserCannotUseGoogleLogin(): void
    {
        $account = $this->account(); $this->bind($account);
        $account[0]->banned = 1; $account[0]->save();
        $this->rejected(fn() => $this->service->login($this->request($this->loginPayload())), 403);
    }

    public function testRegistrationSwitchDoesNotDisableReturningGoogleUsers(): void
    {
        $account = $this->account(); $this->bind($account);
        config(['v2board.google_register_enabled' => 0, 'v2board.stop_register' => 1]);
        $this->assertSame('authenticated', $this->service->login($this->request($this->loginPayload()))['status']);
        $this->rejected(fn() => $this->pending(['sub' => 'other-subject', 'email' => 'other@gmail.com']), 403);
    }

    public function testRegistrationUsesSharedInviteAndIpLimits(): void
    {
        config(['v2board.invite_force' => 1, 'v2board.register_limit_by_ip_enable' => 1, 'v2board.register_limit_count' => 1]);
        [$pending, $nonce] = $this->pending();
        $payload = ['registration_token' => $pending['registration_token'], 'client_nonce' => $nonce, 'password' => 'new-site-password'];
        $this->rejected(fn() => $this->service->register($this->request($payload)), 500);
        DB::table('v2_invite_code')->insert(['code' => 'fixture-invite', 'status' => 0]);
        $this->service->register($this->request($payload + ['invite_code' => 'fixture-invite']));
        $this->assertSame(1, (int)DB::table('v2_invite_code')->first()->status);
        $this->assertSame(1, (int)Cache::get(CacheKey::get('REGISTER_IP_RATE_LIMIT', '192.0.2.17')));
        $ordinary = new \App\Http\Requests\Passport\AuthRegister();
        $ordinary->merge(['email' => 'ordinary@example.test', 'password' => 'new-site-password']);
        $ordinary->server->set('REMOTE_ADDR', '192.0.2.17');
        $this->rejected(fn() => (new \App\Http\Controllers\V1\Passport\AuthController())->register($ordinary), 500);
        $this->assertSame(1, User::count());
    }

    public function testThirdPartyGoogleEmailRequiresAnEmailCodeEvenWhenSiteVerificationIsDisabled(): void
    {
        [$pending, $nonce] = $this->pending(['email' => 'owner@example.test']);
        $this->assertTrue($pending['email_verification_required']);
        $payload = ['registration_token' => $pending['registration_token'], 'client_nonce' => $nonce, 'password' => 'new-site-password'];
        $this->rejected(fn() => $this->service->register($this->request($payload)), 500);
        Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', 'owner@example.test'), '123456', 300);
        $this->service->register($this->request($payload + ['email_code' => '123456']));
        $this->assertNull(Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', 'owner@example.test')));
        $this->assertSame(1, User::count());
    }

    public function testChallengeIsSingleUseAndBoundToBrowserAndConfiguredClient(): void
    {
        $payload = $this->loginPayload();
        $this->rejected(fn() => $this->service->login($this->request(array_merge($payload, ['client_nonce' => str_repeat('b', 43)]))));
        $this->assertSame('registration_required', $this->service->login($this->request($payload))['status']);
        $this->rejected(fn() => $this->service->login($this->request($payload)));
        $payload = $this->loginPayload();
        config(['v2board.google_client_id' => '999999-other.apps.googleusercontent.com']);
        $this->rejected(fn() => $this->service->login($this->request($payload)));
    }

    /** @dataProvider invalidClaims */
    public function testInvalidGoogleClaimsNeverCreateAccountsOrBindings(array $claims): void
    {
        $this->rejected(fn() => $this->pending($claims));
        $this->assertSame(0, User::count());
        $this->assertSame(0, DB::table('v2_social_identity')->count());
    }

    public static function invalidClaims(): array
    {
        return [[['aud' => 'foreign-app']], [['iss' => 'https://attacker.example.test']], [['email_verified' => false]],
            [['exp' => 1]], [['nonce' => 'wrong-nonce']], [['sub' => '']], [['azp' => 'foreign-app']], [['email' => 'not-an-email']]];
    }

    public function testInvalidAlgorithmsAndSignaturesAreRejected(): void
    {
        $payload = $this->loginPayload();
        $parts = explode('.', $payload['credential']); $parts[2] = PasskeyService::encode(str_repeat('z', 256));
        $payload['credential'] = implode('.', $parts);
        $this->rejected(fn() => $this->service->login($this->request($payload)));
        $payload = $this->loginPayload();
        $payload['credential'] = JWT::encode(['sub' => 'attacker'], str_repeat('a', 32), 'HS256', 'fixture-key');
        $this->rejected(fn() => $this->service->login($this->request($payload)));
    }

    public function testGooglePublicKeysAreCachedAndDisabledOriginsDoNotLeakConfiguration(): void
    {
        $this->pending(); $this->pending(['sub' => 'other-subject']);
        Http::assertSentCount(1);
        $settings = GoogleSettings::publicSettings();
        $this->assertSame(['google_login_enabled', 'google_client_id'], array_keys($settings));
        $this->assertSame(self::CLIENT, $settings['google_client_id']);
        (new GoogleIdentitySchema())->ensure();
        $this->assertSame(0, DB::table('v2_social_identity')->count());
    }

    public function testGoogleProofDoesNotBypassConfiguredCaptchaOrSetRoles(): void
    {
        [$pending, $nonce] = $this->pending();
        $payload = ['registration_token' => $pending['registration_token'], 'client_nonce' => $nonce,
            'password' => 'new-site-password', 'is_admin' => 1, 'is_staff' => 1];
        config(['v2board.captcha_provider' => 'turnstile', 'v2board.turnstile_secret_key' => 'fixture-secret']);
        $this->rejected(fn() => $this->service->register($this->request($payload)));
        $this->assertSame(0, User::count());
        config(['v2board.captcha_provider' => 'none']);
        $this->service->register($this->request($payload));
        $this->assertSame(0, (int)User::firstOrFail()->is_admin);
        $this->assertSame(0, (int)User::firstOrFail()->is_staff);
    }

    public function testBindingChallengeCannotBeCompletedUnderAnotherAccount(): void
    {
        $a = $this->account(); $b = $this->account(); $nonce = str_repeat('a', 43);
        $options = $this->service->options($this->request(['client_nonce' => $nonce, 'password' => 'fixture-password'], $a[1]), true);
        $this->rejected(fn() => $this->service->bind($this->request(['client_nonce' => $nonce, 'transaction_id' => $options['transaction_id'],
            'credential' => $this->token($options)], $b[1])), 403);
        $this->assertSame(0, DB::table('v2_social_identity')->count());
    }

    public function testChangedPasswordInvalidatesPendingBindingEvenBeforeSessionCleanup(): void
    {
        [$user, $jwt] = $this->account(); $nonce = str_repeat('a', 43);
        $options = $this->service->options($this->request(['client_nonce' => $nonce, 'password' => 'fixture-password'], $jwt), true);
        $user->password = password_hash('replacement-password', PASSWORD_DEFAULT); $user->save();
        $this->rejected(fn() => $this->service->bind($this->request(['client_nonce' => $nonce, 'transaction_id' => $options['transaction_id'],
            'credential' => $this->token($options)], $jwt)), 403);
        $this->assertSame(0, DB::table('v2_social_identity')->count());
    }

    public function testDeployersMustConfigureTheirOwnClientAndOriginsBeforeEnabling(): void
    {
        $factory = app('validator');
        foreach ([['google_login_enabled' => 1], ['google_login_enabled' => 1, 'google_client_id' => 'not-a-client', 'google_origins' => [self::ORIGIN]]] as $input) {
            config(['v2board.google_client_id' => '', 'v2board.google_origins' => []]);
            $request = new \App\Http\Requests\Admin\ConfigSave(); $request->merge($input);
            $validator = $factory->make($input, $request->rules()); $request->withValidator($validator);
            $this->assertTrue($validator->fails());
        }
        $input = ['google_login_enabled' => 1, 'google_client_id' => self::CLIENT, 'google_origins' => [self::ORIGIN, 'https://other.example.net:8443']];
        $request = new \App\Http\Requests\Admin\ConfigSave(); $request->merge($input);
        $validator = $factory->make($input, $request->rules()); $request->withValidator($validator);
        $this->assertFalse($validator->fails());
    }

    public function testGoogleLoginUpdatesAccountLoginTimeWithoutChangingSiteEmail(): void
    {
        $account = $this->account(['last_login_at' => 1]); $this->bind($account);
        $this->service->login($this->request($this->loginPayload()));
        $this->assertGreaterThan(1, $account[0]->fresh()->last_login_at);
        $this->assertSame($account[0]->email, $account[0]->fresh()->email);
    }

    public function testEmailCodeFailureBudgetIsSharedWithOrdinaryRegistration(): void
    {
        [$pending, $nonce] = $this->pending(['email' => 'owner@example.test']);
        $payload = ['registration_token' => $pending['registration_token'], 'client_nonce' => $nonce,
            'password' => 'new-site-password', 'email_code' => '000000'];
        Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', 'owner@example.test'), '123456', 300);
        for ($i = 0; $i < 10; $i++) $this->rejected(fn() => $this->service->register($this->request($payload)), 500);
        $this->rejected(fn() => $this->service->register($this->request(array_merge($payload, ['email_code' => '123456']))), 429);
        config(['v2board.email_verify' => 1]);
        $this->rejected(fn() => app(\App\Services\RegistrationService::class)->register($this->request([
            'email' => 'owner@example.test', 'password' => 'new-site-password', 'email_code' => '123456'])), 429);
        $this->assertSame(0, User::count());
    }

    public function testGoogleKeyServiceFailureDoesNotIssueSessionOrAccount(): void
    {
        $payload = $this->loginPayload();
        Http::swap(new Factory());
        Http::fake(['*' => Http::response([], 503)]);
        $this->rejected(fn() => $this->service->login($this->request($payload)), 503);
        $this->assertSame(0, User::count());
        $this->assertSame(0, DB::table('v2_social_identity')->count());
    }
}
