<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\TicketSave;
use App\Http\Requests\User\TicketWithdraw;
use App\Jobs\SendTelegramJob;
use App\Models\User;
use App\Models\Plan;
use App\Models\Order;
use App\Services\TelegramService;
use App\Services\TicketService;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Utils\Dict;
use Illuminate\Http\Request;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class TicketController extends Controller
{
    public function fetch(Request $request)
    {
        $userId = $request->user['id'];
        $ticketId = $request->input('id');

        if ($ticketId) {
            $ticket = Ticket::where('id', $ticketId)
                ->where('user_id', $userId)
                ->firstOrFail();

            $ticket['message'] = TicketMessage::where('ticket_id', $ticket->id)->get();
            for ($i = 0; $i < count($ticket['message']); $i++) {
                if ($ticket['message'][$i]['user_id'] !== $ticket->user_id) {
                    $ticket['message'][$i]['is_me'] = false;
                } else {
                    $ticket['message'][$i]['is_me'] = true;
                }
            }

            return response(['data' => $ticket]);

        }
        $ticket = Ticket::where('user_id', $userId)
            ->orderBy('created_at', 'DESC')
            ->get();
        return response([
            'data' => $ticket
        ]);
    }

    public function save(TicketSave $request)
    {
        $this->limitCreation((int)$request->user['id']);
        try {
            DB::beginTransaction();
            User::whereKey($request->user['id'])->lockForUpdate()->firstOrFail();
            if ((int)Ticket::where('status', 0)->where('user_id', $request->user['id'])->lockForUpdate()->count()) {
                throw new \Exception(__('There are other unresolved tickets'));
            }

            // 获取工单状态
            $ticketStatus = config('v2board.ticket_status', 0);

            switch ($ticketStatus) {
                case 0:
                    // 完全开放，不禁止任何工单
                    break;
                case 1:
                    // 仅限有付费订单用户
                    $hasOrder = Order::where('user_id', $request->user['id'])
                        ->whereIn('status', [3, 4])
                        ->exists();

                    if (!$hasOrder) {
                        throw new \Exception(__('请先购买套餐'));
                    }
                    break;
                case 2:
                    // 完全禁止所有工单
                    throw new \Exception(__('当前套餐不允许发起工单'));
                    break;
                default:
                    // 处理未知状态
                    throw new \Exception(__('未知的工单状态'));
            }

            $ticketData = $request->only(['subject', 'level']) + ['user_id' => $request->user['id']];
            $ticket = Ticket::create($ticketData);

            TicketMessage::create([
                'user_id' => $request->user['id'],
                'ticket_id' => $ticket->id,
                'message' => $request->input('message')
            ]);

            DB::commit();
            $this->sendNotify($ticket, $request->input('message'),$request->user['id']);
            return response([
                'data' => true
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            abort(500, $e->getMessage());
        }
    }

    public function reply(Request $request)
    {
        if (empty($request->input('id'))) {
            abort(500, __('Invalid parameter'));
        }
        if (empty($request->input('message'))) {
            abort(500, __('Message cannot be empty'));
        }
        $ticket = Ticket::where('id', $request->input('id'))
            ->where('user_id', $request->user['id'])
            ->first();
        if (!$ticket) {
            abort(500, __('Ticket does not exist'));
        }
        if ($ticket->status) {
            abort(500, __('The ticket is closed and cannot be replied'));
        }
        if ($request->user['id'] == $this->getLastMessage($ticket->id)->user_id) {
            abort(500, __('Please wait for the technical enginneer to reply'));
        }
        $ticketService = new TicketService();
        if (
			!$ticketService->reply(
				$ticket,
				$request->input('message'),
				$request->user['id']
			)
		) {
            abort(500, __('Ticket reply failed'));
        }
        $this->sendNotify($ticket, $request->input('message'), $request->user['id']);
        return response([
            'data' => true
        ]);
    }


    public function close(Request $request)
    {
        if (empty($request->input('id'))) {
            abort(500, __('Invalid parameter'));
        }
        $ticket = Ticket::where('id', $request->input('id'))
            ->where('user_id', $request->user['id'])
            ->first();
        if (!$ticket) {
            abort(500, __('Ticket does not exist'));
        }
        $ticket->status = 1;
        if (!$ticket->save()) {
            abort(500, __('Close failed'));
        }
        return response([
            'data' => true
        ]);
    }

    private function getLastMessage($ticketId)
    {
        return TicketMessage::where('ticket_id', $ticketId)
            ->orderBy('id', 'DESC')
            ->first();
    }

    public function withdraw(TicketWithdraw $request)
    {
        $this->limitCreation((int)$request->user['id']);
        if ((int)config('v2board.withdraw_close_enable', 0)) {
            abort(500, 'user.ticket.withdraw.not_support_withdraw');
        }
        if (
			!in_array(
				$request->input('withdraw_method'),
				config(
					'v2board.commission_withdraw_method',
					Dict::WITHDRAW_METHOD_WHITELIST_DEFAULT
				)
			)
		) {
            abort(500, __('Unsupported withdrawal method'));
        }
        $user = User::find($request->user['id']);
        $limit = config('v2board.commission_withdraw_limit', 100);
        if ($limit > ($user->commission_balance / 100)) {
            abort(500, __('The current required minimum withdrawal commission is :limit', ['limit' => $limit]));
        }
        DB::beginTransaction();
        $subject = __('[Commission Withdrawal Request] This ticket is opened by the system');
        $ticket = Ticket::create([
            'subject' => $subject,
            'level' => 2,
            'user_id' => $request->user['id']
        ]);
        if (!$ticket) {
            DB::rollback();
            abort(500, __('Failed to open ticket'));
        }
        $message = sprintf(
			"%s\r\n%s",
            __('Withdrawal method') . "：" . $request->input('withdraw_method'),
            __('Withdrawal account') . "：" . $request->input('withdraw_account')
        );
        $ticketMessage = TicketMessage::create([
            'user_id' => $request->user['id'],
            'ticket_id' => $ticket->id,
            'message' => $message
        ]);
        if (!$ticketMessage) {
            DB::rollback();
            abort(500, __('Failed to open ticket'));
        }
        DB::commit();
        $this->sendNotify($ticket, $message);
        return response([
            'data' => true
        ]);
    }

    private function sendNotify(Ticket $ticket, string $message, $userid = null)
    {
        try {
            $this->sendNotification($ticket, $message, $userid);
        } catch (\Throwable $error) {
            // The ticket is already committed. Notification errors must not turn
            // a successful submission/reply into a failure or expose bot tokens.
            try {
                Log::channel('daily')->warning('Ticket notification failed', ['ticket_id' => $ticket->id, 'exception' => get_class($error)]);
            } catch (\Throwable $loggingError) {
                // Logging is best effort after the ticket has been committed.
            }
        }
    }

    private function sendNotification(Ticket $ticket, string $message, $userid = null)
	{
		if (!config('v2board.telegram_bot_enable', 0)) return;
		$telegramService = new TelegramService();
		if (!empty($userid)) {
			$user = User::find($userid);

			if ($user) {
				$transfer_enable = $this->getFlowData($user->transfer_enable); // 总流量
				$remaining_traffic = $this->getFlowData($user->transfer_enable - $user->u - $user->d); // 剩余流量
				$u = $this->getFlowData($user->u); // 上传
				$d = $this->getFlowData($user->d); // 下载
				$expired_at = date("Y-m-d H:i:s", $user->expired_at); // 到期时间
				$ip = request()->ip();
				$ip_address = filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '未知';
				$location = $this->lookupLocation($ip_address);

				$plan = Plan::where('id', $user->plan_id)->first();
				$planName = $plan ? $plan->name : '未找到套餐信息'; // Check if plan data is available

				$money = $user->balance / 100;
				$affmoney = $user->commission_balance / 100;
				$telegramService->sendMessageWithAdmin("📮工单提醒 #{$ticket->id}\n———————————————\n邮箱：\n`{$user->email}`\n用户位置：\n`{$location}`\nIP:\n{$ip_address}\n套餐与流量：\n`{$planName} of {$transfer_enable}/{$remaining_traffic}`\n上传/下载：\n`{$u}/{$d}`\n到期时间：\n`{$expired_at}`\n余额/佣金余额：\n`{$money}/{$affmoney}`\n主题：\n`{$ticket->subject}`\n内容：\n {$message} ", true);
			} else {
				// Handle case where user data is not found
				$telegramService->sendMessageWithAdmin("User data not found for user ID: {$userid}", true);
			}
		} else {
			$telegramService->sendMessageWithAdmin("📮工单提醒 #{$ticket->id}\n———————————————\n主题：\n`{$ticket->subject}`\n内容：\n {$message} ", true);
		}
	}

    private function lookupLocation(string $ip): string
    {
        $unknown = '无法确定用户地址';
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) return $unknown;
        $packed = inet_pton($ip);
        // IPv4-mapped IPv6 addresses need the same private/range checks as IPv4.
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            $ip = inet_ntop(substr($packed, 12));
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) return $unknown;
        try {
            $response = Http::withOptions(['connect_timeout' => 1, 'allow_redirects' => false])->timeout(2)
                ->get('http://ip-api.com/json/' . rawurlencode($ip), ['fields' => 520191, 'lang' => 'zh-CN']);
            if (!$response->successful()) return $unknown;
            $data = $response->json();
            if (($data['status'] ?? null) === 'success' && is_string($data['city'] ?? null) && is_string($data['country'] ?? null)) {
                return $data['city'] . ', ' . $data['country'];
            }
        } catch (\Throwable $error) {
            // IP location is optional; a timeout or malformed response is not a ticket error.
        }
        return $unknown;
    }

    private function limitCreation(int $userId): void
    {
        $limit = (int)config('v2board.ticket_create_limit', 5);
        if ($limit <= 0) return;
        $key = 'TICKET_CREATE_RATE:' . $userId;
        try {
            Cache::lock($key . ':lock', 5)->block(2, function () use ($key, $limit) {
                if (RateLimiter::tooManyAttempts($key, $limit)) {
                    abort(429, '工单提交过于频繁，请稍后重试', ['Retry-After' => RateLimiter::availableIn($key)]);
                }
                RateLimiter::hit($key, 60);
            });
        } catch (LockTimeoutException $error) {
            abort(429, '工单提交过于频繁，请稍后重试');
        }
    }

    private function getFlowData($b)
    {
        $g = $b / (1024 * 1024 * 1024); // 转换流量数据
        $m = $b / (1024 * 1024);
        if ($g >= 1) {
            $text = round($g, 2) . "GB";
        } else {
            $text = round($m, 2) . "MB";
        }
        return $text;
    }
}
