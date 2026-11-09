<?php

require __DIR__ . '/bootstrap.php';

use App\Models\User;
use App\Services\LeakProbeService as Probe;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\P1\Environment;

Environment::boot(false);
$operation = $argv[1];
$id = (int)$argv[2];
$gate = $operation === 'advance-fail' ? $argv[4] : $argv[3];
$connectionId = (int)DB::selectOne('SELECT CONNECTION_ID() AS id')->id;

if ($operation === 'advance-fail') {
    $roundId = (int)$argv[3];
    DB::listen(function ($query) use ($gate, $connectionId) {
        $sql = strtolower($query->sql);
        if (strpos($sql, 'v2_user') === false || strpos($sql, 'for update') === false) return;
        file_put_contents($gate . '/candidate-locked', (string)$connectionId);
        $deadline = microtime(true) + 20;
        while (!is_file($gate . '/release-advance')) {
            if (microtime(true) >= $deadline) throw new RuntimeException('Timed out waiting for advance release.');
            usleep(10000);
            clearstatcache(true, $gate . '/release-advance');
        }
    });

    try {
        (new Probe())->mutate([
            'id' => $id,
            'round_id' => $roundId,
            'action' => 'advance',
            'branch' => 'a',
            'a_node_id' => 999999,
            'b_node_id' => 1000000,
        ], 'race@example.test');
        fwrite(STDERR, "Expected invalid next-round nodes to abort advancement.\n");
        exit(1);
    } catch (HttpException $error) {
        if ($error->getStatusCode() !== 422) throw $error;
        echo json_encode(['status' => $error->getStatusCode()]), PHP_EOL;
    }
} elseif ($operation === 'delete-user') {
    file_put_contents($gate . '/delete-connection-id', (string)$connectionId);
    file_put_contents($gate . '/delete-attempting', '1');
    DB::transaction(function () use ($id) {
        $user = User::where('id', $id)->orderBy('id')->lockForUpdate()->firstOrFail();
        $user->delete();
    }, 1);
    file_put_contents($gate . '/delete-done', '1');
    echo json_encode(['deleted' => true]), PHP_EOL;
} else {
    throw new RuntimeException('Unknown leak-probe race operation: ' . $operation);
}
