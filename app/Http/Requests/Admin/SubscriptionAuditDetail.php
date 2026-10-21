<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SubscriptionAuditDetail extends FormRequest
{
    public function rules()
    {
        return ['id' => 'required|integer|min:1'];
    }
}
