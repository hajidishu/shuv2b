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
        foreach ([
            'v2_subscribe_audit' => ['id', 'event_id', 'user_id', 'email', 'ip', 'ua', 'format', 'result', 'status', 'requested_at', 'request_details'],
            'v2_subscribe_audit_setting' => ['id', 'retention_days', 'max_records', 'record_count', 'updated_at'],
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
    }
}
