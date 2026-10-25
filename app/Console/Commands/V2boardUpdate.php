<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class V2boardUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'v2board:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'v2board 更新';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        \Artisan::call('config:cache');
        DB::connection()->getPdo();
        $file = \File::get(base_path() . '/database/update.sql');
        if (!$file) {
            abort(500, '数据库文件不存在');
        }
        $this->info('正在导入数据库请稍等...');
        $upgrade = app(\App\Services\DatabaseUpgrade::class);
        $upgrade->run($file, true);
        $upgrade->verify(\File::get(base_path('database/install.sql')));
        // Unlike legacy SQL replay, audit schema errors must fail the upgrade visibly.
        app(\App\Services\SubscriptionAuditSchema::class)->ensure();
        app(\App\Services\LeakProbeSchema::class)->ensure();
        app(\App\Services\PaymentLedgerSchema::class)->ensure();
        app(\App\Services\PasskeySchema::class)->ensure();
        \Artisan::call('route:clear');
        \Artisan::call('horizon:terminate');
        $this->info('更新完毕，队列服务已重启，你无需进行任何操作。');
        return 0;
    }
}
