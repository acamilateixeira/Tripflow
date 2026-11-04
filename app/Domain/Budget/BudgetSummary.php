<?php

declare(strict_types=1);

namespace App\Domain\Budget;

use Carbon\CarbonImmutable;

final readonly class BudgetSummary
{
    public function __construct(
        public string $costCenterCode,
        public CarbonImmutable $month,
        public int $budgetCents,
        public int $committedCents,
    ) {}

    public function availableCents(): int
    {
        return max(0, $this->budgetCents - $this->committedCents);
    }

    public function usagePercent(): float
    {
        if ($this->budgetCents === 0) {
            return 0.0;
        }

        return round($this->committedCents / $this->budgetCents * 100, 1);
    }
}
