<?php

namespace Tests\P1;

use App\Http\Controllers\V1\Staff\UserController;
use App\Http\Requests\Staff\UserUpdate;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ValidatedUserUpdate extends UserUpdate
{
    public $validatedData;
    public function validated($key = null, $default = null) { return $this->validatedData; }
}

class StaffTest extends DatabaseTestCase
{
    public function protectedAccounts()
    {
        return [[1, 0], [0, 1], [1, 1]];
    }

    /** @dataProvider protectedAccounts */
    public function testStaffCannotChangeProtectedAccount($isAdmin, $isStaff)
    {
        $hash = password_hash('OriginalPassword123', PASSWORD_DEFAULT);
        $user = $this->user(['is_admin' => $isAdmin, 'is_staff' => $isStaff, 'password' => $hash]);
        $request = new ValidatedUserUpdate();
        $request->merge(['id' => $user->id]);
        $request->validatedData = ['email' => $user->email, 'banned' => 0, 'password' => 'NewPassword123'];
        try {
            (new UserController())->update($request);
            $this->fail('Protected account must be rejected');
        } catch (HttpException $e) {
            $this->assertSame($hash, $user->fresh()->password);
            $this->assertSame($isAdmin, (int)$user->fresh()->is_admin);
            $this->assertSame($isStaff, (int)$user->fresh()->is_staff);
        }
    }

    public function testStaffCanStillUpdateOrdinaryUser()
    {
        $user = $this->user();
        $request = new ValidatedUserUpdate();
        $request->merge(['id' => $user->id]);
        $request->validatedData = ['email' => $user->email, 'banned' => 0, 'password' => 'NewPassword123'];
        (new UserController())->update($request);
        $this->assertTrue(password_verify('NewPassword123', $user->fresh()->password));
        $this->assertSame(0, (int)$user->fresh()->is_admin);
    }
}
