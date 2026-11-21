<?php

// Override only stable Pulse settings; the package supplies recorders, routes,
// middleware and the remaining defaults. Keep full nested storage/ingest shapes
// because package config is merged at the top level.
return [
    'path' => env('PULSE_PATH', 'tech/pulse'),

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
        'buffer' => env('PULSE_INGEST_BUFFER', 5_000),
        'trim' => [
            'lottery' => [1, 1_000],
            'keep' => env('PULSE_INGEST_KEEP', '14 days'),
        ],
        'redis' => ['connection' => env('PULSE_REDIS_CONNECTION')],
    ],
];
