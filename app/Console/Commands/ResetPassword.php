<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Utils\Helper;
use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ResetPassword extends Command
{
    protected $builder;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reset:password {email}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '重置用户密码';

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
        $password = Helper::guid(false);
        DB::transaction(function () use ($password) {
            $user = User::where('email', $this->argument('email'))->lockForUpdate()->first();
            if (!$user) abort(500, '邮箱不存在');
            $user->password = password_hash($password, PASSWORD_DEFAULT);
            $user->password_algo = null;
            $user->password_salt = null;
            if (!$user->save()) abort(500, '重置失败');
            \App\Services\PasskeyService::revokeOnRecovery($user);
        }, 3);
        $this->info("!!!重置成功!!!");
        $this->info("新密码为：{$password}，请尽快修改密码。");
    }
}
