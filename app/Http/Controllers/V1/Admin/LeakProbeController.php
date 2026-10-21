<?php
namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LeakProbeInput;
use App\Services\LeakProbeService as Probe;
use App\Services\ServerService;
use Illuminate\Support\Facades\DB;

class LeakProbeController extends Controller
{
    public function fetch(LeakProbeInput $request)
    {
        $query = DB::table(Probe::TASK); $total = $query->count();
        return ['data' => $query->orderByDesc('id')->offset(((int)$request->input('current', 1) - 1) * 20)->limit(20)->get(), 'total' => $total];
    }

    public function detail(LeakProbeInput $request)
    {
        $task = DB::table(Probe::TASK)->where('id', $request->input('id'))->first();
        if (!$task) abort(404, '任务不存在或已清理');
        $rounds = DB::table(Probe::ROUND)->where('task_id', $task->id)->orderBy('sequence')->get();
        foreach ($rounds as $round) {
            foreach (['a','b'] as $branch) {
                $round->{$branch . '_snapshot'} = json_decode($round->{$branch . '_snapshot'}, true);
                $base = DB::table(Probe::MEMBER)->where('round_id', $round->id)->where('branch', $branch);
                $round->{$branch . '_count'} = (clone $base)->count();
                $round->{$branch . '_seen'} = (clone $base)->whereNotNull('first_seen_at')->count();
                $round->{$branch . '_pull'} = $round->{$branch . '_pull_at'} !== null;
            }
        }
        return ['data' => $task, 'rounds' => $rounds,
            'candidates' => $task->status === 'identified' ? DB::table(Probe::MEMBER)->where('round_id', $task->current_round_id)->where('branch', $task->final_branch)->get(['email','user_id','first_seen_at','last_seen_at']) : [],
            'events' => DB::table(Probe::EVENT)->where('task_id', $task->id)->orderByDesc('id')->limit(100)->get()];
    }

    public function members(LeakProbeInput $request)
    {
        $query = DB::table(Probe::MEMBER)->where('round_id', $request->input('round_id'));
        if ($request->filled('branch')) $query->where('branch', $request->input('branch'));
        if ($request->filled('email')) $query->whereRaw("email LIKE ? ESCAPE '!'", ['%' . str_replace(['!','%','_'], ['!!','!%','!_'], $request->input('email')) . '%']);
        $total = (clone $query)->count(); $size = (int)$request->input('pageSize', 20);
        return ['data' => $query->orderBy('branch')->orderBy('id')->offset(((int)$request->input('current', 1) - 1) * $size)->limit($size)->get(), 'total' => $total];
    }

    public function preview(LeakProbeInput $request, Probe $probe)
    {
        return ['data' => $probe->preview($request->validated())];
    }

    public function create(LeakProbeInput $request, Probe $probe)
    {
        return ['data' => ['id' => $probe->create($request->validated(), $request->user['email'])]];
    }

    public function update(LeakProbeInput $request, Probe $probe)
    {
        return ['data' => ['id' => $probe->mutate($request->validated(), $request->user['email'])]];
    }

    public function nodes(LeakProbeInput $request, Probe $probe)
    {
        if ($request->method() === 'POST') {
            if ($request->input('action') === 'register') return ['data' => $probe->registerNode($request->input('node_type'), (int)$request->input('node_id'))];
            return DB::transaction(function () use ($request) {
                $node = DB::table(Probe::NODE)->where('id', $request->input('id'))->lockForUpdate()->first();
                if (!$node) abort(404, '登记不存在');
                if ($node->state === 'active') abort(409, '请先结束使用此节点的任务');
                DB::table(Probe::NODE)->where('id', $node->id)->delete();
                return ['data' => true];
            });
        }
        $nodes = DB::table(Probe::NODE)->orderByDesc('id')->get();
        foreach ($nodes as $node) {
            try { $node->server = $probe->snapshot($node->node_type, $node->node_id); $node->config_matches = $node->fingerprint === $node->server['fingerprint']; }
            catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) { $node->server = null; $node->config_matches = false; }
        }
        $available = [];
        foreach ((new ServerService())->getAllServers() as $server) $available[] = [
            'node_type' => $server['type'], 'node_id' => $server['id'], 'name' => $server['name'], 'host' => $server['host'], 'port' => $server['port'],
        ];
        return ['data' => $nodes, 'available' => $available, 'history_days' => config('leak_probe.history_days', 90)];
    }
}
