<?php
namespace Tests\P1;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\TelegramAdminStatsService;
use App\Services\TelegramBotService;
use App\Services\TelegramService;
use App\Utils\CacheKey;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TelegramAdminStatsSender extends TelegramService
{
    public $panels = [];
    public function answerCallback(string $id, string $text = '') { }
    public function sendPanelMessage(int $chatId, string $text, array $buttons = [], ?int $messageId = null)
    {
        $this->panels[] = compact('chatId','text','buttons','messageId');
    }
}

class TelegramAdminStatsTest extends DatabaseTestCase
{
    private $now;
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-16 12:34:56','Asia/Shanghai'));
        $this->now = Carbon::now()->getTimestamp();
        config(['app.timezone'=>'Asia/Shanghai','v2board.currency'=>'CNY','horizon.trim.recent_failed'=>10080]);
        app()->instance(MasterSupervisorRepository::class, \Mockery::mock(MasterSupervisorRepository::class)->shouldReceive('all')->andReturn([(object)['status'=>'running']])->getMock());
        app()->instance(JobRepository::class, \Mockery::mock(JobRepository::class)->shouldReceive('countRecentlyFailed')->andReturn(3)->getMock());
        app()->instance(WorkloadRepository::class, \Mockery::mock(WorkloadRepository::class)->shouldReceive('get')->andReturn([
            ['name'=>'mail','length'=>2,'wait'=>1.5,'processes'=>1], ['name'=>'order_handle','length'=>5,'wait'=>3,'processes'=>2],
        ])->getMock());
        Cache::put(CacheKey::get('SCHEDULE_LAST_CHECK_AT',null),$this->now-30,120);
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }
    private function panel(string $view = 'overview', string $period = 'today', bool $refresh = false): string
    {
        return (new TelegramAdminStatsService())->panel($view,$period,$refresh);
    }
    private function paid(User $user, array $values = []): Order
    {
        return $this->order($user,array_replace(['type'=>1,'period'=>'month_price','status'=>3,'paid_at'=>$this->now-60,'created_at'=>$this->now-120],$values));
    }
    private function receipt(Order $order, Payment $gateway, int $amount, array $values = []): void
    {
        DB::table('v2_payment_receipt')->insert(array_replace(['order_id'=>$order->id,'user_id'=>$order->user_id,'payment_id'=>$gateway->id,
            'callback_no'=>uniqid('fixture-receipt-'),'amount'=>$amount,'wallet_amount'=>0,'disposition'=>'applied','created_at'=>$this->now-30],$values));
    }
    private function gateway(string $name = 'fixture-channel'): Payment
    {
        return Payment::create(['name'=>$name,'uuid'=>uniqid('fixture-'),'payment'=>'EPay','config'=>['key'=>'never-expose-this-fixture-secret']]);
    }

    public function testOrderCreationAndPaymentDatesAreSeparateAndAllPaidStatusesCount(): void
    {
        $user = $this->user();
        foreach ([1,2,3,4,9] as $index=>$type) $this->paid($user,['type'=>$type,'status'=>[1,3,4,3,3][$index],
            'created_at'=>$index === 0 ? $this->now-86400 : $this->now-120]);
        $this->order($user,['type'=>1,'status'=>0]);
        $this->order($user,['type'=>1,'status'=>2]);
        $this->paid($user,['paid_at'=>$this->now-86400,'created_at'=>$this->now-86400]);
        $text = $this->panel('orders');
        foreach (['新建订单：6 笔','其中待支付：1 笔','其中已取消：1 笔','付清订单：5 笔','成交金额：500.00 CNY',
            '套餐新购：1 笔','套餐续费：1 笔','套餐变更：1 笔','流量重置：1 笔','余额充值：1 笔'] as $value) $this->assertStringContainsString($value,$text);
    }

    public function testIncomeCountsEachReceiptOnceAndSeparatesBalanceCreditManualAndLegacyPayments(): void
    {
        $user = $this->user(); $gateway = $this->gateway('<b>支付宝</b>');
        $plan = $this->paid($user,['total_amount'=>10000,'handling_amount'=>100,'balance_amount'=>5000,'surplus_amount'=>3000,'refund_amount'=>1000]);
        $this->receipt($plan,$gateway,10100);
        $recharge = $this->paid($user,['type'=>9,'period'=>'deposit','total_amount'=>2000]); $this->receipt($recharge,$gateway,2000);
        $this->paid($user,['type'=>2,'total_amount'=>0,'balance_amount'=>4000]);
        $this->paid($user,['type'=>3,'total_amount'=>0,'surplus_amount'=>500,'refund_amount'=>100]);
        $this->paid($user,['total_amount'=>3000,'callback_no'=>'manual_operation']);
        $this->paid($user,['total_amount'=>5000,'callback_no'=>'old-gateway-receipt']);
        $canceled = $this->order($user,['type'=>1,'status'=>2,'total_amount'=>700,'created_at'=>$this->now-86400]);
        $this->receipt($canceled,$gateway,700,['wallet_amount'=>700,'disposition'=>'wallet']);
        $text = $this->panel('income');
        foreach (['确认收款：128.00 CNY / 3 笔','套餐收款：108.00 CNY','充值收款：20.00 CNY','收款中转入余额：7.00 CNY',
            '收款中手续费：1.00 CNY','余额支付：90.00 CNY','套餐折抵：24.00 CNY','手工确认：1 笔 / 30.00 CNY',
            '其他未入台账付款：1 笔 / 50.00 CNY','&lt;b&gt;支付宝&lt;/b&gt;：128.00 CNY / 3 笔'] as $value) $this->assertStringContainsString($value,$text);
        $this->assertStringNotContainsString('<b>支付宝</b>',$text);
        $this->assertStringNotContainsString('never-expose-this-fixture-secret',$text);
        $this->assertSame(3,DB::table('v2_payment_receipt')->count());
        $this->assertStringContainsString('确认收款：128.00 CNY / 3 笔',$this->panel('overview'));
    }

    public function testForeignReceiptsAreListedInTheirOwnMinorUnitsWithoutAddingCurrencies(): void
    {
        $user = $this->user(); $gateway = $this->gateway();
        foreach (['usd'=>1400,'jpy'=>1000] as $currency=>$amount) {
            $order = $this->paid($user);
            $attempt = DB::table('v2_payment_attempt')->insertGetId(['trade_no'=>uniqid('attempt-'),'order_id'=>$order->id,'user_id'=>$user->id,
                'payment_id'=>$gateway->id,'method'=>'StripeCheckout','gateway_uuid'=>$gateway->uuid,'config_hash'=>str_repeat('a',64),
                'request_hash'=>str_repeat('b',64),'base_amount'=>10000,'total_amount'=>10000,'gateway_currency'=>$currency,
                'gateway_amount_minor'=>$amount,'status'=>'paid','created_at'=>$this->now-60,'updated_at'=>$this->now-60]);
            $this->receipt($order,$gateway,10000,['attempt_id'=>$attempt]);
        }
        $text = $this->panel('income');
        foreach (['确认收款：200.00 CNY / 2 笔','14.00 USD / 1 笔','1000 JPY / 1 笔'] as $value) $this->assertStringContainsString($value,$text);
    }

    public function testDateWindowsRespectSiteTimezoneCalendarBoundariesAndMidnightCacheRollover(): void
    {
        $user = $this->user();
        foreach (['2026-11-16 00:00:00','2026-11-15 23:59:59','2026-11-10 00:00:00','2026-11-09 23:59:59','2026-11-01 00:00:00','2026-10-31 23:59:59'] as $date)
            $this->order($user,['created_at'=>Carbon::parse($date,'Asia/Shanghai')->getTimestamp()]);
        foreach (['today'=>1,'yesterday'=>1,'week'=>3,'month'=>5] as $period=>$count)
            $this->assertStringContainsString('新建订单：'.$count.' 笔',$this->panel('orders',$period));
        config(['app.timezone'=>'UTC']);
        $this->assertStringContainsString('新建订单：0 笔',$this->panel('orders','today'));
        config(['app.timezone'=>'Asia/Shanghai']);
        Carbon::setTestNow(Carbon::parse('2026-11-17 00:00:01','Asia/Shanghai'));
        $this->assertStringContainsString('新建订单：0 笔',$this->panel('orders','today'));
    }

    public function testCurrencyComparisonAndGroupingIgnoreCaseWithBinaryDatabaseCollation(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') DB::statement('ALTER TABLE v2_payment_attempt MODIFY gateway_currency VARCHAR(8) COLLATE utf8mb4_bin NULL');
        config(['v2board.currency'=>'USD']);
        $user = $this->user(); $gateway = $this->gateway();
        foreach (['usd'=>1400,'USD'=>1400,'jpy'=>1000,'JPY'=>1000] as $currency=>$amount) {
            $order = $this->paid($user);
            $attempt = DB::table('v2_payment_attempt')->insertGetId(['trade_no'=>uniqid('attempt-'),'order_id'=>$order->id,'user_id'=>$user->id,
                'payment_id'=>$gateway->id,'method'=>'StripeCheckout','gateway_uuid'=>$gateway->uuid,'config_hash'=>str_repeat('a',64),
                'request_hash'=>str_repeat('b',64),'base_amount'=>10000,'total_amount'=>10000,'gateway_currency'=>$currency,
                'gateway_amount_minor'=>$amount,'status'=>'paid','created_at'=>$this->now-60,'updated_at'=>$this->now-60]);
            $this->receipt($order,$gateway,10000,['attempt_id'=>$attempt]);
        }
        $text = $this->panel('income');
        $this->assertStringNotContainsString('14.00 USD',$text);
        $this->assertStringContainsString('2000 JPY / 2 笔',$text);
        $this->assertSame(1,substr_count($text,'JPY /'));
    }

    public function testUserCountsUseRemainingQuotaAndExcludeManagementRoles(): void
    {
        $this->user(['transfer_enable'=>100,'u'=>20,'d'=>30,'expired_at'=>null,'t'=>$this->now-20]);
        $this->user(['transfer_enable'=>100,'d'=>100,'expired_at'=>null]);
        $this->user(['transfer_enable'=>100,'expired_at'=>$this->now-1]);
        $this->user(['transfer_enable'=>100,'expired_at'=>$this->now+3600]);
        $this->user(['transfer_enable'=>100,'banned'=>1,'t'=>$this->now-20]);
        $this->user(['is_admin'=>1,'transfer_enable'=>100,'t'=>$this->now-20]);
        $this->user(['is_staff'=>1,'transfer_enable'=>100,'t'=>$this->now-20]);
        $text = $this->panel('users');
        foreach (['总用户：5','可用账号：2','在线用户（近10分钟）：1','今日注册：5','已到期：1','流量耗尽：1','未来7天到期：1'] as $value)
            $this->assertStringContainsString($value,$text);
    }

    public function testCachedSnapshotAndExplicitRefreshDoNotMutateOrders(): void
    {
        $user = $this->user(); $first = $this->panel('orders');
        $order = $this->paid($user); $before = $order->fresh()->getAttributes();
        $this->assertSame($first,$this->panel('orders'));
        $this->assertStringContainsString('付清订单：1 笔',$this->panel('orders','today',true));
        $this->assertSame($before,$order->fresh()->getAttributes());
    }

    public function testSystemStateHandlesPauseAndUnavailableHorizonWithoutHidingIncome(): void
    {
        $text = $this->panel('system');
        foreach (['队列：运行中','调度：正常','失败任务（近7天）：3','order_handle：等待 5，预计 3 秒，进程 2'] as $value)
            $this->assertStringContainsString($value,$text);
        app()->instance(MasterSupervisorRepository::class,\Mockery::mock(MasterSupervisorRepository::class)->shouldReceive('all')->andReturn([(object)['status'=>'paused']])->getMock());
        Cache::forget(CacheKey::get('SCHEDULE_LAST_CHECK_AT',null));
        $this->assertStringContainsString('队列：已暂停',$this->panel('system','today',true));
        $this->assertStringContainsString('调度：未运行或已超时',$this->panel('system'));
        app()->instance(MasterSupervisorRepository::class,\Mockery::mock(MasterSupervisorRepository::class)->shouldReceive('all')->andThrow(new \RuntimeException('private-monitor-error'))->getMock());
        $text = $this->panel('overview','today',true);
        $this->assertStringContainsString('队列：暂时无法获取',$text);
        $this->assertStringContainsString('确认收款：0.00 CNY / 0 笔',$text);
        $this->assertStringNotContainsString('private-monitor-error',$text);
    }

    public function testAdministratorNavigationPreservesPeriodAndEditsTheExistingMessage(): void
    {
        $this->user(['is_admin'=>1,'telegram_admin_id'=>808002]);
        $sender = new TelegramAdminStatsSender(); $bot = new TelegramBotService($sender);
        $bot->handleMessage((object)['command'=>'/stats','is_private'=>true,'args'=>[],'chat_id'=>808002]);
        $this->assertStringContainsString('管理概况',$sender->panels[0]['text']);
        $this->press($bot,'admin:income:yesterday',808002);
        $panel = end($sender->panels);
        $this->assertStringContainsString('收款统计',$panel['text']); $this->assertStringContainsString('统计区间：昨日',$panel['text']);
        $this->assertSame(77,$panel['messageId']);
        $buttons = json_encode($panel['buttons'],JSON_UNESCAPED_UNICODE);
        foreach (['admin:income:yesterday:refresh','admin:users:yesterday','admin:overview:yesterday'] as $value) $this->assertStringContainsString($value,$buttons);
        foreach (['节点概况','订阅概况','待处理异常','工单'] as $value) $this->assertStringNotContainsString($value,$panel['text'].$buttons);
        foreach ($panel['buttons'] as $row) foreach ($row as $button) if (isset($button['callback_data'])) $this->assertLessThanOrEqual(64,strlen($button['callback_data']));
        $order = $this->paid($this->user(),['paid_at'=>$this->now-86400]);
        $this->receipt($order,$this->gateway(),10000,['created_at'=>$this->now-86400]);
        $this->press($bot,'admin:income:yesterday',808002);
        $this->assertStringContainsString('确认收款：0.00 CNY / 0 笔',end($sender->panels)['text']);
        $this->press($bot,'admin:income:yesterday:refresh',808002);
        $this->assertStringContainsString('统计区间：昨日',end($sender->panels)['text']);
        $this->assertStringContainsString('确认收款：100.00 CNY / 1 笔',end($sender->panels)['text']);
        $count = count($sender->panels); $this->press($bot,'admin:invalid:invalid',808002);
        $this->assertCount($count,$sender->panels);
    }

    public function testCachedStatisticsCannotBeReadByAUserStaffOrRevokedAdministrator(): void
    {
        $admin = $this->user(['is_admin'=>1,'telegram_admin_id'=>808002]);
        $sender = new TelegramAdminStatsSender(); $bot = new TelegramBotService($sender);
        $this->press($bot,'admin:income:today',808002);
        $this->user(['telegram_id'=>808001]); $this->user(['is_staff'=>1,'telegram_id'=>808003]);
        $admin->banned=1; $admin->save();
        foreach ([808001,808002,808003] as $chat) {
            try { $this->press($bot,'admin:income:today', $chat); $this->fail('Unauthorized statistics returned'); }
            catch (HttpException $e) { $this->assertSame(403,$e->getStatusCode()); }
        }
        $this->assertCount(1,$sender->panels);
    }

    private function press(TelegramBotService $bot,string $data,int $chat): void
    {
        $bot->handleCallback(['id'=>'fixture-callback','from'=>['id'=>$chat],'message'=>['message_id'=>77,'chat'=>['id'=>$chat,'type'=>'private']],'data'=>'v2b:'.$data]);
    }
}
