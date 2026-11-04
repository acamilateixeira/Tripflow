<?php

declare(strict_types=1);

namespace App\Domain\Travel\Data;

use Carbon\CarbonImmutable;

/** Dados de entrada já validados para criar uma solicitação. */
final readonly class TravelRequestData
{
    public function __construct(
        public int $costCenterId,
        public string $origin,
        public string $destination,
        public CarbonImmutable $departureOn,
        public CarbonImmutable $returnOn,
        public int $estimatedCostCents,
        public string $reason,
    ) {}
}
