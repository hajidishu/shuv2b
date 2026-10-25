<?php

namespace App\Http\Middleware;

use Fideloper\Proxy\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * @var array|string
     */
    protected $proxies;

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected function getTrustedHeaderNames()
    {
        // Fideloper 4 converts unknown bitmasks back to ALL. Return this mask
        // directly so forwarding a client IP never grants control over Host.
        return Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO;
    }
}
