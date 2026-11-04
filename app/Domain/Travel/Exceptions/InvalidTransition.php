<?php

declare(strict_types=1);

namespace App\Domain\Travel\Exceptions;

use App\Domain\Travel\Enums\TravelRequestStatus;

final class InvalidTransition extends DomainException
{
    public static function between(TravelRequestStatus $from, TravelRequestStatus $to): self
    {
        return new self(
            sprintf('Não é possível mudar de "%s" para "%s".', $from->value, $to->value),
            'invalid_transition',
        );
    }
}
