<?php
namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Utils\StripeCurrency;
use Illuminate\Support\Facades\DB;

class PaymentNotificationService
{
    public static function message(Order $order, array $receipt): string
    {
        $user=User::find($order->user_id,['id','email']);
        $recharge=(int)$order->type===9;
        $types=[1=>'套餐新购',2=>'套餐续费',3=>'套餐变更',4=>'流量重置',9=>'余额充值'];
        $periods=['month_price'=>'月付','quarter_price'=>'季付','half_year_price'=>'半年付','year_price'=>'年付',
            'two_year_price'=>'两年付','three_year_price'=>'三年付','onetime_price'=>'一次性','reset_price'=>'流量重置包'];
        $currency=strtoupper((string)config('v2board.currency','CNY'));
        $lines=['💰成功收款','———————————————','用户：'.self::line($user ? $user->email : '已删除用户'),
            '用户 ID：'.$order->user_id,'业务：'.($types[(int)$order->type] ?? '套餐订单')];
        if (!$recharge) {
            $plan=Plan::find($order->plan_id,['id','name']);
            $lines[]='套餐：'.self::line($plan ? $plan->name : '已删除套餐（ID：'.$order->plan_id.'）');
            $lines[]='周期：'.($periods[$order->period] ?? self::line($order->period));
        }
        $lines[]=($recharge?'充值金额：':'订单应付：').self::money((int)$order->total_amount).' '.$currency;
        $lines[]='余额抵扣：'.self::money((int)$order->balance_amount).' '.$currency;
        foreach (['discount_amount'=>'优惠金额','surplus_amount'=>'套餐折抵'] as $field=>$label) {
            if ($order->$field>0) $lines[]=$label.'：'.self::money((int)$order->$field).' '.$currency;
        }
        $lines[]='手续费：'.self::money((int)$order->handling_amount).' '.$currency;
        $received=isset($receipt['amount_minor'])
            ? StripeCurrency::formatMinor((int)$receipt['amount_minor'],(string)$receipt['currency'])
            : self::money((int)$order->total_amount+(int)$order->handling_amount);
        $amountLabel=isset($receipt['amount_minor']) || isset($receipt['amount']) ? '实收金额：' : '支付报价：';
        $lines[]=$amountLabel.$received.' '.strtoupper((string)($receipt['currency'] ?? $currency));
        $lines[]='支付方式：'.self::line(self::paymentLabel($order,$receipt));
        $lines[]='订单号：'.self::line($order->trade_no);
        $lines[]='支付流水号：'.self::line($order->callback_no);
        $lines[]='付款时间：'.date('Y-m-d H:i:s',(int)$order->paid_at);
        return implode("\n",$lines);
    }

    private static function paymentLabel(Order $order, array $receipt): string
    {
        $gateway=Payment::find($order->payment_id,['id','name','payment']);
        $method=$gateway ? $gateway->payment : null;$name=$gateway ? $gateway->name : null;
        if (!empty($receipt['attempt_id'])) {
            $attempt=DB::table(PaymentAttemptService::TABLE)->where('id',$receipt['attempt_id'])
                ->where('order_id',$order->id)->where('payment_id',$order->payment_id)->first(['method','config_snapshot']);
            if ($attempt) {
                $method=$attempt->method;
                $name=$gateway && $gateway->payment===$method ? $gateway->name : null;
                if ($attempt->config_snapshot) {
                    // Only the frozen display name leaves the encrypted configuration snapshot.
                    try {$name=PaymentConfigSnapshot::open($attempt->config_snapshot)['name'] ?? null;}
                    catch (\Throwable $ignored) {$name=null;}
                }
            }
        }
        if (!$method) return '已删除支付方式（ID：'.$order->payment_id.'）';
        return $name ? $name.'（'.$method.'）' : $method;
    }

    private static function money(int $amount): string
    {
        return intdiv($amount,100).'.'.str_pad((string)($amount%100),2,'0',STR_PAD_LEFT);
    }

    private static function line($value): string
    {
        return preg_replace('/[\r\n]+/',' ',(string)$value);
    }
}
