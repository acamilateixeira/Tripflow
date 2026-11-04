<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Travel\Data\TravelRequestData;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

final class StoreTravelRequestRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cost_center_id' => ['required', 'integer'],
            'origin' => ['required', 'string', 'size:3', 'alpha'],
            'destination' => ['required', 'string', 'size:3', 'alpha', 'different:origin'],
            'departure_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'return_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:departure_on'],
            'estimated_cost_cents' => ['required', 'integer', 'min:1', 'max:100000000'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }

    public function toData(): TravelRequestData
    {
        return new TravelRequestData(
            costCenterId: $this->integer('cost_center_id'),
            origin: strtoupper($this->string('origin')->toString()),
            destination: strtoupper($this->string('destination')->toString()),
            departureOn: CarbonImmutable::parse($this->string('departure_on')->toString()),
            returnOn: CarbonImmutable::parse($this->string('return_on')->toString()),
            estimatedCostCents: $this->integer('estimated_cost_cents'),
            reason: $this->string('reason')->toString(),
        );
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'origin' => 'origem (código IATA)',
            'destination' => 'destino (código IATA)',
            'departure_on' => 'data de ida',
            'return_on' => 'data de volta',
            'estimated_cost_cents' => 'custo estimado',
            'reason' => 'motivo',
        ];
    }
}
