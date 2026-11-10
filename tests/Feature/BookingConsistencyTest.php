<?php

use App\Domain\Travel\Actions\BookTravelRequest;
use App\Domain\Travel\Actions\CancelTravelRequest;
use App\Domain\Travel\Enums\TravelRequestStatus;
use App\Domain\Travel\Exceptions\DomainException;
use App\Domain\Travel\Suppliers\SupplierGateway;
use App\Models\Booking;
use App\Models\OutboxMessage;
use App\Models\TravelRequest;
use Illuminate\Support\Facades\Cache;
use Tests\Support\ScriptedSupplierGateway;

/*
 * Consistência entre o sistema e o fornecedor durante a emissão (ADR 0005).
 * A chamada ao fornecedor acontece fora da transação, então o que ele já
 * confirmou precisa ser desfeito quando a emissão não termina.
 */

beforeEach(function () {
    // Sem espera pelo lock nos testes: quem não consegue, recebe o erro na hora.
    $this->app->when(CancelTravelRequest::class)->needs('$lockWaitSeconds')->give(0);
});

function approvedRequest(): array
{
    ['traveler' => $traveler, 'costCenter' => $cc] = scenario();
    $request = TravelRequest::factory()->approved()->create(['traveler_id' => $traveler->id, 'cost_center_id' => $cc->id]);
    actingAsUser($traveler);

    return [$request, $traveler];
}

const TWO_ITEMS = ['items' => [
    ['type' => 'flight', 'supplier' => 'LATAM', 'amount_cents' => 180_000],
    ['type' => 'hotel', 'supplier' => 'Ibis Paulista', 'amount_cents' => 90_000],
]];

it('cancela no fornecedor o voo já confirmado quando o hotel falha', function () {
    [$request] = approvedRequest();
    $supplier = new ScriptedSupplierGateway(failOnCall: 2);
    $this->app->instance(SupplierGateway::class, $supplier);

    $this->postJson("/api/v1/travel-requests/{$request->id}/bookings", TWO_ITEMS)
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'supplier_unavailable');

    expect(array_map(fn ($c) => $c->locator, $supplier->cancelled))->toBe(['LOC001'])
        ->and(Booking::query()->count())->toBe(0)
        ->and($request->fresh()->status)->toBe(TravelRequestStatus::Approved)
        ->and(OutboxMessage::query()->where('event_type', 'travel_request.booked')->exists())->toBeFalse();
});

it('não deixa o cancelamento entrar enquanto a emissão espera o fornecedor', function () {
    [$request] = approvedRequest();
    $cancelOutcome = null;

    // Simula outra requisição de cancelamento chegando no meio da chamada ao fornecedor.
    $supplier = new ScriptedSupplierGateway(duringBook: function (TravelRequest $r) use (&$cancelOutcome): void {
        try {
            app(CancelTravelRequest::class)->handle($r);
            $cancelOutcome = 'cancelled';
        } catch (DomainException $e) {
            $cancelOutcome = $e->errorCode;
        }
    });
    $this->app->instance(SupplierGateway::class, $supplier);

    // Antes do lock compartilhado: o cancelamento passava, a emissão falhava em
    // cancelled → booked e as duas reservas ficavam no fornecedor sem registro.
    $this->postJson("/api/v1/travel-requests/{$request->id}/bookings", TWO_ITEMS)
        ->assertOk()
        ->assertJsonPath('data.status', 'booked')
        ->assertJsonCount(2, 'data.bookings');

    expect($cancelOutcome)->toBe('booking_in_progress')
        ->and($supplier->cancelled)->toBe([]);
});

it('recusa o cancelamento com booking_in_progress enquanto há emissão em andamento', function () {
    [$request] = approvedRequest();
    $lock = Cache::lock(BookTravelRequest::lockKey($request->id), 30);
    expect($lock->get())->toBeTrue();

    try {
        $this->postJson("/api/v1/travel-requests/{$request->id}/cancel")
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'booking_in_progress');
    } finally {
        $lock->release();
    }

    expect($request->fresh()->status)->toBe(TravelRequestStatus::Approved)
        ->and(OutboxMessage::query()->where('event_type', 'travel_request.cancelled')->exists())->toBeFalse();

    $this->postJson("/api/v1/travel-requests/{$request->id}/cancel")->assertOk();
});

it('compensa todas as reservas quando a gravação falha depois do fornecedor', function () {
    [$request] = approvedRequest();

    // Alguém muda o status por fora do fluxo (sem o lock) enquanto o fornecedor responde.
    $supplier = new ScriptedSupplierGateway(duringBook: fn (TravelRequest $r) => TravelRequest::query()
        ->whereKey($r->id)
        ->update(['status' => TravelRequestStatus::Cancelled->value]));
    $this->app->instance(SupplierGateway::class, $supplier);

    $this->postJson("/api/v1/travel-requests/{$request->id}/bookings", TWO_ITEMS)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'invalid_transition');

    expect(array_map(fn ($c) => $c->locator, $supplier->cancelled))->toBe(['LOC001', 'LOC002'])
        ->and(Booking::query()->count())->toBe(0);
});
