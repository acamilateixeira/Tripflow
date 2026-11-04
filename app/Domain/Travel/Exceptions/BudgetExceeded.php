<?php

declare(strict_types=1);

namespace App\Domain\Travel\Exceptions;

final class BudgetExceeded extends DomainException
{
    public static function forCostCenter(string $code, int $availableCents, int $requestedCents): self
    {
        return new self(
            sprintf(
                'Orçamento insuficiente no centro de custo %s: disponível R$ %s, solicitado R$ %s.',
                $code,
                number_format($availableCents / 100, 2, ',', '.'),
                number_format($requestedCents / 100, 2, ',', '.'),
            ),
            'budget_exceeded',
        );
    }
}
