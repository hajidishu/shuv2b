<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SubscriptionAuditFetch extends FormRequest
{
    public function rules()
    {
        return [
            'dimension' => 'nullable|in:ip,ua,user',
            'fresh' => 'nullable|boolean',
            'current' => 'nullable|integer|min:1|max:10000000',
            'pageSize' => 'nullable|integer|min:1|max:100',
            'filter' => 'nullable|array|max:20',
            'filter.*' => ['array', function ($attribute, $filter, $fail) {
                if (!is_array($filter)) return;
                $key = $filter['key'] ?? '';
                $operator = $filter['condition'] ?? '';
                $value = $filter['value'] ?? null;
                if (!is_scalar($value) || strlen((string)$value) > 512) {
                    $fail('筛选值过长或格式有误'); return;
                }
                if (in_array($key, ['user_id', 'requested_at'], true)) {
                    if (!in_array($operator, ['=', '!=', '>', '>=', '<', '<='], true) ||
                        !preg_match('/\A\d{1,12}\z/', (string)$value)) $fail('数字或时间筛选格式有误');
                } elseif ($key === 'result') {
                    if (!in_array($operator, ['=', '!='], true) || !in_array($value, ['success', 'unavailable', 'error'], true)) $fail('结果筛选有误');
                } elseif (!in_array($operator, ['=', '!=', '模糊'], true)) $fail('文本筛选仅支持等于、不等于或模糊匹配');
            }],
            'filter.*.key' => 'required|in:email,user_id,ip,ua,format,result,requested_at',
            'filter.*.condition' => 'required|in:=,!=,模糊,>,>=,<,<=',
            'filter.*.value' => 'required',
        ];
    }
}
