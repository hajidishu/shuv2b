<?php

$autoload = getenv('P1_VENDOR_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!is_file($autoload)) throw new RuntimeException('Set P1_VENDOR_AUTOLOAD to a Laravel 8 / PHPUnit 9 vendor/autoload.php.');
require $autoload;
$sourceRoot = getenv('P1_SOURCE_ROOT') ?: dirname(__DIR__, 2);
$overrideRoot = getenv('P1_OVERRIDE_ROOT');
spl_autoload_register(function ($class) use ($sourceRoot, $overrideRoot) {
    if (strpos($class, 'App\\') === 0) {
        foreach (array_filter([$overrideRoot, $sourceRoot]) as $root) {
            $file = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
            if (is_file($file)) { require $file; return; }
        }
    }
}, true, true);
require __DIR__ . '/Environment.php';
require __DIR__ . '/PaymentHttpFixture.php';
