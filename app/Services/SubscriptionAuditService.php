<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class SubscriptionAuditService
{
    const TABLE = 'v2_subscribe_audit';
    const SETTINGS = 'v2_subscribe_audit_setting';
    const TOTALS = 'v2_subscribe_audit_total';
    const LEGACY_EVENTS = 'v2_subscribe_audit_count_event';
    const CHUNK = 1000;

    /** Lifetime totals are independent of retained request details. */
    public function countsForUsers(array $ids): array
    {
        if (!$ids) return [];
        return DB::table(self::TOTALS)->whereIn('user_id', $ids)->pluck('pull_count', 'user_id')->all();
    }

    /** A primary-key lookup, including zero for users with no pulls. */
    public function userCountQuery()
    {
        return DB::table(self::TOTALS)->selectRaw('COALESCE(MAX(pull_count), 0)')
            ->whereColumn(self::TOTALS . '.user_id', 'v2_user.id');
    }

    public function recordPull(int $userId): void
    {
        if ($userId < 1) throw new \InvalidArgumentException('Invalid subscription user');
        $grammar = DB::connection()->getQueryGrammar();
        $column = $grammar->wrapTable(self::TOTALS) . '.' . $grammar->wrap('pull_count');
        DB::table(self::TOTALS)->upsert([['user_id' => $userId, 'pull_count' => 1]], ['user_id'], [
            'pull_count' => DB::raw($column . ' + 1'),
        ]);
    }

    public function settings()
    {
        $settings = DB::table(self::SETTINGS)->where('id', 1)->first();
        if (!$settings) throw new \RuntimeException('请先执行 php artisan subscribe-audit:install');
        return $settings;
    }

    public function append(array $event)
    {
        $counted = (bool)($event['count_recorded'] ?? false);
        $event['count_recorded'] = 1;
        DB::transaction(function () use ($event, $counted) {
            $settings = DB::table(self::SETTINGS)->where('id', 1)->lockForUpdate()->first();
            if (!$settings) throw new \RuntimeException('订阅审计尚未安装');
            if (!$counted) {
                // Upgrade-era jobs and failed synchronous writes use an anonymous receipt.
                // New requests are already counted and do not create these receipts.
                if (!DB::table(self::LEGACY_EVENTS)->insertOrIgnore(['event_id' => $event['event_id']])) return;
                $this->recordPull((int)$event['user_id']);
            }
            // A delayed/retried event must retain its original time and cannot resurrect expired data.
            if ($event['requested_at'] < time() - (int)$settings->retention_days * 86400) return;
            $inserted = DB::table(self::TABLE)->insertOrIgnore($event);
            $settings->record_count += $inserted;
            // Normally removes at most one row. Large reductions of an existing limit are also
            // drained by prune() in small committed transactions, rather than one huge DELETE.
            $this->trimLocked($settings);
        }, 3);
    }

    private function trimLocked($settings)
    {
        $excess = max(0, (int)$settings->record_count - (int)$settings->max_records);
        $query = DB::table(self::TABLE);
        if (!$excess) $query->where('requested_at', '<', time() - (int)$settings->retention_days * 86400);
        $ids = $query->orderBy('requested_at')->orderBy('id')
            ->limit($excess ? min($excess, self::CHUNK) : self::CHUNK)->pluck('id')->all();
        $deleted = $ids ? DB::table(self::TABLE)->whereIn('id', $ids)->delete() : 0;
        DB::table(self::SETTINGS)->where('id', 1)->update([
            'record_count' => max(0, (int)$settings->record_count - $deleted),
        ]);
        return $deleted;
    }

    public function prune($seconds = 20)
    {
        $deadline = microtime(true) + $seconds;
        $total = 0;
        do {
            $deleted = DB::transaction(function () {
                $settings = DB::table(self::SETTINGS)->where('id', 1)->lockForUpdate()->first();
                if (!$settings) throw new \RuntimeException('订阅审计尚未安装');
                return $this->trimLocked($settings);
            }, 3);
            $total += $deleted;
        } while ($deleted && microtime(true) < $deadline);
        return $total;
    }

    public function configure($days, $maximum)
    {
        DB::transaction(function () use ($days, $maximum) {
            if (!DB::table(self::SETTINGS)->where('id', 1)->lockForUpdate()->first()) {
                throw new \RuntimeException('订阅审计尚未安装');
            }
            DB::table(self::SETTINGS)->where('id', 1)->update([
                'retention_days' => $days, 'max_records' => $maximum, 'updated_at' => time(),
            ]);
        }, 3);
    }
}
