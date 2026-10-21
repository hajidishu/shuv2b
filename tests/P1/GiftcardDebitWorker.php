<?php

require __DIR__ . '/bootstrap.php';
\Tests\P1\Environment::boot(false);
$request = new \App\Http\Requests\User\OrderSave();
$request->merge(['user' => ['id' => (int)$argv[1]], 'plan_id' => (int)$argv[2], 'period' => $argv[4] ?? 'month_price']);
file_put_contents($argv[3] . '/started', 'started');
(new \App\Http\Controllers\V1\User\OrderController())->save($request);
echo json_encode(['result' => true]), PHP_EOL;
