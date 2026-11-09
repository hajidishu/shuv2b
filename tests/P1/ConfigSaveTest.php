<?php
namespace Tests\P1;

use App\Http\Controllers\V1\Admin\ConfigController;
use App\Http\Requests\Admin\ConfigSave;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

class ConfigSaveTest extends DatabaseTestCase
{
    private function validation(array $input): array
    {
        $request = new ConfigSave();
        $request->merge($input);
        $validator = app('validator')->make($input, $request->rules());
        $request->withValidator($validator);
        $request->setValidator($validator);
        return [$request, $validator];
    }

    public function testUnrelatedSettingsIgnoreIncompleteEnabledIntegrations(): void
    {
        config(['v2board.google_login_enabled' => 1, 'v2board.google_client_id' => '', 'v2board.google_origins' => [],
            'v2board.passkey_enabled' => 1, 'v2board.passkey_rp_id' => '', 'v2board.passkey_origins' => []]);
        [, $validator] = $this->validation(['app_name' => 'Renamed fixture', 'stop_register' => 1]);
        $this->assertFalse($validator->fails(), json_encode($validator->errors()->toArray()));
    }

    public function testEnablingIncompleteIntegrationsStillFails(): void
    {
        foreach (['google_login_enabled', 'passkey_enabled'] as $key) {
            [, $validator] = $this->validation([$key => 1]);
            $this->assertTrue($validator->fails());
            $this->assertArrayHasKey($key, $validator->errors()->toArray());
        }
    }

    public function testPartialIntegrationChangesMergeStoredConfiguration(): void
    {
        config(['v2board.google_login_enabled' => 1, 'v2board.google_client_id' => '123-fixture.apps.googleusercontent.com',
            'v2board.google_origins' => ['https://panel.example.test'], 'v2board.passkey_enabled' => 1,
            'v2board.passkey_rp_id' => 'panel.example.test', 'v2board.passkey_origins' => ['https://panel.example.test']]);
        foreach (['google_origins', 'passkey_origins'] as $key) {
            [, $validator] = $this->validation([$key => ['https://other.example.test']]);
            $this->assertFalse($validator->fails(), json_encode($validator->errors()->toArray()));
            [, $validator] = $this->validation([$key => []]);
            $this->assertTrue($validator->fails());
        }
    }

    public function testBrokenIntegrationDoesNotPreventConfiguringOrDisablingTheOtherOne(): void
    {
        config(['v2board.google_login_enabled' => 1, 'v2board.google_client_id' => '', 'v2board.google_origins' => [],
            'v2board.passkey_enabled' => 1, 'v2board.passkey_rp_id' => '', 'v2board.passkey_origins' => []]);
        foreach (['google_login_enabled', 'passkey_enabled'] as $key) {
            [, $validator] = $this->validation([$key => 0]);
            $this->assertFalse($validator->fails());
        }
        [, $validator] = $this->validation(['passkey_enabled' => 1, 'passkey_rp_id' => 'panel.example.test',
            'passkey_origins' => ['https://panel.example.test']]);
        $this->assertFalse($validator->fails());
        [, $validator] = $this->validation(['google_login_enabled' => 1, 'google_client_id' => '123-fixture.apps.googleusercontent.com',
            'google_origins' => ['https://panel.example.test']]);
        $this->assertFalse($validator->fails());
    }

    public function testUnavailableOpcacheResetDoesNotInterruptConfigCaching(): void
    {
        if (!function_exists('opcache_reset') || @opcache_reset()) {
            $this->markTestSkipped('This regression requires CLI OPcache to be loaded but disabled.');
        }
        $base = app()->basePath();
        $dir = sys_get_temp_dir() . '/v2board-config-save-' . bin2hex(random_bytes(6));
        mkdir($dir . '/config', 0700, true);
        app()->setBasePath($dir);
        File::swap(new \Illuminate\Filesystem\Filesystem());
        $commands = new class {
            public $calls = [];
            public function call($command) { $this->calls[] = $command; return 0; }
        };
        Artisan::swap($commands);
        try {
            [$request] = $this->validation(['app_name' => 'Saved fixture']);
            $response = (new ConfigController())->save($request)->getData(true);
            $this->assertSame(['data' => true], $response);
            $this->assertSame(['config:cache'], $commands->calls);
            $this->assertSame('Saved fixture', (require $dir . '/config/v2board.php')['app_name']);
        } finally {
            app()->setBasePath($base);
            if (is_file($dir . '/config/v2board.php')) unlink($dir . '/config/v2board.php');
            rmdir($dir . '/config');
            rmdir($dir);
        }
    }

    public static function opcacheModes(): array
    {
        return ['memory cache' => [false, false], 'restricted API' => [true, false],
            'file cache' => [false, true], 'restricted file cache' => [true, true]];
    }

    /** @dataProvider opcacheModes */
    public function testOpcacheSaveNeverFreezesOldConfiguration($restricted, $fileCache): void
    {
        if (!function_exists('opcache_invalidate')) $this->markTestSkipped('OPcache extension required.');
        $dir = sys_get_temp_dir() . '/v2board-config-opcache-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        if ($fileCache) mkdir($dir . '/opcache', 0700);
        $command = [PHP_BINARY, '-d', 'opcache.enable=1', '-d', 'opcache.enable_cli=1',
            '-d', 'error_reporting=24575',
            '-d', 'opcache.validate_timestamps=0', '-d', 'opcache.file_update_protection=0',
            '-d', 'opcache.restrict_api=' . ($restricted ? $dir . '/restricted/' : ''),
            '-d', 'opcache.file_cache=' . ($fileCache ? $dir . '/opcache' : ''),
            __DIR__ . '/ConfigSaveWorker.php', $dir];
        try {
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $stderr . $stdout);
            $result = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
            $this->assertTrue($result['disk_saved']);
            $this->assertTrue($result['response']['data']);
            if ($restricted) {
                $this->assertSame(['config:clear'], $result['commands']);
                $this->assertFalse($result['cache_exists']);
                $this->assertStringContainsString('完整重启', $result['response']['warning']);
                if ($fileCache) $this->assertStringContainsString('文件缓存', $result['response']['warning']);
            } else {
                $this->assertSame(['config:cache'], $result['commands']);
                $this->assertTrue($result['cache_exists']);
                $this->assertSame('Saved fixture', $result['cached_name']);
                $this->assertArrayNotHasKey('warning', $result['response']);
            }
        } finally {
            (new \Illuminate\Filesystem\Filesystem())->deleteDirectory($dir);
        }
    }
}
