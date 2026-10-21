<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class OrderFetch extends FormRequest
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
            'filter' => 'nullable|array|max:30',
            'filter.*.key' => 'required|in:email,trade_no,status,type,commission_status,user_id,invite_user_id,callback_no,commission_balance',
            'filter.*.condition' => 'required|in:>,<,=,>=,<=,模糊,!=',
            'filter.*.value' => ['required', function ($attribute, $value, $fail) {
                if (!is_scalar($value)) $fail('过滤值必须是文本或数值');
            }]
        ];
    }

    public function messages()
    {
        return [
            'filter.*.key.required' => '过滤键不能为空',
            'filter.*.key.in' => '过滤键参数有误',
            'filter.*.condition.required' => '过滤条件不能为空',
            'filter.*.condition.in' => '过滤条件参数有误',
        ];
    }
}
