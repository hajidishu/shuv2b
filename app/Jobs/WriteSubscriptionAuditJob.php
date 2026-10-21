<?php

namespace App\Jobs;

use App\Services\SubscriptionAuditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class WriteSubscriptionAuditJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 3;
    public $timeout = 30;
    public $backoff = [2, 10, 30];
    public $event;

    public function __construct(array $event)
    {
        $this->event = $event;
        $this->onConnection('redis')->onQueue('subscription_audit');
    }

    public function handle(SubscriptionAuditService $audit)
    {
        $audit->append($this->event);
    }
}
