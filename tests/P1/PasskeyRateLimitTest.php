<?php

namespace Tests\P1;

use App\Services\PasskeyChallenge;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PasskeyExpiryBoundaryStore extends ArrayStore
{
    public $advanceOnRead = false;
    public $failRateWrite = false;

    public function get($key)
    {
        $value = parent::get($key);
        if ($this->advanceOnRead && strpos($key, 'PASSKEY_RATE_') === 0) {
            $this->advanceOnRead = false;
            Carbon::setTestNow(Carbon::now()->addSeconds(61));
        }
        return $value;
    }

    public function put($key, $value, $seconds)
    {
        if ($this->failRateWrite && strpos($key, 'PASSKEY_RATE_') === 0) return false;
        return parent::put($key, $value, $seconds);
    }
}

class PasskeyRateLimitTest extends DatabaseTestCase
{
    private $store;
    private $limiter;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-26 12:00:00 UTC');
        $this->store = new PasskeyExpiryBoundaryStore();
        app()->instance('cache', new Repository($this->store));
        Cache::clearResolvedInstance('cache');
        $this->limiter = new PasskeyChallenge();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function rejected(callable $operation, int $status = 429): void
    {
        try { $operation(); $this->fail('Expected the rate limiter to reject the request'); }
        catch (HttpException $e) { $this->assertSame($status, $e->getStatusCode()); }
    }

    public function testFixedWindowDoesNotSlideOrBlockUnrelatedScopes(): void
    {
        $this->limiter->limit('login:fixture', 2, 60);
        Carbon::setTestNow(Carbon::now()->addSeconds(30));
        $this->limiter->limit('login:fixture', 2, 60);
        $this->rejected(function () { $this->limiter->limit('login:fixture', 2, 60); });
        $this->limiter->limit('another-scope', 2, 60);
        Carbon::setTestNow(Carbon::now()->addSeconds(31));
        $this->limiter->limit('login:fixture', 2, 60);
        $this->limiter->limit('login:fixture', 2, 60);
        $this->rejected(function () { $this->limiter->limit('login:fixture', 2, 60); });
    }

    public function testExpirationDuringCounterReadCannotCreateAPermanentLockout(): void
    {
        $this->limiter->limit('reauth:fixture', 1, 60);
        $this->store->advanceOnRead = true;
        $this->limiter->limit('reauth:fixture', 1, 60);
        $this->rejected(function () { $this->limiter->limit('reauth:fixture', 1, 60); });
        Carbon::setTestNow(Carbon::now()->addSeconds(61));
        $this->limiter->limit('reauth:fixture', 1, 60);
        $this->rejected(function () { $this->limiter->limit('reauth:fixture', 1, 60); });
    }

    public function testCounterWriteFailureRejectsInsteadOfAllowingUncountedRequests(): void
    {
        $this->store->failRateWrite = true;
        $this->rejected(function () { $this->limiter->limit('write-failure', 1, 60); }, 503);
        $this->store->failRateWrite = false;
        $this->limiter->limit('write-failure', 1, 60);
        $this->rejected(function () { $this->limiter->limit('write-failure', 1, 60); });
    }
}
