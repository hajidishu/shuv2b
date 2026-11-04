<?php
namespace Tests\P1;

use App\Exceptions\TelegramApiException;
use App\Http\Controllers\V1\Guest\TelegramController;
use App\Jobs\ProcessTelegramUpdate;
use App\Jobs\SendTelegramReminder;
use App\Models\TelegramDelivery;
use App\Models\TelegramProfile;
use App\Models\User;
use App\Services\TelegramBindingService;
use App\Services\TelegramBotSchema;
use App\Services\TelegramBotService;
use App\Services\TelegramGroupService;
use App\Services\TelegramReminderService;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TelegramPanelSender extends TelegramService
{
    public $panels=[],$messages=[],$callbacks=[],$members=[],$statuses=[],$failNext=null,$approveError=null,$failMessageOnce=null;
    public function getMe() {return (object)['ok'=>true,'result'=>(object)['username'=>'fixture_bot']];}
    public function sendMessage(int $chatId,string $text,string $parseMode='') {
        if ($this->failMessageOnce) {$e=$this->failMessageOnce;$this->failMessageOnce=null;throw $e;}
        $this->messages[]=[$chatId,$text];
    }
    public function sendPanelMessage(int $chatId,string $text,array $buttons=[],?int $messageId=null) {
        if ($this->failNext) {$e=$this->failNext;$this->failNext=null;throw $e;}
        $this->panels[]=compact('chatId','text','buttons','messageId');return (object)['ok'=>true];
    }
    public function answerCallback(string $id,string $text='') {$this->callbacks[]=$id;}
    public function approveChatJoinRequest(int $chatId,int $userId) {
        if ($this->approveError) throw $this->approveError;
        $this->members[]=['approve',$chatId,$userId];
    }
    public function declineChatJoinRequest(int $chatId,int $userId) {$this->members[]=['decline',$chatId,$userId];}
    public function getChatMember(int $chatId,int $telegramId) {return (object)['result'=>(object)['status'=>$this->statuses[$telegramId] ?? 'member']];}
    public function removeChatMember(int $chatId,int $telegramId): void {$this->members[]=['remove',$chatId,$telegramId];}
}

class TelegramPanelTest extends DatabaseTestCase
{
    private $sender;
    protected function setUp(): void
    {
        parent::setUp();(new TelegramBotSchema())->ensure();
        $this->sender=new TelegramPanelSender();app()->instance(TelegramService::class,$this->sender);
        config(['v2board.telegram_bot_token'=>'fixture-panel-token','v2board.app_url'=>'https://backend.example.test',
            'v2board.telegram_frontend_url'=>'https://front.example.test','v2board.subscribe_url'=>'https://sub.example.test',
            'v2board.show_subscribe_method'=>0,'v2board.telegram_remind_enable'=>1]);
    }
    private function customer(array $values=[]): User
    {
        return parent::user(array_replace(['token'=>bin2hex(random_bytes(16)),'uuid'=>\App\Utils\Helper::guid(true)],$values));
    }
    private function message(string $command,int $chat=808001,array $args=[]) {return (object)['command'=>$command,'args'=>$args,'chat_id'=>$chat,'is_private'=>true,'text'=>$command];}
    private function callbackUpdate(string $data,int $chat=808001): array {return ['id'=>'fixture-query','from'=>['id'=>$chat],
        'message'=>['message_id'=>12,'chat'=>['id'=>$chat,'type'=>'private']],'data'=>'v2b:'.$data];}
    private function bind(User $user,int $telegramId=808001): TelegramProfile
    {
        $nonce=TelegramBindingService::issue($user);TelegramBindingService::confirm($nonce,$telegramId);return TelegramProfile::find($user->id);
    }
    private function panel(): TelegramBotService {return new TelegramBotService($this->sender);}
    private function lastPanel(): array {return $this->sender->panels[count($this->sender->panels)-1];}

    public function testStartMenuHasOnlyTheApprovedFeaturesAndLegacyBindingCannotFetchTokens(): void
    {
        $user=$this->customer(['telegram_id'=>808001,'transfer_enable'=>1000,'expired_at'=>time()+3600]);
        $this->panel()->handleMessage($this->message('/start'));
        $text=json_encode($this->lastPanel(),JSON_UNESCAPED_UNICODE);
        foreach (['我的套餐','流量与到期','获取订阅','通知设置','打开网站'] as $name) $this->assertStringContainsString($name,$text);
        foreach (['订单','工单','帮助','教程'] as $name) $this->assertStringNotContainsString($name,$text);
        $this->panel()->handleMessage($this->message('/subscribe'));
        $this->assertStringContainsString('确认绑定',$this->lastPanel()['text']);
        $this->assertStringNotContainsString($user->token,$this->lastPanel()['text']);
        $this->assertStringContainsString('front.example.test',json_encode($this->lastPanel()));
    }

    public function testLoggedInBindingIsOneTimeBoundToItsOwnerAndIdempotentForTheSameTelegram(): void
    {
        $user=$this->customer();$nonce=TelegramBindingService::issue($user);
        TelegramBindingService::confirm($nonce,808001);$profile=TelegramProfile::find($user->id);
        TelegramBindingService::confirm($nonce,808001);
        $this->assertSame($profile->binding_version,TelegramProfile::find($user->id)->binding_version);
        $this->assertSame(808001,(int)$user->fresh()->telegram_id);
        try {TelegramBindingService::confirm($nonce,808002);$this->fail('Used challenge reassigned to another chat');}
        catch (HttpException $e) {$this->assertSame(403,$e->getStatusCode());}
        $other=$this->customer();
        try {$this->bind($other,808001);$this->fail('Telegram ownership duplicated');}
        catch (HttpException $e) {$this->assertSame(409,$e->getStatusCode());}
        $this->assertNull($other->fresh()->telegram_id);
    }

    public function testNewChallengeAndCredentialChangesInvalidateOldBindingChallenges(): void
    {
        $user=$this->customer();$old=TelegramBindingService::issue($user);$fresh=TelegramBindingService::issue($user);
        $this->assertNull(TelegramBindingService::challenge($old));
        $user->password='changed-hash';$user->save();
        try {TelegramBindingService::confirm($fresh,808001);$this->fail('Challenge survived credential change');}
        catch (HttpException $e) {$this->assertSame(403,$e->getStatusCode());}
    }

    public function testManagementRolesAndBannedUsersCannotUseTheUserBindingFlow(): void
    {
        foreach ([['is_admin'=>1],['is_staff'=>1],['banned'=>1]] as $flags) {
            try {TelegramBindingService::issue($this->customer($flags));$this->fail('Privileged or banned binding issued');}
            catch (HttpException $e) {$this->assertSame(403,$e->getStatusCode());}
        }
    }

    public function testUserEndpointsOnlyBindAndUnbindTheAuthenticatedAccount(): void
    {
        $user=$this->customer();$other=$this->customer();$this->bind($other,808002);
        $controller=new \App\Http\Controllers\V1\User\TelegramController();
        $request=Request::create('https://unexpected.example.test/api','POST',['user_id'=>$other->id]);$request->user=['id'=>$user->id];
        $data=$controller->getBotInfo($request)->getData(true)['data'];
        $this->assertArrayNotHasKey('token',$data);$this->assertArrayNotHasKey('password',$data);$this->assertSame('fixture_bot',$data['username']);
        $url=$controller->bind($request)->getData(true)['data']['url'];
        parse_str(parse_url($url,PHP_URL_QUERY),$params);$this->assertSame('t.me',parse_url($url,PHP_URL_HOST));
        $nonce=substr($params['start'],5);$this->assertSame($user->id,TelegramBindingService::challenge($nonce)['user_id']);
        TelegramBindingService::confirm($nonce,808001);$controller->unbind($request);
        $this->assertNull($user->fresh()->telegram_id);$this->assertSame(808002,(int)$other->fresh()->telegram_id);
    }

    public function testRecoveryRevokesPendingChallengesAndConfirmedBotAccess(): void
    {
        $user=$this->customer();$this->bind($user);$nonce=TelegramBindingService::issue($user->fresh());
        \App\Services\AccountSecurityService::recover($user->fresh());
        $this->assertNull(TelegramBindingService::challenge($nonce));$this->assertNull(TelegramBindingService::confirmed($user->fresh()));
    }

    public function testSettingsAndCommandRegistryKeepOnlyValidBotCommands(): void
    {
        $rules=(new \App\Http\Requests\Admin\ConfigSave())->rules();
        foreach (['telegram_frontend_url'=>['https://front.example.test', 'javascript:alert(1)'],
            'telegram_remind_expire_days'=>[3,0], 'telegram_remind_traffic_percent'=>[10,100], 'telegram_discuss_id'=>['-100123,-100456','@group']] as $field=>$values) {
            $this->assertFalse(app('validator')->make([$field=>$values[0]],[$field=>$rules[$field]])->fails());
            $this->assertTrue(app('validator')->make([$field=>$values[1]],[$field=>$rules[$field]])->fails());
        }
        $commands=(new TelegramService())->discoverCommands(app_path('Plugins/Telegram/Commands'));
        $names=array_column($commands,'command');$this->assertContains('start',$names);$this->assertContains('subscribe',$names);$this->assertContains('notifications',$names);
        foreach ($names as $name) $this->assertMatchesRegularExpression('/\A[a-z0-9_]{1,32}\z/',$name);
    }

    public function testConfirmedSubscriptionUsesTheConfiguredOriginAndAllExistingTokenModes(): void
    {
        $user=$this->customer(['transfer_enable'=>1000,'expired_at'=>time()+3600]);$this->bind($user);
        app()->instance('request',Request::create('https://unexpected.example.test/telegram/webhook'));
        foreach ([0,1,2] as $mode) {
            config(['v2board.show_subscribe_method'=>$mode,'v2board.show_subscribe_expire'=>5]);
            $this->panel()->handleMessage($this->message('/subscribe'));
            $panel=$this->lastPanel();$url=$panel['buttons'][0][0]['url'];
            $this->assertSame('sub.example.test',parse_url($url,PHP_URL_HOST));
            parse_str(parse_url($url,PHP_URL_QUERY),$params);$this->assertNotEmpty($params['token']);
            if ($mode===0) $this->assertSame($user->token,$params['token']);else $this->assertNotSame($user->token,$params['token']);
            $this->assertStringNotContainsString('unexpected.example.test',$url);
        }
        $user->token=bin2hex(random_bytes(16));$user->save();
        $this->panel()->handleMessage($this->message('/subscribe'));
        $this->assertStringContainsString('确认绑定',$this->lastPanel()['text']);
        $this->assertStringNotContainsString($user->token,$this->lastPanel()['text']);
    }

    public function testCallbacksRequireTheSamePrivateChatAndIgnoreUnknownActions(): void
    {
        $this->bind($this->customer());
        $bad=$this->callbackUpdate('subscribe');$bad['message']['chat']['id']=808002;$this->panel()->handleCallback($bad);
        $bad=$this->callbackUpdate('subscribe');$bad['message']['chat']['type']='group';$this->panel()->handleCallback($bad);
        $this->panel()->handleCallback($this->callbackUpdate('unknown'));
        $this->assertSame([],$this->sender->panels);
        $this->panel()->handleCallback($this->callbackUpdate('home'));
        $this->assertCount(1,$this->sender->panels);
    }

    public function testUnbindConfirmationCannotRemoveALaterBinding(): void
    {
        $user=$this->customer();$this->bind($user);$this->panel()->handleCallback($this->callbackUpdate('unbind'));
        $old=$this->lastPanel()['buttons'][0][0]['callback_data'];
        TelegramBindingService::unbind($user);$this->bind($user->fresh());
        try {$this->panel()->handleCallback($this->callbackUpdate(substr($old,4)));$this->fail('Stale unbind confirmation accepted');}
        catch (HttpException $e) {$this->assertSame(409,$e->getStatusCode());}
        $this->assertSame(808001,(int)$user->fresh()->telegram_id);
        $this->panel()->handleCallback($this->callbackUpdate('unbind'));
        $data=$this->lastPanel()['buttons'][0][0]['callback_data'];$this->panel()->handleCallback($this->callbackUpdate(substr($data,4)));
        $this->assertNull($user->fresh()->telegram_id);$this->assertNull(TelegramProfile::find($user->id));
    }

    public function testWebhookQueuesRealUpdateIdsOnceAndWorkerRejectsBotTokenRotation(): void
    {
        $user=$this->customer(['telegram_id'=>808001]);
        $data=['access_token'=>md5('fixture-panel-token'),'update_id'=>901,'message'=>['text'=>'/start','message_id'=>1,'chat'=>['id'=>808001,'type'=>'private']]];
        $request=Request::create('/','POST',$data);$controller=new TelegramController($request);
        $controller->webhook($request);$controller->webhook($request);
        Environment::$queue->assertPushed(ProcessTelegramUpdate::class,1);
        $job=Environment::$queue->pushed(ProcessTelegramUpdate::class)->first();
        $job->handle();$job->handle();$this->assertCount(1,$this->sender->panels);
        $data['update_id']=902;$request=Request::create('/','POST',$data);$controller->webhook($request);
        $job=Environment::$queue->pushed(ProcessTelegramUpdate::class)->last();
        config(['v2board.telegram_bot_token'=>'rotated-token']);$job->handle();$this->assertCount(1,$this->sender->panels);
    }

    public function testOnlyTheManuallyConfiguredAdministratorCanViewManagementSummary(): void
    {
        $admin=$this->customer(['is_admin'=>1,'telegram_admin_id'=>808002]);
        $this->panel()->handleMessage($this->message('/start',808002));
        $this->assertStringContainsString('管理概况',$this->lastPanel()['text']);
        $admin->banned=1;$admin->save();
        $this->panel()->handleMessage($this->message('/start',808002));
        $this->assertStringNotContainsString('总用户',$this->lastPanel()['text']);
        $this->assertStringNotContainsString('管理概况',$this->lastPanel()['text']);
    }

    private function ticketFixture(User $owner)
    {
        $schema=DB::connection()->getSchemaBuilder();
        foreach (['v2_ticket_message','v2_ticket'] as $table) $schema->dropIfExists($table);
        $schema->create('v2_ticket',function ($t) {
            $t->increments('id');$t->integer('user_id');$t->string('subject');$t->integer('status')->default(0);$t->integer('reply_status')->default(0);
            $t->integer('created_at')->nullable();$t->integer('updated_at')->nullable();
        });
        $schema->create('v2_ticket_message',function ($t) {
            $t->increments('id');$t->integer('user_id');$t->integer('ticket_id');$t->text('message');
            $t->integer('created_at')->nullable();$t->integer('updated_at')->nullable();
        });
        return \App\Models\Ticket::create(['user_id'=>$owner->id,'subject'=>'Synthetic ticket']);
    }

    public function testTicketReplyRetryDoesNotDuplicateTheCommittedReply(): void
    {
        $owner=$this->customer();$admin=$this->customer(['is_admin'=>1,'telegram_admin_id'=>808002]);$ticket=$this->ticketFixture($owner);
        $data=['access_token'=>md5('fixture-panel-token'),'update_id'=>903,'message'=>['text'=>'Synthetic reply','message_id'=>18,
            'chat'=>['id'=>808002,'type'=>'private'],'reply_to_message'=>['text'=>'#'.$ticket->id]]];
        $request=Request::create('/','POST',$data);$controller=new TelegramController($request);$controller->webhook($request);
        $job=Environment::$queue->pushed(ProcessTelegramUpdate::class)->last();$this->sender->failMessageOnce=new TelegramApiException(500);
        try {$job->handle();$this->fail('Acknowledgement failure was swallowed');}
        catch (TelegramApiException $e) {$this->assertSame(500,$e->getCode());}
        $this->assertSame(1,\App\Models\TicketMessage::count());
        $job->handle();$this->assertSame(1,\App\Models\TicketMessage::count());$this->assertSame(1,DB::table('v2_telegram_action')->count());$this->assertCount(1,$this->sender->messages);
        // A lost cache marker or a replay after commit must not repeat the business action.
        $key='TELEGRAM_UPDATE_'.hash('sha256','fixture-panel-token').'_903';Cache::forget($key);
        $controller->webhook($request);Environment::$queue->pushed(ProcessTelegramUpdate::class)->last()->handle();
        $this->assertSame(1,\App\Models\TicketMessage::count());$this->assertCount(2,$this->sender->messages);
        $data['update_id']=904;$data['message']['message_id']=19;$controller->webhook(Request::create('/','POST',$data));
        Environment::$queue->pushed(ProcessTelegramUpdate::class)->last()->handle();
        $this->assertSame(2,\App\Models\TicketMessage::count());$this->assertSame(2,DB::table('v2_telegram_action')->count());
        $this->assertSame($admin->id,(int)\App\Models\TicketMessage::first()->user_id);
    }

    public function testActionMarkerRollsBackWithTheReplyAndFailedJobsCanBeRedelivered(): void
    {
        $owner=$this->customer();$this->customer(['is_admin'=>1,'telegram_admin_id'=>808002]);$ticket=$this->ticketFixture($owner);
        $msg=(object)['chat_id'=>808002,'is_private'=>true,'text'=>'Synthetic reply','update_key'=>hash('sha256','synthetic-update')];
        $command=new \App\Plugins\Telegram\Commands\ReplyTicket();
        try {DB::transaction(function () use ($command,$msg,$ticket) {$command->handle($msg,['',$ticket->id]);throw new \RuntimeException('rollback fixture');});}
        catch (\RuntimeException $e) {$this->assertSame('rollback fixture',$e->getMessage());}
        $this->assertSame(0,\App\Models\TicketMessage::count());$this->assertSame(0,DB::table('v2_telegram_action')->count());
        $command->handle($msg,['',$ticket->id]);$this->assertSame(1,\App\Models\TicketMessage::count());
        $key='TELEGRAM_UPDATE_failed_fixture';$job=new ProcessTelegramUpdate([],hash('sha256','fixture-panel-token'),$key);
        Cache::put($key,'queued',86400);$job->failed();$this->assertFalse(Cache::has($key));
        Cache::put($key,'done',86400);$job->failed();$this->assertSame('done',Cache::get($key));
    }

    public function testRemindersAreDeduplicatedAndWorkersDoNotSendAfterOptOutOrRebinding(): void
    {
        $user=$this->customer(['transfer_enable'=>10000,'u'=>9500,'expired_at'=>time()+86400]);$this->bind($user);
        $service=new TelegramReminderService();$service->maintain();$service->maintain();
        $this->assertSame(2,TelegramDelivery::count());Environment::$queue->assertPushed(SendTelegramReminder::class,2);
        foreach (TelegramDelivery::get() as $delivery) {(new SendTelegramReminder($delivery->id))->handle();(new SendTelegramReminder($delivery->id))->handle();}
        $this->assertCount(2,$this->sender->panels);$this->assertSame(2,TelegramDelivery::where('status','sent')->count());
        $user->u=0;$user->save();$service->maintain();$user->u=9600;$user->save();$service->maintain();
        $delivery=TelegramDelivery::orderByDesc('id')->first();$this->assertSame('pending',$delivery->status);
        $this->panel()->handleCallback($this->callbackUpdate('traffic_off'));
        $before=count($this->sender->panels);(new SendTelegramReminder($delivery->id))->handle();
        $this->assertSame('canceled',$delivery->fresh()->status);$this->assertCount($before,$this->sender->panels);
        TelegramBindingService::unbind($user);$this->bind($user->fresh());$service->maintain();
        $old=TelegramDelivery::where('status','pending')->first();
        TelegramBindingService::unbind($user);$this->bind($user->fresh());
        (new SendTelegramReminder($old->id))->handle();$this->assertSame('canceled',$old->fresh()->status);
    }

    public function testFailedReminderCanRetryWithoutDuplicatingSuccessfulDelivery(): void
    {
        $user=$this->customer(['transfer_enable'=>10000,'u'=>9500,'expired_at'=>null]);$this->bind($user);
        (new TelegramReminderService())->maintain();$delivery=TelegramDelivery::first();
        $this->sender->failNext=new TelegramApiException(500);
        try {(new SendTelegramReminder($delivery->id))->handle();$this->fail('Network failure was swallowed');}
        catch (TelegramApiException $e) {$this->assertSame('pending',$delivery->fresh()->status);}
        (new SendTelegramReminder($delivery->id))->handle();(new SendTelegramReminder($delivery->id))->handle();
        $this->assertSame('sent',$delivery->fresh()->status);$this->assertCount(1,$this->sender->panels);
    }

    public function testGroupAutomationOnlyTouchesConfiguredTrackedMembersAndPreservesAdministrators(): void
    {
        config(['v2board.telegram_discuss_id'=>'-100123','v2board.telegram_group_prune_enable'=>1]);
        $user=$this->customer(['transfer_enable'=>10000,'expired_at'=>time()+3600]);$this->bind($user);
        $service=new TelegramGroupService();
        $service->join(['from'=>['id'=>808001],'chat'=>['id'=>-100999,'type'=>'supergroup']]);$this->assertSame([],$this->sender->members);
        $service->join(['from'=>['id'=>808001],'chat'=>['id'=>-100123,'type'=>'supergroup']]);
        $this->assertSame([['approve',-100123,808001]],$this->sender->members);$this->assertSame(1,DB::table('v2_telegram_member')->count());
        $user->expired_at=time()-1;$user->save();$this->sender->statuses[808001]='administrator';$service->prune();
        $this->assertCount(1,$this->sender->members);$this->assertSame(1,DB::table('v2_telegram_member')->count());
        $this->sender->statuses[808001]='member';$service->prune();
        $this->assertSame(['remove',-100123,808001],end($this->sender->members));$this->assertSame(0,DB::table('v2_telegram_member')->count());
    }

    public function testMembershipGrantedToTheManagementAccountIsRevokedAfterItsRoleIsLost(): void
    {
        config(['v2board.telegram_discuss_id'=>'-100123','v2board.telegram_group_prune_enable'=>1]);
        $admin=$this->customer(['is_admin'=>1,'telegram_admin_id'=>808003]);$service=new TelegramGroupService();
        $service->join(['from'=>['id'=>808003],'chat'=>['id'=>-100123,'type'=>'supergroup']]);
        $this->assertSame([['approve',-100123,808003]],$this->sender->members);
        $service->prune();$this->assertCount(1,$this->sender->members);
        $admin->banned=1;$admin->save();$service->prune();
        $this->assertSame(['remove',-100123,808003],end($this->sender->members));
    }

    public function testMembershipScanResumesAfterThePerRunBudget(): void
    {
        config(['v2board.telegram_discuss_id'=>'-100123','v2board.telegram_group_prune_enable'=>1]);
        $user=$this->customer(['expired_at'=>time()-1]);
        for ($i=0;$i<101;$i++) {
            DB::table('v2_telegram_member')->insert(['user_id'=>$user->id,'chat_id'=>-100123,'telegram_id'=>200000+$i,'joined_at'=>time()]);
            if ($i<100) $this->sender->statuses[200000+$i]='administrator';
        }
        $service=new TelegramGroupService();$service->prune();$this->assertSame([],$this->sender->members);
        $service->prune();$this->assertSame([['remove',-100123,200100]],$this->sender->members);
    }

    public function testAlreadyApprovedJoinKeepsItsMembershipRecordForLaterCleanup(): void
    {
        config(['v2board.telegram_discuss_id'=>'-100123','v2board.telegram_group_prune_enable'=>1]);
        $user=$this->customer(['transfer_enable'=>1000,'expired_at'=>time()+1000]);$this->bind($user);
        $this->sender->approveError=new TelegramApiException(400);
        (new TelegramGroupService())->join(['from'=>['id'=>808001],'chat'=>['id'=>-100123,'type'=>'supergroup']]);
        $this->assertSame(1,DB::table('v2_telegram_member')->count());
        $user->banned=1;$user->save();(new TelegramGroupService())->prune();
        $this->assertSame([['remove',-100123,808001]],$this->sender->members);
    }

    public function testSchemaSetupAndSqlReplayPreserveExistingConfirmedBindings(): void
    {
        $user=$this->customer();$profile=$this->bind($user);(new TelegramBotSchema())->ensure();
        $root=getenv('P1_SOURCE_ROOT') ?: dirname(__DIR__,2);
        foreach (['install','update'] as $name) foreach (['profile','delivery','member','action'] as $table) {
            $this->assertSame(1,preg_match('/CREATE TABLE `v2_telegram_'.$table.'`\s*\(.*?ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;/s',file_get_contents($root.'/database/'.$name.'.sql'),$m));
            (new \App\Services\DatabaseUpgrade())->run($m[0],true);(new \App\Services\DatabaseUpgrade())->verify($m[0]);
        }
        $this->assertSame($profile->binding_version,TelegramProfile::find($user->id)->binding_version);
    }
}
