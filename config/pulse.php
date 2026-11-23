<?php

// Override stable Pulse settings. The package supplies the default recorders
// and authorization middleware. MariaDB storage runs without Redis workers.
return [
    'path' => env('PULSE_PATH', 'crm/technical/pulse'),

    'storage' => [
        'driver' => env('PULSE_STORAGE_DRIVER', 'database'),
        'trim' => ['keep' => env('PULSE_STORAGE_KEEP', '14 days')],
        'database' => [
            'connection' => env('PULSE_DB_CONNECTION'),
            'chunk' => 1000,
        ],
    ],

    'ingest' => [
        'driver' => env('PULSE_INGEST_DRIVER', 'storage'),
        'buffer' => env('PULSE_INGEST_BUFFER', 5000),
        'trim' => [
            'lottery' => [1, 1000],
            'keep' => env('PULSE_INGEST_KEEP', '14 days'),
        ],
        'redis' => ['connection' => env('PULSE_REDIS_CONNECTION')],
    ],
];
