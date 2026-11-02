<?php
namespace Tests\P1;

use App\Http\Controllers\V1\Admin\ConfigController;
use App\Http\Controllers\V1\Admin\UserController;
use App\Http\Requests\Admin\ConfigSave;
use App\Http\Requests\Admin\UserUpdate;
use App\Jobs\SendTelegramJob;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TelegramSingleAdminTest extends DatabaseTestCase
{
    private function setting()
    {
        return (new ConfigController())->fetch(Request::create('/', 'GET', ['key' => 'telegram']))->getData(true)['data']['telegram']['telegram_bot_admin_id'];
    }

    public function testChoosingOneAdministratorReplacesAllPreviousManagementBindings(): void
    {
        $old = $this->user(['is_admin' => 1, 'telegram_admin_id' => 808001]);
        $staff = $this->user(['is_staff' => 1, 'telegram_admin_id' => 808002, 'telegram_id' => 909002]);
        $admin = $this->user(['is_admin' => 1, 'telegram_id' => 909001]);
        TelegramService::setManagementAccount($admin->id, '3456789012345678');
        $this->assertSame(1, User::whereNotNull('telegram_admin_id')->count());
        $this->assertNull($old->fresh()->telegram_admin_id);
        $this->assertNull($staff->fresh()->telegram_admin_id);
        $this->assertSame(909002, (int)$staff->fresh()->telegram_id);
        $this->assertSame(909001, (int)$admin->fresh()->telegram_id);
        $this->assertSame('3456789012345678', (string)$this->setting());
        $version = $admin->fresh()->telegram_admin_version;
        TelegramService::setManagementAccount($old->id, '3456789012345678');
        $this->assertSame($version, $admin->fresh()->telegram_admin_version, 'Saving an unchanged ID must preserve its owner and queued notifications');
        $this->assertSame($admin->id, TelegramService::managementUsers()->first()->id);
    }

    public function testAmbiguousLegacyAdministratorsRequireAnExplicitSingleSelection(): void
    {
        config(['v2board.telegram_bot_enable' => 1]);
        $a = $this->user(['is_admin' => 1, 'telegram_admin_id' => 808001]);
        $this->user(['is_admin' => 1, 'telegram_admin_id' => 808002]);
        $this->assertNull($this->setting());
        $this->assertSame(0, TelegramService::managementUsers()->count());
        (new TelegramService())->sendMessageWithAdmin('Fixture');
        Environment::$queue->assertNothingPushed();
        TelegramService::setManagementAccount($a->id, '808001');
        $this->assertSame(1, TelegramService::managementUsers()->count());
        (new TelegramService())->sendMessageWithAdmin('Fixture');
        Environment::$queue->assertPushed(SendTelegramJob::class, 1);
        TelegramService::setManagementAccount($a->id, null);
        $this->assertNull($this->setting());
        $this->assertSame(0, TelegramService::managementUsers()->count());
    }

    public function testStaffAndBannedAccountsCannotOwnTheSingleManagementSetting(): void
    {
        foreach ([['is_staff' => 1], ['is_admin' => 1, 'banned' => 1]] as $values) {
            $user = $this->user($values);
            try { TelegramService::setManagementAccount($user->id, '808001'); $this->fail('Unprivileged owner accepted'); }
            catch (HttpException $error) { $this->assertSame(403, $error->getStatusCode()); }
        }
        $this->assertSame(0, User::whereNotNull('telegram_admin_id')->count());
    }

    public function testUserUpdateCannotAssignOrReplaceTheGlobalTelegramAdministrator(): void
    {
        $admin = $this->user(['is_admin' => 1]);
        TelegramService::setManagementAccount($admin->id, '808001');
        $request = new class extends UserUpdate {
            public $values;
            public function validated($key = null, $default = null) { return $this->values; }
        };
        $request->merge(['id' => $admin->id]);
        $request->values = ['email' => $admin->email, 'is_admin' => 1, 'is_staff' => 0, 'banned' => 0, 'telegram_admin_id' => '808002'];
        (new UserController())->update($request);
        $this->assertSame('808001', (string)$this->setting());
        $this->assertArrayNotHasKey('telegram_admin_id', (new UserUpdate())->rules());
        $rules = (new ConfigSave())->rules()['telegram_bot_admin_id'];
        foreach ([['808001', '808002'], '808001,808002', '-1', '4503599627370496'] as $id) {
            $this->assertTrue(app('validator')->make(['id' => $id], ['id' => $rules])->fails());
        }
    }

    public function testReplacingTheAdministratorRevokesPreviouslyQueuedMessages(): void
    {
        config(['v2board.telegram_bot_enable' => 1]);
        $a = $this->user(['is_admin' => 1]); $b = $this->user(['is_admin' => 1]);
        TelegramService::setManagementAccount($a->id, '808001');
        (new TelegramService())->sendMessageWithAdmin('Old binding');
        $old = Environment::$queue->pushed(SendTelegramJob::class)->first();
        TelegramService::setManagementAccount($b->id, '808002');
        $sender = new class extends TelegramService {
            public $sent = [];
            public function sendMessage(int $id, string $text, string $mode = '') { $this->sent[] = [$id, $text]; }
        };
        app()->instance(TelegramService::class, $sender);
        $old->handle(); $this->assertSame([], $sender->sent);
        (new TelegramService())->sendMessageWithAdmin('Current binding', true);
        Environment::$queue->pushed(SendTelegramJob::class)->last()->handle();
        $this->assertSame([[808002, 'Current binding']], $sender->sent);
    }

    public function testConfigurationControllerSavesTheSingleAdministratorInTheExistingDatabase(): void
    {
        $admin = $this->user(['is_admin' => 1]);
        $worker = new \Symfony\Component\Process\Process([PHP_BINARY, '-d', 'opcache.enable_cli=1', '-d', 'error_reporting=24575',
            __DIR__ . '/TelegramAdminConfigWorker.php', (string)$admin->id, '3456789012345678']);
        $worker->run();
        $this->assertTrue($worker->isSuccessful(), $worker->getOutput() . $worker->getErrorOutput());
        $this->assertSame('3456789012345678', (string)$this->setting());
        $this->assertSame($admin->id, TelegramService::managementUsers()->first()->id);
    }
}
