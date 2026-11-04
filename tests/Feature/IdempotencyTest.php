<?php

use App\Models\TravelRequest;
use App\Models\User;

it('repete a mesma resposta para a mesma Idempotency-Key sem duplicar', function () {
    ['traveler' => $traveler, 'costCenter' => $cc] = scenario();
    actingAsUser($traveler);
    $payload = travelPayload($cc);

    $first = $this->postJson('/api/v1/travel-requests', $payload, ['Idempotency-Key' => 'abc-123'])->assertCreated();
    $retry = $this->postJson('/api/v1/travel-requests', $payload, ['Idempotency-Key' => 'abc-123'])->assertCreated();

    expect($retry->headers->get('Idempotent-Replayed'))->toBe('true')
        ->and($retry->json('data.id'))->toBe($first->json('data.id'))
        ->and(TravelRequest::query()->count())->toBe(1);
});

it('rejeita a mesma chave com payload diferente', function () {
    ['traveler' => $traveler, 'costCenter' => $cc] = scenario();
    actingAsUser($traveler);

    $this->postJson('/api/v1/travel-requests', travelPayload($cc), ['Idempotency-Key' => 'k1'])->assertCreated();

    $this->postJson('/api/v1/travel-requests', travelPayload($cc, ['destination' => 'GIG']), ['Idempotency-Key' => 'k1'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'idempotency_key_reused');
});

it('isola chaves por usuário', function () {
    ['traveler' => $traveler, 'costCenter' => $cc] = scenario();
    $colleague = User::factory()->create(['company_id' => $traveler->company_id]);

    actingAsUser($traveler);
    $this->postJson('/api/v1/travel-requests', travelPayload($cc), ['Idempotency-Key' => 'same'])->assertCreated();

    actingAsUser($colleague);
    $this->postJson('/api/v1/travel-requests', travelPayload($cc), ['Idempotency-Key' => 'same'])
        ->assertCreated()
        ->assertHeaderMissing('Idempotent-Replayed');

    expect(TravelRequest::query()->withoutGlobalScopes()->count())->toBe(2);
});

it('não guarda erros de validação como sucesso', function () {
    ['traveler' => $traveler, 'costCenter' => $cc] = scenario();
    actingAsUser($traveler);

    $this->postJson('/api/v1/travel-requests', [], ['Idempotency-Key' => 'k2'])->assertUnprocessable();
    $this->postJson('/api/v1/travel-requests', [], ['Idempotency-Key' => 'k2'])
        ->assertUnprocessable()
        ->assertHeader('Idempotent-Replayed', 'true'); // mesma resposta, mesmo payload
});

it('funciona sem a chave (comportamento normal)', function () {
    ['traveler' => $traveler, 'costCenter' => $cc] = scenario();
    actingAsUser($traveler);

    $this->postJson('/api/v1/travel-requests', travelPayload($cc))->assertCreated();
    $this->postJson('/api/v1/travel-requests', travelPayload($cc))->assertCreated();

    expect(TravelRequest::query()->count())->toBe(2);
});
