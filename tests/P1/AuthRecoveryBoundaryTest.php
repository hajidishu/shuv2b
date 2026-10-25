<?php

namespace Tests\P1;

use App\Http\Controllers\V1\Passport\AuthController;
use App\Http\Requests\Passport\AuthForget;
use App\Http\Requests\Passport\AuthLogin;
use App\Models\User;
use App\Services\AuthService;
use App\Services\PasskeyService;
use App\Utils\CacheKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AuthRecoveryBoundaryTest extends DatabaseTestCase
{
    private function account()
    {
        return $this->user(['password' => password_hash('OldPassword!2026', PASSWORD_DEFAULT), 'token' => 'subscription-unchanged', 'uuid' => 'uuid-unchanged'])->fresh();
    }

    private function login($user)
    {
        return (new AuthService($user))->generateAuthData(Request::create('/', 'POST'))['auth_data'];
    }

    private function rejected(callable $operation)
    {
        try { $operation(); $this->fail('Expected revoked credentials to fail'); }
        catch (HttpException $error) { $this->assertContains($error->getStatusCode(), [403, 422]); }
    }

    private function recover($user)
    {
        Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', strtolower($user->email)), '123456', 60);
        $request = new AuthForget();
        $request->merge(['email' => $user->email, 'email_code' => '123456', 'password' => 'NewPassword!2026']);
        (new AuthController())->forget($request);
    }

    public function testQuickCodeNormalRedemptionWorksOnceAndKeepsSubscriptionIdentity()
    {
        $user = $this->account();
        $code = AuthService::issueQuickLoginCode($this->login($user));
        $result = AuthService::redeemQuickLoginCode(Request::create('/', 'GET'), $code);
        $this->assertSame((int)$user->id, (int)AuthService::decryptAuthData($result['auth_data'])['id']);
        $this->assertSame('subscription-unchanged', $result['token']);
        $this->assertSame('uuid-unchanged', $user->fresh()->uuid);
        $this->rejected(function () use ($code) { AuthService::redeemQuickLoginCode(Request::create('/'), $code); });
    }

    public function testPasswordRecoveryInvalidatesPreviouslyIssuedQuickCode()
    {
        $user = $this->account(); $jwt = $this->login($user);
        $code = AuthService::issueQuickLoginCode($jwt);
        $this->recover($user);
        $this->assertFalse(AuthService::decryptAuthData($jwt));
        $this->rejected(function () use ($code) { AuthService::redeemQuickLoginCode(Request::create('/'), $code); });
        $this->assertSame([], (new AuthService($user))->getSessions());
        $this->assertTrue(password_verify('NewPassword!2026', $user->fresh()->password));
    }

    public function testSingleSessionRevocationInvalidatesOnlyItsQuickCode()
    {
        $user = $this->account(); $first = $this->login($user); $second = $this->login($user);
        $old = AuthService::issueQuickLoginCode($first); $retained = AuthService::issueQuickLoginCode($second);
        $auth = new AuthService($user);
        foreach ($auth->getSessions() as $id => $meta) if ($meta['auth_data'] === $first) $auth->removeSession($id);
        $this->rejected(function () use ($old) { AuthService::redeemQuickLoginCode(Request::create('/'), $old); });
        $result = AuthService::redeemQuickLoginCode(Request::create('/'), $retained);
        $this->assertIsArray(AuthService::decryptAuthData($result['auth_data']));
    }

    public function testUnboundLegacyAndClaimedQuickCodesAreRejected()
    {
        $user = $this->account(); $code = str_repeat('a', 32);
        Cache::put(CacheKey::get('TEMP_TOKEN', $code), $user->id, 60);
        $this->rejected(function () use ($code) { AuthService::redeemQuickLoginCode(Request::create('/'), $code); });
        $code = AuthService::issueQuickLoginCode($this->login($user));
        Cache::add(CacheKey::get('TEMP_TOKEN', $code) . '_USED', true, 60);
        $this->rejected(function () use ($code) { AuthService::redeemQuickLoginCode(Request::create('/'), $code); });
    }

    public function testPasswordValidatedBeforeRecoveryCannotPublishAfterRecovery()
    {
        $user = $this->account();
        $this->assertTrue(password_verify('OldPassword!2026', $user->password));
        $validated = new AuthService($user);
        $this->recover($user);
        // Resume an already validated login after the recovery's cache revoke.
        $this->rejected(function () use ($validated) { $validated->generateAuthData(Request::create('/', 'POST')); });
        $this->assertSame([], (new AuthService($user))->getSessions());
        $request = new AuthLogin(); $request->merge(['email' => $user->email, 'password' => 'NewPassword!2026']);
        $response = (new AuthController())->login($request)->getData(true);
        $this->assertIsArray(AuthService::decryptAuthData($response['data']['auth_data']));
    }

    public function testSessionIssuedBeforeRecoveryIsRevokedInTheOppositeOrdering()
    {
        $user = $this->account(); $token = $this->login($user);
        $this->recover($user);
        $this->assertFalse(AuthService::decryptAuthData($token));
    }

    public function testCurrentAccountBanAndPrivilegeChangesBlockStaleIssuance()
    {
        foreach (['banned', 'is_admin', 'is_staff'] as $field) {
            $user = $this->account(); $validated = new AuthService($user);
            User::where('id', $user->id)->update([$field => 1]);
            $this->rejected(function () use ($validated) { $validated->generateAuthData(Request::create('/')); });
        }
    }

    public function testQuickCodeCannotBeIssuedByARevokedSourceSession()
    {
        $user = $this->account(); $token = $this->login($user);
        PasskeyService::revokeOnRecovery($user);
        $this->rejected(function () use ($token) { AuthService::issueQuickLoginCode($token); });
    }
}
