<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Models\OutboxMessage;

interface MessagePublisher
{
    /**
     * Publica a mensagem e só retorna depois que o broker confirmou o recebimento.
     *
     * @throws PublishFailed
     */
    public function publish(OutboxMessage $message): void;
}
