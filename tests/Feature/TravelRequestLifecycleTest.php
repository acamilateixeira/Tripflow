<?php

use App\Domain\Travel\Enums\TravelRequestStatus;
use App\Models\OutboxMessage;
use App\Models\TravelRequest;
use App\Models\User;

describe('criação', function () {
    it('cria a solicitação como rascunho', function () {
        ['traveler' => $traveler, 'costCenter' => $cc] = scenario();
        actingAsUser($traveler);

        $this->postJson('/api/v1/travel-requests', travelPayload($cc, ['origin' => 'cnf']))
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.origin', 'CNF')
            ->assertJsonPath('data.allowed_transitions', ['pending_approval', 'cancelled']);

        expect(TravelRequest::query()->count())->toBe(1)
            ->and(OutboxMessage::query()->count())->toBe(0); // rascunho não é evento de negócio
    });

    it('valida datas e trechos', function () {
        ['traveler' => $traveler, 'costCenter' => $cc] = scenario();
        actingAsUser($traveler);

        $this->postJson('/api/v1/travel-requests', travelPayload($cc, [
            'destination' => 'CNF',
            'departure_on' => now()->subDay()->toDateString(),
            'return_on' => now()->subDays(3)->toDateString(),
            'estimated_cost_cents' => 0,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['destination', 'departure_on', 'return_on', 'estimated_cost_cents']);
    });
});

describe('fluxo completo', function () {
    it('vai de rascunho a emitida publicando um evento por transição', function () {
        ['traveler' => $traveler, 'approver' => $approver, 'costCenter' => $cc] = scenario();

        actingAsUser($traveler);
        $id = $this->postJson('/api/v1/travel-requests', travelPayload($cc))->json('data.id');
        $this->postJson("/api/v1/travel-requests/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'pending_approval');

        actingAsUser($approver);
        $this->postJson("/api/v1/travel-requests/{$id}/approve")->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.approver_id', $approver->id);

        actingAsUser($traveler);
        $this->postJson("/api/v1/travel-requests/{$id}/bookings", ['items' => [
            ['type' => 'flight', 'supplier' => 'LATAM', 'amount_cents' => 180_000],
            ['type' => 'hotel', 'supplier' => 'Ibis Paulista', 'amount_cents' => 90_000],
        ]])
            ->assertOk()
            ->assertJsonPath('data.status', 'booked')
            ->assertJsonCount(2, 'data.bookings');

        expect(OutboxMessage::query()->orderBy('occurred_at')->pluck('event_type')->all())->toBe([
            'travel_request.submitted',
            'travel_request.approved',
            'travel_request.booked',
        ]);

        $booked = OutboxMessage::query()->where('event_type', 'travel_request.booked')->sole();
        expect($booked->payload)->toMatchArray([
            'travel_request_id' => $id,
            'total_booked_cents' => 270_000,
            'status' => 'booked',
        ]);
    });

    it('recusa exigindo motivo', function () {
        ['traveler' => $traveler, 'approver' => $approver, 'costCenter' => $cc] = scenario();
        $request = TravelRequest::factory()->pending()->create(['traveler_id' => $traveler->id, 'cost_center_id' => $cc->id]);
        actingAsUser($approver);

        $this->postJson("/api/v1/travel-requests/{$request->id}/reject")->assertJsonValidationErrors('reason');

        $this->postJson("/api/v1/travel-requests/{$request->id}/reject", ['reason' => 'Use videoconferência.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'Use videoconferência.')
            ->assertJsonPath('data.allowed_transitions', []);
    });

    it('cancela uma viagem já emitida', function () {
        ['traveler' => $traveler, 'costCenter' => $cc] = scenario();
        $request = TravelRequest::factory()->approved()->create(['traveler_id' => $traveler->id, 'cost_center_id' => $cc->id]);
        $request->forceFill(['status' => TravelRequestStatus::Booked])->save();
        actingAsUser($traveler);

        $this->postJson("/api/v1/travel-requests/{$request->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        expect(OutboxMessage::query()->where('event_type', 'travel_request.cancelled')->exists())->toBeTrue();
    });
});

describe('regras de transição', function () {
    it('recusa transição inválida com código estável', function () {
        ['traveler' => $traveler, 'approver' => $approver, 'costCenter' => $cc] = scenario();
        $draft = TravelRequest::factory()->create(['traveler_id' => $traveler->id, 'cost_center_id' => $cc->id]);
        actingAsUser($approver);

        $this->postJson("/api/v1/travel-requests/{$draft->id}/approve")
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'invalid_transition');

        expect($draft->fresh()->status)->toBe(TravelRequestStatus::Draft)
            ->and(OutboxMessage::query()->count())->toBe(0); // rollback leva o evento junto
    });

    it('não emite reserva antes da aprovação', function () {
        ['traveler' => $traveler, 'costCenter' => $cc] = scenario();
        $pending = TravelRequest::factory()->pending()->create(['traveler_id' => $traveler->id, 'cost_center_id' => $cc->id]);
        actingAsUser($traveler);

        $this->postJson("/api/v1/travel-requests/{$pending->id}/bookings", ['items' => [
            ['type' => 'flight', 'supplier' => 'GOL', 'amount_cents' => 100],
        ]])->assertUnprocessable()->assertJsonPath('error.code', 'invalid_transition');
    });
});

describe('autorização', function () {
    it('impede o viajante de aprovar', function () {
        ['traveler' => $traveler, 'costCenter' => $cc] = scenario();
        $colleague = User::factory()->create(['company_id' => $traveler->company_id]);
        $request = TravelRequest::factory()->pending()->create(['traveler_id' => $colleague->id, 'cost_center_id' => $cc->id]);
        actingAsUser($traveler);

        $this->postJson("/api/v1/travel-requests/{$request->id}/approve")->assertForbidden();
    });

    it('impede que a aprovadora aprove a própria viagem (segregação de funções)', function () {
        ['approver' => $approver, 'costCenter' => $cc] = scenario();
        $own = TravelRequest::factory()->pending()->create(['traveler_id' => $approver->id, 'cost_center_id' => $cc->id]);
        actingAsUser($approver);

        $this->postJson("/api/v1/travel-requests/{$own->id}/approve")->assertForbidden();
    });

    it('lista só as próprias solicitações para viajantes e todas para aprovadores', function () {
        ['traveler' => $traveler, 'approver' => $approver, 'costCenter' => $cc] = scenario();
        $colleague = User::factory()->create(['company_id' => $traveler->company_id]);
        TravelRequest::factory()->create(['traveler_id' => $traveler->id, 'cost_center_id' => $cc->id]);
        TravelRequest::factory()->create(['traveler_id' => $colleague->id, 'cost_center_id' => $cc->id]);

        actingAsUser($traveler);
        $this->getJson('/api/v1/travel-requests')->assertJsonCount(1, 'data');

        actingAsUser($approver);
        $this->getJson('/api/v1/travel-requests')->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/travel-requests?status=approved')->assertJsonCount(0, 'data');
    });
});
