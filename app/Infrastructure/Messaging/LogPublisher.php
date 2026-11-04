<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Models\OutboxMessage;
use Psr\Log\LoggerInterface;

/** Permite rodar a API sem broker: o relay apenas registra o evento no log. */
final readonly class LogPublisher implements MessagePublisher
{
    public function __construct(private LoggerInterface $logger) {}

    public function publish(OutboxMessage $message): void
    {
        $this->logger->info('event.published', [
            'message_id' => $message->id,
            'event_type' => $message->event_type,
            'correlation_id' => $message->correlation_id,
        ]);
    }
}
