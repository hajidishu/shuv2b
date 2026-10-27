<?php

namespace Tests\P1;

use App\Services\AuthService;
use App\Services\PasskeyChallenge;
use App\Services\PasskeySchema;
use App\Services\PasskeyService;
use App\Services\PasskeySettings;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PasskeyTest extends DatabaseTestCase
{
    private $service;
    private $account;
    private $session;
    private $private;
    private $rawId;
    private $handle;
    private $credentialId;
    private $origin = 'https://passkey.example.test:8443';
    private $rp = 'passkey.example.test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['v2board.passkey_enabled' => 1, 'v2board.passkey_rp_id' => $this->rp, 'v2board.passkey_origins' => [$this->origin]]);
        DB::getSchemaBuilder()->table('v2_user', function (Blueprint $t) { $t->integer('last_login_at')->nullable(); });
        (new PasskeySchema())->ensure();
        $this->service = new PasskeyService(new PasskeyChallenge());
        $this->account = $this->user(['password' => password_hash('test-password', PASSWORD_DEFAULT), 'token' => 'subscription-token', 'uuid' => 'unchanged-uuid'])->fresh();
        $this->session = (new AuthService($this->account))->generateAuthData(Request::create('/', 'POST'))['auth_data'];
        $this->private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->rawId = random_bytes(32);
    }

    private function request(array $data = [], $session = null, $origin = null): Request
    {
        $r = Request::create('/', 'POST', $data);
        $r->headers->set('Origin', $origin ?? $this->origin);
        $r->headers->set('Authorization', $session ?? $this->session);
        return $r;
    }

    private function reject(callable $fn, int $status = 422): void
    {
        try { $fn(); $this->fail('Expected rejected Passkey operation'); }
        catch (HttpException $e) { $this->assertSame($status, $e->getStatusCode()); }
    }

    private function grant(string $action = 'register', $target = null): string
    {
        return $this->service->passwordReauth($this->request(['action' => $action, 'target_id' => $target, 'password' => 'test-password']))['reauth_token'];
    }

    private function client(string $type, $challenge, $origin = null): string
    {
        return json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $origin ?? $this->origin, 'crossOrigin' => false]);
    }

    private function bytes(string $s): string { return "\x59" . pack('n', strlen($s)) . $s; }

    private function register(bool $backup = false): void
    {
        $nonce = PasskeyService::encode(random_bytes(32));
        $options = $this->service->registerOptions($this->request(['reauth_token' => $this->grant(), 'client_nonce' => $nonce]));
        $public = json_decode(json_encode($options['publicKey']), true);
        $this->handle = $public['user']['id'];
        $ec = openssl_pkey_get_details($this->private)['ec'];
        // OpenSSL may omit a leading zero; COSE P-256 coordinates are 32 bytes.
        foreach (['x', 'y'] as $coordinate) $ec[$coordinate] = str_pad($ec[$coordinate], 32, "\0", STR_PAD_LEFT);
        $cose = "\xa5\x01\x02\x03\x26\x20\x01\x21\x58\x20" . $ec['x'] . "\x22\x58\x20" . $ec['y'];
        $auth = hash('sha256', $this->rp, true) . chr(0x45 | ($backup ? 8 : 0)) . pack('N', 0) . str_repeat("\0", 16) . pack('n', strlen($this->rawId)) . $this->rawId . $cose;
        $attestation = "\xa3\x63fmt\x64none\x67attStmt\xa0\x68authData" . $this->bytes($auth);
        $id = PasskeyService::encode($this->rawId);
        $result = $this->service->registerVerify($this->request(['name' => 'Test key', 'client_nonce' => $nonce, 'transaction_id' => $options['transaction_id'], 'credential' => [
            'id' => $id, 'rawId' => $id, 'type' => 'public-key', 'transports' => ['internal'],
            'response' => ['clientDataJSON' => PasskeyService::encode($this->client('webauthn.create', $public['challenge'])), 'attestationObject' => PasskeyService::encode($attestation)],
        ]]));
        $this->credentialId = $result['id'];
    }

    private function loginPayload(int $counter = 1, int $flags = 5, array $extra = []): array
    {
        $nonce = PasskeyService::encode(random_bytes(32));
        $options = $this->service->loginOptions($this->request(['client_nonce' => $nonce, 'audience' => $extra['audience'] ?? 'user']));
        $public = json_decode(json_encode($options['publicKey']), true);
        $client = $this->client('webauthn.get', $public['challenge'], $extra['origin'] ?? null);
        $auth = hash('sha256', $extra['rp'] ?? $this->rp, true) . chr($flags) . pack('N', $counter);
        openssl_sign($auth . hash('sha256', $client, true), $signature, $this->private, OPENSSL_ALGO_SHA256);
        $id = PasskeyService::encode($this->rawId);
        return ['client_nonce' => $nonce, 'transaction_id' => $options['transaction_id'], 'credential' => [
            'id' => $id, 'rawId' => $id, 'type' => 'public-key',
            'response' => ['clientDataJSON' => PasskeyService::encode($client), 'authenticatorData' => PasskeyService::encode($auth), 'signature' => PasskeyService::encode($signature), 'userHandle' => $extra['handle'] ?? $this->handle],
        ]];
    }

    public function testGoogleReauthenticationUsesRealPasskeySignatureAndSinglePurposeGrant(): void
    {
        $this->register();
        foreach (['google_bind', 'google_unbind'] as $counter => $action) {
            $nonce = PasskeyService::encode(random_bytes(32));
            $options = $this->service->reauthOptions($this->request(['action' => $action, 'client_nonce' => $nonce]));
            $public = json_decode(json_encode($options['publicKey']), true);
            $client = $this->client('webauthn.get', $public['challenge']);
            $auth = hash('sha256', $this->rp, true) . chr(5) . pack('N', $counter + 1);
            openssl_sign($auth . hash('sha256', $client, true), $signature, $this->private, OPENSSL_ALGO_SHA256);
            $id = PasskeyService::encode($this->rawId);
            $grant = $this->service->reauthVerify($this->request(['client_nonce' => $nonce, 'transaction_id' => $options['transaction_id'],
                'credential' => ['id' => $id, 'rawId' => $id, 'type' => 'public-key', 'response' => [
                    'clientDataJSON' => PasskeyService::encode($client), 'authenticatorData' => PasskeyService::encode($auth),
                    'signature' => PasskeyService::encode($signature), 'userHandle' => $this->handle]]]));
            $request = $this->request(['reauth_token' => $grant['reauth_token']]);
            $this->service->authorizeGoogleAction($request, $this->account, $action);
            $this->reject(fn() => $this->service->authorizeGoogleAction($request, $this->account, $action));
        }
        $grant = $this->grant('google_bind');
        $this->reject(fn() => $this->service->authorizeGoogleAction($this->request(['reauth_token' => $grant]), $this->account, 'google_unbind'));
        $this->assertNotFalse(AuthService::decryptAuthData($this->session));
    }

    public function testRealAttestationAndSignatureProduceExistingLoginContract(): void
    {
        $this->register();
        $payload = $this->loginPayload();
        $result = $this->service->loginVerify($this->request($payload));
        $this->assertSame('subscription-token', $result['token']);
        $this->assertSame($this->account->id, AuthService::decryptAuthData($result['auth_data'])['id']);
        $this->assertSame('unchanged-uuid', $this->account->fresh()->uuid);
        $this->assertCount(1, $this->service->listing($this->request())['credentials']);
        $this->reject(function () use ($payload) { $this->service->loginVerify($this->request($payload)); });
    }

    public function testChallengeCannotBeReusedOrMovedToAnotherOriginNonceOrSession(): void
    {
        $this->register();
        $payload = $this->loginPayload();
        $altered = $payload; $altered['client_nonce'] = PasskeyService::encode(random_bytes(32));
        $this->reject(function () use ($altered) { $this->service->loginVerify($this->request($altered)); });
        $this->reject(function () use ($payload) { $this->service->loginVerify($this->request($payload)); });
        $grant = $this->grant();
        $second = (new AuthService($this->account))->generateAuthData(Request::create('/', 'POST'))['auth_data'];
        $this->reject(function () use ($grant, $second) { $this->service->registerOptions($this->request(['reauth_token' => $grant, 'client_nonce' => PasskeyService::encode(random_bytes(32))], $second)); });
        $this->reject(function () { $this->service->loginOptions($this->request(['client_nonce' => PasskeyService::encode(random_bytes(32))], null, 'https://attacker.example')); });
    }

    private function associatedOrigins(): array
    {
        $origins = [$this->origin, 'https://associated.example.net'];
        config(['v2board.passkey_origins' => $origins]);
        return $origins;
    }

    public function testOneCredentialWorksAcrossDifferentAllowedDomainsWithTheSameRp(): void
    {
        [$primary, $related] = $this->associatedOrigins();
        $this->register();
        $this->origin = $related;
        $result = $this->service->loginVerify($this->request($this->loginPayload()));
        $this->assertSame($this->account->id, AuthService::decryptAuthData($result['auth_data'])['id']);
        $this->assertCount(1, $this->service->listing($this->request())['credentials']);
        $this->assertSame($this->rp, DB::table('v2_passkey_user')->where('user_id', $this->account->id)->value('rp_id'));
        $this->assertSame('unchanged-uuid', $this->account->fresh()->uuid);
    }

    public function testCredentialCreatedOnAssociatedOriginWorksOnPrimaryOrigin(): void
    {
        [$primary, $related] = $this->associatedOrigins();
        $this->origin = $related;
        $this->register();
        $this->origin = $primary;
        $result = $this->service->loginVerify($this->request($this->loginPayload()));
        $this->assertSame($this->account->id, AuthService::decryptAuthData($result['auth_data'])['id']);
    }

    public function testAllowedRelatedOriginsStillCannotShareAnInFlightChallenge(): void
    {
        [$primary, $related] = $this->associatedOrigins();
        $this->register(); $payload = $this->loginPayload();
        $this->reject(function () use ($payload, $related) { $this->service->loginVerify($this->request($payload, null, $related)); });
    }

    public function testRemovedOriginCannotUseAPreviouslyIssuedChallenge(): void
    {
        [$primary, $related] = $this->associatedOrigins();
        $this->register(); $this->origin = $related;
        $payload = $this->loginPayload();
        config(['v2board.passkey_origins' => [$primary]]);
        $this->reject(function () use ($payload) { $this->service->loginVerify($this->request($payload)); });
    }

    public function testAssociatedOriginCannotChangeRpHashOrTheSignedOrigin(): void
    {
        [$primary, $related] = $this->associatedOrigins();
        $this->register(); $this->origin = $related;
        foreach ([['rp' => 'associated.example.net'], ['origin' => $primary]] as $extra) {
            $payload = $this->loginPayload(1, 5, $extra);
            $this->reject(function () use ($payload) { $this->service->loginVerify($this->request($payload)); });
        }
    }

    public function testWellKnownIsPublicJsonOnlyOnTheSecureRpHostAndUsesTheSameAllowlist(): void
    {
        [$primary, $related] = $this->associatedOrigins();
        $controller = new \App\Http\Controllers\V1\Passport\PasskeyController($this->service);
        $response = $controller->relatedOrigins(Request::create('https://' . $this->rp . '/.well-known/webauthn', 'GET'));
        $this->assertSame(['origins' => [$related]], $response->getData(true));
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
        $this->assertFalse($response->headers->has('Set-Cookie'));
        $this->assertStringContainsString('max-age=300', $response->headers->get('Cache-Control'));
        foreach (['http://' . $this->rp, $related] as $base) {
            $this->reject(function () use ($controller, $base) { $controller->relatedOrigins(Request::create($base . '/.well-known/webauthn')); }, 404);
        }
        config(['v2board.passkey_enabled' => 0]);
        $this->reject(function () use ($controller) { $controller->relatedOrigins(Request::create('https://' . $this->rp . '/.well-known/webauthn')); }, 404);
    }

    public function testAdministratorDeploymentDocumentUsesTheCurrentSavedConfiguration(): void
    {
        $this->associatedOrigins();
        $controller = new \App\Http\Controllers\V1\Admin\ConfigController();
        $data = $controller->passkeyDeployment()->getData(true)['data'];
        $this->assertSame($this->rp, $data['rp_id']);
        $this->assertSame('https://' . $this->rp . '/.well-known/webauthn', $data['well_known_url']);
        $this->assertSame(PasskeySettings::relatedOriginsDocument(), $data['document']);
        $this->assertTrue($data['configured']);
        $this->assertTrue($data['enabled']);
    }

    public function testWellKnownRegistersWithoutAuthenticationOrSessionMiddlewareOnLaravel8(): void
    {
        $router = new \Illuminate\Routing\Router(new \Illuminate\Events\Dispatcher(app()), app());
        app()->instance('router', $router);
        \Illuminate\Support\Facades\Facade::clearResolvedInstance('router');
        $provider = new class(app()) extends \App\Providers\RouteServiceProvider {
            protected function mapApiRoutes() {}
            protected function mapWebRoutes() {}
        };
        $provider->map();
        $route = $router->getRoutes()->match(Request::create('https://' . $this->rp . '/.well-known/webauthn'));
        $this->assertSame(\App\Http\Controllers\V1\Passport\PasskeyController::class . '@relatedOrigins', $route->getActionName());
        $this->assertSame([], $route->gatherMiddleware());
    }

    public function testWrongSignatureOriginRpHandlePresenceAndVerificationAreRejected(): void
    {
        $this->register();
        foreach ([['origin' => 'https://attacker.example'], ['rp' => 'attacker.example'], ['handle' => PasskeyService::encode(random_bytes(32))]] as $extra) {
            $payload = $this->loginPayload(1, 5, $extra);
            $this->reject(function () use ($payload) { $this->service->loginVerify($this->request($payload)); });
        }
        foreach ([0, 1, 4] as $flags) {
            $payload = $this->loginPayload(1, $flags);
            $this->reject(function () use ($payload) { $this->service->loginVerify($this->request($payload)); });
        }
        $payload = $this->loginPayload(); $payload['credential']['response']['signature'] = PasskeyService::encode(str_repeat('x', 72));
        $this->reject(function () use ($payload) { $this->service->loginVerify($this->request($payload)); });
    }

    public function testUserCannotEnterAdminAndBannedOrRevokedKeyCannotLogin(): void
    {
        $this->register();
        $payload = $this->loginPayload(1, 5, ['audience' => 'admin']);
        $this->reject(function () use ($payload) { $this->service->loginVerify($this->request($payload)); });
        $this->account->banned = 1; $this->account->save();
        $payload = $this->loginPayload();
        $this->reject(function () use ($payload) { $this->service->loginVerify($this->request($payload)); });
        $this->account->banned = 0; $this->account->save();
        DB::table('v2_passkey_credential')->where('id', $this->credentialId)->update(['revoked_at' => time()]);
        $payload = $this->loginPayload();
        $this->reject(function () use ($payload) { $this->service->loginVerify($this->request($payload)); });
    }

    public function testCounterEnforcedForHardwareButSyncedKeysAllowNonMonotonicCounters(): void
    {
        $this->register();
        $this->service->loginVerify($this->request($this->loginPayload(4)));
        $payload = $this->loginPayload(3);
        $this->reject(function () use ($payload) { $this->service->loginVerify($this->request($payload)); });
        DB::table('v2_passkey_credential')->where('id', $this->credentialId)->update(['backup_eligible' => true]);
        $this->service->loginVerify($this->request($this->loginPayload(1, 13)));
        $this->assertSame(4, (int)DB::table('v2_passkey_credential')->where('id', $this->credentialId)->value('sign_count'));
    }

    public function testDeleteRevokesOnlySessionsUsingThatCredentialAndCannotUseRenameGrant(): void
    {
        $this->register();
        $login = $this->service->loginVerify($this->request($this->loginPayload()));
        $wrong = $this->grant('rename', $this->credentialId);
        $this->reject(function () use ($wrong) { $this->service->delete($this->request(['action' => 'delete', 'target_id' => $this->credentialId, 'reauth_token' => $wrong])); });
        $result = $this->service->delete($this->request(['action' => 'delete', 'target_id' => $this->credentialId, 'reauth_token' => $this->grant('delete', $this->credentialId)]));
        $this->assertFalse($result['sign_out']);
        $this->assertFalse(AuthService::decryptAuthData($login['auth_data']));
        $this->assertNotFalse(AuthService::decryptAuthData($this->session));
    }

    public function testDeleteRevokesChainedQuickLoginsButKeepsPasswordAndOtherKeySessions(): void
    {
        $this->register();
        $target = $this->credentialId;
        $direct = $this->service->loginVerify($this->request($this->loginPayload()));
        $child = AuthService::redeemQuickLoginCode($this->request(['login_method' => 'password', 'passkey_id' => 999]), AuthService::issueQuickLoginCode($direct['auth_data']));
        $grandchild = AuthService::redeemQuickLoginCode($this->request(), AuthService::issueQuickLoginCode($child['auth_data']));
        $unusedCode = AuthService::issueQuickLoginCode($grandchild['auth_data']);
        $passwordChild = AuthService::redeemQuickLoginCode($this->request(), AuthService::issueQuickLoginCode($this->session));

        $this->rawId = random_bytes(32);
        $this->register();
        $otherKey = $this->service->loginVerify($this->request($this->loginPayload()));
        foreach ((new AuthService($this->account))->getSessions() as $meta) {
            if (in_array($meta['auth_data'], [$child['auth_data'], $grandchild['auth_data']], true)) {
                $this->assertSame('passkey', $meta['login_method'] ?? null);
                $this->assertSame($target, $meta['passkey_id'] ?? null);
            }
        }
        $result = $this->service->delete($this->request(['action' => 'delete', 'target_id' => $target, 'reauth_token' => $this->grant('delete', $target)]));
        $this->assertFalse($result['sign_out']);
        foreach ([$direct, $child, $grandchild] as $revoked) $this->assertFalse(AuthService::decryptAuthData($revoked['auth_data']));
        foreach ([$this->session, $passwordChild['auth_data'], $otherKey['auth_data']] as $retained) $this->assertIsArray(AuthService::decryptAuthData($retained));
        $this->reject(function () use ($unusedCode) { AuthService::redeemQuickLoginCode($this->request(), $unusedCode); });
        $this->assertSame('subscription-token', $this->account->fresh()->token);
        $this->assertSame('unchanged-uuid', $this->account->fresh()->uuid);
    }

    public function testDeleteAlsoRevokesUnattributedLegacySessionsAndTheirQuickLoginDescendants(): void
    {
        $this->register();
        $legacy = (new AuthService($this->account))->generateAuthData($this->request());
        $cacheKey = \App\Utils\CacheKey::get('USER_SESSIONS', $this->account->id);
        $sessions = \Illuminate\Support\Facades\Cache::get($cacheKey);
        foreach ($sessions as &$meta) if ($meta['auth_data'] === $legacy['auth_data']) unset($meta['login_method'], $meta['passkey_id']);
        unset($meta);
        \Illuminate\Support\Facades\Cache::put($cacheKey, $sessions);
        $child = AuthService::redeemQuickLoginCode($this->request(), AuthService::issueQuickLoginCode($legacy['auth_data']));
        $grandchild = AuthService::redeemQuickLoginCode($this->request(), AuthService::issueQuickLoginCode($child['auth_data']));
        $this->service->delete($this->request(['action' => 'delete', 'target_id' => $this->credentialId, 'reauth_token' => $this->grant('delete', $this->credentialId)]));
        foreach ([$legacy, $child, $grandchild] as $revoked) $this->assertFalse(AuthService::decryptAuthData($revoked['auth_data']));
        $this->assertIsArray(AuthService::decryptAuthData($this->session));
    }

    public function testRecoveryRevokesKeysAndSessionsWithoutChangingSubscription(): void
    {
        $this->register(); PasskeyService::revokeOnRecovery($this->account);
        $this->assertFalse(AuthService::decryptAuthData($this->session));
        $this->assertNotNull(DB::table('v2_passkey_credential')->where('id', $this->credentialId)->value('revoked_at'));
        $this->assertSame('subscription-token', $this->account->fresh()->token);
        $this->assertSame('unchanged-uuid', $this->account->fresh()->uuid);
    }

    public function testSettingsAllowRelatedOriginsButRejectIpAndHttpAndSchemaIsIdempotent(): void
    {
        $this->assertTrue(PasskeySettings::validate(['passkey_rp_id' => $this->rp, 'passkey_origins' => [$this->origin]]));
        foreach (['http://example.com', 'https://192.0.2.10', 'https://example.com/path', 'https://user@example.com', 'https://example.com?x=1'] as $origin) $this->assertNull(PasskeySettings::origin($origin));
        $this->assertTrue(PasskeySettings::validate(['passkey_rp_id' => $this->rp, 'passkey_origins' => ['https://associated.example']]));
        $this->assertFalse(PasskeySettings::validate(['passkey_rp_id' => $this->rp, 'passkey_origins' => ['http://localhost']]));
        (new PasskeySchema())->ensure(); $this->assertTrue(PasskeySchema::installed());
    }

    public function testOtherUsersCannotManageKeysAndPasswordGrantIsSingleUse(): void
    {
        $this->register();
        $other = $this->user(['password' => password_hash('test-password', PASSWORD_DEFAULT)])->fresh();
        $otherSession = (new AuthService($other))->generateAuthData(Request::create('/', 'POST'))['auth_data'];
        $this->reject(function () use ($otherSession) {
            $this->service->passwordReauth($this->request(['action' => 'delete', 'target_id' => $this->credentialId, 'password' => 'test-password'], $otherSession));
        });
        $grant = $this->grant('rename', $this->credentialId);
        $payload = ['action' => 'rename', 'target_id' => $this->credentialId, 'name' => 'Renamed', 'reauth_token' => $grant];
        $this->service->rename($this->request($payload));
        $this->reject(function () use ($payload) { $this->service->rename($this->request($payload)); });
    }

    public function testDisabledFeatureAndInvalidPasswordsFailClosed(): void
    {
        $this->reject(function () { $this->service->passwordReauth($this->request(['action' => 'register', 'password' => 'wrong'])); });
        config(['v2board.passkey_enabled' => 0]);
        $this->assertFalse($this->service->capabilities()['enabled']);
        $this->reject(function () { $this->service->listing($this->request()); });
        $this->assertNotFalse(AuthService::decryptAuthData($this->session));
    }

    public function testChallengeExpiryAndAttemptRateLimit(): void
    {
        $challenges = new PasskeyChallenge();
        $transaction = $challenges->issue(['purpose' => 'fixture']);
        $cacheKey = 'PASSKEY_' . hash('sha256', $transaction);
        \Illuminate\Support\Facades\Cache::put($cacheKey, ['expires_at' => time() - 1], 300);
        $this->reject(function () use ($challenges, $transaction) { $challenges->consume($transaction); });
        $challenges->limit('fixture', 1, 60);
        $this->reject(function () use ($challenges) { $challenges->limit('fixture', 1, 60); }, 429);
    }
}
