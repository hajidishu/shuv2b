<?php
namespace Tests\P1;

use App\Http\Controllers\V1\Admin\UserController as AdminUsers;
use App\Http\Requests\Admin\UserUpdate;
use App\Jobs\SendTelegramJob;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Plugins\Telegram\Commands\Bind;
use App\Plugins\Telegram\Commands\ReplyTicket;
use App\Plugins\Telegram\Commands\UnBind;
use App\Services\AccountSecurityService;
use App\Services\DatabaseUpgrade;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TelegramFakeSender extends TelegramService
{
    public $sent = [];
    public function sendMessage(int $chatId, string $text, string $parseMode = '') { $this->sent[] = [$chatId, $text]; }
}
class TelegramAdminUpdate extends UserUpdate
{
    public $values;
    public function validated($key = null, $default = null) { return $this->values; }
}

class TelegramBindingTest extends DatabaseTestCase
{
    private function message($token = '', $chatId = 808001)
    {
        return (object)['is_private' => true, 'chat_id' => $chatId,
            'args' => ['https://panel.example.test/api/v1/client/subscribe?token=' . rawurlencode($token)], 'text' => 'Synthetic ticket reply'];
    }
    private function command($class)
    {
        $command = new $class(); $command->telegramService = new TelegramFakeSender(); return $command;
    }
    private function userToken($user, $mode, $cached = false)
    {
        config(['v2board.show_subscribe_method' => $mode, 'v2board.show_subscribe_expire' => 5]);
        if ($mode === 0) return $user->token;
        if ($mode === 1) { $token = 'otp-' . $user->id; Cache::put('otpn_' . $token, $user->token, 300); return $token; }
        $hash = hash_hmac('sha1', pack('N*', 0) . pack('N*', (int)floor(time() / 300)), $user->token);
        $token = rtrim(strtr(base64_encode($user->id . ':' . $hash), '+/', '-_'), '=');
        if ($cached) Cache::put('totp_' . $token, $user->token, 300);
        return $token;
    }
    public function rolesAndModes(): array
    {
        $cases = [];
        foreach ([[1,0],[0,1],[1,1]] as $role) foreach ([[0,false],[1,false],[2,false],[2,true]] as $mode) $cases[] = [...$role, ...$mode];
        return $cases;
    }
    /** @dataProvider rolesAndModes */
    public function testSubscriptionCredentialsCannotBindAnyManagementRole($admin, $staff, $mode, $cached)
    {
        $user = $this->user(['is_admin' => $admin, 'is_staff' => $staff, 'token' => bin2hex(random_bytes(16))]);
        $command = $this->command(Bind::class);
        try { $command->handle($this->message($this->userToken($user, $mode, $cached))); $this->fail('Management subscription credential was accepted'); }
        catch (HttpException $error) { $this->assertSame(403, $error->getStatusCode()); }
        $this->assertNull($user->fresh()->telegram_id); $this->assertNull($user->fresh()->telegram_admin_id);
        $this->assertSame([], $command->telegramService->sent);
    }
    public function testOrdinaryUserBindingStillWorksInEverySubscriptionMode()
    {
        foreach ([0,1,2] as $mode) {
            $user = $this->user(['token' => bin2hex(random_bytes(16))]);
            $command = $this->command(Bind::class); $command->handle($this->message($this->userToken($user, $mode), 808000 + $mode));
            $this->assertSame(808000 + $mode, (int)$user->fresh()->telegram_id);
            $this->assertNull($user->fresh()->telegram_admin_id); $this->assertCount(1, $command->telegramService->sent);
        }
    }
    public function bannedModes(): array
    {
        return [[0, false], [1, false], [2, false], [2, true]];
    }
    /** @dataProvider bannedModes */
    public function testBannedOrdinaryUserCannotBindInAnySubscriptionMode($mode, $cached)
    {
        $user = $this->user(['banned' => 1, 'token' => bin2hex(random_bytes(16))]);
        $command = $this->command(Bind::class);
        try { $command->handle($this->message($this->userToken($user, $mode, $cached))); $this->fail('Banned user binding was accepted'); }
        catch (HttpException $error) { $this->assertSame(403, $error->getStatusCode()); }
        $this->assertNull($user->fresh()->telegram_id);
        $this->assertNull($user->fresh()->telegram_admin_id);
        $this->assertSame([], $command->telegramService->sent);
    }
    public function testArraySubscriptionTokenCannotBindAnyAccount()
    {
        $user = $this->user(['token' => bin2hex(random_bytes(16))]);
        $message = $this->message();
        $message->args = ['https://panel.example.test/api/v1/client/subscribe?token%5B%5D=invalid'];
        $command = $this->command(Bind::class);
        try { $command->handle($message); $this->fail('Array subscription token was accepted'); }
        catch (HttpException $error) { $this->assertSame(500, $error->getStatusCode()); }
        $this->assertNull($user->fresh()->telegram_id);
        $this->assertNull($user->fresh()->telegram_admin_id);
        $this->assertSame([], $command->telegramService->sent);
    }
    public function testUnbindRejectsManualManagementAccountAndKeepsItBound()
    {
        $admin = $this->user(['is_admin' => 1, 'telegram_admin_id' => 808001]);
        $command = $this->command(UnBind::class);
        try { $command->handle($this->message('', 808001)); $this->fail('Management account was unbound through Telegram'); }
        catch (HttpException $error) { $this->assertSame(403, $error->getStatusCode()); }
        $this->assertSame(808001, (int)$admin->fresh()->telegram_admin_id);
        $this->assertSame([], $command->telegramService->sent);
    }
    private function tickets($owner)
    {
        $schema = DB::connection()->getSchemaBuilder();
        foreach (['v2_ticket_message','v2_ticket'] as $table) $schema->dropIfExists($table);
        $schema->create('v2_ticket', function ($t) {
            $t->increments('id'); $t->integer('user_id'); $t->string('subject'); $t->integer('status')->default(0); $t->integer('reply_status')->default(0);
            $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
        });
        $schema->create('v2_ticket_message', function ($t) {
            $t->increments('id'); $t->integer('user_id'); $t->integer('ticket_id'); $t->text('message');
            $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
        });
        return Ticket::create(['user_id' => $owner->id, 'subject' => 'Synthetic ticket']);
    }
    public function testLegacyPrivilegedBindingCannotReplyButManualManagementIdCan()
    {
        $owner = $this->user(); $admin = $this->user(['is_admin' => 1, 'telegram_id' => 808001]);
        $ticket = $this->tickets($owner); $command = $this->command(ReplyTicket::class);
        try { $command->handle($this->message(), ['', $ticket->id]); $this->fail('Legacy binding authorized ticket reply'); }
        catch (HttpException $error) { $this->assertSame(403, $error->getStatusCode()); $this->assertSame(0, TicketMessage::count()); }
        $this->adminUpdate($admin, ['telegram_admin_id' => '808002']);
        $command->handle($this->message('', 808002), ['', $ticket->id]);
        $this->assertSame($admin->id, (int)TicketMessage::first()->user_id);
        $this->assertSame($ticket->id, (int)TicketMessage::first()->ticket_id);
    }
    private function adminUpdate($user, array $changes)
    {
        $r = new TelegramAdminUpdate(['id' => $user->id]);
        $r->values = array_merge(['email' => $user->email, 'is_admin' => (int)$user->is_admin, 'is_staff' => (int)$user->is_staff, 'banned' => 0], $changes);
        return (new AdminUsers())->update($r);
    }
    public function testPromotionDoesNotPromoteOrdinaryTelegramBinding()
    {
        $user = $this->user(['telegram_id' => 808001]);
        $this->adminUpdate($user, ['is_staff' => 1]);
        $this->assertNull($user->fresh()->telegram_admin_id);
        $this->assertSame(0, TelegramService::managementUsers()->whereKey($user->id)->count());
    }
    public function testNotificationsOnlyUseManualIdsAndExcludeBannedAndUnprivilegedUsers()
    {
        config(['v2board.telegram_bot_enable' => 1]);
        $this->user(['is_admin' => 1, 'telegram_id' => 808001]);
        $this->user(['is_staff' => 1, 'telegram_id' => 808002]);
        $this->user(['is_admin' => 1, 'telegram_admin_id' => 808003]);
        $this->user(['is_staff' => 1, 'telegram_admin_id' => 808004]);
        $this->user(['is_admin' => 1, 'banned' => 1, 'telegram_admin_id' => 808005]);
        $this->user(['telegram_admin_id' => 808006]);
        (new TelegramService())->sendMessageWithAdmin('Synthetic payment');
        (new TelegramService())->sendMessageWithAdmin('Synthetic ticket', true);
        $jobs = Environment::$queue->pushed(SendTelegramJob::class);
        $ids = $jobs->map(function ($job) { $p = new \ReflectionProperty($job, 'telegramId'); $p->setAccessible(true); return $p->getValue($job); })->all();
        sort($ids); $this->assertSame([808003,808003,808004], $ids);
        $sender = new TelegramFakeSender(); Environment::$app->instance(TelegramService::class, $sender);
        // Legacy and banned recipients cannot send via a stale or malformed job.
        (new SendTelegramJob(808001, 'Legacy queued notification'))->handle();
        (new SendTelegramJob(808005, 'Banned recipient'))->handle();
        $this->assertSame([], $sender->sent);
    }
    public function testManualBindingIsRevokedOnRecoveryBanAndRoleRemoval()
    {
        $user = $this->user(['is_admin' => 1, 'telegram_admin_id' => 808001]);
        AccountSecurityService::recover($user); $this->assertNull($user->fresh()->telegram_admin_id);
        $this->adminUpdate($user, ['telegram_admin_id' => '808001']);
        $this->adminUpdate($user, ['banned' => 1]); $this->assertNull($user->fresh()->telegram_admin_id);
        $this->adminUpdate($user->fresh(), ['banned' => 0, 'telegram_admin_id' => '808001']);
        $this->adminUpdate($user->fresh(), ['is_admin' => 0]); $this->assertNull($user->fresh()->telegram_admin_id);
    }
    public function testManagementIdValidationRejectsInvalidAndUnsafeIntegerInputs()
    {
        $rules = (new UserUpdate())->rules()['telegram_admin_id'];
        foreach (['-100123','0','1e6','4503599627370496',[],true,'user-name'] as $value) $this->assertTrue(app('validator')->make(['id' => $value], ['id' => $rules])->fails());
        foreach ([null,'808001','4503599627370495'] as $value) $this->assertFalse(app('validator')->make(['id' => $value], ['id' => $rules])->fails());
        $this->assertArrayNotHasKey('telegram_admin_id', (new \App\Http\Requests\Staff\UserUpdate())->rules());
        $this->assertArrayNotHasKey('telegram_admin_id', (new \App\Http\Requests\User\UserUpdate())->rules());
    }
    public function testTelegramMigrationIsRepeatableAndDoesNotTrustOrEraseOldBindings()
    {
        if (DB::connection()->getDriverName() !== 'mysql') $this->markTestSkipped('Uses the production MySQL DDL');
        $user = $this->user(['is_admin' => 1, 'telegram_id' => 808001]);
        DB::statement('ALTER TABLE v2_user DROP INDEX v2_user_telegram_admin_id_unique');
        DB::statement('ALTER TABLE v2_user DROP COLUMN telegram_admin_id');
        $statements = array_filter(DatabaseUpgrade::statements(file_get_contents(base_path('database/update.sql'))), fn($s) => strpos($s, 'telegram_admin_') !== false);
        $sql = implode(';', $statements) . ';'; $this->assertCount(3, $statements);
        (new DatabaseUpgrade())->run($sql, true); (new DatabaseUpgrade())->run($sql, true);
        $this->assertSame(808001, (int)$user->fresh()->telegram_id); $this->assertNull($user->fresh()->telegram_admin_id);
    }

    public function testRebindingSameIdNeverReactivatesEarlierQueuedManagementMessages()
    {
        config(['v2board.telegram_bot_enable' => 1]);
        $admin = $this->user(['is_admin' => 1, 'telegram_admin_id' => 808001]);
        $sender = new TelegramFakeSender(); Environment::$app->instance(TelegramService::class, $sender);
        (new TelegramService())->sendMessageWithAdmin('Synthetic prior authorization');
        $job = Environment::$queue->pushed(SendTelegramJob::class)->first();
        $this->adminUpdate($admin, ['telegram_admin_id' => null]);
        $job->handle();
        $this->assertSame([], $sender->sent, 'Revoked queued job must not send');
        $this->adminUpdate($admin->fresh(), ['telegram_admin_id' => '808001']);
        $this->assertSame(2, (int)$admin->fresh()->telegram_admin_version);
        $job->handle();
        $this->assertSame([], $sender->sent, 'Revoked queued job must not send after the same ID is rebound');
        (new TelegramService())->sendMessageWithAdmin('Synthetic current authorization');
        $currentJob = Environment::$queue->pushed(SendTelegramJob::class)->last();
        $currentJob->handle();
        $this->assertSame([[808001, 'Synthetic current authorization']], $sender->sent);
    }
}
