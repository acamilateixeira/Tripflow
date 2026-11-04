<?php

use App\Models\CostCenter;
use App\Models\TravelRequest;
use App\Models\User;

/*
 * Isolamento entre empresas. Um ID de outra empresa deve se comportar
 * exatamente como um ID inexistente (404), para não vazar informação.
 */

beforeEach(function () {
    ['traveler' => $this->traveler, 'costCenter' => $this->cc] = scenario();
    ['approver' => $this->otherApprover, 'traveler' => $other, 'costCenter' => $this->otherCc] = scenario();
    $this->otherRequest = TravelRequest::factory()->pending()->create(['traveler_id' => $other->id, 'cost_center_id' => $this->otherCc->id]);
});

it('responde 404 para solicitação de outra empresa', function () {
    $approver = User::factory()->approver()->create(['company_id' => $this->traveler->company_id]);
    actingAsUser($approver);

    $this->getJson("/api/v1/travel-requests/{$this->otherRequest->id}")
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');

    $this->postJson("/api/v1/travel-requests/{$this->otherRequest->id}/approve")->assertNotFound();
});

it('não lista solicitações de outra empresa', function () {
    $approver = User::factory()->approver()->create(['company_id' => $this->traveler->company_id]);
    TravelRequest::factory()->create(['traveler_id' => $this->traveler->id, 'cost_center_id' => $this->cc->id]);
    actingAsUser($approver);

    $ids = collect($this->getJson('/api/v1/travel-requests')->json('data'))->pluck('id');

    expect($ids)->not->toContain($this->otherRequest->id)->and($ids)->toHaveCount(1);
});

it('não aceita centro de custo de outra empresa', function () {
    actingAsUser($this->traveler);

    $this->postJson('/api/v1/travel-requests', travelPayload($this->otherCc))->assertNotFound();

    expect(TravelRequest::query()->withoutGlobalScopes()->where('cost_center_id', $this->otherCc->id)->count())->toBe(1);
});

it('não expõe orçamento de outra empresa', function () {
    actingAsUser($this->traveler);

    $this->getJson("/api/v1/cost-centers/{$this->otherCc->id}/budget")->assertNotFound();
    expect(CostCenter::query()->count())->toBe(1); // o escopo vale também fora do HTTP, com usuário logado
});
