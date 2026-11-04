<?php
namespace Tests\P1;

use App\Http\Controllers\V1\Guest\PaymentController;
use App\Jobs\OrderHandleJob;
use App\Jobs\SendTelegramJob;
use App\Models\Payment;
use App\Models\Plan;
use App\Services\PaymentAttemptService;
use App\Services\PaymentNotificationService;
use App\Services\TelegramService;
use App\Utils\StripeCurrency;
use Illuminate\Http\Request;

class PaymentNotificationTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();config(['v2board.telegram_bot_enable'=>1,'v2board.currency'=>'CNY']);
        $this->user(['is_admin'=>1,'telegram_admin_id'=>808099]);
    }

    private function gateway(string $name='支付宝', int $fee=0): Payment
    {
        return Payment::create(['name'=>$name,'uuid'=>uniqid('notice-'),'payment'=>'EPay','handling_fee_fixed'=>$fee,
            'config'=>['pid'=>'fixture-merchant','key'=>'fixture-notice-secret','url'=>'https://gateway.example.test']]);
    }

    private function notify(Payment $gateway, string $reference, string $amount): string
    {
        $params=['pid'=>'fixture-merchant','trade_status'=>'TRADE_SUCCESS','out_trade_no'=>$reference,'trade_no'=>'fixture-receipt','money'=>$amount];
        ksort($params);$params['sign']=md5(stripslashes(urldecode(http_build_query($params))).'fixture-notice-secret');$params['sign_type']='MD5';
        $request=Request::create('/','POST',$params);app()->instance('request',$request);
        return (new PaymentController())->notify('EPay',$gateway->uuid,$request);
    }

    private function delivered(): array
    {
        $sender=new class extends TelegramService {
            public $sent=[];
            public function sendMessage(int $chatId,string $text,string $parseMode='') {$this->sent[]=compact('chatId','text','parseMode');}
        };
        app()->instance(TelegramService::class,$sender);
        Environment::$queue->pushed(SendTelegramJob::class)->last()->handle();
        $this->assertCount(1,$sender->sent);return $sender->sent[0];
    }

    public function testPlanNoticeContainsUserPlanPeriodAmountsAndPaymentWithoutMarkupOrSecrets(): void
    {
        $user=$this->user(['email'=>'buyer_name+tag@example.test','token'=>'fixture-subscription-secret','uuid'=>'fixture-uuid']);
        $plan=Plan::create(['name'=>'高级 *专线* [套餐] `月付`']);$gateway=$this->gateway('支付宝 *直付*',100);
        $order=$this->order($user,['type'=>1,'period'=>'month_price','plan_id'=>$plan->id,'payment_id'=>$gateway->id,
            'total_amount'=>7500,'balance_amount'=>2500,'discount_amount'=>1000,'handling_amount'=>100]);
        $this->assertSame('success',$this->notify($gateway,$order->trade_no,'76.00'));
        $message=$this->delivered();$this->assertSame('',$message['parseMode']);$this->assertSame(808099,$message['chatId']);
        foreach (['用户：'.$user->email,'用户 ID：'.$user->id,'业务：套餐新购','套餐：'.$plan->name,'周期：月付',
            '订单应付：75.00 CNY','余额抵扣：25.00 CNY','优惠金额：10.00 CNY','手续费：1.00 CNY','实收金额：76.00 CNY',
            '支付方式：支付宝 *直付*（EPay）','订单号：'.$order->trade_no,'支付流水号：fixture-receipt','付款时间：'.date('Y-m-d H:i:s',$order->fresh()->paid_at)] as $text)
            $this->assertStringContainsString($text,$message['text']);
        foreach (['fixture-subscription-secret','fixture-uuid','fixture-notice-secret','gateway.example.test'] as $secret) $this->assertStringNotContainsString($secret,$message['text']);
        $this->assertSame('success',$this->notify($gateway,$order->trade_no,'76.00'));
        Environment::$queue->assertPushed(SendTelegramJob::class,1);Environment::$queue->assertPushed(OrderHandleJob::class,1);
    }

    public function testRechargeNoticeAndNotificationOffPreservePaymentAndOpening(): void
    {
        $user=$this->user();$gateway=$this->gateway();$order=$this->order($user,['payment_id'=>$gateway->id]);
        $this->assertSame('success',$this->notify($gateway,$order->trade_no,'100.00'));
        $message=$this->delivered()['text'];$this->assertStringContainsString('业务：余额充值',$message);
        $this->assertStringContainsString('充值金额：100.00 CNY',$message);$this->assertStringNotContainsString('套餐：',$message);$this->assertStringNotContainsString('周期：',$message);
        (new OrderHandleJob($order->trade_no))->handle();$this->assertSame(10000,(int)$user->fresh()->balance);
        config(['v2board.telegram_bot_enable'=>0]);$other=$this->gateway();$second=$this->order($user,['payment_id'=>$other->id]);
        $this->assertSame('success',$this->notify($other,$second->trade_no,'100.00'));
        Environment::$queue->assertPushed(SendTelegramJob::class,1);Environment::$queue->assertPushed(OrderHandleJob::class,2);
    }

    public function testNoticeUsesTheSettledAttemptRatherThanTheLastSelectedPayment(): void
    {
        $user=$this->user();$order=$this->order($user);$a=$this->gateway('最初选择的支付宝',100);$b=$this->gateway('后来选择的微信',300);
        $first=(new PaymentAttemptService())->begin($user->id,$order->trade_no,$a->id);
        (new PaymentAttemptService())->begin($user->id,$order->trade_no,$b->id);
        $this->assertSame('success',$this->notify($a,$first->trade_no,'101.00'));
        $message=$this->delivered()['text'];$this->assertStringContainsString('支付方式：最初选择的支付宝（EPay）',$message);
        $this->assertStringContainsString('手续费：1.00 CNY',$message);$this->assertStringContainsString('实收金额：101.00 CNY',$message);
        $this->assertStringNotContainsString('后来选择的微信',$message);
        $this->assertSame($a->id,(int)$order->fresh()->payment_id);
    }

    public function testPaymentNoticeUsesFrozenChannelNameAndMethodAfterTheGatewayIsReconfigured(): void
    {
        $user=$this->user();$order=$this->order($user);$gateway=$this->gateway('原支付宝通道',100);
        $attempt=(new PaymentAttemptService())->begin($user->id,$order->trade_no,$gateway->id);
        $gateway->name='新的通道';$gateway->payment='Paytaro';$gateway->config=['key'=>'new-fixture-secret'];$gateway->save();
        $this->assertSame('success',$this->notify($gateway,$attempt->trade_no,'101.00'));
        $text=$this->delivered()['text'];$this->assertStringContainsString('支付方式：原支付宝通道（EPay）',$text);
        foreach (['新的通道','Paytaro','fixture-notice-secret','new-fixture-secret'] as $value) $this->assertStringNotContainsString($value,$text);
        $this->assertSame(1,(int)$order->fresh()->status);Environment::$queue->assertPushed(OrderHandleJob::class,1);
        $bad=['attempt_id'=>$attempt->id,'currency'=>'CNY','amount'=>'101.00'];
        \Illuminate\Support\Facades\DB::table('v2_payment_attempt')->where('id',$attempt->id)->update(['config_snapshot'=>'unreadable-fixture']);
        $text=PaymentNotificationService::message($order->fresh(),$bad);
        $this->assertStringContainsString('支付方式：EPay',$text);$this->assertStringNotContainsString('新的通道',$text);
    }

    public function testUnverifiedReceiptAmountIsShownAsAQuoteRatherThanAReceivedAmount(): void
    {
        $order=$this->order($this->user(),['paid_at'=>time(),'handling_amount'=>100])->fresh();
        $text=PaymentNotificationService::message($order,[]);
        $this->assertStringContainsString('支付报价：101.00 CNY',$text);$this->assertStringNotContainsString('实收金额：',$text);
        $text=PaymentNotificationService::message($order,['amount'=>'101.00','currency'=>'CNY']);
        $this->assertStringContainsString('实收金额：101.00 CNY',$text);$this->assertStringNotContainsString('支付报价：',$text);
    }

    public function testOrderTypesPeriodsAndMissingDisplayRecordsRemainReadable(): void
    {
        $order=$this->order($this->user(),['type'=>2,'period'=>'year_price','plan_id'=>999,'payment_id'=>999,'paid_at'=>time()]);
        foreach ([2=>'套餐续费',3=>'套餐变更',4=>'流量重置'] as $type=>$label) {
            $order->type=$type;$message=PaymentNotificationService::message($order,[]);$this->assertStringContainsString('业务：'.$label,$message);
        }
        $this->assertStringContainsString('周期：年付',$message);$this->assertStringContainsString('已删除套餐（ID：999）',$message);
        $this->assertStringContainsString('已删除支付方式（ID：999）',$message);
        $order->period='reset_price';$this->assertStringContainsString('周期：流量重置包',PaymentNotificationService::message($order,[]));
        $order->period='onetime_price';$this->assertStringContainsString('周期：一次性',PaymentNotificationService::message($order,[]));
    }

    public function testForeignReceiptsAndLegacyQueuedMessageModes(): void
    {
        $order=$this->order($this->user(),['paid_at'=>time()])->fresh();
        foreach ([[1400,'usd','14.00 USD'],[1200,'jpy','1200 JPY'],[150000,'ugx','1500.00 UGX']] as [$minor,$currency,$expected]) {
            $message=PaymentNotificationService::message($order,['amount_minor'=>$minor,'currency'=>$currency]);
            $this->assertStringContainsString('实收金额：'.$expected,$message);$this->assertStringContainsString('充值金额：100.00 CNY',$message);
        }
        $this->assertSame('0.01',StripeCurrency::formatMinor(1,'usd'));$this->assertSame('100.00',StripeCurrency::formatMinor(10000,'isk'));
        (new TelegramService())->sendMessageWithAdmin('Existing notification');
        $this->assertSame('markdown',$this->delivered()['parseMode']);
    }
}
