<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Services\TelegramService;
use App\Services\TelegramBotService;
use App\Jobs\ProcessTelegramUpdate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;

class TelegramController extends Controller
{
    protected $msg;
    protected $commands = [];
    protected $telegramService;

    public function __construct(Request $request)
    {
        $this->assertWebhookAuthorization($request);
        $this->telegramService = app(TelegramService::class);
    }

    private function assertWebhookAuthorization(Request $request): void
    {
        $token = config('v2board.telegram_bot_token');
        $accessToken = $request->input('access_token');
        // Never turn an unconfigured bot into a publicly predictable credential.
        // Keep the registered webhook URL format compatible with existing bots.
        if (!is_string($token) || trim($token) === '' || !is_string($accessToken) ||
            !hash_equals(md5($token), $accessToken)) {
            abort(401);
        }
    }

    public function webhook(Request $request)
    {
        // A long-lived worker may reuse the route/controller. Authenticate and
        // reset request state on every update, not only during construction.
        $this->msg = null;
        $this->assertWebhookAuthorization($request);
        $data=array_intersect_key($request->input(),array_flip(['update_id','message','callback_query','chat_join_request']));
        if (isset($data['update_id']) && is_int($data['update_id']) && $data['update_id']>=0) {
            $version=hash('sha256',(string)config('v2board.telegram_bot_token'));
            $key='TELEGRAM_UPDATE_'.$version.'_'.$data['update_id'];
            if (Cache::add($key,'queued',86400)) {
                try {ProcessTelegramUpdate::dispatch($data,$version,$key);}
                catch (\Throwable $e) {Cache::forget($key);throw $e;}
            }
            return response(['ok'=>true]);
        }
        $this->processUpdate($data);
        return response(['ok'=>true]);
    }

    public function processUpdate(array $data): void
    {
        $this->msg=null;$this->telegramService=app(TelegramService::class);
        if (isset($data['callback_query']) && is_array($data['callback_query'])) {
            try {(new TelegramBotService($this->telegramService))->handleCallback($data['callback_query']);}
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $chat=$data['callback_query']['message']['chat'] ?? [];
                if (($chat['type'] ?? '')==='private' && (string)($chat['id'] ?? '')===(string)($data['callback_query']['from']['id'] ?? null))
                    $this->telegramService->sendMessage((int)$chat['id'],$e->getMessage());
            }
            return;
        }
        $this->formatMessage($data);
        $this->formatChatJoinRequest($data);
        $this->handle();
    }

    public function handle()
    {
        if (!$this->msg) return;
        $msg = $this->msg;
        $commandName = explode('@', $msg->command);

        // To reduce request, only commands contains @ will get the bot name
        if (count($commandName) == 2) {
            $botName = $this->getBotName();
            if (strcasecmp($commandName[1],$botName)===0){
                $msg->command = $commandName[0];
            }
        }

        try {
            if ((new TelegramBotService($this->telegramService))->handleMessage($msg)) return;
            foreach (glob(base_path('app//Plugins//Telegram//Commands') . '/*.php') as $file) {
                $command = basename($file, '.php');
                $class = '\\App\\Plugins\\Telegram\\Commands\\' . $command;
                if (!class_exists($class)) continue;
                $instance = new $class();
                if ($msg->message_type === 'message') {
                    if (!isset($instance->command)) continue;
                    if ($msg->command !== $instance->command) continue;
                    $instance->handle($msg);
                    return;
                }
                if ($msg->message_type === 'reply_message') {
                    if (!isset($instance->regex)) continue;
                    if (!preg_match($instance->regex, $msg->reply_text, $match)) continue;
                    $instance->handle($msg, $match);
                    return;
                }
            }
        } catch (\App\Exceptions\TelegramApiException $e) {
            throw $e;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->telegramService->sendMessage($msg->chat_id, $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Telegram command failed',['exception'=>get_class($e)]);
            $this->telegramService->sendMessage($msg->chat_id,'操作暂时失败，请稍后重试');
        }
    }

    public function getBotName()
    {
        return $this->telegramService->botUsername();
    }

    private function formatMessage(array $data)
    {
        if (!isset($data['message'])) return;
        if (!isset($data['message']['text'])) return;
        if (!is_string($data['message']['text']) || !is_int($data['message']['chat']['id'] ?? null) || !is_int($data['message']['message_id'] ?? null) || !is_string($data['message']['chat']['type'] ?? null)) return;
        $obj = new \StdClass();
        if (($data['message']['chat']['type'] ?? '')==='private' && isset($data['message']['from']['id']) && (string)$data['message']['from']['id']!==(string)$data['message']['chat']['id']) return;
        $text = preg_split('/\s+/u',trim($data['message']['text'])) ?: [''];
        $obj->command = $text[0];
        $obj->args = array_slice($text, 1);
        $obj->chat_id = $data['message']['chat']['id'];
        $obj->message_id = $data['message']['message_id'];
        $obj->update_key = isset($data['update_id']) && is_int($data['update_id']) && $data['update_id']>=0
            ? hash('sha256',hash('sha256',(string)config('v2board.telegram_bot_token')).':'.$data['update_id']) : null;
        $obj->message_type = 'message';
        $obj->text = $data['message']['text'];
        $obj->is_private = $data['message']['chat']['type'] === 'private';
        if (isset($data['message']['reply_to_message']['text'])) {
            $obj->message_type = 'reply_message';
            $obj->reply_text = $data['message']['reply_to_message']['text'];
        }
        $this->msg = $obj;
    }

    private function formatChatJoinRequest(array $data)
    {
        if (isset($data['chat_join_request']) && is_array($data['chat_join_request'])) app(\App\Services\TelegramGroupService::class)->join($data['chat_join_request']);
    }
}
