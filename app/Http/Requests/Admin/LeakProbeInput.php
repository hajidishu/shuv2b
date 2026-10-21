<?php
namespace App\Http\Requests\Admin;
use Illuminate\Foundation\Http\FormRequest;

class LeakProbeInput extends FormRequest
{
    public function rules()
    {
        $last = basename($this->path());
        if ($this->method() === 'GET') return ['id' => 'nullable|integer|min:1', 'round_id' => 'nullable|integer|min:1',
            'current' => 'nullable|integer|min:1|max:100000', 'pageSize' => 'nullable|integer|min:1|max:100',
            'branch' => 'nullable|in:a,b', 'email' => 'nullable|string|max:64'];
        if ($last === 'nodes') return ['action' => 'required|in:register,remove', 'node_type' => 'required_if:action,register|in:shadowsocks,vmess,trojan,vless,hysteria,tuic,anytls,v2node',
            'node_id' => 'required_if:action,register|integer|min:1', 'id' => 'required_if:action,remove|integer|min:1'];
        if ($last === 'update') return [
            'id' => 'required|integer|min:1', 'round_id' => 'required|integer|min:1', 'action' => 'required|in:results,pause,resume,finish,advance',
            'result_a' => 'required_if:action,results|in:unknown,normal,abnormal', 'result_b' => 'required_if:action,results|in:unknown,normal,abnormal',
            'branch' => 'required_if:action,advance|in:a,b', 'note' => 'nullable|string|max:2000',
            'a_node_id' => 'nullable|integer|min:1', 'b_node_id' => 'nullable|integer|min:1',
        ];
        $rules = [
            'scope' => 'required|in:all,plans,emails,filters', 'plan_ids' => 'required_if:scope,plans|array|max:1000', 'plan_ids.*' => 'integer|min:1',
            'emails' => 'required_if:scope,emails|string|max:650000', 'filter' => 'required_if:scope,filters|array|min:1|max:10',
            'filter.*.key' => 'required|in:id,email,plan_id,balance,commission_balance,expired_at',
            'filter.*.condition' => 'required|in:=,!=,>,>=,<,<=,模糊',
            'filter.*' => ['array', function ($attribute, $f, $fail) {
                if (!is_array($f)) return;
                if (!isset($f['value']) || !is_scalar($f['value']) || strlen((string)$f['value']) > 256 || $f['value'] === '') { $fail('筛选值无效'); return; }
                if (($f['key'] ?? '') === 'email') {
                    if (!in_array($f['condition'] ?? '', ['=','!=','模糊'], true)) $fail('邮箱筛选条件无效');
                } elseif (!(($f['key'] ?? '') === 'plan_id' && $f['value'] === 'null') && !preg_match('/\A-?\d{1,15}\z/', (string)$f['value'])) $fail('数字筛选值无效');
                if (($f['condition'] ?? '') === '模糊' && ($f['key'] ?? '') !== 'email') $fail('该字段不支持模糊匹配');
            }],
        ];
        if ($last === 'create') $rules += ['name' => 'required|string|max:120', 'observation_hours' => 'required|integer|min:1|max:720',
            'a_node_id' => 'required|integer|min:1', 'b_node_id' => 'required|integer|min:1|different:a_node_id',
            'expected_count' => 'required|integer|min:2|max:10000', 'expected_hash' => 'required|string|size:64'];
        return $rules;
    }
}
