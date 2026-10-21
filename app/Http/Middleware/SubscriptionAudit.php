<?php

namespace App\Http\Middleware;

use App\Jobs\WriteSubscriptionAuditJob;
use App\Services\SubscriptionAuditRequest;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class SubscriptionAudit
{
    public function handle(Request $request, Closure $next)
    {
        if (!config('subscription_audit.enabled', true)) return $next($request);
        $user = $request->user;
        if (!$user || !$user->id) return $next($request);
        $ip = $request->ip();
        $packed = @inet_pton((string)$ip);
        $started = microtime(true);
        $metadata = new SubscriptionAuditRequest($request, $user);
        $details = $metadata->capture($request);
        $event = [
            'event_id' => bin2hex(random_bytes(16)), 'user_id' => (int)$user->id,
            'email' => mb_substr((string)$user->email, 0, 64, 'UTF-8'), 'ip' => $packed === false ? '' : inet_ntop($packed),
            'ua' => $metadata->text($request->userAgent() ?? '', 512), 'format' => '',
            'result' => 'error', 'status' => 500, 'requested_at' => time(),
        ];
        try {
            $response = $next($request);
            $event['status'] = $response->getStatusCode();
            $event['result'] = $request->attributes->get('subscription_audit_result', 'unavailable');
            if ($event['status'] >= 400) $event['result'] = 'error';
            return $response;
        } catch (\Throwable $error) {
            $event['status'] = $error instanceof HttpExceptionInterface ? $error->getStatusCode() : 500;
            throw $error;
        } finally {
            $event['format'] = $metadata->text($request->attributes->get('subscription_audit_format', ''), 64);
            $details['duration_ms'] = (int)round((microtime(true) - $started) * 1000);
            $details['leak_probe'] = array_values($request->attributes->get('leak_probe_deliveries', []));
            $event['request_details'] = $metadata->encode($details);
            try {
                WriteSubscriptionAuditJob::dispatch($event);
            } catch (\Throwable $error) {
                // Never log a Request/exception message here: it can contain the subscription token.
                Log::warning('Subscription audit enqueue failed', ['event_id' => $event['event_id'], 'user_id' => $event['user_id']]);
            }
        }
    }
}
