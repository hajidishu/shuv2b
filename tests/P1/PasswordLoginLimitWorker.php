<?php

require __DIR__ . '/bootstrap.php';

use App\Services\PasswordLoginLimiter;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\P1\Environment;

Environment::boot(false);
$directory = $argv[1];
$worker = $argv[2];
$operation = $argv[3] ?? 'throttle';
Cache::swap(new Repository(new FileStore(new Filesystem(), $directory)));
file_put_contents($directory . '/' . $worker . '.ready', 'ready');
$deadline = microtime(true) + 15;
while (!is_file($directory . '/go')) {
    if (microtime(true) > $deadline) throw new RuntimeException('Limiter worker barrier timed out');
    usleep(10000);
}
$limiter = new PasswordLoginLimiter();
if ($operation === 'verify') {
    config(['v2board.password_limit_enable' => 1, 'v2board.password_limit_count' => 100, 'v2board.password_limit_expire' => 60]);
    $user = new \App\Models\User();
    $user->id = 987654;
    $user->password = password_hash('actual-password', PASSWORD_DEFAULT);
    $limiter->verify($user, 'parallel@example.test', 'wrong-password');
} else {
    $limiter->throttle(Request::create('/', 'POST', [], [], [], ['REMOTE_ADDR' => '192.0.2.99']));
}
echo "ok\n";
