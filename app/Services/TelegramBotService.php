<?php
namespace App\Services;

use App\Models\Plan;
use App\Models\TelegramProfile;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TelegramBotService
{
    private $telegram;
    public function __construct(?TelegramService $telegram = null) { $this->telegram = $telegram ?: app(TelegramService::class); }
    private static function esc($value): string { return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
    private static function button(string $text,string $action): array { return ['text'=>$text,'callback_data'=>'v2b:'.$action]; }

    public static function siteUrl(): ?string
    {
        return PaymentReturnUrl::normalizeBase(config('v2board.telegram_frontend_url')) ?: PaymentReturnUrl::normalizeBase(config('v2board.app_url'));
    }

    private function website(): array
    {
        $url = self::siteUrl(); return $url ? [['text'=>'打开网站','url'=>$url]] : [];
    }

    private function user(int $telegramId): ?User
    {
        $users = User::where('telegram_id',$telegramId)->where('banned',0)->where('is_admin',0)->where('is_staff',0)->limit(2)->get();
        return $users->count() === 1 ? $users->first() : null;
    }

    public function requestUnbind(int $telegramId): void { $this->show($telegramId,'unbind'); }

    public function handleMessage($message): bool
    {
        $actions = ['/start'=>'home','/menu'=>'home','/account'=>'account','/traffic'=>'traffic','/subscribe'=>'subscribe','/notifications'=>'notifications','/stats'=>'admin'];
        if (!isset($actions[$message->command])) return false;
        if (!$message->is_private) return true;
        $argument = $message->args[0] ?? '';
        if ($message->command === '/start' && is_string($argument) && strpos($argument,'bind_') === 0) {
            $nonce = substr($argument,5); $challenge = TelegramBindingService::challenge($nonce);
            abort_unless($challenge,422,'绑定链接已过期，请从用户中心重新打开');
            $this->telegram->sendPanelMessage((int)$message->chat_id,'确认将已登录的网站账号绑定到当前 Telegram？',[[self::button('确认绑定','bind_confirm:'.$nonce),self::button('取消','home')]]);
            return true;
        }
        $this->show((int)$message->chat_id,$actions[$message->command]); return true;
    }

    public function handleCallback(array $callback): void
    {
        $id = $callback['id'] ?? null;
        if (!is_string($id)) return;
        try {$this->telegram->answerCallback($id);}
        catch (\App\Exceptions\TelegramApiException $e) {if ($e->getCode()!==400) throw $e;}
        $chat = $callback['message']['chat'] ?? [];
        $from = $callback['from']['id'] ?? null;
        if (($chat['type'] ?? '') !== 'private' || !is_int($from) || $from <= 0 || (string)($chat['id'] ?? '') !== (string)$from) return;
        $data = $callback['data'] ?? '';
        if (!is_string($data) || strlen($data)>64 || strpos($data,'v2b:') !== 0) return;
        $parts = explode(':',substr($data,4),2); $action=$parts[0]; $argument=$parts[1] ?? '';
        $messageId = $callback['message']['message_id'] ?? null;
        if ($action === 'bind_confirm') {
            TelegramBindingService::confirm($argument,$from);
            $this->show($from,'home',is_int($messageId)?$messageId:null); return;
        }
        if ($action === 'unbind_yes') {
            $context = preg_match('/\A[0-9a-f]{32}\z/',$argument) ? Cache::pull('TELEGRAM_UNBIND_'.$argument) : null;
            $user=$this->user($from);$profile=$user ? TelegramBindingService::confirmed($user) : null;
            abort_unless($context && $profile && $context['user_id']===$user->id && $context['telegram_id']===$from && $context['version']===$profile->binding_version,409,'确认已过期，请重新操作');
            TelegramBindingService::unbind($user); $this->show($from,'home'); return;
        }
        if (!in_array($action,['home','account','traffic','subscribe','notifications','expiry_on','expiry_off','traffic_on','traffic_off','unbind','admin'],true)) return;
        $this->show($from,$action,is_int($messageId)?$messageId:null);
    }

    private function show(int $chatId,string $action,?int $messageId = null): void
    {
        $admin = TelegramService::managementUsers()->where('telegram_admin_id',$chatId)->first();
        if ($admin) {
            $text = '<b>管理概况</b>\n总用户：'.User::count().'\n可用账号：'.User::where('banned',0)->where('is_admin',0)->where('is_staff',0)->where('transfer_enable','>',0)
                ->where(function ($q) {$q->whereNull('expired_at')->orWhere('expired_at','>',time());})->count()
                .'\n今日注册：'.User::where('created_at','>=',strtotime('today'))->count();
            $buttons=[[self::button('刷新概况','admin')]];if ($this->website()) $buttons[]=$this->website();
            $this->telegram->sendPanelMessage($chatId,str_replace('\\n',"\n",$text),$buttons,$messageId);return;
        }
        if ($action==='admin') abort(403,'没有管理权限');
        $user = $this->user($chatId);
        if (!$user) {
            $buttons=[];if ($this->website()) $buttons[]=$this->website();
            $this->telegram->sendPanelMessage($chatId,'请登录用户中心确认 Telegram 绑定。',$buttons,$messageId); return;
        }
        $profile=TelegramBindingService::confirmed($user);
        if (!$profile && in_array($action,['subscribe','notifications','expiry_on','expiry_off','traffic_on','traffic_off','unbind'],true)) {
            $buttons=[];if ($this->website()) $buttons[]=$this->website();$buttons[]=[self::button('返回','home')];
            $this->telegram->sendPanelMessage($chatId,'此账号需要先在已登录的用户中心确认绑定，才能使用此功能。',$buttons,$messageId);return;
        }
        if (strpos($action,'expiry_')===0 || strpos($action,'traffic_')===0) {
            $key=strpos($action,'expiry_')===0?'remind_expire':'remind_traffic';
            $profile->{$key}=substr($action,-3)==='_on'?1:0;$profile->save();$action='notifications';
        }
        $back=[[self::button('返回菜单','home')]];
        if ($action==='subscribe') {
            abort_unless((new UserService())->isAvailable($user),403,'当前套餐不可用，请先在网站续费或检查账号状态');
            abort_unless(is_string($user->token) && $user->token!=='',422,'订阅凭据不可用，请从网站重置订阅信息');
            $bases=array_filter(array_map([PaymentReturnUrl::class,'normalizeBase'],explode(',',(string)config('v2board.subscribe_url',''))));
            $base=$bases ? array_values($bases)[0] : PaymentReturnUrl::normalizeBase(config('v2board.app_url'));
            abort_unless($base,422,'后台尚未设置有效的订阅地址或站点地址');
            $path=config('v2board.subscribe_path','/api/v1/client/subscribe') ?: '/api/v1/client/subscribe';
            abort_if(!is_string($path) || !preg_match('~\A/(?!/)[^\s?#\\\\]*\z~',$path),422,'后台订阅路径无效');
            $url=Helper::getSubscribeUrl($user->token,$base);abort_if(strlen($url)>3000,422,'订阅地址过长，请从网站获取');
            $this->telegram->sendPanelMessage($chatId,"<b>当前订阅</b>\n<code>".self::esc($url).'</code>',[[['text'=>'打开订阅','url'=>$url]],...$back]);return;
        }
        if ($action==='notifications') {
            $text="<b>通知设置</b>\n到期提醒：".($profile->remind_expire?'开启':'关闭')."\n流量不足提醒：".($profile->remind_traffic?'开启':'关闭');
            if (!config('v2board.telegram_remind_enable',0)) $text.="\n站点尚未开启自动提醒。";
            $buttons=[[self::button($profile->remind_expire?'关闭到期提醒':'开启到期提醒',$profile->remind_expire?'expiry_off':'expiry_on')],
                [self::button($profile->remind_traffic?'关闭流量提醒':'开启流量提醒',$profile->remind_traffic?'traffic_off':'traffic_on')],...$back];
            $this->telegram->sendPanelMessage($chatId,$text,$buttons,$messageId);return;
        }
        if ($action==='unbind') {
            $nonce=bin2hex(random_bytes(16));Cache::put('TELEGRAM_UNBIND_'.$nonce,['user_id'=>(int)$user->id,'telegram_id'=>$chatId,'version'=>$profile->binding_version],120);
            $this->telegram->sendPanelMessage($chatId,'确认解除当前 Telegram 绑定？',[[self::button('确认解绑','unbind_yes:'.$nonce),self::button('取消','home')]],$messageId);return;
        }
        $used=(int)$user->u+(int)$user->d;$total=(int)$user->transfer_enable;
        $expiry=$user->expired_at===null?'长期有效':date('Y-m-d H:i',(int)$user->expired_at);
        $plan=$user->plan_id ? Plan::find($user->plan_id) : null;
        $text='<b>'.self::esc(config('v2board.app_name','V2Board'))."</b>\n套餐：".self::esc($plan?$plan->name:'未订阅')."\n到期：".self::esc($expiry)
            ."\n已用流量：".Helper::trafficConvert($used)."\n剩余流量：".Helper::trafficConvert(max(0,$total-$used))."\n总流量：".Helper::trafficConvert($total);
        if ($action==='account') $text="<b>我的套餐</b>\n名称：".self::esc($plan?$plan->name:'未订阅')."\n到期：".self::esc($expiry)
            ."\n设备上限：".((int)$user->device_limit<=0?'不限':(int)$user->device_limit)
            ."\n限速：".((int)$user->speed_limit<=0?'不限':(int)$user->speed_limit.' Mbps');
        if ($action==='traffic') $text="<b>流量与到期</b>\n已用上传：".Helper::trafficConvert((int)$user->u)."\n已用下载：".Helper::trafficConvert((int)$user->d)
            ."\n剩余流量：".Helper::trafficConvert(max(0,$total-$used))."\n总流量：".Helper::trafficConvert($total)."\n到期：".self::esc($expiry);
        if (!$profile) $text.="\n绑定状态：兼容查询，需在用户中心确认绑定。";
        $buttons=[[self::button('我的套餐','account'),self::button('流量与到期','traffic')],
            [self::button('获取订阅','subscribe'),self::button('通知设置','notifications')]];
        if ($this->website()) $buttons[]=$this->website();
        if ($profile) $buttons[]=[self::button('解除绑定','unbind')];
        $group=PaymentReturnUrl::normalizeBase(config('v2board.telegram_discuss_link'));
        if ($group) $buttons[]=[['text'=>'加入用户群','url'=>$group]];
        if ($action!=='home') $buttons[]=[self::button('返回菜单','home')];
        $this->telegram->sendPanelMessage($chatId,$text,$buttons,$messageId);
    }
}
