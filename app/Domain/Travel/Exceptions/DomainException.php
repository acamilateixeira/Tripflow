<?php

declare(strict_types=1);

namespace App\Domain\Travel\Exceptions;

use RuntimeException;

/**
 * Violação de regra de negócio. Renderizada como 422 com um código estável
 * que o cliente pode tratar sem depender do texto da mensagem.
 */
class DomainException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode)
    {
        parent::__construct($message);
    }
}
