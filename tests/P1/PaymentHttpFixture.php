<?php

namespace Tests\P1 {
    class PaymentHttpFixture
    {
        public static $responses = [];
        public static $requests = [];
    }
}

namespace App\Payments {
    // Keep signed webhook handling real, replace only the remote invoice lookup.
    function file_get_contents($url, ...$args)
    {
        \Tests\P1\PaymentHttpFixture::$requests[] = $url;
        if (!array_key_exists($url, \Tests\P1\PaymentHttpFixture::$responses)) {
            throw new \RuntimeException('Unexpected network request: ' . $url);
        }
        return \Tests\P1\PaymentHttpFixture::$responses[$url];
    }
}
