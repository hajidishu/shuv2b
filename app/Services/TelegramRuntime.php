<?php
namespace App\Services;

class TelegramRuntime
{
    public static function refresh(): void
    {
        // Horizon/Webman workers may outlive a configuration save.
        $file=config_path('v2board.php');
        if (is_file($file)) {
            $settings=require $file;
            if (is_array($settings)) config(['v2board'=>$settings]);
        }
    }
}
