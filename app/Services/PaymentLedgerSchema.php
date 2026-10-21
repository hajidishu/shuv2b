<?php
namespace App\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

class PaymentLedgerSchema
{
    public function ensure()
    {
        $schema = DB::connection()->getSchemaBuilder();
        if (!$schema->hasTable('v2_payment_attempt')) $schema->create('v2_payment_attempt', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->string('trade_no', 64)->unique();
            $t->unsignedInteger('order_id'); $t->unsignedInteger('user_id'); $t->unsignedInteger('payment_id');
            $t->string('method', 64); $t->string('gateway_uuid', 64); $t->text('config_snapshot')->nullable(); $t->char('config_hash', 64); $t->char('request_hash', 64);
            $t->unsignedBigInteger('base_amount'); $t->unsignedBigInteger('handling_amount')->default(0); $t->unsignedBigInteger('total_amount');
            $t->unsignedBigInteger('gateway_amount_minor')->nullable(); $t->string('gateway_currency', 8)->nullable();
            $t->string('status', 16)->default('creating'); $t->char('lease_token', 32)->nullable(); $t->unsignedBigInteger('lease_at')->nullable();
            $t->text('response')->nullable(); $t->unsignedBigInteger('created_at'); $t->unsignedBigInteger('updated_at');
            $t->index(['order_id','payment_id','id']);
            $t->index(['gateway_uuid','method','id']);
        });
        if (!$schema->hasTable('v2_payment_receipt')) $schema->create('v2_payment_receipt', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->unsignedBigInteger('attempt_id')->nullable();
            $t->unsignedInteger('order_id'); $t->unsignedInteger('user_id'); $t->unsignedInteger('payment_id');
            $t->string('callback_no', 191); $t->unsignedBigInteger('amount'); $t->unsignedBigInteger('wallet_amount')->default(0);
            $t->string('disposition', 16); $t->string('reason', 64)->nullable(); $t->unsignedBigInteger('created_at');
            $t->unique(['payment_id','callback_no']); $t->index(['order_id','id']); $t->index(['user_id','id']);
        });
        if (!$schema->hasColumn('v2_payment_attempt', 'gateway_uuid')) $schema->table('v2_payment_attempt', function (Blueprint $t) { $t->string('gateway_uuid', 64)->nullable(); });
        if (!$schema->hasColumn('v2_payment_attempt', 'config_snapshot')) $schema->table('v2_payment_attempt', function (Blueprint $t) { $t->text('config_snapshot')->nullable(); });
        foreach (['v2_payment_attempt' => ['id','trade_no','order_id','user_id','payment_id','method','gateway_uuid','config_snapshot','config_hash','request_hash','base_amount','handling_amount','total_amount','gateway_amount_minor','gateway_currency','status','lease_token','lease_at','response','created_at','updated_at'],
            'v2_payment_receipt' => ['id','attempt_id','order_id','user_id','payment_id','callback_no','amount','wallet_amount','disposition','reason','created_at']] as $table => $columns) {
            foreach ($columns as $column) if (!$schema->hasColumn($table, $column)) throw new \RuntimeException('支付台账结构不完整：' . $table . '.' . $column);
        }
    }
    public static function installed()
    {
        $schema = DB::connection()->getSchemaBuilder();
        return $schema->hasTable('v2_payment_attempt') && $schema->hasTable('v2_payment_receipt');
    }
    public static function requireInstalled()
    {
        if (!self::installed()) abort(503, '请先执行 php artisan payment-ledger:install 完成支付台账升级');
    }
}
