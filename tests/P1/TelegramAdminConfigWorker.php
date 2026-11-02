<?php
require __DIR__ . '/bootstrap.php';
Tests\P1\Environment::boot(false);

Illuminate\Support\Facades\File::swap(new class {
    public function put($path, $contents) {
        throw new RuntimeException('Saving only the Telegram administrator must not rewrite configuration or restart workers');
    }
});
Illuminate\Support\Facades\Artisan::swap(new class {
    public function call($command) {
        if ($command !== 'config:cache') throw new RuntimeException('Unexpected command');
        return 0;
    }
});
$request = new class extends App\Http\Requests\Admin\ConfigSave {
    public function validated($key = null, $default = null) { return ['telegram_bot_admin_id' => $this->input('telegram_bot_admin_id')]; }
};
$request->merge(['user' => ['id' => (int)$argv[1]], 'telegram_bot_admin_id' => $argv[2]]);
$response = (new App\Http\Controllers\V1\Admin\ConfigController())->save($request)->getData(true);
if ($response !== ['data' => true]) throw new RuntimeException('Setting was not saved');
echo json_encode($response), PHP_EOL;
