<?php

return [
    // rabbitmq em produção/docker; "memory" nos testes; "log" para rodar sem broker.
    'driver' => env('MESSAGING_DRIVER', 'log'),

    'rabbitmq' => [
        'host' => env('RABBITMQ_HOST', '127.0.0.1'),
        'port' => (int) env('RABBITMQ_PORT', 5672),
        'user' => env('RABBITMQ_USER', 'guest'),
        'password' => env('RABBITMQ_PASSWORD', 'guest'),
        'vhost' => env('RABBITMQ_VHOST', '/'),
        'exchange' => env('RABBITMQ_EXCHANGE', 'tripflow.events'),
    ],

    'outbox' => [
        'batch_size' => (int) env('OUTBOX_BATCH_SIZE', 100),
        'max_attempts' => (int) env('OUTBOX_MAX_ATTEMPTS', 10),
        'idle_sleep_ms' => (int) env('OUTBOX_IDLE_SLEEP_MS', 500),
    ],

    // Fila do consumidor de exemplo e as routing keys que ela escuta.
    'consumers' => [
        'notifications' => [
            'queue' => 'tripflow.notifications',
            'bindings' => ['travel_request.approved', 'travel_request.rejected', 'travel_request.booked'],
        ],
    ],
];
