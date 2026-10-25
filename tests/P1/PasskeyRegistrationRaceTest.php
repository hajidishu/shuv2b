<?php

namespace Tests\P1;

use App\Services\AuthService;
use App\Services\PasskeyChallenge;
use App\Services\PasskeySchema;
use App\Services\PasskeyService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RegistrationRevokeChallenge extends PasskeyChallenge
{
    public $onConsume;
    public function consume($id): array
    {
        $context = parent::consume($id);
        if ($this->onConsume) { $callback = $this->onConsume; $this->onConsume = null; $callback($context); }
        return $context;
    }
}

class PasskeyRegistrationRaceTest extends DatabaseTestCase
{
    public function testRecoveryDuringRegistrationRejectsTheOldRequestAndItsCredential(): void
    {
        $rp = 'test.example.test'; $origin = 'https://test.example.test';
        config(['v2board.passkey_enabled' => 1, 'v2board.passkey_rp_id' => $rp, 'v2board.passkey_origins' => [$origin]]);
        DB::getSchemaBuilder()->table('v2_user', function (Blueprint $t) { $t->integer('last_login_at')->nullable(); });
        (new PasskeySchema())->ensure();
        $account = $this->user(['password' => password_hash('test-password', PASSWORD_DEFAULT), 'token' => 'test-subscription-token'])->fresh();
        $session = (new AuthService($account))->generateAuthData(Request::create('/', 'POST'))['auth_data'];
        $challenge = new RegistrationRevokeChallenge(); $service = new PasskeyService($challenge);
        $req = function (array $data, $authorization = null) use ($origin, $session) {
            $r = Request::create('/', 'POST', $data); $r->headers->set('Origin', $origin);
            $r->headers->set('Authorization', $authorization ?? $session); return $r;
        };
        $grant = $service->passwordReauth($req(['action' => 'register', 'password' => 'test-password']))['reauth_token'];
        $registerNonce = PasskeyService::encode(random_bytes(32));
        $options = $service->registerOptions($req(['reauth_token' => $grant, 'client_nonce' => $registerNonce]));
        $public = json_decode(json_encode($options['publicKey']), true);

        $private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $ec = openssl_pkey_get_details($private)['ec']; $rawId = random_bytes(32);
        foreach (['x', 'y'] as $coordinate) $ec[$coordinate] = str_pad($ec[$coordinate], 32, "\0", STR_PAD_LEFT);
        $cose = "\xa5\x01\x02\x03\x26\x20\x01\x21\x58\x20" . $ec['x'] . "\x22\x58\x20" . $ec['y'];
        $authData = hash('sha256', $rp, true) . chr(0x45) . pack('N', 0) . str_repeat("\0", 16) . pack('n', strlen($rawId)) . $rawId . $cose;
        $bytes = function (string $s) { return "\x59" . pack('n', strlen($s)) . $s; };
        $attestation = "\xa3\x63fmt\x64none\x67attStmt\xa0\x68authData" . $bytes($authData);
        $id = PasskeyService::encode($rawId);
        $createClientData = json_encode(['type' => 'webauthn.create', 'challenge' => $public['challenge'], 'origin' => $origin, 'crossOrigin' => false]);

        // Simulate recovery immediately after registerVerify consumes its context,
        // after the old request passed owner()/context() and before its DB lock.
        $challenge->onConsume = function ($context) use ($account) {
            if (($context['purpose'] ?? '') === 'register') PasskeyService::revokeOnRecovery($account);
        };
        try {
        $service->registerVerify($req(['name' => 'Race key', 'client_nonce' => $registerNonce, 'transaction_id' => $options['transaction_id'], 'credential' => [
            'id' => $id, 'rawId' => $id, 'type' => 'public-key', 'transports' => ['internal'],
            'response' => ['clientDataJSON' => PasskeyService::encode($createClientData), 'attestationObject' => PasskeyService::encode($attestation)],
        ]]));

        $this->fail('Revoked registration must be rejected');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertFalse(AuthService::decryptAuthData($session));
        $this->assertSame(0, DB::table('v2_passkey_credential')->whereNull('revoked_at')->count());

        $loginNonce = PasskeyService::encode(random_bytes(32));
        $loginOptions = $service->loginOptions($req(['client_nonce' => $loginNonce]));
        $loginPublic = json_decode(json_encode($loginOptions['publicKey']), true);
        $getClientData = json_encode(['type' => 'webauthn.get', 'challenge' => $loginPublic['challenge'], 'origin' => $origin, 'crossOrigin' => false]);
        $mapping = DB::table('v2_passkey_user')->where('user_id', $account->id)->where('rp_id', $rp)->first();
        $assertionData = hash('sha256', $rp, true) . chr(0x05) . pack('N', 1);
        openssl_sign($assertionData . hash('sha256', $getClientData, true), $signature, $private, OPENSSL_ALGO_SHA256);
        try {
        $loginResult = $service->loginVerify($req(['client_nonce' => $loginNonce, 'transaction_id' => $loginOptions['transaction_id'], 'credential' => [
            'id' => $id, 'rawId' => $id, 'type' => 'public-key',
            'response' => ['clientDataJSON' => PasskeyService::encode($getClientData), 'authenticatorData' => PasskeyService::encode($assertionData),
                'signature' => PasskeyService::encode($signature), 'userHandle' => $mapping->user_handle],
        ]]));
        $this->fail('A credential rejected after recovery must never authenticate');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(422, $error->getStatusCode());
        }
    }
    public function testRecoveryWhileConsumingARegistrationGrantDoesNotIssueOptions(): void
    {
        config(['v2board.passkey_enabled'=>1,'v2board.passkey_rp_id'=>'example.test','v2board.passkey_origins'=>['https://example.test']]);
        (new PasskeySchema())->ensure();
        $user=$this->user(['password'=>password_hash('fixture-password',PASSWORD_DEFAULT)])->fresh();
        $jwt=(new AuthService($user))->generateAuthData(Request::create('/','POST'))['auth_data'];
        $request=function(array $body)use($jwt){$r=Request::create('/','POST',$body);$r->headers->set('Origin','https://example.test');$r->headers->set('Authorization',$jwt);return $r;};
        $challenge=new RegistrationRevokeChallenge();$service=new PasskeyService($challenge);
        $grant=$service->passwordReauth($request(['action'=>'register','password'=>'fixture-password']))['reauth_token'];
        $challenge->onConsume=function()use($user){PasskeyService::revokeOnRecovery($user);};
        try {
            $service->registerOptions($request(['reauth_token'=>$grant,'client_nonce'=>PasskeyService::encode(random_bytes(32))]));
            $this->fail('A revoked session must not issue registration options');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {$this->assertSame(403,$error->getStatusCode());}
        $this->assertSame(0, DB::table('v2_passkey_user')->count());
    }

}
