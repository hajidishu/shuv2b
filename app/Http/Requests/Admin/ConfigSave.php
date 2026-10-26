<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ConfigSave extends FormRequest
{
    const RULES = [
        // deposit
        'deposit_bounus' => [
            'nullable',
            'array',
        ],
        // invite & commission
        'ticket_status' => 'in:0,1,2',
        'invite_force' => 'in:0,1',
        'invite_commission' => 'integer',
        'invite_gen_limit' => 'integer',
        'invite_never_expire' => 'in:0,1',
        'commission_first_time_enable' => 'in:0,1',
        'commission_auto_check_enable' => 'in:0,1',
        'commission_withdraw_limit' => 'nullable|numeric',
        'commission_withdraw_method' => 'nullable|array',
        'withdraw_close_enable' => 'in:0,1',
        'commission_distribution_enable' => 'in:0,1',
        'commission_distribution_l1' => 'nullable|numeric',
        'commission_distribution_l2' => 'nullable|numeric',
        'commission_distribution_l3' => 'nullable|numeric',
        // site
        'logo' => 'nullable|url',
        'force_https' => 'in:0,1',
        'stop_register' => 'in:0,1',
        'app_name' => '',
        'app_description' => '',
        'app_url' => 'nullable|url',
        'payment_return_url' => 'nullable|string|max:2048',
        'payment_return_path' => 'nullable|string|max:512',
        'payment_return_origins' => 'nullable|array|max:50',
        'payment_return_origins.*' => 'required|string|max:2048',
        'subscribe_url' => 'nullable',
        'subscribe_path' => 'nullable|regex:/^\\//',
        'try_out_enable' => 'in:0,1',
        'try_out_plan_id' => 'integer',
        'try_out_hour' => 'numeric',
        'tos_url' => 'nullable|url',
        'currency' => '',
        'currency_symbol' => '',
        // subscribe
        'plan_change_enable' => 'in:0,1',
        'reset_traffic_method' => 'in:0,1,2,3,4',
        'surplus_enable' => 'in:0,1',
        'allow_new_period' => 'in:0,1',
        'new_order_event_id' => 'in:0,1',
        'renew_order_event_id' => 'in:0,1',
        'change_order_event_id' => 'in:0,1',
        'show_info_to_server_enable' => 'in:0,1',
        'show_subscribe_method' => 'in:0,1,2',
        'show_subscribe_expire' => 'nullable|integer',
        // server
        'server_api_url' => 'nullable|string',
        'server_token' => 'nullable|min:16',
        'server_pull_interval' => 'integer',
        'server_push_interval' => 'integer',
        'device_limit_mode' => 'in:0,1',
        'server_node_report_min_traffic' => 'integer', 
        'server_device_online_min_traffic' => 'integer', 
        // frontend
        'frontend_theme' => '',
        'frontend_theme_sidebar' => 'nullable|in:dark,light',
        'frontend_theme_header' => 'nullable|in:dark,light',
        'frontend_theme_color' => 'nullable|in:default,darkblue,black,green',
        'frontend_background_url' => 'nullable|url',
        // email
        'email_template' => '',
        'email_host' => '',
        'email_port' => '',
        'email_username' => '',
        'email_password' => '',
        'email_encryption' => '',
        'email_from_address' => '',
        // telegram
        'telegram_bot_enable' => 'in:0,1',
        'telegram_bot_token' => '',
        'telegram_webhook_domain' => 'nullable|string|max:2048',
        'telegram_discuss_id' => '',
        'telegram_channel_id' => '',
        'telegram_discuss_link' => 'nullable|url',
        // app
        'windows_version' => '',
        'windows_download_url' => '',
        'macos_version' => '',
        'macos_download_url' => '',
        'android_version' => '',
        'android_download_url' => '',
        // safe
        'passkey_enabled' => 'in:0,1',
        'passkey_rp_id' => 'nullable|string|max:253',
        'passkey_origins' => 'nullable|array|max:30',
        'passkey_origins.*' => 'required|string|max:2048',
        'email_whitelist_enable' => 'in:0,1',
        'email_whitelist_suffix' => 'nullable|array',
        'email_gmail_limit_enable' => 'in:0,1',
        'recaptcha_enable' => 'in:0,1',
        'captcha_provider' => 'in:none,recaptcha,turnstile',
        'turnstile_site_key' => 'nullable|string|max:2048',
        'turnstile_secret_key' => 'nullable|string|max:2048',
        'recaptcha_key' => 'nullable|string|max:2048',
        'recaptcha_site_key' => 'nullable|string|max:2048',
        'email_verify' => 'in:0,1',
        'safe_mode_enable' => 'in:0,1',
        'register_limit_by_ip_enable' => 'in:0,1',
        'register_limit_count' => 'integer',
        'register_limit_expire' => 'integer',
        'secure_path' => 'min:8|regex:/^[\w-]*$/',
        'password_limit_enable' => 'in:0,1',
        'password_limit_count' => 'integer',
        'password_limit_expire' => 'integer',
    ];
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $rules = self::RULES;
        $rules['passkey_rp_id'] = ['nullable', 'string', 'max:253', function ($attribute, $value, $fail) {
            if (!\App\Services\PasskeySettings::rpId($value)) $fail('RP ID 必须为域名，不包含协议、端口或路径');
        }];
        $rules['passkey_origins.*'] = ['required', 'string', 'max:2048', function ($attribute, $value, $fail) {
            if (!\App\Services\PasskeySettings::origin($value)) $fail('Passkey 来源必须为完整 HTTPS 来源地址，不含路径；仅 localhost 允许 HTTP');
        }];

        $rules['telegram_webhook_domain'] = ['nullable', 'string', 'max:2048', function ($attribute, $value, $fail) {
            if (!\App\Services\TelegramService::normalizeWebhookDomain($value)) {
                $fail('Webhook 域名必须为 https://域名，可选端口为 443、80、88、8443，不能包含路径、账号或查询参数');
            }
        }];

        $rules['payment_return_url'] = ['nullable', 'string', 'max:2048', function ($attribute, $value, $fail) {
            if (!\App\Services\PaymentReturnUrl::normalizeBase($value)) $fail('支付回跳前端地址必须为不含账号、查询参数和片段的 HTTP(S) 地址');
        }];
        $rules['payment_return_path'] = ['nullable', 'string', 'max:512', function ($attribute, $value, $fail) {
            if (!\App\Services\PaymentReturnUrl::validPath($value)) $fail('支付回跳路径必须以单个 / 开头并包含一个 {trade_no}');
        }];
        $rules['payment_return_origins.*'] = ['required', 'string', 'max:2048', function ($attribute, $value, $fail) {
            if (!\App\Services\PaymentReturnUrl::normalizeBase($value, true)) $fail('允许域名必须为完整 HTTP(S) 来源地址，不能包含子路径');
        }];

        $rules['deposit_bounus'][] = function ($attribute, $value, $fail) {
            foreach ($value as $tier) {
                if (!preg_match('/^\d+(\.\d+)?:\d+(\.\d+)?$/', $tier)) {
                    if($tier == '') {
                        continue;
                    }
                    $fail('充值奖励格式不正确，必须为充值金额:奖励金额');
                }
            }
        };
        return $rules;
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->isNotEmpty()) return;
            $captcha = $this->only(['captcha_provider', 'recaptcha_enable', 'recaptcha_key', 'recaptcha_site_key', 'turnstile_site_key', 'turnstile_secret_key']);
            if ($captcha) {
                $captcha = array_merge((array)config('v2board', []), $captcha);
                if ($this->has('recaptcha_enable') && !$this->has('captcha_provider')) $captcha['captcha_provider'] = (int)$captcha['recaptcha_enable'] ? 'recaptcha' : 'none';
                $provider = \App\Services\CaptchaService::provider($captcha);
                foreach (['site_key', 'secret'] as $kind) {
                    $field = \App\Services\CaptchaService::PROVIDERS[$provider][$kind] ?? null;
                    if ($field && (!is_string($captcha[$field] ?? null) || trim($captcha[$field]) === '')) $validator->errors()->add($field, '请填写所选人机验证方式的网站密钥和私钥');
                }
            }
            $values = array_merge((array)config('v2board', []), $this->only(['passkey_enabled', 'passkey_rp_id', 'passkey_origins']));
            if (!empty($values['passkey_enabled']) && !\App\Services\PasskeySettings::validate($values)) {
                $validator->errors()->add('passkey_enabled', '启用 Passkey 前请填写固定 RP ID 和完整 HTTPS 允许来源；修改 RP ID 前请先关闭功能');
            }
        });
    }

    public function messages()
    {
        // illiteracy prompt
        return [
            'app_url.url' => '站点URL格式不正确，必须携带http(s)://',
            'subscribe_url.url' => '订阅URL格式不正确，必须携带http(s)://',
            'subscribe_path.regex' => '订阅路径必须以/开头',
            'server_token.min' => '通讯密钥长度必须大于16位',
            'tos_url.url' => '服务条款URL格式不正确，必须携带http(s)://',
            'telegram_discuss_link.url' => 'Telegram群组地址必须为URL格式，必须携带http(s)://',
            'logo.url' => 'LOGO URL格式不正确，必须携带https(s)://',
            'secure_path.min' => '后台路径长度最小为8位',
            'secure_path.regex' => '后台路径只能为字母或数字',
        ];
    }
}
