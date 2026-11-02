<?php

namespace App\Protocols\Singbox;

class HysteriaPorts
{
    public static function build($value, bool $hopping = true): array
    {
        $ports = array_map('trim', explode(',', (string)$value));
        $first = (int)explode('-', $ports[0], 2)[0];
        if (!$hopping || (count($ports) === 1 && strpos($ports[0], '-') === false)) {
            return ['server_port' => $first];
        }

        // sing-box requires colon ranges, including single ports in a hopping list.
        $ranges = [];
        foreach ($ports as $port) {
            $bounds = array_map('trim', explode('-', $port, 2));
            $ranges[] = $bounds[0] . ':' . ($bounds[1] ?? $bounds[0]);
        }
        return ['server_ports' => $ranges];
    }
}
