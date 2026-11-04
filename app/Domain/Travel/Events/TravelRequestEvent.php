<?php

declare(strict_types=1);

namespace App\Domain\Travel\Events;

use App\Models\TravelRequest;

/**
 * Eventos do ciclo de vida da solicitação. Cada transição vira um evento
 * com o snapshot mínimo necessário para o consumidor não precisar chamar a API.
 */
final readonly class TravelRequestEvent implements DomainEvent
{
    /**
     * @param  array<string, mixed>  $extra
     */
    private function __construct(
        private string $name,
        private TravelRequest $request,
        private array $extra = [],
    ) {}

    public static function submitted(TravelRequest $request): self
    {
        return new self('submitted', $request);
    }

    public static function approved(TravelRequest $request): self
    {
        return new self('approved', $request, ['approver_id' => $request->approver_id]);
    }

    public static function rejected(TravelRequest $request): self
    {
        return new self('rejected', $request, [
            'approver_id' => $request->approver_id,
            'rejection_reason' => $request->rejection_reason,
        ]);
    }

    public static function booked(TravelRequest $request, int $totalBookedCents): self
    {
        return new self('booked', $request, ['total_booked_cents' => $totalBookedCents]);
    }

    public static function cancelled(TravelRequest $request): self
    {
        return new self('cancelled', $request);
    }

    public function eventType(): string
    {
        return 'travel_request.'.$this->name;
    }

    public function aggregateType(): string
    {
        return 'travel_request';
    }

    public function aggregateId(): int
    {
        return $this->request->id;
    }

    public function payload(): array
    {
        return [
            'travel_request_id' => $this->request->id,
            'company_id' => $this->request->company_id,
            'traveler_id' => $this->request->traveler_id,
            'cost_center_id' => $this->request->cost_center_id,
            'status' => $this->request->status->value,
            'origin' => $this->request->origin,
            'destination' => $this->request->destination,
            'departure_on' => $this->request->departure_on->toDateString(),
            'return_on' => $this->request->return_on->toDateString(),
            'estimated_cost_cents' => $this->request->estimated_cost_cents,
            ...$this->extra,
        ];
    }
}
