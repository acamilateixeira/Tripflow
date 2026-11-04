<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Infrastructure\Messaging\Consumers\NotifyTravelerOnDecision;
use App\Infrastructure\Messaging\RabbitMqConnection;
use Illuminate\Console\Command;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

/**
 * Consome a fila de notificações. Ack manual só depois do processamento;
 * em erro, nack sem requeue → a mensagem vai para a DLQ configurada na fila.
 */
final class ConsumeEvents extends Command
{
    protected $signature = 'events:consume {--max= : Encerra após N mensagens}';

    protected $description = 'Consome eventos de viagem do RabbitMQ e notifica os viajantes';

    public function handle(RabbitMqConnection $connection, NotifyTravelerOnDecision $handler): int
    {
        $config = config('messaging.consumers.notifications');
        $exchange = (string) config('messaging.rabbitmq.exchange');
        $queue = $config['queue'];
        $dlq = $queue.'.dlq';

        $channel = $connection->channel();
        $channel->exchange_declare($exchange, 'topic', false, true, false);
        $channel->exchange_declare($exchange.'.dlx', 'fanout', false, true, false);
        $channel->queue_declare($dlq, false, true, false, false);
        $channel->queue_bind($dlq, $exchange.'.dlx');
        $channel->queue_declare($queue, false, true, false, false, false, new AMQPTable([
            'x-dead-letter-exchange' => $exchange.'.dlx',
        ]));

        foreach ($config['bindings'] as $routingKey) {
            $channel->queue_bind($queue, $exchange, $routingKey);
        }

        $channel->basic_qos(0, 10, false);

        $max = (int) $this->option('max');
        $count = 0;

        $channel->basic_consume($queue, 'tripflow-'.gethostname(), false, false, false, false,
            function (AMQPMessage $message) use ($handler, &$count): void {
                try {
                    /** @var array<string, mixed> $payload */
                    $payload = json_decode($message->getBody(), true, flags: JSON_THROW_ON_ERROR);
                    $processed = $handler->handle(
                        (string) $message->get('message_id'),
                        (string) $message->get('type'),
                        $payload,
                    );
                    $message->ack();
                    $this->line(sprintf('%s %s %s', $message->get('type'), $message->get('message_id'), $processed ? 'ok' : 'ignorada'));
                } catch (Throwable $e) {
                    report($e);
                    $message->nack(requeue: false);
                }
                $count++;
            });

        while ($channel->is_consuming() && ($max === 0 || $count < $max)) {
            try {
                $channel->wait(timeout: 30);
            } catch (AMQPTimeoutException) {
                // fila ociosa: segue esperando
            }
        }

        return self::SUCCESS;
    }
}
