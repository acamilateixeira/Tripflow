<?php

use App\Domain\Budget\BudgetSummary;
use Carbon\CarbonImmutable;

it('calcula disponível e percentual de uso', function () {
    $summary = new BudgetSummary('CC-1', CarbonImmutable::parse('2026-12-01'), 1_000_000, 250_000);

    expect($summary->availableCents())->toBe(750_000)
        ->and($summary->usagePercent())->toBe(25.0);
});

it('nunca retorna disponível negativo', function () {
    $summary = new BudgetSummary('CC-1', CarbonImmutable::parse('2026-12-01'), 100, 500);

    expect($summary->availableCents())->toBe(0);
});

it('trata orçamento zero sem divisão por zero', function () {
    expect((new BudgetSummary('CC-1', CarbonImmutable::now(), 0, 0))->usagePercent())->toBe(0.0);
});
