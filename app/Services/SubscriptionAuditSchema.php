<?php

namespace App\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

class SubscriptionAuditSchema
{
    public function ensure()
    {
        $schema = DB::connection()->getSchemaBuilder();
        if (!$schema->hasTable('v2_subscribe_audit')) {
            $schema->create('v2_subscribe_audit', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->char('event_id', 32)->unique('subscribe_audit_event');
                $table->unsignedInteger('user_id');
                $table->string('email', 64);
                $table->string('ip', 45);
                $table->string('ua', 512);
                $table->string('format', 64)->default('');
                $table->string('result', 16);
                $table->unsignedSmallInteger('status');
                $table->unsignedBigInteger('requested_at');
                $table->index(['user_id', 'requested_at', 'id'], 'subscribe_audit_user_time');
                $table->index(['email', 'requested_at'], 'subscribe_audit_email_time');
                $table->index(['ip', 'requested_at'], 'subscribe_audit_ip_time');
                $table->index(['requested_at', 'id'], 'subscribe_audit_time');
            });
        }
        if (!$schema->hasTable('v2_subscribe_audit_setting')) {
            $schema->create('v2_subscribe_audit_setting', function (Blueprint $table) {
                $table->unsignedInteger('id')->primary();
                $table->unsignedInteger('retention_days')->default(30);
                $table->unsignedBigInteger('max_records')->default(1000000);
                $table->unsignedBigInteger('record_count')->default(0);
                $table->unsignedBigInteger('updated_at');
            });
        }
        // Nullable append-only metadata upgrade: older records and queued jobs remain valid.
        if (!$schema->hasColumn('v2_subscribe_audit', 'request_details')) {
            $schema->table('v2_subscribe_audit', function (Blueprint $table) {
                $table->text('request_details')->nullable();
            });
        }
        if (!$schema->hasColumn(SubscriptionAuditService::TABLE, 'count_recorded')) {
            $schema->table(SubscriptionAuditService::TABLE, function (Blueprint $table) {
                $table->boolean('count_recorded')->default(0);
            });
        }
        if (!$schema->hasTable(SubscriptionAuditService::TOTALS)) {
            $schema->create(SubscriptionAuditService::TOTALS, function (Blueprint $table) {
                $table->unsignedInteger('user_id')->primary();
                $table->unsignedBigInteger('pull_count')->default(0);
            });
        }
        if (!$schema->hasTable(SubscriptionAuditService::LEGACY_EVENTS)) {
            $schema->create(SubscriptionAuditService::LEGACY_EVENTS, function (Blueprint $table) {
                // Random deduplication IDs only, with no identity or request metadata.
                $table->char('event_id', 32)->primary();
            });
        }
        foreach ([
            'v2_subscribe_audit' => ['id', 'event_id', 'user_id', 'email', 'ip', 'ua', 'format', 'result', 'status', 'requested_at', 'request_details', 'count_recorded'],
            'v2_subscribe_audit_setting' => ['id', 'retention_days', 'max_records', 'record_count', 'updated_at'],
            SubscriptionAuditService::TOTALS => ['user_id', 'pull_count'],
            SubscriptionAuditService::LEGACY_EVENTS => ['event_id'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                if (!$schema->hasColumn($table, $column)) {
                    throw new \RuntimeException("订阅审计数据库结构不完整：{$table}.{$column}");
                }
            }
        }
        $expected = [
            'subscribe_audit_event' => ['event_id'],
            'subscribe_audit_user_time' => ['user_id', 'requested_at', 'id'],
            'subscribe_audit_email_time' => ['email', 'requested_at'],
            'subscribe_audit_ip_time' => ['ip', 'requested_at'],
            'subscribe_audit_time' => ['requested_at', 'id'],
        ];
        $indexes = [];
        if (DB::connection()->getDriverName() === 'mysql') {
            foreach (DB::select('SHOW INDEX FROM v2_subscribe_audit') as $index) {
                $indexes[$index->Key_name][(int)$index->Seq_in_index - 1] = $index->Column_name;
                if ($index->Key_name === 'subscribe_audit_event' && (int)$index->Non_unique !== 0) {
                    throw new \RuntimeException('订阅审计事件索引必须唯一');
                }
            }
        } else {
            foreach ($expected as $name => $columns) {
                foreach (DB::select('PRAGMA index_info(' . $name . ')') as $index) {
                    $indexes[$name][(int)$index->seqno] = $index->name;
                }
            }
        }
        foreach ($expected as $name => $columns) {
            $actual = $indexes[$name] ?? [];
            ksort($actual);
            if (array_values($actual) !== $columns) throw new \RuntimeException("订阅审计索引不完整：{$name}");
        }
        DB::table('v2_subscribe_audit_setting')->insertOrIgnore([
            'id' => 1, 'retention_days' => 30, 'max_records' => 1000000,
            'record_count' => DB::table('v2_subscribe_audit')->count(), 'updated_at' => time(),
        ]);
        $this->backfillCounts();
    }

    private function backfillCounts(): void
    {
        DB::transaction(function () {
            DB::table(SubscriptionAuditService::SETTINGS)->where('id', 1)->lockForUpdate()->first();
            $grammar = DB::connection()->getQueryGrammar();
            $audit = $grammar->wrapTable(SubscriptionAuditService::TABLE);
            $totals = $grammar->wrapTable(SubscriptionAuditService::TOTALS);
            $seen = $grammar->wrapTable(SubscriptionAuditService::LEGACY_EVENTS);
            $mysql = DB::connection()->getDriverName() === 'mysql';
            $suffix = $mysql
                ? ' ON DUPLICATE KEY UPDATE pull_count = ' . $totals . '.pull_count + VALUES(pull_count)'
                : ' ON CONFLICT(user_id) DO UPDATE SET pull_count = ' . $totals . '.pull_count + excluded.pull_count';
            DB::statement('INSERT INTO ' . $totals . ' (user_id, pull_count) '
                . 'SELECT a.user_id, COUNT(*) FROM ' . $audit . ' AS a WHERE a.count_recorded = 0 '
                . 'AND NOT EXISTS (SELECT 1 FROM ' . $seen . ' AS s WHERE s.event_id = a.event_id) GROUP BY a.user_id' . $suffix);
            DB::statement(($mysql ? 'INSERT IGNORE' : 'INSERT OR IGNORE') . ' INTO ' . $seen
                . ' (event_id) SELECT event_id FROM ' . $audit . ' WHERE count_recorded = 0');
            DB::table(SubscriptionAuditService::TABLE)->where('count_recorded', 0)->update(['count_recorded' => 1]);
            // Repair detail-retention bookkeeping after a manual detail reset.
            DB::table(SubscriptionAuditService::SETTINGS)->where('id', 1)->update([
                'record_count' => DB::table(SubscriptionAuditService::TABLE)->count(),
            ]);
        }, 3);
    }
}
