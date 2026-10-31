<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UserFetch extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'current' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:10|max:500',
            'sort' => 'nullable|in:id,email,banned,plan_id,group_id,created_at,updated_at,expired_at,subscribe_count,balance,commission_balance,commission_rate,discount,transfer_enable,u,d,total_used,device_limit,speed_limit,last_login_at',
            'sort_type' => 'nullable|in:ASC,DESC',
            'filter' => 'nullable|array|max:30',
            'ids' => 'nullable|array|min:1|max:500',
            'ids.*' => 'required|integer|min:1|distinct',
            'filter.*' => ['array', function ($attribute, $filter, $fail) {
                if (!is_array($filter) || ($filter['key'] ?? null) !== 'subscribe_count') return;
                $value = $filter['value'] ?? null;
                if (!in_array($filter['condition'] ?? null, ['>', '<', '=', '>=', '<=', '!='], true)
                    || (!is_int($value) && !is_string($value))
                    || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 9007199254740991]]) === false) {
                    $fail('拉取订阅次数必须使用非负整数和数值比较条件');
                }
            }],
            'filter.*.key' => 'required|in:id,email,transfer_enable,device_limit,u,d,total_used,expired_at,subscribe_count,created_at,last_login_at,uuid,token,invite_by_email,invite_user_id,plan_id,banned,remarks,is_admin,is_staff,balance,commission_balance,commission_rate,commission_type,discount,speed_limit,auto_renewal',
            'filter.*.condition' => 'required|in:>,<,=,>=,<=,模糊,!=',
            'filter.*.value' => 'required'
        ];
    }

    public function messages()
    {
        return [
            'filter.*.key.required' => '过滤键不能为空',
            'filter.*.key.in' => '过滤键参数有误',
            'filter.*.condition.required' => '过滤条件不能为空',
            'filter.*.condition.in' => '过滤条件参数有误',
            'filter.*.value.required' => '过滤值不能为空'
        ];
    }
}
