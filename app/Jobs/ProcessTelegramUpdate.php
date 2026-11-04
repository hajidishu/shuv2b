<?php
namespace App\Jobs;

use App\Exceptions\TelegramApiException;
use App\Http\Controllers\V1\Guest\TelegramController;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class ProcessTelegramUpdate implements ShouldQueue
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;
    public $tries=5;
    public $timeout=25;
    public $backoff=[15,60,300];
    private $update;
    private $botVersion;
    private $key;

    public function __construct(array $update,string $botVersion,string $key)
    {
        $this->onQueue('send_telegram');$this->update=$update;$this->botVersion=$botVersion;$this->key=$key;
    }

    public function handle(): void
    {
        \App\Services\TelegramRuntime::refresh();
        $token=(string)config('v2board.telegram_bot_token');
        if ($token==='' || !hash_equals($this->botVersion,hash('sha256',$token)) || Cache::get($this->key)==='done') return;
        $lock=Cache::lock($this->key.':running',30);
        if (!$lock->get()) {if ($this->job) $this->release(5);return;}
        try {
            if (Cache::get($this->key)==='done') return;
            $controller=new TelegramController(Request::create('/','POST',['access_token'=>md5($token)]));
            $controller->processUpdate($this->update);
            Cache::put($this->key,'done',86400);
        } catch (TelegramApiException $e) {
            if ($e->retryAfter && $this->job) {$this->release(min(3600,max(1,$e->retryAfter)));return;}
            throw $e;
        } finally {$lock->release();}
    }

    public function failed(): void
    {
        if (Cache::get($this->key)==='queued') Cache::forget($this->key);
    }
}
