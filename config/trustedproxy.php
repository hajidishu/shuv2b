<?php

use Illuminate\Http\Request;

return [
    // Leave empty for a direct deployment. List only proxies you control, e.g.
    // TRUSTED_PROXIES=127.0.0.1,::1 for the local Nginx / API middleware chain.
    'proxies' => array_values(array_filter(array_map('trim', explode(',', (string)env('TRUSTED_PROXIES', ''))))),
    'headers' => Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO,
];
