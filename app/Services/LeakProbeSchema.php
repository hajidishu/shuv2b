<?php
namespace App\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

class LeakProbeSchema
{
    public function ensure()
    {
        $schema = DB::connection()->getSchemaBuilder();
        if (!$schema->hasTable('v2_leak_probe_task')) $schema->create('v2_leak_probe_task', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->string('name', 120); $t->string('status', 20);
            $t->unsignedBigInteger('current_round_id')->nullable(); $t->string('final_branch', 1)->nullable();
            $t->unsignedInteger('observation_hours')->default(24); $t->unsignedInteger('member_count');
            $t->unsignedBigInteger('created_at'); $t->unsignedBigInteger('updated_at'); $t->unsignedBigInteger('closed_at')->nullable();
            $t->index(['status', 'id']);
        });
        if (!$schema->hasTable('v2_leak_probe_round')) $schema->create('v2_leak_probe_round', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->unsignedBigInteger('task_id'); $t->unsignedBigInteger('parent_id')->nullable();
            $t->unsignedInteger('sequence'); $t->string('selected_from', 1)->nullable();
            $t->unsignedBigInteger('a_node_id'); $t->unsignedBigInteger('b_node_id');
            $t->text('a_snapshot'); $t->text('b_snapshot');
            $t->unsignedBigInteger('a_pull_at')->nullable(); $t->unsignedBigInteger('b_pull_at')->nullable();
            $t->string('result_a', 16)->default('unknown'); $t->string('result_b', 16)->default('unknown');
            $t->text('note')->nullable(); $t->unsignedBigInteger('checked_at')->nullable();
            $t->unsignedBigInteger('created_at'); $t->unsignedBigInteger('closed_at')->nullable();
            $t->unique(['task_id', 'sequence']);
        });
        if (!$schema->hasTable('v2_leak_probe_member')) $schema->create('v2_leak_probe_member', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->unsignedBigInteger('round_id'); $t->unsignedInteger('user_id');
            $t->string('email', 64); $t->string('branch', 1);
            $t->unsignedBigInteger('first_seen_at')->nullable(); $t->unsignedBigInteger('last_seen_at')->nullable();
            $t->string('last_source', 32)->nullable();
            $t->unique(['round_id', 'user_id']); $t->index(['round_id', 'branch']); $t->index(['user_id', 'round_id']);
        });
        if (!$schema->hasTable('v2_leak_probe_node')) $schema->create('v2_leak_probe_node', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->string('node_type', 20); $t->unsignedInteger('node_id');
            $t->char('fingerprint', 64); $t->char('host_key', 64)->unique(); $t->string('state', 16)->default('ready');
            $t->unsignedBigInteger('last_pull_at')->nullable(); $t->unsignedBigInteger('last_pull_round_id')->nullable();
            $t->unsignedBigInteger('created_at');
            $t->unique(['node_type', 'node_id']);
        });
        if (!$schema->hasTable('v2_leak_probe_active_user')) $schema->create('v2_leak_probe_active_user', function (Blueprint $t) {
            $t->unsignedInteger('user_id')->primary(); $t->unsignedBigInteger('task_id')->index();
        });
        if (!$schema->hasTable('v2_leak_probe_event')) $schema->create('v2_leak_probe_event', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->unsignedBigInteger('task_id'); $t->unsignedBigInteger('round_id')->nullable();
            $t->string('action', 32); $t->string('actor_email', 64); $t->text('note')->nullable(); $t->unsignedBigInteger('created_at');
            $t->index(['task_id', 'id']);
        });
        foreach ([
            'task' => ['id','name','status','current_round_id','final_branch','observation_hours','member_count','created_at','updated_at','closed_at'],
            'round' => ['id','task_id','parent_id','sequence','selected_from','a_node_id','b_node_id','a_snapshot','b_snapshot','a_pull_at','b_pull_at','result_a','result_b','note','checked_at','created_at','closed_at'],
            'member' => ['id','round_id','user_id','email','branch','first_seen_at','last_seen_at','last_source'],
            'node' => ['id','node_type','node_id','fingerprint','host_key','state','last_pull_at','last_pull_round_id','created_at'],
            'active_user' => ['user_id','task_id'], 'event' => ['id','task_id','round_id','action','actor_email','note','created_at'],
        ] as $table => $columns) foreach ($columns as $column) {
            if (!$schema->hasColumn('v2_leak_probe_' . $table, $column)) throw new \RuntimeException('内鬼排查结构不完整：' . $table . '.' . $column);
        }
        // Old releases retired every closed-round node. Restore availability, preserving history.
        DB::table('v2_leak_probe_node')->where('state', 'retired')->update(['state' => 'ready']);
    }
}
