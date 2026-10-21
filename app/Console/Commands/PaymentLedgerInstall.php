<?php
namespace App\Console\Commands;
use App\Services\PaymentLedgerSchema;
use Illuminate\Console\Command;
class PaymentLedgerInstall extends Command
{
    protected $signature = 'payment-ledger:install';
    protected $description = '安装并校验支付尝试及收款台账';
    public function handle(PaymentLedgerSchema $schema) { $schema->ensure(); $this->info('支付台账校验通过'); return 0; }
}
