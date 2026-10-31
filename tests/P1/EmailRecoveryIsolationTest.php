<?php

namespace Tests\P1;

use App\Http\Controllers\V1\Passport\AuthController;
use App\Http\Controllers\V1\Passport\CommController;
use App\Http\Requests\Passport\AuthForget;
use App\Http\Requests\Passport\CommSendEmailVerify;
use App\Jobs\SendEmailJob;
use App\Models\User;
use App\Services\AuthService;
use App\Services\EmailAddress;
use App\Services\EmailIdentity;
use App\Services\EmailIdentitySchema;
use App\Services\EmailVerificationService;
use App\Services\RegistrationService;
use App\Utils\CacheKey;
use Illuminate\Cache\RateLimiter as Limiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EmailRecoveryIsolationTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new EmailIdentitySchema())->ensure();
        RateLimiter::swap(new Limiter(app('cache')));
    }

    private function account(array $attributes = []): User
    {
        return $this->user($attributes + ['email' => 'victim@example.test', 'password' => password_hash('old-password', PASSWORD_DEFAULT),
            'uuid' => 'unchanged-uuid', 'token' => 'unchanged-token'])->fresh();
    }

    private function rejected(callable $operation, array $statuses = [409, 422, 429, 500, 503]): void
    {
        try { $operation(); $this->fail('Unsafe operation was accepted'); }
        catch (HttpException $e) { $this->assertContains($e->getStatusCode(), $statuses); }
    }

    private function send(string $email, array $extra = []): array
    {
        $request = new CommSendEmailVerify();
        $request->merge(['email' => $email] + $extra);
        $request->server->set('REMOTE_ADDR', '192.0.2.' . random_int(1, 254));
        (new CommController())->sendEmailVerify($request);
        $job = Environment::$queue->pushed(SendEmailJob::class)->last();
        $property = new \ReflectionProperty($job, 'params'); $property->setAccessible(true);
        return $property->getValue($job);
    }

    private function reset(string $email, string $code): void
    {
        $request = new AuthForget();
        $request->merge(['email' => $email, 'email_code' => $code, 'password' => 'new-password']);
        (new AuthController())->forget($request);
    }

    public function testRealAccentInsensitiveDatabaseAndActualMailerDisagreeButRecoveryRejectsVariant(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') $this->markTestSkipped('MariaDB/MySQL collation regression');
        DB::statement('ALTER TABLE v2_user MODIFY email VARCHAR(64) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL');
        $user = $this->account(); $variant = 'victim@éxample.test';
        $this->assertFalse(app('validator')->make(['email' => $variant], ['email' => 'email:strict'])->fails());
        $this->assertSame((int)$user->id, (int)User::where('email', $variant)->firstOrFail()->id);
        $this->assertSame('victim@xn--xample-9ua.test', (new \Swift_AddressEncoder_IdnAddressEncoder())->encodeString($variant));
        $this->assertNull(EmailIdentity::find($variant));
        $this->rejected(fn() => $this->send($variant, ['isforget' => 1]));
        Environment::$queue->assertNothingPushed();
        $mail = $this->send($variant); // Missing isforget must remain registration-only.
        $this->assertSame('victim@xn--xample-9ua.test', $mail['email']);
        $this->rejected(fn() => $this->reset($variant, $mail['template_value']['code']));
        $this->assertTrue(password_verify('old-password', $user->fresh()->password));
        $this->assertSame('victim@example.test', $user->fresh()->email);
    }

    public function testOldSharedCodeAndOrdinaryRegistrationCodeNeverResetExistingAccount(): void
    {
        $user = $this->account();
        Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', $user->email), '123456', 300);
        $this->rejected(fn() => $this->reset($user->email, '123456'));
        foreach ([[], ['isforget' => 0]] as $mode) $this->rejected(fn() => $this->send($user->email, $mode));
        $mail = $this->send('new-owner@example.test', ['isforget' => 0]);
        $this->rejected(fn() => $this->reset('new-owner@example.test', $mail['template_value']['code']));
        $this->assertTrue(password_verify('old-password', $user->fresh()->password));
    }

    /** @dataProvider roles */
    public function testLegitimateRecoveryUsesStoredRecipientAndRevokesExistingSessions(int $admin): void
    {
        $user = $this->account(['email' => 'Stored.Owner@Example.Test', 'is_admin' => $admin]);
        $jwt = (new AuthService($user))->generateAuthData(Request::create('/'))['auth_data'];
        $quick = AuthService::issueQuickLoginCode($jwt);
        $mail = $this->send('STORED.OWNER@EXAMPLE.TEST', ['isforget' => '1']);
        $this->assertSame('Stored.Owner@example.test', $mail['email']);
        $this->reset('stored.owner@example.test', $mail['template_value']['code']);
        $fresh = $user->fresh();
        $this->assertTrue(password_verify('new-password', $fresh->password));
        $this->assertSame($admin, (int)$fresh->is_admin);
        $this->assertSame('unchanged-token', $fresh->token);
        $this->assertSame('unchanged-uuid', $fresh->uuid);
        $this->assertFalse(AuthService::decryptAuthData($jwt));
        $this->rejected(fn() => AuthService::redeemQuickLoginCode(Request::create('/'), $quick), [403, 422]);
        $login = new \App\Http\Requests\Passport\AuthLogin();
        $login->merge(['email' => strtoupper($user->email), 'password' => 'new-password']);
        $auth = (new AuthController())->login($login)->getData(true)['data'];
        $this->assertSame((int)$user->id, (int)AuthService::decryptAuthData($auth['auth_data'])['id']);
        $this->rejected(fn() => $this->reset($user->email, $mail['template_value']['code']));
    }

    public static function roles(): array { return [[0], [1]]; }

    public function testUnicodeAndPunycodeIdentifySameMailboxWithoutRewritingLegacyAddress(): void
    {
        DB::getSchemaBuilder()->drop(EmailIdentity::TABLE);
        $id = DB::table('v2_user')->insertGetId(['email' => 'Legacy@éxample.test', 'password' => password_hash('old-password', PASSWORD_DEFAULT)]);
        (new EmailIdentitySchema())->ensure();
        $this->assertSame($id, (int)EmailIdentity::find('LEGACY@xn--xample-9ua.test')->id);
        $mail = $this->send('legacy@xn--xample-9ua.test', ['isforget' => 1]);
        $this->assertSame('Legacy@xn--xample-9ua.test', $mail['email']);
        $this->reset('LEGACY@ÉXAMPLE.TEST', $mail['template_value']['code']);
        $this->assertSame('Legacy@éxample.test', User::findOrFail($id)->email);
    }

    public function testEmailChangeBackAndPasswordChangeInvalidateOutstandingRecovery(): void
    {
        $user = $this->account(); $code = $this->emailCode($user->email, true);
        $originalVersion = EmailIdentity::current($user)->email_version;
        $user->email = 'replacement@example.test'; $user->save();
        $user->email = 'victim@example.test'; $user->save();
        $this->assertNotSame($originalVersion, EmailIdentity::current($user)->email_version);
        $this->rejected(fn() => $this->reset($user->email, $code));
        Cache::forget(EmailVerificationService::key('recovery', $user->email) . '_SENT');
        $code = $this->emailCode($user->email, true);
        $user->password = password_hash('changed-password', PASSWORD_DEFAULT); $user->save();
        $this->rejected(fn() => $this->reset($user->email, $code));
        $this->assertTrue(password_verify('changed-password', $user->fresh()->password));
    }

    public function testDeletedAccountChallengeCannotTargetNewAccountWithSameMailbox(): void
    {
        $user = $this->account(); $code = $this->emailCode($user->email, true); $id = $user->id; $user->delete();
        $next = $this->account(); $this->assertNotSame($id, $next->id);
        $this->rejected(fn() => $this->reset($next->email, $code));
        $this->assertTrue(password_verify('old-password', $next->fresh()->password));
    }

    public function testRegistrationProofPreservesActualRecipientCaseAndIsSingleUse(): void
    {
        DB::getSchemaBuilder()->table('v2_user', function ($table) { $table->integer('last_login_at')->nullable(); });
        config(['v2board.email_verify' => 1]);
        $code = $this->emailCode('Actual.Owner@example.test');
        $request = Request::create('/', 'POST', ['email' => 'ACTUAL.OWNER@EXAMPLE.TEST', 'password' => 'site-password', 'email_code' => $code]);
        (new RegistrationService())->register($request);
        $this->assertSame('Actual.Owner@example.test', User::firstOrFail()->email);
        $this->rejected(fn() => (new EmailVerificationService())->registrationProof('actual.owner@example.test', $code));
    }

    public function testPurposesRemainIsolatedAndResendingDoesNotResetAttemptBudget(): void
    {
        $user = $this->account(); $code = $this->emailCode($user->email, true);
        $this->rejected(fn() => (new EmailVerificationService())->registrationProof($user->email, $code));
        for ($i = 0; $i < 3; $i++) $this->rejected(fn() => $this->reset($user->email, '000000'));
        Cache::forget(EmailVerificationService::key('recovery', $user->email) . '_SENT');
        $next = $this->emailCode($user->email, true);
        $this->rejected(fn() => $this->reset($user->email, $next));
        $this->assertTrue(password_verify('old-password', $user->fresh()->password));
    }

    public function testResendInvalidatesPriorNonceAndPurposeFlagRejectsArrays(): void
    {
        $user = $this->account(); $code = $this->emailCode($user->email, true);
        $key = EmailVerificationService::key('recovery', $user->email); $record = Cache::get($key);
        Cache::forget($key . '_SENT'); $next = $this->emailCode($user->email, true);
        $this->assertNotSame($record['nonce'], Cache::get($key)['nonce']);
        // A stale cached record cannot be restored under another purpose/hash.
        Cache::put(EmailVerificationService::key('recovery', 'other@example.test'), $record, 300);
        $this->rejected(fn() => $this->reset('other@example.test', $code));
        $this->rejected(fn() => $this->send('new@example.test', ['isforget' => [1]]), [422]);
        $this->reset($user->email, $next);
    }

    public function testMigrationCollisionFailsBeforeChangingAccountsOrExistingIndex(): void
    {
        $user = $this->account(); $before = DB::table(EmailIdentity::TABLE)->get()->toJson();
        DB::table('v2_user')->insert([
            ['email' => 'collision@éxample.test'], ['email' => 'collision@xn--xample-9ua.test']
        ]);
        $emails = DB::table('v2_user')->pluck('email', 'id')->all();
        foreach ([true, false] as $check) {
            try { (new EmailIdentitySchema())->ensure($check); $this->fail('Collision must abort migration'); }
            catch (\RuntimeException $e) { $this->assertStringContainsString('ID', $e->getMessage()); $this->assertStringNotContainsString('collision@', $e->getMessage()); }
        }
        $this->assertSame($emails, DB::table('v2_user')->pluck('email', 'id')->all());
        $this->assertSame($before, DB::table(EmailIdentity::TABLE)->get()->toJson());
        $this->assertSame((int)$user->id, (int)EmailIdentity::find($user->email)->id);
    }

    public function testRepeatMigrationKeepsVersionAndPasswordOnlyUpdatesDoNotRotateEmail(): void
    {
        $user = $this->account(); $version = EmailIdentity::current($user)->email_version;
        $check = (new EmailIdentitySchema())->ensure(true);
        $this->assertFalse($check['account_emails_rewritten']);
        (new EmailIdentitySchema())->ensure();
        $user->password = password_hash('other-password', PASSWORD_DEFAULT); $user->save();
        $this->assertSame($version, EmailIdentity::current($user)->email_version);
    }

    public function testExpiryOrReplacementWhileWaitingForUserLockPreventsReset(): void
    {
        $user = $this->account(); $code = $this->emailCode($user->email, true);
        $key = EmailVerificationService::key('recovery', $user->email); $changed = false;
        // Runs after the SELECT has returned, before the service rechecks the challenge.
        DB::listen(function ($query) use (&$changed, $key) {
            if ($changed || stripos($query->sql, 'select') !== 0 || strpos($query->sql, 'v2_user`') === false || stripos($query->sql, 'for update') === false) return;
            $changed = true; $record = Cache::get($key); $record['expires_at'] = time() - 1; Cache::put($key, $record, 300);
        });
        $this->rejected(fn() => $this->reset($user->email, $code));
        $this->assertTrue($changed);
        $this->assertTrue(password_verify('old-password', $user->fresh()->password));
    }

    public function testExpiryAndReissueBetweenRegistrationProofAndCommitRejectsStaleProof(): void
    {
        $service = new EmailVerificationService(); $email = 'new-owner@example.test';
        $code = $this->emailCode($email); $proof = $service->registrationProof($email, $code);
        $key = EmailVerificationService::key('register', $email);
        Cache::forget($key . '_SENT'); $this->emailCode($email);
        $this->rejected(fn() => $service->consumeRegistration($email, $proof));
        $current = Cache::get($key); $current['expires_at'] = time() - 1; Cache::put($key, $current, 300);
        $this->rejected(fn() => $service->consumeRegistration($email, $current));
    }

    public function testMigrationReportsInvalidLegacyRowsWithoutChangingTheirAddresses(): void
    {
        $id = DB::table('v2_user')->insertGetId(['email' => 'invalid-address']);
        $report = (new EmailIdentitySchema())->ensure();
        $this->assertSame(1, $report['invalid_legacy_count']);
        $this->assertContains($id, $report['invalid_legacy_user_ids']);
        $this->assertSame('invalid-address', DB::table('v2_user')->where('id', $id)->value('email'));
        $this->assertFalse(DB::table(EmailIdentity::TABLE)->where('user_id', $id)->exists());
    }

    public function testRecoveryFailsClosedUntilIndexInstalledAndPreMigrationLookupRejectsAccentFold(): void
    {
        $user = $this->account(); DB::getSchemaBuilder()->drop(EmailIdentity::TABLE);
        $this->assertNull(EmailIdentity::find('victim@éxample.test'));
        $this->assertSame((int)$user->id, (int)EmailIdentity::find('VICTIM@EXAMPLE.TEST')->id);
        $this->rejected(fn() => $this->emailCode($user->email, true), [503]);
    }

    public function testNewInternationalizedDomainAccountIsDistinctFromCollationEquivalentAccount(): void
    {
        $normal = $this->account(); $international = $this->account(['email' => 'victim@éxample.test']);
        $this->assertSame('victim@xn--xample-9ua.test', $international->email);
        $this->assertSame((int)$normal->id, (int)EmailIdentity::find('VICTIM@EXAMPLE.TEST')->id);
        $this->assertSame((int)$international->id, (int)EmailIdentity::find('victim@éxample.test')->id);
        $mail = $this->send('victim@éxample.test', ['isforget' => 1]);
        $this->reset('victim@éxample.test', $mail['template_value']['code']);
        $this->assertTrue(password_verify('old-password', $normal->fresh()->password));
        $this->assertTrue(password_verify('new-password', $international->fresh()->password));
    }

    public function testInternationalizedAndAsciiWhitelistDomainsUseSameNormalization(): void
    {
        config(['v2board.email_whitelist_enable' => 1, 'v2board.email_whitelist_suffix' => ['ÉXAMPLE.TEST']]);
        $mail = $this->send('new@éxample.test');
        $this->assertSame('new@xn--xample-9ua.test', $mail['email']);
        $this->assertTrue(\App\Utils\Helper::emailSuffixVerify('new@xn--xample-9ua.test', ' éxample.test , example.org '));
        $this->assertFalse(\App\Utils\Helper::emailSuffixVerify('new@example.test', ['éxample.test']));
    }

    /** @dataProvider controllers */
    public function testAdministrativeEmailChangesMaintainIndexAndInvalidateOldProof(bool $staff): void
    {
        $user = $this->account(); $code = $this->emailCode($user->email, true);
        $version = EmailIdentity::current($user)->email_version;
        $class = $staff ? \App\Http\Requests\Staff\UserUpdate::class : \App\Http\Requests\Admin\UserUpdate::class;
        $request = new $class();
        $request->merge(['id' => $user->id, 'email' => 'New.Owner@éxample.test', 'banned' => 0, 'is_admin' => 0, 'is_staff' => 0]);
        $request->setValidator(app('validator')->make($request->all(), $request->rules()));
        $controller = $staff ? new \App\Http\Controllers\V1\Staff\UserController() : new \App\Http\Controllers\V1\Admin\UserController();
        $controller->update($request);
        $this->assertSame('New.Owner@xn--xample-9ua.test', $user->fresh()->email);
        $this->assertNull(EmailIdentity::find('victim@example.test'));
        $this->assertSame((int)$user->id, (int)EmailIdentity::find('new.owner@éxample.test')->id);
        $this->assertNotSame($version, EmailIdentity::current($user->fresh())->email_version);
        $this->rejected(fn() => $this->reset('victim@example.test', $code));
        $this->assertTrue(password_verify('old-password', $user->fresh()->password));
    }

    public static function controllers(): array { return [[false], [true]]; }
}
