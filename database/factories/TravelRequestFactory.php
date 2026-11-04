<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Travel\Enums\TravelRequestStatus;
use App\Models\CostCenter;
use App\Models\TravelRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TravelRequest> */
final class TravelRequestFactory extends Factory
{
    protected $model = TravelRequest::class;

    public function definition(): array
    {
        $departure = now()->addMonth()->startOfMonth()->addDays(fake()->numberBetween(0, 20));

        return [
            'company_id' => fn (array $attrs) => self::companyOf($attrs['traveler_id']),
            'traveler_id' => User::factory(),
            'cost_center_id' => fn (array $attrs) => CostCenter::factory()->create([
                'company_id' => self::companyOf($attrs['traveler_id']),
            ])->id,
            'origin' => 'CNF',
            'destination' => fake()->randomElement(['GRU', 'GIG', 'BSB', 'POA', 'REC']),
            'departure_on' => $departure->toDateString(),
            'return_on' => $departure->copy()->addDays(2)->toDateString(),
            'estimated_cost_cents' => 250_000,
            'reason' => 'Reunião presencial com cliente para kickoff do projeto.',
            'status' => TravelRequestStatus::Draft,
        ];
    }

    private static function companyOf(mixed $travelerId): int
    {
        $id = $travelerId instanceof User ? $travelerId->id : (int) $travelerId;

        return User::query()->findOrFail($id)->company_id;
    }

    public function pending(): self
    {
        return $this->state(['status' => TravelRequestStatus::PendingApproval, 'submitted_at' => now()]);
    }

    public function approved(): self
    {
        return $this->state(['status' => TravelRequestStatus::Approved, 'submitted_at' => now(), 'decided_at' => now()]);
    }
}
