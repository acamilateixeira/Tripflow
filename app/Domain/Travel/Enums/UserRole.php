<?php

declare(strict_types=1);

namespace App\Domain\Travel\Enums;

enum UserRole: string
{
    case Traveler = 'traveler';
    case Approver = 'approver';
    case Admin = 'admin';

    public function canApprove(): bool
    {
        return $this === self::Approver || $this === self::Admin;
    }
}
