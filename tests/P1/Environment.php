<?php

namespace Tests\P1;

use Illuminate\Bus\Dispatcher;
use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher as EventDispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class Environment
{
    public static $app;
    public static $capsule;
    public static $queue;

    public static function boot($reset = true)
    {
        $app = new Application(dirname(__DIR__, 2));
        Application::setInstance($app);
        $driver = getenv('P1_DB_DRIVER') ?: 'sqlite';
        $database = $driver === 'mysql' ? getenv('P1_DB_DATABASE') : ':memory:';
        if ($driver === 'mysql' && !preg_match('/\Av2board_p1_[a-z0-9_]+\z/', $database ?: '')) {
            throw new \RuntimeException('Tests require an explicitly named v2board_p1_* disposable database.');
        }
        $connection = [
            'driver' => $driver, 'database' => $database,
            'host' => getenv('P1_DB_HOST') ?: '127.0.0.1',
            'port' => getenv('P1_DB_PORT') ?: 33079,
            'username' => getenv('P1_DB_USER') ?: 'root',
            'password' => getenv('P1_DB_PASSWORD') ?: '',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '', 'strict' => true, 'engine' => 'InnoDB'
        ];
        $app->instance('config', new Repository([
            'database' => ['default' => 'p1', 'connections' => ['p1' => $connection]],
            'v2board' => ['telegram_bot_enable' => 0],
            'app' => ['key' => 'base64:' . base64_encode(str_repeat('t', 32)), 'cipher' => 'AES-256-CBC']
        ]));
        $app->instance('translator', new Translator(new ArrayLoader(), 'en'));
        $app->instance('log', new NullLogger());
        $app->instance('cache', new \Illuminate\Cache\Repository(new \Illuminate\Cache\ArrayStore()));
        $app->instance(\Illuminate\Contracts\Routing\ResponseFactory::class, new class {
            public function make($data = '', $status = 200, $headers = []) { return new JsonResponse($data, $status, $headers); }
            public function json($data = [], $status = 200, $headers = [], $options = 0) { return new JsonResponse($data, $status, $headers, $options); }
        });
        $capsule = new Capsule($app);
        $capsule->addConnection($connection, 'p1');
        $capsule->getDatabaseManager()->setDefaultConnection('p1');
        $capsule->setEventDispatcher(new EventDispatcher($app));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::clearBootedModels();
        $app->instance('db', $capsule->getDatabaseManager());
        $capsule->getConnection('p1')->setTransactionManager(new DatabaseTransactionsManager());
        $queue = new QueueFake($app);
        $app->instance('queue', $queue);
        $bus = new Dispatcher($app, function () use ($queue) { return $queue; });
        $app->instance(\Illuminate\Contracts\Bus\Dispatcher::class, $bus);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        self::$app = $app;
        self::$capsule = $capsule;
        self::$queue = $queue;
        if ($reset) self::createSchema();
        return $app;
    }

    private static function createSchema()
    {
        $schema = self::$capsule->getConnection('p1')->getSchemaBuilder();
        foreach (['v2_passkey_credential', 'v2_passkey_user', 'v2_payment_receipt', 'v2_payment_attempt', 'v2_commission_log', 'v2_giftcard', 'v2_coupon', 'v2_payment', 'v2_order', 'v2_plan', 'v2_user'] as $table) $schema->dropIfExists($table);
        $schema->create('v2_user', function (Blueprint $t) {
            $t->increments('id'); $t->string('email')->unique(); $t->string('password')->nullable();
            foreach (['is_admin', 'is_staff', 'banned', 'commission_type', 'auto_renewal'] as $key) $t->integer($key)->default(0);
            foreach (['balance', 'commission_balance', 'transfer_enable', 'u', 'd'] as $key) $t->bigInteger($key)->default(0);
            foreach (['plan_id', 'group_id', 'expired_at', 'device_limit', 'speed_limit', 'discount', 'invite_user_id', 'commission_rate'] as $key) $t->integer($key)->nullable();
            foreach (['password_algo', 'password_salt', 'token', 'uuid'] as $key) $t->string($key)->nullable();
            $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
        });
        $schema->create('v2_plan', function (Blueprint $t) {
            $t->increments('id'); $t->string('name')->default('test');
            $t->integer('show')->default(1); $t->integer('renew')->default(1);
            $t->integer('group_id')->default(1); $t->integer('transfer_enable')->default(100);
            foreach (['month_price', 'onetime_price', 'reset_price', 'capacity_limit', 'device_limit', 'speed_limit'] as $key) $t->integer($key)->nullable();
            $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
        });
        $schema->create('v2_order', function (Blueprint $t) {
            $t->increments('id'); $t->integer('user_id'); $t->integer('plan_id')->default(0);
            $t->string('trade_no')->unique(); $t->string('callback_no')->nullable(); $t->string('period')->default('deposit');
            $t->integer('type')->default(9); $t->integer('status')->default(0); $t->integer('commission_status')->default(0);
            foreach (['total_amount', 'commission_balance', 'actual_commission_balance'] as $key) $t->integer($key)->default(0);
            foreach (['payment_id', 'handling_amount', 'balance_amount', 'discount_amount', 'surplus_amount', 'refund_amount', 'invite_user_id', 'coupon_id', 'paid_at'] as $key) $t->integer($key)->nullable();
            $t->text('surplus_order_ids')->nullable();
            $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
        });
        $schema->create('v2_commission_log', function (Blueprint $t) {
            $t->increments('id'); $t->integer('invite_user_id'); $t->integer('user_id'); $t->string('trade_no');
            $t->integer('order_amount'); $t->integer('get_amount');
            $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
        });
        $schema->create('v2_payment', function (Blueprint $t) {
            $t->increments('id'); $t->string('uuid'); $t->string('payment'); $t->text('config');
            $t->integer('enable')->default(1); $t->string('notify_domain')->nullable();
            $t->integer('handling_fee_fixed')->nullable(); $t->decimal('handling_fee_percent', 10, 2)->nullable();
            $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
        });
        $schema->create('v2_coupon', function (Blueprint $t) {
            $t->increments('id'); $t->string('code'); $t->integer('show')->default(1);
            $t->integer('type')->default(1); $t->integer('value');
            foreach (['limit_use', 'limit_use_with_user'] as $key) $t->integer($key)->nullable();
            foreach (['limit_plan_ids', 'limit_period'] as $key) $t->text($key)->nullable();
            $t->integer('started_at'); $t->integer('ended_at');
            $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
        });
        $schema->create('v2_giftcard', function (Blueprint $t) {
            $t->increments('id'); $t->string('code'); $t->integer('type'); $t->integer('value')->nullable();
            $t->integer('plan_id')->nullable(); $t->integer('limit_use')->nullable();
            $t->text('used_user_ids')->nullable();
            $t->integer('started_at')->nullable(); $t->integer('ended_at')->nullable();
            $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
        });
        (new \App\Services\PaymentLedgerSchema())->ensure();
    }
}

abstract class DatabaseTestCase extends TestCase
{
    protected function setUp(): void
    {
        Environment::boot();
        PaymentHttpFixture::$responses = PaymentHttpFixture::$requests = [];
    }
    protected function tearDown(): void
    {
        \Mockery::close();
        Environment::$capsule->getDatabaseManager()->disconnect('p1');
    }
    protected function user(array $values = [])
    {
        return \App\Models\User::create(array_merge(['email' => 'user' . uniqid() . '@example.test'], $values));
    }
    protected function order($user, array $values = [])
    {
        return \App\Models\Order::create(array_merge(['user_id' => $user->id, 'trade_no' => uniqid('order-'), 'total_amount' => 10000], $values));
    }
}
