<?php

declare(strict_types=1);

namespace App\Domain\Travel\Actions;

use App\Domain\Travel\Data\TravelRequestData;
use App\Domain\Travel\Enums\TravelRequestStatus;
use App\Models\CostCenter;
use App\Models\TravelRequest;
use App\Models\User;

final class CreateTravelRequest
{
    public function handle(User $traveler, TravelRequestData $data): TravelRequest
    {
        // O escopo de empresa garante que o centro de custo é da mesma empresa (senão, 404).
        $costCenter = CostCenter::query()->findOrFail($data->costCenterId);

        return TravelRequest::query()->create([
            'company_id' => $traveler->company_id,
            'traveler_id' => $traveler->id,
            'cost_center_id' => $costCenter->id,
            'origin' => $data->origin,
            'destination' => $data->destination,
            'departure_on' => $data->departureOn->toDateString(),
            'return_on' => $data->returnOn->toDateString(),
            'estimated_cost_cents' => $data->estimatedCostCents,
            'reason' => $data->reason,
            'status' => TravelRequestStatus::Draft,
        ]);
    }
}
