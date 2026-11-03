<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\ServerService;
use Illuminate\Http\Request;

class GroupController extends Controller
{
    public function fetch(Request $request)
    {
        if ($request->input('group_id')) {
            return response([
                'data' => [ServerGroup::find($request->input('group_id'))]
            ]);
        }
        $serverGroups = ServerGroup::get();
        $serverService = new ServerService();
        $servers = $serverService->getAllServers();
        if (\Illuminate\Support\Facades\DB::connection()->getSchemaBuilder()->hasTable('v2_server_external')) {
            $servers = array_merge($servers, \App\Models\ServerExternal::get(['group_id'])->toArray());
        }
        foreach ($serverGroups as $k => $v) {
            $serverGroups[$k]['user_count'] = User::where('group_id', $v['id'])->count();
            $serverGroups[$k]['server_count'] = 0;
            foreach ($servers as $server) {
                if (in_array($v['id'], $server['group_id'])) {
                    $serverGroups[$k]['server_count'] = $serverGroups[$k]['server_count']+1;
                }
            }
        }
        return response([
            'data' => $serverGroups
        ]);
    }

    public function save(Request $request)
    {
        if (empty($request->input('name'))) {
            abort(500, '组名不能为空');
        }

        if ($request->input('id')) {
            $serverGroup = ServerGroup::find($request->input('id'));
        } else {
            $serverGroup = new ServerGroup();
        }

        $serverGroup->name = $request->input('name');
        return response([
            'data' => $serverGroup->save()
        ]);
    }

    public function drop(Request $request)
    {
        if ($request->input('id')) {
            $serverGroup = ServerGroup::find($request->input('id'));
            if (!$serverGroup) {
                abort(500, '组不存在');
            }
        }

        $servers = (new ServerService())->getAllServers();
        foreach ($servers as $server) {
            if (in_array($request->input('id'), (array)$server['group_id'])) {
                abort(500, '该组已被节点所使用，无法删除');
            }
        }

        if (\Illuminate\Support\Facades\DB::connection()->getSchemaBuilder()->hasTable('v2_server_external')) {
            foreach (\App\Models\ServerExternal::get(['group_id']) as $node) {
                if (in_array((int)$request->input('id'), $node->group_id, true)) abort(422, '该组已被外部节点使用，请先调整外部节点的权限组');
            }
        }

        if (Plan::where('group_id', $request->input('id'))->first()) {
            abort(500, '该组已被订阅所使用，无法删除');
        }
        if (User::where('group_id', $request->input('id'))->first()) {
            abort(500, '该组已被用户所使用，无法删除');
        }
        return response([
            'data' => $serverGroup->delete()
        ]);
    }
}
