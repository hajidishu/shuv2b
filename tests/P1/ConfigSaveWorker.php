<?php
require __DIR__ . '/bootstrap.php';
Tests\P1\Environment::boot(false);

$dir = $argv[1];
mkdir($dir . '/config', 0700);
mkdir($dir . '/bootstrap/cache', 0700, true);
app()->setBasePath($dir);
$source = $dir . '/config/v2board.php';
$cached = app()->getCachedConfigPath();
file_put_contents($source, '<?php return ["app_name" => "before"];');
file_put_contents($cached, '<?php return ["v2board" => ["app_name" => "before"]];');
require $source;
require $cached;

Illuminate\Support\Facades\File::swap(new Illuminate\Filesystem\Filesystem());
$commands = new class($source, $cached) {
    public $calls = [];
    private $source;
    private $cached;
    public function __construct($source, $cached) { $this->source = $source; $this->cached = $cached; }
    public function call($command)
    {
        $this->calls[] = $command;
        if ($command === 'config:clear') {
            if (is_file($this->cached)) unlink($this->cached);
        } elseif ($command === 'config:cache') {
            $values = require $this->source;
            file_put_contents($this->cached, '<?php return ' . var_export(['v2board' => $values], true) . ';');
        } else throw new RuntimeException('Unexpected command');
        return 0;
    }
};
Illuminate\Support\Facades\Artisan::swap($commands);
$request = new class extends App\Http\Requests\Admin\ConfigSave {
    public function validated($key = null, $default = null) { return ['app_name' => 'Saved fixture']; }
};
$response = (new App\Http\Controllers\V1\Admin\ConfigController())->save($request)->getData(true);
$cacheExists = is_file($cached);
$after = $cacheExists ? require $cached : null;
echo json_encode(['response' => $response, 'commands' => $commands->calls,
    'disk_saved' => strpos(file_get_contents($source), 'Saved fixture') !== false,
    'cache_exists' => $cacheExists, 'cached_name' => $after['v2board']['app_name'] ?? null]), PHP_EOL;
