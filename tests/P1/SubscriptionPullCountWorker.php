<?php
require __DIR__ . '/bootstrap.php';
\Tests\P1\Environment::boot(false);
$id = (int)($argv[1] ?? 0); $iterations = (int)($argv[2] ?? 0);
if ($id < 1 || $iterations < 1 || $iterations > 100) throw new RuntimeException('Invalid fixture worker parameters');
$service = new \App\Services\SubscriptionAuditService();
for ($i = 0; $i < $iterations; $i++) $service->recordPull($id);
