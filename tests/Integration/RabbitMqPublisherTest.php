<?php

use App\Infrastructure\Messaging\RabbitMqConnection;
use App\Infrastructure\Messaging\RabbitMqPublisher;
use App\Models\OutboxMessage;
use Illuminate\Support\Str;

/*
 * Testes contra um RabbitMQ real (serviço do CI / docker compose).
 * Pulados automaticamente se o broker não estiver acessível.
 */

beforeEach(function () {
    $this->connection = new RabbitMqConnection(config('messaging.rabbitmq'));

    try {
        $this->connection->channel();
    } catch (Throwable) {
        $this->markTestSkipped('RabbitMQ indisponível em '.config('messaging.rabbitmq.host'));
    }
});

afterEach(fn () => $this->connection?->reset());

it('publica com confirmação, routing key e message_id da outbox', function () {
    $exchange = 'tripflow.test.'.Str::random(6);
    $channel = $this->connection->channel();
    $channel->exchange_declare($exchange, 'topic', false, false, true);
    [$queue] = $channel->queue_declare('', false, false, true, true);
    $channel->queue_bind($queue, $exchange, 'travel_request.*');

    $message = new OutboxMessage([
        'event_type' => 'travel_request.approved',
        'aggregate_type' => 'travel_request',
        'aggregate_id' => 42,
        'payload' => ['travel_request_id' => 42, 'status' => 'approved'],
        'correlation_id' => 'corr-123',
        'occurred_at' => now(),
        'available_at' => now(),
    ]);
    $message->id = (string) Str::uuid();

    (new RabbitMqPublisher($this->connection, $exchange))->publish($message);

    $received = null;
    for ($i = 0; $i < 20 && $received === null; $i++) {
        $received = $channel->basic_get($queue, true);
        $received ?? usleep(100_000);
    }

    expect($received)->not->toBeNull()
        ->and($received->get('message_id'))->toBe($message->id)
        ->and($received->get('correlation_id'))->toBe('corr-123')
        ->and($received->getRoutingKey())->toBe('travel_request.approved')
        ->and(json_decode($received->getBody(), true))->toBe(['travel_request_id' => 42, 'status' => 'approved']);
});
