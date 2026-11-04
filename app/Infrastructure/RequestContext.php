<?php

declare(strict_types=1);

namespace App\Infrastructure;

use Illuminate\Support\Str;

/**
 * Correlation ID da requisição atual. Vai para os logs e para o cabeçalho
 * das mensagens publicadas, permitindo seguir uma operação da API até o
 * consumidor do evento.
 */
final class RequestContext
{
    private ?string $correlationId = null;

    public function correlationId(): string
    {
        return $this->correlationId ??= (string) Str::uuid();
    }

    public function setCorrelationId(string $id): void
    {
        $this->correlationId = $id;
    }
}
