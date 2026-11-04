<?php

declare(strict_types=1);

namespace App\Domain\Travel\Suppliers;

use App\Domain\Travel\Enums\BookingType;

final readonly class BookingConfirmation
{
    public function __construct(
        public BookingType $type,
        public string $supplier,
        public string $locator,
        public int $amountCents,
    ) {}
}
