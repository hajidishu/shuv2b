<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\ServerExternal;
use App\Services\ExternalNodeSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExternalController extends Controller
{
    public function fetch(Request $request)
    {
        $request->validate(['group_id' => 'required|integer|exists:v2_server_group,id']);
        $nodes = ExternalNodeSettings::nodesForGroup((int)$request->input('group_id'));
        return response(['data' => $nodes]);
    }

    public function parse(Request $request)
    {
        $request->validate(['uri' => 'required|string|max:16384']);
        return response(['data' => ExternalNodeSettings::parse($request->input('uri'))]);
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            'id' => 'nullable|integer|exists:v2_server_external,id',
            'name' => ['required','string','max:255','regex:/\A[^\x00-\x1f\x7f]+\z/u'],
            'group_id' => 'required|array|min:1|max:1000',
            'group_id.*' => 'required|integer|distinct|exists:v2_server_group,id',
            'show' => 'required|boolean', 'config' => 'required|array',
        ]);
        $config = ExternalNodeSettings::normalize($data['config']);
        $node = isset($data['id']) ? ServerExternal::findOrFail($data['id']) : new ServerExternal();
        if (!$node->exists) $node->sort = (int)ServerExternal::max('sort') + 1;
        $node->name = $data['name'];
        $node->group_id = array_map('intval', $data['group_id']);
        $node->group_sort = array_intersect_key($node->group_sort ?? [], array_flip($node->group_id));
        $node->show = (int)$data['show'];
        $node->config = $config;
        $node->save();
        return response(['data' => $node]);
    }

    public function update(Request $request)
    {
        $data = $request->validate(['id' => 'required|integer|exists:v2_server_external,id', 'show' => 'required|boolean']);
        ServerExternal::where('id', $data['id'])->update(['show' => (int)$data['show'], 'updated_at' => time()]);
        return response(['data' => true]);
    }

    public function drop(Request $request)
    {
        $data = $request->validate(['id' => 'required|integer|exists:v2_server_external,id']);
        ServerExternal::findOrFail($data['id'])->delete();
        return response(['data' => true]);
    }

    public function sort(Request $request)
    {
        $data = $request->validate([
            'group_id' => 'required|integer|exists:v2_server_group,id',
            'ids' => 'required|array|min:1', 'ids.*' => 'required|integer|distinct|exists:v2_server_external,id',
        ]);
        DB::transaction(function () use ($data) {
            $nodes = ServerExternal::orderBy('id')->lockForUpdate()->get()->filter(function ($node) use ($data) {
                return in_array((int)$data['group_id'], $node->group_id, true);
            });
            $expected = $nodes->pluck('id')->map(function ($id) { return (int)$id; })->sort()->values()->all();
            $ids = array_map('intval', $data['ids']); $actual = $ids; sort($actual);
            abort_unless($expected === $actual, 422, '节点列表已变化，请刷新后重新排序');
            foreach ($ids as $index => $id) {
                $node = $nodes->firstWhere('id', $id);
                $order = $node->group_sort ?? [];
                $order[(string)$data['group_id']] = $index + 1;
                $node->group_sort = $order;
                $node->save();
            }
        });
        return response(['data' => true]);
    }
}
