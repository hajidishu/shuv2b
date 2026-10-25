<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class PasskeyRequest
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->method() !== 'GET') {
            $type = strtolower(trim(explode(';', (string)$request->header('Content-Type'))[0]));
            if ($type !== 'application/json') abort(415, 'Passkey 接口需要 JSON 请求');
            if (strlen($request->getContent()) > 100000) abort(413, 'Passkey 请求过大');
            $data = json_decode($request->getContent(), true);
            if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) abort(422, 'Passkey 请求格式不正确');
        }
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        return $response;
    }
}
