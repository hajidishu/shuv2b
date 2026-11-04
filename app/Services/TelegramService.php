<?php
namespace App\Services;

use App\Jobs\SendTelegramJob;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use \Curl\Curl;
use Illuminate\Mail\Markdown;

class TelegramService {
    protected $api;

    public function __construct($token = '')
    {
        $this->api = 'https://api.telegram.org/bot' . config('v2board.telegram_bot_token', $token) . '/';
    }

    public function sendMessage(int $chatId, string $text, string $parseMode = '')
    {
        if ($parseMode === 'markdown') {
            $text = str_replace('_', '\_', $text);
        }
        $this->request('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $parseMode
        ]);
    }

    public function sendPanelMessage(int $chatId, string $text, array $buttons = [], ?int $messageId = null)
    {
        $params = ['chat_id'=>$chatId,'text'=>$text,'parse_mode'=>'HTML','disable_web_page_preview'=>true,
            'reply_markup'=>json_encode(['inline_keyboard'=>$buttons],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];
        if ($messageId) {
            $params['message_id'] = $messageId;
            try { return $this->request('editMessageText',$params); }
            catch (\App\Exceptions\TelegramApiException $e) {
                if ($e->getCode() !== 400) throw $e;
                unset($params['message_id']);
            }
        }
        return $this->request('sendMessage',$params);
    }

    public function answerCallback(string $id, string $text = '')
    {
        return $this->request('answerCallbackQuery',['callback_query_id'=>$id,'text'=>$text]);
    }

    public function getChatMember(int $chatId, int $telegramId)
    {
        return $this->request('getChatMember',['chat_id'=>$chatId,'user_id'=>$telegramId]);
    }

    public function removeChatMember(int $chatId, int $telegramId): void
    {
        $this->request('banChatMember',['chat_id'=>$chatId,'user_id'=>$telegramId,'until_date'=>time()+60]);
        $this->request('unbanChatMember',['chat_id'=>$chatId,'user_id'=>$telegramId,'only_if_banned'=>true]);
    }

    public function approveChatJoinRequest(int $chatId, int $userId)
    {
        $this->request('approveChatJoinRequest', [
            'chat_id' => $chatId,
            'user_id' => $userId
        ]);
    }

    public function declineChatJoinRequest(int $chatId, int $userId)
    {
        $this->request('declineChatJoinRequest', [
            'chat_id' => $chatId,
            'user_id' => $userId
        ]);
    }

    public function getMe()
    {
        return $this->request('getMe');
    }

    public function botUsername(): string
    {
        return Cache::remember('TELEGRAM_USERNAME_'.hash('sha256',(string)config('v2board.telegram_bot_token')),600,function () {
            $response=$this->getMe(); $username=$response->result->username ?? '';
            if (!is_string($username) || !preg_match('/\A[A-Za-z0-9_]{5,64}\z/',$username)) abort(503,'机器人资料暂时不可用');
            return $username;
        });
    }

    public static function normalizeWebhookDomain($value)
    {
        if (!is_string($value) || strlen($value) > 2048 ||
            preg_match('/[\x00-\x20\x7f\\\\]/', $value) || !filter_var($value, FILTER_VALIDATE_URL)) return null;
        $parts = parse_url($value);
        if (!$parts || !isset($parts['scheme'], $parts['host']) || strtolower($parts['scheme']) !== 'https' ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) ||
            !in_array($parts['path'] ?? '', ['', '/'], true)) return null;
        $port = $parts['port'] ?? 443;
        if (!in_array($port, [443, 80, 88, 8443], true)) return null;
        return 'https://' . strtolower($parts['host']) . ($port === 443 ? '' : ':' . $port);
    }

    public function setWebhook(string $url)
    {
        $commands = $this->discoverCommands(base_path('app/Plugins/Telegram/Commands'));
        $this->setMyCommands($commands);
        return $this->request('setWebhook', [
            'url' => $url
        ]);
    }

    public function discoverCommands(string $directory): array
    {
        $commands = [];

        foreach (glob($directory . '/*.php') as $file) {
            $className = 'App\\Plugins\\Telegram\\Commands\\' . basename($file, '.php');

            if (!class_exists($className)) {
                require_once $file;
            }

            if (!class_exists($className)) {
                continue;
            }

            try {
                $ref = new \ReflectionClass($className);

                if (
                    $ref->hasProperty('command') &&
                    $ref->hasProperty('description')
                ) {
                    $commandProp = $ref->getProperty('command');
                    $descProp = $ref->getProperty('description');

                    $command = $commandProp->isStatic()
                        ? $commandProp->getValue()
                        : $ref->newInstanceWithoutConstructor()->command;

                    $description = $descProp->isStatic()
                        ? $descProp->getValue()
                        : $ref->newInstanceWithoutConstructor()->description;

                    $command=ltrim((string)$command,'/');
                    if (!preg_match('/\A[a-z0-9_]{1,32}\z/',$command) || !is_string($description) || $description==='') continue;

                    $commands[] = [
                        'command' => $command,
                        'description' => $description,
                    ];
                }
            } catch (\ReflectionException $e) {
                continue;
            }
        }
        return $commands;
    }
    
    public function setMyCommands(array $commands)
    {
        $this->request('setMyCommands', [
            'commands' => json_encode($commands),
        ]);
    }

    private function request(string $method, array $params = [])
    {
        $curl = new Curl();
        $curl->setOpt(CURLOPT_CONNECTTIMEOUT,3);
        $curl->setOpt(CURLOPT_TIMEOUT,7);
        $curl->post($this->api . $method,$params);
        $response = $curl->response;
        $curl->close();
        if (!isset($response->ok)) throw new \App\Exceptions\TelegramApiException();
        if (!$response->ok) {
            if ($method === 'editMessageText' && strpos($response->description ?? '', 'message is not modified') !== false) return $response;
            throw new \App\Exceptions\TelegramApiException((int)($response->error_code ?? 0),(int)($response->parameters->retry_after ?? 0));
        }
        return $response;
    }

    public static function managementUsers($isStaff = true)
    {
        // Ambiguous legacy bindings grant no management access until one is selected.
        return User::where('banned', 0)->where('is_admin', 1)->whereNotNull('telegram_admin_id')
            ->whereRaw('(SELECT COUNT(*) FROM v2_user WHERE banned = 0 AND is_admin = 1 AND telegram_admin_id IS NOT NULL) = 1');
    }

    public static function setManagementAccount(int $adminId, $telegramId): void
    {
        Cache::lock('TELEGRAM_ADMIN_SETTING', 15)->block(3, function () use ($adminId, $telegramId) {
            DB::transaction(function () use ($adminId, $telegramId) {
                $bindings = User::whereNotNull('telegram_admin_id')->orWhere('id', $adminId)->orderBy('id')->lockForUpdate()->get();
                $admin = $bindings->firstWhere('id', $adminId);
                if (!$admin || !$admin->is_admin || $admin->banned) abort(403, '仅有效面板管理员可设置 Telegram 管理账号');
                $id = $telegramId === null || $telegramId === '' ? null : (string)$telegramId;
                $existing = $bindings->filter(fn($user) => $user->telegram_admin_id !== null);
                if ($id !== null && $existing->count() === 1 && (string)$existing->first()->telegram_admin_id === $id &&
                    $existing->first()->is_admin && !$existing->first()->banned) return;
                User::whereNotNull('telegram_admin_id')->update(['telegram_admin_id' => null, 'telegram_admin_version' => DB::raw('telegram_admin_version + 1')]);
                if ($id !== null) {
                    $admin->refresh();
                    $admin->telegram_admin_id = $id;
                    $admin->telegram_admin_version = (int)$admin->telegram_admin_version + 1;
                    $admin->save();
                }
            });
        });
    }

    public function sendMessageWithAdmin($message, $isStaff = false)
    {
        if (!config('v2board.telegram_bot_enable', 0)) return;
        $users = self::managementUsers($isStaff)->get();
        foreach ($users as $user) {
            SendTelegramJob::dispatch($user->telegram_admin_id, $message, $isStaff, $user->id, $user->telegram_admin_version);
        }
    }
}
