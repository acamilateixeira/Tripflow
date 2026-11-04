<?php

declare(strict_types=1);

namespace App\Domain\Travel\Events;

/**
 * Fato de negócio que outros sistemas podem consumir. O payload é o contrato
 * público do evento: só cresce de forma compatível (campos novos opcionais).
 */
interface DomainEvent
{
    /** Nome estável, usado como routing key no RabbitMQ. Ex.: travel_request.approved */
    public function eventType(): string;

    public function aggregateType(): string;

    public function aggregateId(): int;

    /** @return array<string, mixed> */
    public function payload(): array;
}
