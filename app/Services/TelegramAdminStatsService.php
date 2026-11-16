<?php
namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Utils\CacheKey;
use App\Utils\StripeCurrency;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;

class TelegramAdminStatsService
{
    public const VIEWS = ['overview'=>'管理概况', 'income'=>'收款统计', 'orders'=>'订单统计', 'users'=>'用户统计', 'system'=>'系统状态'];
    public const PERIODS = ['today'=>'今日', 'yesterday'=>'昨日', 'week'=>'近7天', 'month'=>'本月'];

    public function panel(string $view, string $period, bool $refresh = false): string
    {
        $now = Carbon::now(config('app.timezone', 'Asia/Shanghai'));
        $start = $now->copy()->startOfDay();
        if ($period === 'yesterday') $start->subDay();
        if ($period === 'week') $start->subDays(6);
        if ($period === 'month') $start->startOfMonth();
        $end = ($period === 'yesterday' ? $start : $now)->copy()->startOfDay()->addDay();
        $from = $start->getTimestamp(); $to = $end->getTimestamp();
        $currency = strtoupper((string)config('v2board.currency', 'CNY'));
        $key = 'TELEGRAM_ADMIN_STATS_V1_' . hash('sha256', json_encode([$view, $from, $to, $currency, $now->timezoneName]));
        $load = function () use ($view, $from, $to, $now) {
            $data = ['updated_at'=>$now->format('Y-m-d H:i:s')];
            if (in_array($view, ['overview','orders'], true)) $data['orders'] = $this->orders($from, $to);
            if (in_array($view, ['overview','income'], true)) $data['income'] = $this->income($from, $to, $view === 'income');
            if (in_array($view, ['overview','users'], true)) $data['users'] = $this->users($from, $to, $now->getTimestamp());
            if (in_array($view, ['overview','system'], true)) $data['system'] = $this->system($now->getTimestamp());
            return $data;
        };
        if ($refresh) {
            $data = $load(); Cache::put($key, $data, 30);
        } else $data = Cache::remember($key, 30, $load);

        $lines = ['<b>' . self::VIEWS[$view] . '</b>'];
        $lines[] = $view === 'system' ? '当前运行状态' : '统计区间：' . self::PERIODS[$period] . '（' . $start->format('m-d') . ' 至 ' . $end->copy()->subDay()->format('m-d') . '）';
        $money = fn($amount) => number_format((int)$amount / 100, 2, '.', '') . ' ' . self::esc($currency);
        if ($view === 'overview') {
            $orders = $data['orders']; $income = $data['income']; $users = $data['users'];
            $lines = array_merge($lines, ['', '<b>订单与收款</b>', '新建订单：' . $orders['created'] . ' 笔', '付清订单：' . $orders['paid'] . ' 笔',
                '确认收款：' . $money($income['amount']) . ' / ' . $income['count'] . ' 笔',
                '套餐收款：' . $money($income['plans']), '充值收款：' . $money($income['recharges']),
                '', '<b>用户</b>', '总用户：' . $users['total'], '可用账号：' . $users['available'],
                '在线用户（近10分钟）：' . $users['online'], self::PERIODS[$period] . '注册：' . $users['registered'],
                '', '<b>运行状态</b>', '队列：' . $data['system']['horizon'], '调度：' . $data['system']['schedule']]);
            $lines[] = $this->failureLine($data['system']);
            $lines[] = '\n收款按支付台账记账，含手续费；余额与折抵不重复计入。';
            if ($income['unrecorded_count']) $lines[] = '另有 ' . $income['unrecorded_count'] . ' 笔非台账付款，见收款统计。';
        } elseif ($view === 'income') {
            $income = $data['income'];
            $lines = array_merge($lines, ['', '确认收款：' . $money($income['amount']) . ' / ' . $income['count'] . ' 笔',
                '套餐收款：' . $money($income['plans']), '充值收款：' . $money($income['recharges']),
                '收款中转入余额：' . $money($income['wallet']), '收款中手续费：' . $money($income['fees']),
                '', '<b>支付方式（收款前8）</b>']);
            foreach ($income['channels'] as $channel) $lines[] = self::esc($channel['name']) . '：' . $money($channel['amount']) . ' / ' . $channel['count'] . ' 笔';
            if (!$income['channels']) $lines[] = '暂无收款';
            if ($income['foreign']) {
                $lines[] = ''; $lines[] = '<b>外币回执（按原币种）</b>';
                foreach ($income['foreign'] as $row) $lines[] = self::esc(StripeCurrency::formatMinor((int)$row->amount, $row->currency) . ' ' . strtoupper($row->currency)) . ' / ' . $row->count . ' 笔';
            }
            $lines = array_merge($lines, ['', '<b>订单付款构成（非新增收款）</b>',
                '余额支付：' . $money($income['balance']), '套餐折抵：' . $money($income['surplus']),
                '手工确认：' . $income['manual_count'] . ' 笔 / ' . $money($income['manual_amount']),
                '其他未入台账付款：' . $income['legacy_count'] . ' 笔 / ' . $money($income['legacy_amount']),
                '', '收款按台账入账时间；金额为系统记账值，含手续费。', '余额、折抵及非台账付款不计入上方确认收款。']);
        } elseif ($view === 'orders') {
            $orders = $data['orders'];
            $lines = array_merge($lines, ['', '新建订单：' . $orders['created'] . ' 笔',
                '其中待支付：' . $orders['unpaid'] . ' 笔', '其中已取消：' . $orders['canceled'] . ' 笔',
                '', '付清订单：' . $orders['paid'] . ' 笔', '成交金额：' . $money($orders['amount'])]);
            foreach ([1=>'套餐新购',2=>'套餐续费',3=>'套餐变更',4=>'流量重置',9=>'余额充值'] as $type=>$label) {
                $row = $orders['types'][$type] ?? ['count'=>0,'amount'=>0];
                $lines[] = $label . '：' . $row['count'] . ' 笔 / ' . $money($row['amount']);
            }
            $lines[] = '\n新建按创建时间，付清按付款时间。';
            $lines[] = '成交金额含余额与折抵、不含手续费，不等于外部收款。';
        } elseif ($view === 'users') {
            $users = $data['users'];
            $lines = array_merge($lines, ['', self::PERIODS[$period] . '注册：' . $users['registered'], '', '<b>当前用户状态</b>',
                '总用户：' . $users['total'], '可用账号：' . $users['available'], '在线用户（近10分钟）：' . $users['online'],
                '已到期：' . $users['expired'], '流量耗尽：' . $users['exhausted'], '未来7天到期：' . $users['expiring'],
                '', '不含管理员及员工；可用账号须未封禁、未到期且有剩余流量。']);
        } else {
            $system = $data['system'];
            $lines = array_merge($lines, ['', '队列：' . $system['horizon'], '调度：' . $system['schedule'],
                '调度最近运行：' . ($system['last_schedule'] ? Carbon::createFromTimestamp($system['last_schedule'], $now->timezoneName)->format('Y-m-d H:i:s') : '暂无记录'),
                $this->failureLine($system), '', '<b>队列负载（积压前10）</b>']);
            if ($system['workload'] === null) $lines[] = '暂时无法获取';
            elseif (!$system['workload']) $lines[] = '暂无队列';
            else foreach ($system['workload'] as $queue) $lines[] = self::esc(Str::limit($queue['name'], 60)) . '：等待 ' . (int)$queue['length'] . '，预计 ' . (int)ceil($queue['wait']) . ' 秒，进程 ' . (int)$queue['processes'];
            $lines[] = '\n运行状态为当前快照，不随统计日期切换。';
        }
        $lines[] = ''; $lines[] = '更新：' . $data['updated_at'] . '（' . self::esc($now->timezoneName) . '）';
        return str_replace('\\n', "\n", implode("\n", $lines));
    }

    private function paid(int $from, int $to)
    {
        return Order::whereIn('status', [1,3,4])->where('paid_at', '>=', $from)->where('paid_at', '<', $to);
    }

    private function orders(int $from, int $to): array
    {
        $created = Order::where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->selectRaw('COUNT(*) AS total, SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) AS unpaid, SUM(CASE WHEN status = 2 THEN 1 ELSE 0 END) AS canceled')->first();
        $types = $this->paid($from, $to)->selectRaw('type, COUNT(*) AS count, SUM(COALESCE(total_amount,0) + COALESCE(balance_amount,0) + COALESCE(surplus_amount,0) - COALESCE(refund_amount,0)) AS amount')
            ->groupBy('type')->get()->keyBy('type')->toArray();
        return ['created'=>(int)$created->total, 'unpaid'=>(int)$created->unpaid, 'canceled'=>(int)$created->canceled,
            'paid'=>array_sum(array_column($types,'count')), 'amount'=>array_sum(array_column($types,'amount')), 'types'=>$types];
    }

    private function receipts(int $from, int $to)
    {
        return DB::table('v2_payment_receipt as r')->leftJoin('v2_order as o','o.id','=','r.order_id')
            ->leftJoin('v2_payment_attempt as a','a.id','=','r.attempt_id')->where('r.created_at','>=',$from)->where('r.created_at','<',$to);
    }

    private function income(int $from, int $to, bool $details): array
    {
        $row = $this->receipts($from, $to)->selectRaw('COUNT(*) AS count, SUM(r.amount) AS amount,
            SUM(CASE WHEN o.type = 9 THEN r.amount ELSE 0 END) AS recharges,
            SUM(CASE WHEN o.type != 9 THEN r.amount ELSE 0 END) AS plans,
            SUM(r.wallet_amount) AS wallet, SUM(COALESCE(a.handling_amount,o.handling_amount,0)) AS fees')->first();
        $data = (array)$row;
        foreach ($data as &$value) $value = (int)$value;
        unset($value);
        $unrecorded = $this->paid($from, $to)->where('total_amount','>',0)->whereNotExists(function ($q) {
            $q->selectRaw('1')->from('v2_payment_receipt as r')->whereColumn('r.order_id','v2_order.id');
        });
        $data['unrecorded_count'] = (clone $unrecorded)->count();
        if (!$details) return $data;
        $channels = $this->receipts($from, $to)->selectRaw('r.payment_id, COUNT(*) AS count, SUM(r.amount) AS amount')
            ->groupBy('r.payment_id')->orderByDesc('amount')->orderBy('r.payment_id')->limit(8)->get();
        $names = Payment::whereIn('id',$channels->pluck('payment_id'))->pluck('name','id');
        $data['channels'] = $channels->map(fn($r) => ['name'=>Str::limit($names[$r->payment_id] ?? '已删除通道 #' . $r->payment_id, 60), 'count'=>(int)$r->count, 'amount'=>(int)$r->amount])->all();
        $data['foreign'] = $this->receipts($from, $to)->whereNotNull('a.gateway_currency')->whereRaw('UPPER(a.gateway_currency) <> ?', [strtoupper((string)config('v2board.currency','CNY'))])
            ->selectRaw('UPPER(a.gateway_currency) AS currency, COUNT(*) AS count, SUM(a.gateway_amount_minor) AS amount')->groupByRaw('UPPER(a.gateway_currency)')->orderBy('currency')->limit(10)->get()->all();
        $offsets = $this->paid($from, $to)->selectRaw('SUM(balance_amount) AS balance, SUM(COALESCE(surplus_amount,0)-COALESCE(refund_amount,0)) AS surplus')->first();
        $data['balance'] = (int)$offsets->balance; $data['surplus'] = (int)$offsets->surplus;
        $manual = (clone $unrecorded)->where('callback_no','manual_operation')->selectRaw('COUNT(*) AS count, SUM(total_amount) AS amount')->first();
        $legacy = (clone $unrecorded)->where(fn($q) => $q->whereNull('callback_no')->orWhere('callback_no','!=','manual_operation'))
            ->selectRaw('COUNT(*) AS count, SUM(total_amount) AS amount')->first();
        $data['manual_count'] = (int)$manual->count; $data['manual_amount'] = (int)$manual->amount;
        $data['legacy_count'] = (int)$legacy->count; $data['legacy_amount'] = (int)$legacy->amount;
        return $data;
    }

    private function users(int $from, int $to, int $now): array
    {
        $row = User::where('is_admin',0)->where('is_staff',0)->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END) AS registered',[$from,$to])
            ->selectRaw('SUM(CASE WHEN banned = 0 AND (expired_at IS NULL OR expired_at > ?) AND transfer_enable > u+d THEN 1 ELSE 0 END) AS available',[$now])
            ->selectRaw('SUM(CASE WHEN banned = 0 AND t >= ? AND t <= ? THEN 1 ELSE 0 END) AS online',[$now-600,$now])
            ->selectRaw('SUM(CASE WHEN expired_at IS NOT NULL AND expired_at <= ? THEN 1 ELSE 0 END) AS expired',[$now])
            ->selectRaw('SUM(CASE WHEN banned = 0 AND (expired_at IS NULL OR expired_at > ?) AND transfer_enable > 0 AND transfer_enable <= u+d THEN 1 ELSE 0 END) AS exhausted',[$now])
            ->selectRaw('SUM(CASE WHEN banned = 0 AND expired_at > ? AND expired_at <= ? THEN 1 ELSE 0 END) AS expiring',[$now,$now+7*86400])->first();
        return array_map('intval',$row->getAttributes());
    }

    private function system(int $now): array
    {
        $data = ['horizon'=>'暂时无法获取','schedule'=>'暂时无法获取','last_schedule'=>null,'failed'=>null,
            'failed_minutes'=>(int)config('horizon.trim.recent_failed',config('horizon.trim.failed',10080)), 'workload'=>null];
        try {
            $masters = collect(app(MasterSupervisorRepository::class)->all()); $paused = $masters->where('status','paused')->count();
            $data['horizon'] = !$masters->count() ? '未运行' : ($paused === $masters->count() ? '已暂停' : ($paused ? '部分暂停' : '运行中'));
        } catch (\Throwable $error) { /* An unavailable monitor must not hide financial statistics. */ }
        try {
            $last = Cache::get(CacheKey::get('SCHEDULE_LAST_CHECK_AT',null)); $data['last_schedule'] = $last ? (int)$last : null;
            $data['schedule'] = $last && (int)$last > $now-120 ? '正常' : '未运行或已超时';
        } catch (\Throwable $error) { }
        try { $data['failed'] = app(JobRepository::class)->countRecentlyFailed(); } catch (\Throwable $error) { }
        try { $data['workload'] = collect(app(WorkloadRepository::class)->get())->sortByDesc('length')->take(10)->values()->all(); } catch (\Throwable $error) { }
        return $data;
    }

    private function failureLine(array $data): string
    {
        $minutes = $data['failed_minutes'];
        $period = $minutes > 0 && $minutes % 1440 === 0 ? intdiv($minutes,1440) . '天' : $minutes . '分钟';
        return '失败任务（近' . $period . '）：' . ($data['failed'] === null ? '暂时无法获取' : $data['failed']);
    }

    private static function esc(string $text): string { return htmlspecialchars($text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
}
