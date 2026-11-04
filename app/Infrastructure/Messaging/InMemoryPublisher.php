<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Models\OutboxMessage;

/** Publisher para testes: guarda as mensagens e pode simular falhas do broker. */
final class InMemoryPublisher implements MessagePublisher
{
    /** @var list<OutboxMessage> */
    public array $published = [];

    public int $failNext = 0;

    public function publish(OutboxMessage $message): void
    {
        if ($this->failNext > 0) {
            $this->failNext--;

            throw new PublishFailed('broker indisponível (simulado)');
        }

        $this->published[] = $message;
    }

    /** @return list<string> */
    public function eventTypes(): array
    {
        return array_map(fn (OutboxMessage $m) => $m->event_type, $this->published);
    }
}
