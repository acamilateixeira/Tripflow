<?php

declare(strict_types=1);

namespace App\Domain\Travel\Enums;

enum BookingType: string
{
    case Flight = 'flight';
    case Hotel = 'hotel';
    case Car = 'car';
}
