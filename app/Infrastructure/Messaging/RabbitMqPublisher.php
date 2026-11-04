<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Models\OutboxMessage;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

/**
 * Publica no exchange "topic" com a routing key = tipo do evento.
 *
 * - Publisher confirms: só consideramos publicado após o ack do broker.
 * - Mensagem persistente (delivery_mode 2) em exchange durável.
 * - message_id = id da outbox, para o consumidor deduplicar (entrega at-least-once).
 */
final readonly class RabbitMqPublisher implements MessagePublisher
{
    public function __construct(
        private RabbitMqConnection $connection,
        private string $exchange,
    ) {}

    public function publish(OutboxMessage $message): void
    {
        try {
            $channel = $this->channel();

            $amqp = new AMQPMessage(
                (string) json_encode($message->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                [
                    'content_type' => 'application/json',
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    'message_id' => $message->id,
                    'type' => $message->event_type,
                    'timestamp' => $message->occurred_at->getTimestamp(),
                    'correlation_id' => $message->correlation_id ?? $message->id,
                    'application_headers' => new AMQPTable([
                        'aggregate_type' => $message->aggregate_type,
                        'aggregate_id' => $message->aggregate_id,
                    ]),
                ],
            );

            $channel->basic_publish($amqp, $this->exchange, $message->event_type, mandatory: false);
            $channel->wait_for_pending_acks_returns(5.0);
        } catch (Throwable $e) {
            $this->connection->reset();

            throw new PublishFailed($e->getMessage(), previous: $e);
        }
    }

    private function channel(): AMQPChannel
    {
        $channel = $this->connection->channel();
        $channel->exchange_declare($this->exchange, 'topic', passive: false, durable: true, auto_delete: false);

        return $channel;
    }
}
