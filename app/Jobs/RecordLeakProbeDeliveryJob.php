<?php
namespace App\Jobs;
use App\Services\LeakProbeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class RecordLeakProbeDeliveryJob implements ShouldQueue
{
    use Dispatchable, Queueable;
    public $marks; public $at; public $source; public $tries = 3; public $timeout = 30;
    public function __construct(array $marks, $at, $source)
    {
        $this->marks = $marks; $this->at = $at; $this->source = $source;
        $this->onConnection('redis')->onQueue('subscription_audit');
    }
    public function handle(LeakProbeService $probe) { $probe->delivered($this->marks, $this->at, $this->source); }
}
