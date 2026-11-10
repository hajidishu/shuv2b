<?php

use Illuminate\Http\Request;

$proxies = env('TRUSTED_PROXIES');
// Missing and blank values include existing installations using the old defaults.
if ($proxies === null || (is_string($proxies) && trim($proxies) === '')) {
    $proxies = '127.0.0.1,::1';
}

return [
    // Trust local Nginx / API middleware by default. An explicit list replaces
    // this default; TRUSTED_PROXIES=false disables forwarded-header trust.
    'proxies' => array_values(array_filter(array_map('trim', explode(',', (string)$proxies)))),
    'headers' => Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO,
];
