<?php
return [
    'enabled' => env('LEAK_PROBE_ENABLED', true),
    'history_days' => max(1, min(3650, (int)env('LEAK_PROBE_HISTORY_DAYS', 90))),
];
