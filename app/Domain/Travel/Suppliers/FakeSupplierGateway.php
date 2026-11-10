<?php

declare(strict_types=1);

namespace App\Domain\Travel\Suppliers;

use App\Domain\Travel\Enums\BookingType;
use App\Models\TravelRequest;
use Illuminate\Support\Str;

final class FakeSupplierGateway implements SupplierGateway
{
    /** @var list<BookingConfirmation> */
    public array $booked = [];

    /** @var list<BookingConfirmation> */
    public array $cancelled = [];

    public function book(TravelRequest $request, BookingType $type, string $supplier, int $amountCents): BookingConfirmation
    {
        $confirmation = new BookingConfirmation(
            type: $type,
            supplier: $supplier,
            locator: strtoupper(Str::random(6)),
            amountCents: $amountCents,
        );

        $this->booked[] = $confirmation;

        return $confirmation;
    }

    public function cancel(BookingConfirmation $confirmation): void
    {
        $this->cancelled[] = $confirmation;
    }
}
