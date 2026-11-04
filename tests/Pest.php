<?php

use App\Infrastructure\Messaging\InMemoryPublisher;
use App\Infrastructure\Messaging\MessagePublisher;
use App\Models\CostCenter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Integration');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Cenário padrão: uma empresa com viajante, aprovadora e um centro de custo.
 *
 * @return array{traveler: User, approver: User, costCenter: CostCenter}
 */
function scenario(int $budgetCents = 1_000_000): array
{
    $traveler = User::factory()->create();
    $approver = User::factory()->approver()->create(['company_id' => $traveler->company_id]);
    $costCenter = CostCenter::factory()->create([
        'company_id' => $traveler->company_id,
        'monthly_budget_cents' => $budgetCents,
    ]);

    return compact('traveler', 'approver', 'costCenter');
}

function actingAsUser(User $user): User
{
    Sanctum::actingAs($user);

    return $user;
}

/** @return array<string, mixed> */
function travelPayload(CostCenter $costCenter, array $overrides = []): array
{
    $departure = now()->addMonth()->startOfMonth()->addDays(5);

    return [
        'cost_center_id' => $costCenter->id,
        'origin' => 'CNF',
        'destination' => 'GRU',
        'departure_on' => $departure->toDateString(),
        'return_on' => $departure->copy()->addDays(2)->toDateString(),
        'estimated_cost_cents' => 300_000,
        'reason' => 'Visita técnica ao cliente em São Paulo.',
        ...$overrides,
    ];
}

function publisher(): InMemoryPublisher
{
    $publisher = app(MessagePublisher::class);
    assert($publisher instanceof InMemoryPublisher);

    return $publisher;
}
