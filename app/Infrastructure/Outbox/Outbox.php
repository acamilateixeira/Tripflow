<?php

declare(strict_types=1);

namespace App\Infrastructure\Outbox;

use App\Domain\Travel\Events\DomainEvent;
use App\Infrastructure\RequestContext;
use App\Models\OutboxMessage;
use Illuminate\Database\ConnectionInterface;
use LogicException;

/**
 * Registra eventos na tabela de outbox. Exige transação aberta: se o evento
 * fosse gravado fora dela, um rollback deixaria o mundo sabendo de algo que
 * não aconteceu (ou o contrário). Ver docs/adr/0002.
 */
final readonly class Outbox
{
    public function __construct(
        private ConnectionInterface $db,
        private RequestContext $context,
    ) {}

    public function record(DomainEvent $event): OutboxMessage
    {
        if ($this->db->transactionLevel() === 0) {
            throw new LogicException('Eventos de outbox devem ser gravados dentro de uma transação.');
        }

        return OutboxMessage::query()->create([
            'event_type' => $event->eventType(),
            'aggregate_type' => $event->aggregateType(),
            'aggregate_id' => $event->aggregateId(),
            'payload' => $event->payload(),
            'correlation_id' => $this->context->correlationId(),
            'occurred_at' => now(),
            'available_at' => now(),
        ]);
    }
}
