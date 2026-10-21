<?php
namespace App\Console\Commands;
use App\Services\LeakProbeService as Probe;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
class LeakProbePrune extends Command
{
    protected $signature = 'leak-probe:prune';
    protected $description = '清理结束后的内鬼排查历史，保留专用节点登记';
    public function handle()
    {
        if (!Probe::installed()) return 0;
        $ids = DB::table(Probe::TASK)->whereIn('status', ['completed','identified'])->where('closed_at', '<', time() - (int)config('leak_probe.history_days', 90) * 86400)->limit(100)->pluck('id')->all();
        foreach ($ids as $id) DB::transaction(function () use ($id) {
            $task = DB::table(Probe::TASK)->where('id', $id)->lockForUpdate()->first();
            if (!$task || !in_array($task->status, ['completed','identified'], true)) return;
            $rounds = DB::table(Probe::ROUND)->where('task_id', $id)->pluck('id')->all();
            foreach (array_chunk($rounds, 100) as $chunk) DB::table(Probe::MEMBER)->whereIn('round_id', $chunk)->delete();
            DB::table(Probe::EVENT)->where('task_id', $id)->delete(); DB::table(Probe::ROUND)->where('task_id', $id)->delete();
            DB::table(Probe::ACTIVE)->where('task_id', $id)->delete(); DB::table(Probe::TASK)->where('id', $id)->delete();
        });
        $this->info('已清理 ' . count($ids) . ' 个结束的排查任务'); return 0;
    }
}
