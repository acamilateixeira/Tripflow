<?php

use App\Domain\Travel\Events\TravelRequestEvent;
use App\Infrastructure\Outbox\Outbox;
use App\Infrastructure\Outbox\OutboxRelay;
use App\Infrastructure\RequestContext;
use App\Models\OutboxMessage;
use App\Models\TravelRequest;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

function pendingMessages(int $count): void
{
    ['traveler' => $traveler, 'costCenter' => $cc] = scenario();
    $request = TravelRequest::factory()->pending()->create(['traveler_id' => $traveler->id, 'cost_center_id' => $cc->id]);

    DB::transaction(function () use ($request, $count) {
        foreach (range(1, $count) as $_) {
            app(Outbox::class)->record(TravelRequestEvent::submitted($request));
        }
    });
}

it('recusa gravar evento fora de transação', function () {
    ['traveler' => $traveler, 'costCenter' => $cc] = scenario();
    $request = TravelRequest::factory()->create(['traveler_id' => $traveler->id, 'cost_center_id' => $cc->id]);

    // RefreshDatabase envolve o teste numa transação; simulamos uma conexão sem transação.
    $db = Mockery::mock(ConnectionInterface::class);
    $db->shouldReceive('transactionLevel')->andReturn(0);

    (new Outbox($db, new RequestContext))->record(TravelRequestEvent::submitted($request));
})->throws(LogicException::class, 'dentro de uma transação');

it('publica pendentes e marca como publicadas', function () {
    pendingMessages(3);

    $result = app(OutboxRelay::class)->relayBatch(10);

    expect($result)->toBe(['published' => 3, 'failed' => 0])
        ->and(publisher()->eventTypes())->toBe(array_fill(0, 3, 'travel_request.submitted'))
        ->and(OutboxMessage::query()->whereNull('published_at')->count())->toBe(0);

    // Rodar de novo não republica nada.
    expect(app(OutboxRelay::class)->relayBatch(10))->toBe(['published' => 0, 'failed' => 0]);
});

it('aplica backoff quando o broker falha e tenta de novo depois', function () {
    pendingMessages(1);
    publisher()->failNext = 1;

    expect(app(OutboxRelay::class)->relayBatch(10))->toBe(['published' => 0, 'failed' => 1]);

    $message = OutboxMessage::query()->sole();
    expect($message->attempts)->toBe(1)
        ->and($message->last_error)->toContain('broker indisponível')
        ->and($message->available_at->isFuture())->toBeTrue();

    // Ainda dentro do backoff: não tenta.
    expect(app(OutboxRelay::class)->relayBatch(10)['published'])->toBe(0);

    $this->travel(5)->seconds();
    expect(app(OutboxRelay::class)->relayBatch(10)['published'])->toBe(1);
});

it('para de tentar após o limite e mantém o erro para análise', function () {
    config(['messaging.outbox.max_attempts' => 2]);
    pendingMessages(1);
    OutboxMessage::query()->update(['attempts' => 2]);

    expect(app(OutboxRelay::class)->relayBatch(10))->toBe(['published' => 0, 'failed' => 0])
        ->and(OutboxMessage::query()->whereNull('published_at')->count())->toBe(1);
});

it('respeita o tamanho do lote', function () {
    pendingMessages(5);

    expect(app(OutboxRelay::class)->relayBatch(2)['published'])->toBe(2);
});

it('leva o correlation id da requisição para a mensagem', function () {
    ['traveler' => $traveler, 'costCenter' => $cc] = scenario();
    $request = TravelRequest::factory()->create(['traveler_id' => $traveler->id, 'cost_center_id' => $cc->id]);
    actingAsUser($traveler);

    $this->postJson("/api/v1/travel-requests/{$request->id}/submit", [], ['X-Request-Id' => 'req-abc-12345'])
        ->assertHeader('X-Request-Id', 'req-abc-12345');

    expect(OutboxMessage::query()->sole()->correlation_id)->toBe('req-abc-12345');
});
