<?php

namespace App\Jobs;

use App\Services\SubscriptionAuditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class PruneSubscriptionAuditJob implements ShouldQueue
{
    use Dispatchable, Queueable;
    public $tries = 3;
    public $timeout = 30;

    public function __construct()
    {
        $this->onConnection('redis')->onQueue('subscription_audit');
    }

    public function handle(SubscriptionAuditService $audit)
    {
        $audit->prune();
    }
}
