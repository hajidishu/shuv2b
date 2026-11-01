<?php

namespace App\Jobs;

use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendTelegramJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $telegramId;
    protected $text;
    protected $includeStaff = false;
    protected $managementUserId = null;
    protected $bindingVersion = null;

    public $tries = 3;
    public $timeout = 10;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(int $telegramId, string $text, bool $includeStaff = false, ?int $managementUserId = null, ?int $bindingVersion = null)
    {
        $this->onQueue('send_telegram');
        $this->telegramId = $telegramId;
        $this->text = $text;
        $this->includeStaff = $includeStaff;
        $this->managementUserId = $managementUserId;
        $this->bindingVersion = $bindingVersion;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        // Queued messages must also respect subsequent unbinding, bans and role changes.
        if ($this->managementUserId === null || $this->bindingVersion === null) return;
        if (!TelegramService::managementUsers($this->includeStaff)->whereKey($this->managementUserId)
            ->where('telegram_admin_version', $this->bindingVersion)->where('telegram_admin_id', $this->telegramId)->exists()) return;
        $telegramService = app(TelegramService::class);
        $telegramService->sendMessage($this->telegramId, $this->text, 'markdown');
    }
}
