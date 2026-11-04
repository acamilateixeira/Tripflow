<?php

declare(strict_types=1);

namespace App\Domain\Travel\Enums;

/**
 * Ciclo de vida de uma solicitação de viagem.
 *
 *   draft ──submit──▶ pending_approval ──approve──▶ approved ──book──▶ booked
 *     │                    │                           │                 │
 *     └──cancel──┐         └──reject──▶ rejected       └──cancel──┐      └──cancel──┐
 *                ▼                                                ▼                 ▼
 *            cancelled                                        cancelled         cancelled
 */
enum TravelRequestStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Booked = 'booked';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::PendingApproval, self::Cancelled],
            self::PendingApproval => [self::Approved, self::Rejected, self::Cancelled],
            self::Approved => [self::Booked, self::Cancelled],
            self::Booked => [self::Cancelled],
            self::Rejected, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Estados que consomem orçamento do centro de custo.
     *
     * @return list<self>
     */
    public static function committingBudget(): array
    {
        return [self::Approved, self::Booked];
    }
}
