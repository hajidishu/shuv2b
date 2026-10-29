<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\ServerNaive;
use App\Services\NaiveSettings;
use Illuminate\Http\Request;

class NaiveController extends Controller
{
    public function save(Request $request)
    {
        $params = $request->validate(array_merge([
            'name' => 'required|string|max:255', 'group_id' => 'required|array|min:1',
            'group_id.*' => 'integer|exists:v2_server_group,id', 'route_id' => 'nullable|array',
            'route_id.*' => 'integer|exists:v2_server_route,id',
            'parent_id' => 'nullable|integer|exists:v2_server_naive,id',
            'host' => 'required|string|max:255', 'listen_ip' => 'nullable|ip',
            'port' => 'required|integer|between:1,65535', 'server_port' => 'required|integer|between:1,65535',
            'rate' => 'required|numeric|min:0', 'tags' => 'nullable|array',
            'tags.*' => 'string|max:255', 'show' => 'nullable|in:0,1', 'sort' => 'nullable|integer|min:0',
        ], NaiveSettings::rules()));
        $params = NaiveSettings::normalize($params);
        $params['listen_ip'] = $params['listen_ip'] ?? '0.0.0.0';
        if ($request->input('id')) {
            $server = ServerNaive::findOrFail($request->input('id'));
            if (isset($params['parent_id']) && (int)$params['parent_id'] === (int)$server->id) abort(422, '父节点不能是节点自身');
            $server->update($params);
        } else ServerNaive::create($params);
        return response(['data' => true]);
    }

    public function drop(Request $request)
    {
        $params = $request->validate(['id' => 'required|integer|min:1']);
        return response(['data' => (bool)ServerNaive::findOrFail($params['id'])->delete()]);
    }

    public function update(Request $request)
    {
        $params = $request->validate(['id' => 'required|integer|min:1', 'show' => 'required|in:0,1']);
        ServerNaive::findOrFail($params['id'])->update(['show' => $params['show']]);
        return response(['data' => true]);
    }

    public function copy(Request $request)
    {
        $params = $request->validate(['id' => 'required|integer|min:1']);
        $server = ServerNaive::findOrFail($params['id'])->replicate();
        $server->show = 0; $server->save();
        return response(['data' => true]);
    }
}
