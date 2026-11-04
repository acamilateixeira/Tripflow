<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Booking;
use App\Models\TravelRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TravelRequest */
final class TravelRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'origin' => $this->origin,
            'destination' => $this->destination,
            'departure_on' => $this->departure_on->toDateString(),
            'return_on' => $this->return_on->toDateString(),
            'estimated_cost_cents' => $this->estimated_cost_cents,
            'reason' => $this->reason,
            'rejection_reason' => $this->rejection_reason,
            'traveler' => ['id' => $this->traveler_id, 'name' => $this->whenLoaded('traveler', fn () => $this->traveler->name)],
            'cost_center_id' => $this->cost_center_id,
            'approver_id' => $this->approver_id,
            'bookings' => $this->whenLoaded('bookings', fn () => $this->bookings->map(fn (Booking $b) => [
                'type' => $b->type->value,
                'supplier' => $b->supplier,
                'locator' => $b->locator,
                'amount_cents' => $b->amount_cents,
            ])),
            'allowed_transitions' => array_map(fn ($s) => $s->value, $this->status->allowedTransitions()),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
