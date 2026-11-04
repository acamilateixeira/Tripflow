<?php

use App\Models\TravelRequest;

it('não deixa a segunda aprovação estourar o orçamento', function () {
    ['traveler' => $traveler, 'approver' => $approver, 'costCenter' => $cc] = scenario(budgetCents: 500_000);
    $first = TravelRequest::factory()->pending()->create(['traveler_id' => $traveler->id, 'cost_center_id' => $cc->id, 'estimated_cost_cents' => 300_000]);
    $second = TravelRequest::factory()->pending()->create(['traveler_id' => $traveler->id, 'cost_center_id' => $cc->id, 'estimated_cost_cents' => 300_000]);
    actingAsUser($approver);

    $this->postJson("/api/v1/travel-requests/{$first->id}/approve")->assertOk();

    $this->postJson("/api/v1/travel-requests/{$second->id}/approve")
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'budget_exceeded');

    expect($second->fresh()->status->value)->toBe('pending_approval');
});

it('libera orçamento quando uma viagem aprovada é cancelada', function () {
    ['traveler' => $traveler, 'approver' => $approver, 'costCenter' => $cc] = scenario(budgetCents: 500_000);
    $approved = TravelRequest::factory()->approved()->create(['traveler_id' => $traveler->id, 'cost_center_id' => $cc->id, 'estimated_cost_cents' => 400_000]);
    $pending = TravelRequest::factory()->pending()->create(['traveler_id' => $traveler->id, 'cost_center_id' => $cc->id, 'estimated_cost_cents' => 300_000]);
    actingAsUser($approver);

    $this->postJson("/api/v1/travel-requests/{$pending->id}/approve")->assertJsonPath('error.code', 'budget_exceeded');
    $this->postJson("/api/v1/travel-requests/{$approved->id}/cancel")->assertOk();
    $this->postJson("/api/v1/travel-requests/{$pending->id}/approve")->assertOk();
});

it('considera só o mês da viagem', function () {
    ['traveler' => $traveler, 'approver' => $approver, 'costCenter' => $cc] = scenario(budgetCents: 500_000);
    $nextMonth = now()->addMonth()->startOfMonth()->addDays(3);
    $monthAfter = now()->addMonths(2)->startOfMonth()->addDays(3);

    TravelRequest::factory()->approved()->create([
        'traveler_id' => $traveler->id, 'cost_center_id' => $cc->id, 'estimated_cost_cents' => 500_000,
        'departure_on' => $nextMonth->toDateString(), 'return_on' => $nextMonth->toDateString(),
    ]);
    $later = TravelRequest::factory()->pending()->create([
        'traveler_id' => $traveler->id, 'cost_center_id' => $cc->id, 'estimated_cost_cents' => 500_000,
        'departure_on' => $monthAfter->toDateString(), 'return_on' => $monthAfter->toDateString(),
    ]);
    actingAsUser($approver);

    $this->postJson("/api/v1/travel-requests/{$later->id}/approve")->assertOk();
});

it('expõe o resumo do orçamento e invalida o cache após aprovação', function () {
    ['traveler' => $traveler, 'approver' => $approver, 'costCenter' => $cc] = scenario(budgetCents: 1_000_000);
    $request = TravelRequest::factory()->pending()->create(['traveler_id' => $traveler->id, 'cost_center_id' => $cc->id, 'estimated_cost_cents' => 250_000]);
    $month = $request->departure_on->format('Y-m');
    actingAsUser($approver);

    $this->getJson("/api/v1/cost-centers/{$cc->id}/budget?month={$month}")
        ->assertOk()
        ->assertJsonPath('data.committed_cents', 0)
        ->assertJsonPath('data.available_cents', 1_000_000);

    $this->postJson("/api/v1/travel-requests/{$request->id}/approve")->assertOk();

    // Sem a invalidação, o cache ainda mostraria 0 comprometido.
    $this->getJson("/api/v1/cost-centers/{$cc->id}/budget?month={$month}")
        ->assertJsonPath('data.committed_cents', 250_000)
        ->assertJsonPath('data.usage_percent', 25);
});
