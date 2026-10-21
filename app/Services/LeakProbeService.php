<?php
namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class LeakProbeService
{
    private $pulledNode;
    const TASK = 'v2_leak_probe_task';
    const ROUND = 'v2_leak_probe_round';
    const MEMBER = 'v2_leak_probe_member';
    const NODE = 'v2_leak_probe_node';
    const ACTIVE = 'v2_leak_probe_active_user';
    const EVENT = 'v2_leak_probe_event';
    const TYPES = ['shadowsocks','vmess','trojan','vless','hysteria','tuic','anytls','v2node'];

    public static function installed()
    {
        return DB::connection()->getSchemaBuilder()->hasTable(self::NODE);
    }

    public function snapshot($type, $id)
    {
        if (!in_array($type, self::TYPES, true)) abort(422, '节点类型无效');
        $node = (new ServerService())->getServer($id, $type);
        if (!$node) abort(422, '节点不存在');
        $host = strtolower(trim((string)$node->host, '[] '));
        $packed = @inet_pton($host);
        if ($packed !== false) $host = inet_ntop($packed);
        return ['node_type' => $type, 'node_id' => (int)$node->id, 'name' => $node->name, 'host' => $host,
            'port' => (string)$node->port, 'server_port' => (int)$node->server_port, 'group_id' => $node->group_id ?? [],
            'show' => (int)$node->show, 'parent_id' => $node->parent_id,
            'fingerprint' => hash('sha256', json_encode([$type, $host, (string)$node->port, (int)$node->server_port, (int)$node->parent_id, (int)$node->show, $node->network, $node->tls, $node->protocol])),
            'host_key' => hash('sha256', $host)];
    }

    public function registerNode($type, $id)
    {
        $snapshot = $this->snapshot($type, $id);
        if ($snapshot['parent_id'] || !$snapshot['show'] || !$snapshot['host']) abort(422, '请选择启用且没有父节点的独立节点');
        foreach (self::TYPES as $otherType) {
            $class = [
                'shadowsocks' => \App\Models\ServerShadowsocks::class, 'vmess' => \App\Models\ServerVmess::class,
                'trojan' => \App\Models\ServerTrojan::class, 'vless' => \App\Models\ServerVless::class,
                'hysteria' => \App\Models\ServerHysteria::class, 'tuic' => \App\Models\ServerTuic::class,
                'anytls' => \App\Models\ServerAnytls::class, 'v2node' => \App\Models\ServerV2node::class,
            ][$otherType];
            $query = $class::query();
            if ($otherType === $type) {
                if ((clone $query)->where('parent_id', $id)->exists()) abort(422, '该节点有子节点，不能作为独立排查入口');
                $query->where('id', '!=', $id);
            }
            if ($query->whereRaw('LOWER(host) = ?', [$snapshot['host']])->exists()) abort(422, '其他节点共用此入口，请使用独立节点');
        }
        try {
            return DB::table(self::NODE)->insertGetId(['node_type' => $type, 'node_id' => $id, 'fingerprint' => $snapshot['fingerprint'],
                'host_key' => $snapshot['host_key'], 'state' => 'ready', 'created_at' => time()]);
        } catch (\Illuminate\Database\QueryException $error) {
            if ($error->getCode() === '23000' || $error->getCode() === '23505') abort(409, '节点或入口已登记');
            throw $error;
        }
    }

    public function eligible(array $input)
    {
        $query = User::query()->where('banned', 0)->where('is_admin', 0)->whereNotNull('group_id')->whereRaw('u + d < transfer_enable')
            ->where(function ($q) { $q->whereNull('expired_at')->orWhere('expired_at', '>=', time()); })
            ->whereExists(function ($q) { $q->selectRaw('1')->from('v2_plan')->whereColumn('v2_plan.id', 'v2_user.plan_id'); });
        $scope = $input['scope'] ?? 'all';
        if ($scope === 'plans') $query->whereIn('plan_id', $input['plan_ids'] ?? []);
        if ($scope === 'emails') {
            $emails = array_values(array_unique(preg_split('/[\s,;，；]+/u', trim($input['emails'] ?? ''), -1, PREG_SPLIT_NO_EMPTY)));
            if (!$emails || count($emails) > 10000) abort(422, '邮箱清单为空或超过10000条');
            $query->whereIn('email', $emails);
        }
        if ($scope === 'filters') foreach ($input['filter'] ?? [] as $f) {
            if ($f['condition'] === '模糊') {
                $query->whereRaw('email LIKE ? ESCAPE \'!\'', ['%' . str_replace(['!','%','_'], ['!!','!%','!_'], $f['value']) . '%']);
            } else $query->where($f['key'], $f['condition'], $f['value'] === 'null' ? null : $f['value']);
        }
        return $query;
    }

    private function event($taskId, $roundId, $action, $actor, $note = '')
    {
        DB::table(self::EVENT)->insert(['task_id' => $taskId, 'round_id' => $roundId, 'action' => $action,
            'actor_email' => mb_substr($actor, 0, 64, 'UTF-8'), 'note' => $note, 'created_at' => time()]);
    }

    public function preview(array $input)
    {
        $members = $this->eligible($input)->orderBy('id')->limit(10001)->get(['id','email','group_id']);
        return ['count' => count($members), 'hash' => hash('sha256', $members->toJson()), 'sample' => $members->take(20)->pluck('email')->all()];
    }

    private function newRound($task, $members, $a, $b, $parent = null, $branch = null)
    {
        $pool = DB::table(self::NODE)->whereIn('id', [$a, $b])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($a === $b || count($pool) !== 2) abort(422, '请选择两个不同的专用节点');
        $snapshots = [];
        foreach ([$a, $b] as $nodeId) {
            $node = $pool[$nodeId];
            if (!in_array($node->state, ['ready','retired'], true)) abort(409, '所选节点正在被任务占用，请选择空闲节点');
            $snapshot = $this->snapshot($node->node_type, $node->node_id);
            if (!$snapshot['show'] || $snapshot['parent_id'] || $snapshot['fingerprint'] !== $node->fingerprint) abort(409, '节点配置已变化，请移出专用池后重新登记');
            $snapshots[] = $snapshot;
        }
        if ($snapshots[0]['host_key'] === $snapshots[1]['host_key']) abort(422, '两个节点不能共用入口');
        $id = DB::table(self::ROUND)->insertGetId(['task_id' => $task->id, 'parent_id' => $parent ? $parent->id : null,
            'sequence' => $parent ? $parent->sequence + 1 : 1, 'selected_from' => $branch,
            'a_node_id' => $a, 'b_node_id' => $b, 'a_snapshot' => json_encode($snapshots[0]), 'b_snapshot' => json_encode($snapshots[1]), 'created_at' => time()]);
        $ids = array_keys($members->keyBy('id')->all());
        for ($i = count($ids) - 1; $i > 0; $i--) {
            $j = random_int(0, $i); $temporary = $ids[$i]; $ids[$i] = $ids[$j]; $ids[$j] = $temporary;
        }
        $lookup = $members->keyBy('id'); $cut = (int)ceil(count($ids) / 2); $rows = [];
        foreach ($ids as $i => $userId) $rows[] = ['round_id' => $id, 'user_id' => $userId, 'email' => $lookup[$userId]->email, 'branch' => $i < $cut ? 'a' : 'b'];
        foreach (array_chunk($rows, 500) as $chunk) DB::table(self::MEMBER)->insert($chunk);
        DB::table(self::NODE)->whereIn('id', [$a, $b])->update(['state' => 'active', 'last_pull_at' => null, 'last_pull_round_id' => null]);
        DB::table(self::TASK)->where('id', $task->id)->update(['current_round_id' => $id, 'updated_at' => time()]);
        return $id;
    }

    public function create(array $input, $actor)
    {
        try {
            return DB::transaction(function () use ($input, $actor) {
                $members = $this->eligible($input)->orderBy('id')->limit(10001)->get(['id','email','group_id']);
                if (count($members) < 2 || count($members) > 10000) abort(422, '参与人数必须为2至10000人');
                if (count($members) !== (int)$input['expected_count']) abort(409, '名单人数已变化，请重新预览');
                if (!hash_equals(hash('sha256', $members->toJson()), $input['expected_hash'])) abort(409, '名单内容已变化，请重新预览');
                $id = DB::table(self::TASK)->insertGetId(['name' => $input['name'], 'status' => 'active', 'observation_hours' => $input['observation_hours'],
                    'member_count' => count($members), 'created_at' => time(), 'updated_at' => time()]);
                $leases = [];
                foreach ($members as $member) $leases[] = ['task_id' => $id, 'user_id' => $member->id];
                foreach (array_chunk($leases, 500) as $chunk) DB::table(self::ACTIVE)->insert($chunk);
                $roundId = $this->newRound((object)['id' => $id], $members, (int)$input['a_node_id'], (int)$input['b_node_id']);
                $this->event($id, $roundId, 'created', $actor, '固定参与名单并开始第一轮');
                return $id;
            }, 3);
        } catch (\Illuminate\Database\QueryException $error) {
            if ($error->getCode() === '23000' || $error->getCode() === '23505') abort(409, '部分用户正在其他排查任务中');
            throw $error;
        }
    }

    public function mutate(array $input, $actor)
    {
        return DB::transaction(function () use ($input, $actor) {
            $task = DB::table(self::TASK)->where('id', $input['id'])->lockForUpdate()->first();
            if (!$task) abort(404, '任务不存在');
            if (!in_array($task->status, ['active','paused'], true)) abort(409, '任务已结束');
            if ((int)$task->current_round_id !== (int)$input['round_id']) abort(409, '轮次已变化，请刷新任务');
            $round = DB::table(self::ROUND)->where('id', $task->current_round_id)->first();
            $action = $input['action']; $note = $input['note'] ?? '';
            if ($action === 'results') {
                DB::table(self::ROUND)->where('id', $round->id)->update(['result_a' => $input['result_a'], 'result_b' => $input['result_b'], 'note' => $note, 'checked_at' => time()]);
            } elseif ($action === 'pause' || $action === 'resume') {
                $next = $action === 'pause' ? 'paused' : 'active';
                if ($task->status === $next) abort(409, '任务状态已变化');
                DB::table(self::TASK)->where('id', $task->id)->update(['status' => $next, 'updated_at' => time()]);
            } elseif ($action === 'finish') {
                DB::table(self::TASK)->where('id', $task->id)->update(['status' => 'completed', 'closed_at' => time(), 'updated_at' => time()]);
                $this->closeRound($round);
                DB::table(self::ACTIVE)->where('task_id', $task->id)->delete();
            } elseif ($action === 'advance') {
                if ($task->status !== 'active') abort(409, '请先恢复任务');
                $branch = $input['branch']; $other = $branch === 'a' ? 'b' : 'a';
                if ($round->{'result_' . $branch} !== 'abnormal' || $round->{'result_' . $other} !== 'normal') abort(422, '请先保存人工结果：选定分支异常、另一分支正常');
                $members = DB::table(self::MEMBER)->where('round_id', $round->id)->where('branch', $branch)->get();
                $this->closeRound($round);
                if (count($members) === 1) {
                    DB::table(self::TASK)->where('id', $task->id)->update(['status' => 'identified', 'final_branch' => $branch, 'closed_at' => time(), 'updated_at' => time()]);
                    DB::table(self::ACTIVE)->where('task_id', $task->id)->delete();
                    $action = 'identified';
                } else {
                    $users = User::whereIn('id', $members->pluck('user_id')->all())->get(['id','email','group_id']);
                    if (count($users) !== count($members)) abort(409, '候选账号已删除，无法完整推进；请结束任务后重建名单');
                    $new = $this->newRound($task, $users, (int)($input['a_node_id'] ?? 0), (int)($input['b_node_id'] ?? 0), $round, $branch);
                    $this->event($task->id, $new, 'round_started', $actor, '从上一轮 ' . strtoupper($branch) . ' 组拆分');
                }
            } else abort(422, '操作无效');
            $this->event($task->id, $round->id, $action, $actor, $note);
            return $task->id;
        }, 3);
    }

    private function closeRound($round)
    {
        DB::table(self::ROUND)->where('id', $round->id)->update(['closed_at' => time()]);
        DB::table(self::NODE)->whereIn('id', [$round->a_node_id, $round->b_node_id])->update(['state' => 'ready']);
    }

    // Resolve once per subscription; only the assigned pool node can bypass package groups.
    public function visibility($user)
    {
        $empty = ['pool' => collect(), 'assignment' => null, 'extra' => []];
        if (!self::installed()) return $empty;
        $pool = DB::table(self::NODE)->get()->keyBy(function ($node) { return $node->node_type . ':' . $node->node_id; });
        if (!$pool->count()) return $empty;
        $assignment = config('leak_probe.enabled', true) ? DB::table(self::MEMBER . ' as m')->join(self::ROUND . ' as r', 'r.id', '=', 'm.round_id')
            ->join(self::TASK . ' as t', 't.current_round_id', '=', 'r.id')->where('t.status', 'active')->where('m.user_id', $user->id)
            ->select('m.*', 'r.task_id', 'r.a_node_id', 'r.b_node_id', 'r.a_snapshot', 'r.b_snapshot')->first() : null;
        if ($assignment && ($user->banned || $user->is_admin || !$user->plan_id || $user->u + $user->d >= $user->transfer_enable ||
            ($user->expired_at !== null && $user->expired_at < time()) || !\App\Models\Plan::where('id', $user->plan_id)->exists())) $assignment = null;
        $extra = [];
        if ($assignment) {
            $nodeId = $assignment->branch === 'a' ? $assignment->a_node_id : $assignment->b_node_id;
            $node = $pool->firstWhere('id', $nodeId);
            if ($node && $node->state === 'active') $extra[$node->node_type] = (int)$node->node_id;
        }
        return compact('pool', 'assignment', 'extra');
    }

    public function filterServers($user, array $servers, $visibility = null)
    {
        $visibility = $visibility ?? $this->visibility($user);
        $pool = $visibility['pool']; $assignment = $visibility['assignment'];
        if (!$pool->count()) return $servers;
        $result = [];
        foreach ($servers as $server) {
            $key = $server['type'] . ':' . $server['id'];
            if (!isset($pool[$key])) { $result[] = $server; continue; }
            if (!$assignment || $pool[$key]->state !== 'active') continue;
            $nodeId = $assignment->branch === 'a' ? $assignment->a_node_id : $assignment->b_node_id;
            if ((int)$pool[$key]->id !== (int)$nodeId) continue;
            $snapshot = json_decode($assignment->{$assignment->branch . '_snapshot'}, true);
            $current = $this->snapshot($server['type'], $server['id']);
            if ($current['fingerprint'] !== $snapshot['fingerprint']) continue;
            $server['_leak_probe'] = ['task_id' => (int)$assignment->task_id, 'round_id' => (int)$assignment->round_id,
                'user_id' => (int)$user->id, 'branch' => $assignment->branch, 'node_type' => $server['type'], 'node_id' => (int)$server['id']];
            $result[] = $server;
        }
        return $result;
    }

    public function restrictUsers($query, $type, $id, $groupIds)
    {
        if (!self::installed()) return $query->whereIn('group_id', (array)$groupIds);
        $node = DB::table(self::NODE)->where('node_type', $type)->where('node_id', $id)->first();
        if (!$node) return $query->whereIn('group_id', (array)$groupIds);
        if (!config('leak_probe.enabled', true) || $node->state !== 'active') return $query->whereRaw('1 = 0');
        $round = DB::table(self::ROUND . ' as r')->join(self::TASK . ' as t', 't.current_round_id', '=', 'r.id')
            ->where('t.status', 'active')->where(function ($q) use ($node) { $q->where('r.a_node_id', $node->id)->orWhere('r.b_node_id', $node->id); })
            ->select('r.*')->first();
        if (!$round) return $query->whereRaw('1 = 0');
        $branch = (int)$round->a_node_id === (int)$node->id ? 'a' : 'b';
        $snapshot = json_decode($round->{$branch . '_snapshot'}, true);
        if ($this->snapshot($type, $id)['fingerprint'] !== $snapshot['fingerprint']) return $query->whereRaw('1 = 0');
        $this->pulledNode = ['id' => $node->id, 'round_id' => $round->id, 'branch' => $branch];
        return $query->where('is_admin', 0)
            ->whereExists(function ($q) { $q->selectRaw('1')->from('v2_plan')->whereColumn('v2_plan.id', 'v2_user.plan_id'); })
            ->whereIn('id', DB::table(self::MEMBER)->where('round_id', $round->id)->where('branch', $branch)->select('user_id'));
    }

    public static function rendered(array $server)
    {
        if (empty($server['_leak_probe']) || !app()->bound('request')) return;
        $request = app('request');
        $seen = $request->attributes->get('leak_probe_deliveries', []);
        $mark = $server['_leak_probe'];
        $seen[$mark['round_id'] . ':' . $mark['user_id']] = $mark;
        $request->attributes->set('leak_probe_deliveries', $seen);
    }

    public function recordPull()
    {
        if ($this->pulledNode) {
            DB::table(self::NODE)->where('id', $this->pulledNode['id'])->update(['last_pull_at' => time(), 'last_pull_round_id' => $this->pulledNode['round_id']]);
            DB::table(self::ROUND)->where('id', $this->pulledNode['round_id'])->update([$this->pulledNode['branch'] . '_pull_at' => time()]);
        }
    }

    public static function renderedValue(array $server, $value)
    {
        if ($value !== '' && $value !== null && $value !== false && $value !== []) self::rendered($server);
        return $value;
    }

    public function delivered(array $marks, $at, $source)
    {
        foreach ($marks as $mark) {
            DB::table(self::MEMBER)->where('round_id', $mark['round_id'])->where('user_id', $mark['user_id'])->where('branch', $mark['branch'])
                ->where(function ($q) use ($at) { $q->whereNull('first_seen_at')->orWhere('first_seen_at', '>', $at); })->update(['first_seen_at' => $at]);
            DB::table(self::MEMBER)->where('round_id', $mark['round_id'])->where('user_id', $mark['user_id'])->where('branch', $mark['branch'])
                ->where(function ($q) use ($at) { $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<=', $at); })->update(['last_seen_at' => $at, 'last_source' => $source]);
        }
    }
}
