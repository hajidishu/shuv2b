<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Protocols\General;
use App\Protocols\Singbox\Singbox;
use App\Protocols\Singbox\SingboxOld;
use App\Protocols\ClashMeta;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function subscribe(Request $request)
    {
        $userAgent = (string)$request->userAgent();
        $flag = $request->input('flag') ?? $userAgent;
        $flag = strtolower($flag);
        if (strpos($flag, 'mihomo') !== false) $flag = 'meta';
        $user = $request->user;
        // account not expired and is not banned.
        $userService = new UserService();
        if ($userService->isAvailable($user)) {
            $serverService = new ServerService();
            $servers = $serverService->getAvailableServers($user);
            if($flag) {
                if (strpos($flag, 'sing') === false) {
                    $this->setSubscribeInfoToServers($servers, $user);
                    foreach (array_reverse(glob(app_path('Protocols') . '/*.php')) as $file) {
                        $file = 'App\\Protocols\\' . basename($file, '.php');
                        $class = new $file($user, $servers);
                        if (strpos($flag, $class->flag) !== false) {
                            return $this->renderSubscription($request, $class);
                        }
                    }
                }
                if (strpos($flag, 'sing') !== false) {
                    $version = null;
                    // A format-only query flag must not hide the real client version.
                    foreach ([$flag, $userAgent] as $versionHint) {
                        if (preg_match('/sing-box\s+([0-9.]+)/i', $versionHint, $matches)) {
                            $version = $matches[1];
                            break;
                        }
                    }
                    if (!is_null($version) && version_compare($version, '1.12.0', '>=')) {
                        $class = new Singbox($user, $servers, ['version' => $version]);
                    } else {
                        $class = new SingboxOld($user, $servers);
                    }
                    return $this->renderSubscription($request, $class);
                }
            }
            $class = new General($user, $servers);
            return $this->renderSubscription($request, $class);
        }
    }

    private function renderSubscription(Request $request, $protocol)
    {
        $request->attributes->set('subscription_audit_format', class_basename($protocol));
        $content = $protocol->handle();
        $request->attributes->set('subscription_audit_result', 'success');
        return $content;
    }

    private function setSubscribeInfoToServers(&$servers, $user)
    {
        if (!isset($servers[0])) return;
        if (!(int)config('v2board.show_info_to_server_enable', 0)) return;
        $useTraffic = $user['u'] + $user['d'];
        $totalTraffic = $user['transfer_enable'];
        $remainingTraffic = Helper::trafficConvert($totalTraffic - $useTraffic);
        $expiredDate = $user['expired_at'] ? date('Y-m-d', $user['expired_at']) : '长期有效';
        $userService = new UserService();
        $resetDay = $userService->getResetDay($user);
        array_unshift($servers, array_merge($servers[0], [
            'name' => "套餐到期：{$expiredDate}",
        ]));
        if ($resetDay) {
            array_unshift($servers, array_merge($servers[0], [
                'name' => "距离下次重置剩余：{$resetDay} 天",
            ]));
        }
        array_unshift($servers, array_merge($servers[0], [
            'name' => "剩余流量：{$remainingTraffic}",
        ]));
    }
}
