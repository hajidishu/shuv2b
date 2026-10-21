<?php
namespace App\Http\Middleware;
use App\Jobs\RecordLeakProbeDeliveryJob;
use Closure;
use Illuminate\Support\Facades\Log;

class LeakProbeDelivery
{
    public function handle($request, Closure $next)
    {
        $at = time();
        $request->attributes->set('leak_probe_deliveries', []);
        $response = $next($request);
        $marks = array_values($request->attributes->get('leak_probe_deliveries', []));
        if ($marks && $response->getStatusCode() === 200) {
            $source = strpos($request->path(), 'app/getConfig') !== false ? 'app' : (strpos($request->path(), 'user/server/fetch') !== false ? 'user_api' : 'subscribe');
            try { RecordLeakProbeDeliveryJob::dispatch($marks, $at, $source); }
            catch (\Throwable $error) { Log::warning('Leak probe delivery enqueue failed'); }
        }
        return $response;
    }
}
