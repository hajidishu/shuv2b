<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SubscriptionAuditFetch;
use App\Http\Requests\Admin\SubscriptionAuditDetail;
use App\Jobs\PruneSubscriptionAuditJob;
use App\Services\SubscriptionAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class SubscriptionAuditController extends Controller
{
    public function fetch(SubscriptionAuditFetch $request, SubscriptionAuditService $audit)
    {
        $settings = $audit->settings();
        $query = $this->filtered($request);
        $total = (clone $query)->count();
        $size = (int)$request->input('pageSize', 20);
        $page = (int)$request->input('current', 1);
        $records = $query->select(['id', 'user_id', 'email', 'ip', 'ua', 'format', 'result', 'status', 'requested_at'])
            ->selectRaw('CASE WHEN request_details IS NULL THEN 0 ELSE 1 END AS has_request_details')
            ->orderByDesc('requested_at')->orderByDesc('id')
            ->offset(($page - 1) * $size)->limit($size)->get();
        return ['data' => $records, 'total' => $total, 'settings' => $this->publicSettings($settings)];
    }

    private function filtered(SubscriptionAuditFetch $request)
    {
        $query = DB::table(SubscriptionAuditService::TABLE);
        foreach ($request->input('filter', []) as $filter) {
            $key = $filter['key']; $value = $filter['value']; $operator = $filter['condition'];
            if ($key === 'ip' && $operator !== '模糊' && filter_var($value, FILTER_VALIDATE_IP)) {
                $value = inet_ntop(inet_pton($value));
            }
            if ($operator === '模糊') {
                // Escape user-supplied LIKE wildcards using a fixed SQL escape character.
                $literal = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], (string)$value);
                $query->whereRaw(DB::connection()->getQueryGrammar()->wrap($key) . " LIKE ? ESCAPE '!'", ['%' . $literal . '%']);
            } else $query->where($key, $operator, $value);
        }
        return $query;
    }

    public function detail(SubscriptionAuditDetail $request)
    {
        $record = DB::table(SubscriptionAuditService::TABLE)->where('id', $request->input('id'))->first();
        if (!$record) abort(404, '记录不存在或已按保留规则清理');
        $details = json_decode($record->request_details ?? '', true);
        $record->request_details = is_array($details) ? $details : null;
        $user = \App\Models\User::select(['id', 'token'])->find($record->user_id);
        $record->current_subscribe_url = $user && $user->token ? \App\Utils\Helper::getSubscribeUrl($user->token) : null;
        return ['data' => $record];
    }

    public function stats(SubscriptionAuditFetch $request, SubscriptionAuditService $audit)
    {
        $settings = $audit->settings();
        $dimension = $request->input('dimension', 'ip');
        $key = 'subscription_audit_stats_v2_' . hash('sha256', json_encode([
            $request->input('filter', []), $dimension, $settings->updated_at, $settings->retention_days, $settings->max_records,
        ]));
        if (!$request->boolean('fresh')) {
            try { if ($cached = Cache::get($key)) return ['data' => $cached]; } catch (\Throwable $ignored) {}
        }
        $query = $this->filtered($request);
        $ua = DB::connection()->getDriverName() === 'mysql' ? 'BINARY ua' : 'ua COLLATE BINARY';
        $summary = (clone $query)->selectRaw("COUNT(*) AS total, COUNT(DISTINCT user_id) AS users, COUNT(DISTINCT NULLIF(ip, '')) AS ips, COUNT(DISTINCT NULLIF({$ua}, '')) AS uas")->first();
        $column = $dimension === 'user' ? 'user_id' : ($dimension === 'ua' ? $ua : 'ip');
        $rows = (clone $query)->selectRaw("{$column} AS value, COUNT(*) AS count, MAX(requested_at) AS last_at")
            ->groupBy(DB::raw($column))->orderByDesc('count')->orderBy('value')->limit(10)->get();
        $emails = $dimension === 'user' ? DB::table('v2_user')->whereIn('id', $rows->pluck('value')->all())->pluck('email', 'id') : [];
        $ranking = [];
        foreach ($rows as $row) {
            $label = $row->value;
            if ($dimension === 'user') {
                $label = $emails[$row->value] ?? (clone $query)->where('user_id', $row->value)->orderByDesc('requested_at')->orderByDesc('id')->value('email');
            }
            $ranking[] = ['value' => $row->value, 'label' => $label ?: ($dimension === 'ua' ? '未提供 UA' : ($dimension === 'ip' ? '未知 IP' : '邮箱未记录')),
                'count' => (int)$row->count, 'last_at' => (int)$row->last_at];
        }
        $data = ['dimension' => $dimension, 'total' => (int)$summary->total, 'users' => (int)$summary->users,
            'ips' => (int)$summary->ips, 'uas' => (int)$summary->uas, 'ranking' => $ranking, 'generated_at' => time()];
        try { Cache::put($key, $data, 60); } catch (\Throwable $ignored) {}
        return ['data' => $data];
    }

    public function settings(SubscriptionAuditService $audit)
    {
        return ['data' => $this->publicSettings($audit->settings())];
    }

    public function saveSettings(Request $request, SubscriptionAuditService $audit)
    {
        $values = $request->validate([
            'retention_days' => 'required|integer|min:1|max:3650',
            'max_records' => 'required|integer|min:1|max:100000000',
        ]);
        $audit->configure((int)$values['retention_days'], (int)$values['max_records']);
        try {
            PruneSubscriptionAuditJob::dispatch();
        } catch (\Throwable $error) {
            Log::warning('Subscription audit cleanup enqueue failed; scheduled cleanup will retry');
        }
        return ['data' => $this->publicSettings($audit->settings())];
    }

    private function publicSettings($settings)
    {
        return [
            'retention_days' => (int)$settings->retention_days,
            'max_records' => (int)$settings->max_records,
        ];
    }
}
