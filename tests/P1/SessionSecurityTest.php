<?php

namespace Tests\P1;

use App\Http\Controllers\V1\Admin\UserController as AdminUsers;
use App\Http\Controllers\V1\Staff\UserController as StaffUsers;
use App\Http\Middleware\Admin;
use App\Http\Middleware\Staff;
use App\Http\Middleware\User as UserGate;
use App\Http\Requests\Admin\UserUpdate;
use App\Services\AuthService;
use App\Utils\CacheKey;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SessionAdminUpdate extends UserUpdate
{
    public $values;
    public function validated($key = null, $default = null) { return $this->values; }
}

class SessionStaffUpdate extends \App\Http\Requests\Staff\UserUpdate
{
    public $values;
    public function validated($key = null, $default = null) { return $this->values; }
}

class SessionSecurityTest extends DatabaseTestCase
{
    private function login($user)
    {
        return (new AuthService($user))->generateAuthData(Request::create('/', 'POST'))['auth_data'];
    }

    private function seedLegacyIdentity($user, $token)
    {
        Cache::put($token, $user->only(['id', 'email', 'is_admin', 'is_staff']), 3600);
    }

    private function gate($middleware, $token)
    {
        $request = Request::create('/', 'GET');
        $request->headers->set('authorization', $token);
        return (new $middleware())->handle($request, function () { return new Response('allowed'); });
    }

    private function denied($middleware, $token)
    {
        try {
            $this->gate($middleware, $token);
            $this->fail('Revoked or unauthorized token must be rejected');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    private function staffUpdate($user, array $changes)
    {
        $request = new SessionStaffUpdate();
        $request->merge(['id' => $user->id]);
        $request->values = array_merge(['email' => $user->email, 'banned' => 0], $changes);
        return (new StaffUsers())->update($request);
    }

    public function testActiveSessionKeepsExistingIdentityAndRoleBoundaries()
    {
        $user = $this->user()->fresh(); $token = $this->login($user);
        $this->assertSame($user->only(['id', 'email', 'is_admin', 'is_staff']), AuthService::decryptAuthData($token));
        $this->assertSame('allowed', $this->gate(UserGate::class, $token)->getContent());
        $this->denied(Admin::class, $token); $this->denied(Staff::class, $token);
        foreach ([['is_admin' => 1, 'gate' => Admin::class], ['is_staff' => 1, 'gate' => Staff::class]] as $role) {
            $middleware = $role['gate']; unset($role['gate']);
            $account = $this->user($role);
            $this->assertSame('allowed', $this->gate($middleware, $this->login($account))->getContent());
        }
    }

    public function testRemovingOneSessionDeletesLegacyCacheAndKeepsOtherSession()
    {
        $user = $this->user(); $first = $this->login($user); $second = $this->login($user);
        $this->seedLegacyIdentity($user, $first);
        $auth = new AuthService($user); $sessions = $auth->getSessions();
        $this->assertTrue($auth->removeSession(array_key_first($sessions)));
        $this->assertFalse(Cache::has($first));
        $this->denied(UserGate::class, $first);
        $this->assertSame('allowed', $this->gate(UserGate::class, $second)->getContent());
    }

    public function testOrphanedIdentityFromBeforeUpgradeCannotAuthorize()
    {
        $admin = $this->user(['is_admin' => 1]); $token = $this->login($admin);
        $this->seedLegacyIdentity($admin, $token);
        Cache::forget(CacheKey::get('USER_SESSIONS', $admin->id));
        $this->assertTrue(Cache::has($token));
        $this->denied(Admin::class, $token); $this->denied(UserGate::class, $token);
    }

    public function testRevokedAdministratorStaysRejectedAfterBanAndDemotion()
    {
        $admin = $this->user(['is_admin' => 1]); $token = $this->login($admin);
        $this->seedLegacyIdentity($admin, $token);
        Cache::forget(CacheKey::get('USER_SESSIONS', $admin->id));
        $request = new SessionAdminUpdate(); $request->merge(['id' => $admin->id]);
        $request->values = ['email' => $admin->email, 'is_admin' => 0, 'is_staff' => 0, 'banned' => 1];
        (new AdminUsers())->update($request);
        $this->assertSame(0, (int)$admin->fresh()->is_admin);
        $this->assertSame(1, (int)$admin->fresh()->banned);
        $this->denied(Admin::class, $token); $this->denied(UserGate::class, $token);
    }

    public function roleChanges()
    {
        return [[['is_admin' => 1], 'is_admin', Admin::class], [['is_staff' => 1], 'is_staff', Staff::class]];
    }

    /** @dataProvider roleChanges */
    public function testCachedPrivilegeCannotSurviveCurrentRoleChange($values, $field, $middleware)
    {
        $user = $this->user($values); $token = $this->login($user);
        $this->seedLegacyIdentity($user, $token);
        $user->update([$field => 0]);
        $this->denied($middleware, $token);
        $this->assertSame('allowed', $this->gate(UserGate::class, $token)->getContent());
    }

    public function testBannedAndDeletedAccountsCannotUseLegacyIdentityCache()
    {
        $user = $this->user(); $token = $this->login($user); $this->seedLegacyIdentity($user, $token);
        $user->update(['banned' => 1]); $this->denied(UserGate::class, $token);
        Cache::forget($token); $this->denied(UserGate::class, $token);
        $user->delete(); $this->seedLegacyIdentity($user, $token);
        $this->denied(UserGate::class, $token);
    }

    public function testRemoveAllSessionsRejectsAllOldTokensAndAllowsFreshLogin()
    {
        $user = $this->user(); $first = $this->login($user); $second = $this->login($user);
        $this->seedLegacyIdentity($user, $first); $this->seedLegacyIdentity($user, $second);
        $this->assertTrue((new AuthService($user))->removeAllSession());
        $this->denied(UserGate::class, $first); $this->denied(UserGate::class, $second);
        $this->assertFalse(Cache::has($first)); $this->assertFalse(Cache::has($second));
        $this->assertSame('allowed', $this->gate(UserGate::class, $this->login($user))->getContent());
    }

    public function testStaffPasswordResetRevokesAllOldSessions()
    {
        $user = $this->user(['password' => password_hash('OldPassword!2026', PASSWORD_DEFAULT)]);
        $first = $this->login($user); $second = $this->login($user); $this->seedLegacyIdentity($user, $first);
        $this->staffUpdate($user, ['password' => 'NewPassword!2026']);
        $this->assertTrue(password_verify('NewPassword!2026', $user->fresh()->password));
        $this->assertSame([], (new AuthService($user))->getSessions());
        $this->denied(UserGate::class, $first); $this->denied(UserGate::class, $second);
        Cache::forget($first); $this->denied(UserGate::class, $first);
        $this->assertSame('allowed', $this->gate(UserGate::class, $this->login($user->fresh()))->getContent());
    }

    public function testStaffBanRevokesSessionsEvenAfterAccountIsUnbanned()
    {
        $user = $this->user(); $first = $this->login($user); $second = $this->login($user);
        $this->seedLegacyIdentity($user, $first); $this->staffUpdate($user, ['banned' => 1]);
        $this->assertSame(1, (int)$user->fresh()->banned);
        $this->assertSame([], (new AuthService($user))->getSessions());
        $this->denied(UserGate::class, $first); $this->denied(UserGate::class, $second);
        $this->staffUpdate($user, ['banned' => 0]); $this->denied(UserGate::class, $first);
        $this->assertSame('allowed', $this->gate(UserGate::class, $this->login($user->fresh()))->getContent());
    }

    public function testOrdinaryStaffEditDoesNotLogOutUser()
    {
        $user = $this->user(); $token = $this->login($user);
        $this->staffUpdate($user, ['balance' => 123, 'email' => 'updated@example.test']);
        $this->assertSame('allowed', $this->gate(UserGate::class, $token)->getContent());
        $this->assertSame('updated@example.test', AuthService::decryptAuthData($token)['email']);
        $this->assertSame(123, (int)$user->fresh()->balance);
    }

    public function testFailedStaffUpdateDoesNotChangePasswordOrRevokeSession()
    {
        $user = $this->user(['password' => password_hash('Original!2026', PASSWORD_DEFAULT)]); $token = $this->login($user);
        try {
            $this->staffUpdate($user, ['password' => 'Uncommitted!2026', 'plan_id' => 99999]);
            $this->fail('Missing plan must fail');
        } catch (HttpException $e) {
            $this->assertTrue(password_verify('Original!2026', $user->fresh()->password));
            $this->assertSame('allowed', $this->gate(UserGate::class, $token)->getContent());
        }
    }

    public function arrayCredentialInputs()
    {
        return [
            [Staff::class, 'GET', ['is_staff' => '1']],
            [Admin::class, 'GET', ['is_admin' => '1']],
            [UserGate::class, 'GET', ['id' => '2']],
            [Staff::class, 'FORM', ['is_staff' => '1']],
            [Admin::class, 'JSON', ['is_admin' => 1]],
            [UserGate::class, 'JSON', ['id' => 2]],
        ];
    }

    /** @dataProvider arrayCredentialInputs */
    public function testArrayCredentialsCannotReachProtectedHandlers($middleware, $transport, $identity)
    {
        $data = ['auth_data' => $identity, 'id' => 2];
        if ($transport === 'JSON') {
            $request = Request::create('/fixture', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($data));
        } elseif ($transport === 'FORM') {
            $request = Request::create('/fixture', 'POST', $data);
        } else {
            $request = Request::create('/fixture?' . http_build_query($data), 'GET');
        }
        $reached = false;
        try {
            (new $middleware())->handle($request, function () use (&$reached) {
                $reached = true;
                return new Response('must-not-reach');
            });
            $this->fail('Array credentials must be rejected before reaching the controller');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertFalse($reached);
    }

    public function malformedTokens()
    {
        return [[null], [''], ['not-a-jwt'], [[]], [123], ['eyJhbGciOiJub25lIn0.e30.']];
    }

    /** @dataProvider malformedTokens */
    public function testMalformedTokenIsRejected($token)
    {
        $this->assertFalse(AuthService::decryptAuthData($token));
    }
}
