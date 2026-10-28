<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConfigSave;
use App\Jobs\SendEmailJob;
use App\Services\TelegramService;
use App\Utils\Dict;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;

class ConfigController extends Controller
{
    public function passkeyDeployment()
    {
        $settings = \App\Services\PasskeySettings::class;
        $rp = $settings::rpId(config('v2board.passkey_rp_id'));
        return response(['data' => [
            'enabled' => $settings::enabled(),
            'configured' => $settings::configured(),
            'rp_id' => $rp,
            'well_known_url' => $rp && $rp !== 'localhost' ? 'https://' . $rp . '/.well-known/webauthn' : null,
            'document' => $settings::relatedOriginsDocument(),
        ]]);
    }

    public function getEmailTemplate()
    {
        $path = resource_path('views/mail/');
        $files = array_map(function ($item) use ($path) {
            return str_replace($path, '', $item);
        }, glob($path . '*'));
        return response([
            'data' => $files
        ]);
    }

    public function getThemeTemplate()
    {
        $path = public_path('theme/');
        $files = array_map(function ($item) use ($path) {
            return str_replace($path, '', $item);
        }, glob($path . '*'));
        return response([
            'data' => $files
        ]);
    }

    public function testSendMail(Request $request)
    {
        $obj = new SendEmailJob([
            'email' => $request->user['email'],
            'subject' => 'This is v2board test email',
            'template_name' => 'notify',
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'content' => 'This is v2board test email',
                'url' => config('v2board.app_url')
            ]
        ]);
        return response([
            'data' => true,
            'log' => $obj->handle()
        ]);
    }

    public function setTelegramWebhook(Request $request, TelegramService $telegramService)
    {
        $domain = TelegramService::normalizeWebhookDomain(config('v2board.telegram_webhook_domain'));
        if (!$domain) abort(422, '请先填写并保存有效的 HTTPS Webhook 域名');
        $token = config('v2board.telegram_bot_token');
        if (!is_string($token) || trim($token) === '') abort(422, '请先填写并保存 Bot Token');
        $hookUrl = $domain . '/api/v1/guest/telegram/webhook?access_token=' . md5($token);
        $telegramService->getMe();
        $telegramService->setWebhook($hookUrl);
        return response([
            'data' => true
        ]);
    }

    public function fetch(Request $request)
    {
        $key = $request->input('key');
        $data = [
            'ticket' => [
                'ticket_status' => config('v2board.ticket_status', 0)
            ],
            'deposit' => [
                'deposit_bounus' => config('v2board.deposit_bounus', [])
            ],
            'invite' => [
                'invite_force' => (int)config('v2board.invite_force', 0),
                'invite_commission' => config('v2board.invite_commission', 10),
                'invite_gen_limit' => config('v2board.invite_gen_limit', 5),
                'invite_never_expire' => config('v2board.invite_never_expire', 0),
                'commission_first_time_enable' => config('v2board.commission_first_time_enable', 1),
                'commission_auto_check_enable' => config('v2board.commission_auto_check_enable', 1),
                'commission_withdraw_limit' => config('v2board.commission_withdraw_limit', 100),
                'commission_withdraw_method' => config('v2board.commission_withdraw_method', Dict::WITHDRAW_METHOD_WHITELIST_DEFAULT),
                'withdraw_close_enable' => config('v2board.withdraw_close_enable', 0),
                'commission_distribution_enable' => config('v2board.commission_distribution_enable', 0),
                'commission_distribution_l1' => config('v2board.commission_distribution_l1'),
                'commission_distribution_l2' => config('v2board.commission_distribution_l2'),
                'commission_distribution_l3' => config('v2board.commission_distribution_l3')
            ],
            'site' => [
                'logo' => config('v2board.logo'),
                'force_https' => (int)config('v2board.force_https', 0),
                'stop_register' => (int)config('v2board.stop_register', 0),
                'app_name' => config('v2board.app_name', 'V2Board'),
                'app_description' => config('v2board.app_description', 'V2Board is best!'),
                'app_url' => config('v2board.app_url'),
                'payment_return_url' => config('v2board.payment_return_url'),
                'payment_return_path' => config('v2board.payment_return_path') ?: \App\Services\PaymentReturnUrl::DEFAULT_PATH,
                'payment_return_origins' => config('v2board.payment_return_origins', []) ?: [],
                'subscribe_url' => config('v2board.subscribe_url'),
                'subscribe_path' => config('v2board.subscribe_path'),
                'try_out_plan_id' => (int)config('v2board.try_out_plan_id', 0),
                'try_out_hour' => (int)config('v2board.try_out_hour', 1),
                'tos_url' => config('v2board.tos_url'),
                'currency' => config('v2board.currency', 'CNY'),
                'currency_symbol' => config('v2board.currency_symbol', '¥'),
            ],
            'subscribe' => [
                'public_plan_enabled' => (int)config('v2board.public_plan_enabled', 0),
                'public_plan_ids' => config('v2board.public_plan_ids', []) ?: [],
                'plan_change_enable' => (int)config('v2board.plan_change_enable', 1),
                'reset_traffic_method' => (int)config('v2board.reset_traffic_method', 0),
                'surplus_enable' => (int)config('v2board.surplus_enable', 1),
                'allow_new_period' => (int)config('v2board.allow_new_period', 0),
                'new_order_event_id' => (int)config('v2board.new_order_event_id', 0),
                'renew_order_event_id' => (int)config('v2board.renew_order_event_id', 0),
                'change_order_event_id' => (int)config('v2board.change_order_event_id', 0),
                'show_info_to_server_enable' => (int)config('v2board.show_info_to_server_enable', 0),
                'show_subscribe_method' => (int)config('v2board.show_subscribe_method', 0),
                'show_subscribe_expire' => (int)config('v2board.show_subscribe_expire', 5),
            ],
            'frontend' => [
                'frontend_theme' => config('v2board.frontend_theme', 'v2board'),
                'frontend_theme_sidebar' => config('v2board.frontend_theme_sidebar', 'light'),
                'frontend_theme_header' => config('v2board.frontend_theme_header', 'dark'),
                'frontend_theme_color' => config('v2board.frontend_theme_color', 'default'),
                'frontend_background_url' => config('v2board.frontend_background_url'),
            ],
            'server' => [
                'server_api_url' => config('v2board.server_api_url'),
                'server_token' => config('v2board.server_token'),
                'server_pull_interval' => config('v2board.server_pull_interval', 60),
                'server_push_interval' => config('v2board.server_push_interval', 60),
                'server_node_report_min_traffic' => config('v2board.server_node_report_min_traffic', 0),
                'server_device_online_min_traffic' => config('v2board.server_device_online_min_traffic', 0),
                'device_limit_mode' => config('v2board.device_limit_mode', 0)
            ],
            'email' => [
                'email_template' => config('v2board.email_template', 'default'),
                'email_host' => config('v2board.email_host'),
                'email_port' => config('v2board.email_port'),
                'email_username' => config('v2board.email_username'),
                'email_password' => config('v2board.email_password'),
                'email_encryption' => config('v2board.email_encryption'),
                'email_from_address' => config('v2board.email_from_address')
            ],
            'telegram' => [
                'telegram_bot_enable' => config('v2board.telegram_bot_enable', 0),
                'telegram_bot_token' => config('v2board.telegram_bot_token'),
                'telegram_webhook_domain' => config('v2board.telegram_webhook_domain'),
                'telegram_discuss_link' => config('v2board.telegram_discuss_link')
            ],
            'app' => [
                'windows_version' => config('v2board.windows_version'),
                'windows_download_url' => config('v2board.windows_download_url'),
                'macos_version' => config('v2board.macos_version'),
                'macos_download_url' => config('v2board.macos_download_url'),
                'android_version' => config('v2board.android_version'),
                'android_download_url' => config('v2board.android_download_url')
            ],
            'safe' => [
                'google_login_enabled' => (int)config('v2board.google_login_enabled', 0),
                'google_register_enabled' => (int)config('v2board.google_register_enabled', 1),
                'google_client_id' => config('v2board.google_client_id', ''),
                'google_origins' => config('v2board.google_origins', []) ?: [],
                'passkey_enabled' => (int)config('v2board.passkey_enabled', 0),
                'passkey_rp_id' => config('v2board.passkey_rp_id', ''),
                'passkey_origins' => config('v2board.passkey_origins', []) ?: [],
                'email_verify' => (int)config('v2board.email_verify', 0),
                'safe_mode_enable' => (int)config('v2board.safe_mode_enable', 0),
                'secure_path' => config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key')))),
                'email_whitelist_enable' => (int)config('v2board.email_whitelist_enable', 0),
                'email_whitelist_suffix' => config('v2board.email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT),
                'email_gmail_limit_enable' => config('v2board.email_gmail_limit_enable', 0),
                'captcha_provider' => \App\Services\CaptchaService::provider(),
                'turnstile_site_key' => config('v2board.turnstile_site_key'),
                'turnstile_secret_key' => config('v2board.turnstile_secret_key'),
                'recaptcha_enable' => \App\Services\CaptchaService::provider() === 'recaptcha' ? 1 : 0,
                'recaptcha_key' => config('v2board.recaptcha_key'),
                'recaptcha_site_key' => config('v2board.recaptcha_site_key'),
                'register_limit_by_ip_enable' => (int)config('v2board.register_limit_by_ip_enable', 0),
                'register_limit_count' => config('v2board.register_limit_count', 3),
                'register_limit_expire' => config('v2board.register_limit_expire', 60),
                'password_limit_enable' => (int)config('v2board.password_limit_enable', 1),
                'password_limit_count' => config('v2board.password_limit_count', 5),
                'password_limit_expire' => config('v2board.password_limit_expire', 60)
            ]
        ];
        if ($key && isset($data[$key])) {
            return response([
                'data' => [
                    $key => $data[$key]
                ]
            ]);
        };
        // TODO: default should be in Dict
        return response([
            'data' => $data
        ]);
    }

    public function save(ConfigSave $request)
    {
        $data = $request->validated();
        if (array_key_exists('public_plan_ids', $data)) $data['public_plan_ids'] = array_values(array_map('intval', $data['public_plan_ids'] ?? []));
        $config = config('v2board');
        if (array_key_exists('recaptcha_enable', $data) && !array_key_exists('captcha_provider', $data)) {
            $data['captcha_provider'] = (int)$data['recaptcha_enable'] ? 'recaptcha' : 'none';
        }
        if (array_key_exists('captcha_provider', $data)) $data['recaptcha_enable'] = $data['captcha_provider'] === 'recaptcha' ? 1 : 0;
        foreach (ConfigSave::RULES as $k => $v) {
            if (!in_array($k, array_keys(ConfigSave::RULES))) {
                unset($config[$k]);
                continue;
            }
            if (array_key_exists($k, $data)) {
                $config[$k] = $data[$k];
            }
        }
        $data = var_export($config, 1);
        if (!File::put(base_path() . '/config/v2board.php', "<?php\n return $data ;")) {
            abort(500, '修改失败');
        }
        if (function_exists('opcache_reset')) {
            if (opcache_reset() === false) {
                abort(500, '缓存清除失败，请卸载或检查opcache配置状态');
            }
        }
        Artisan::call('config:cache');
        if(Cache::has('WEBMANPID')) {
            $pid = Cache::get('WEBMANPID');
            Cache::forget('WEBMANPID');
            return response([
                'data' => posix_kill($pid, 15)
            ]);
        }
        return response([
            'data' => true
        ]);
    }
}
