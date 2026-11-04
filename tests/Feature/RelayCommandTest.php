<?php

use App\Domain\Travel\Events\TravelRequestEvent;
use App\Infrastructure\Messaging\LogPublisher;
use App\Infrastructure\Outbox\Outbox;
use App\Models\OutboxMessage;
use App\Models\TravelRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

it('publica o backlog com outbox:relay --once', function () {
    ['traveler' => $traveler, 'costCenter' => $cc] = scenario();
    $request = TravelRequest::factory()->pending()->create(['traveler_id' => $traveler->id, 'cost_center_id' => $cc->id]);
    DB::transaction(fn () => app(Outbox::class)->record(TravelRequestEvent::submitted($request)));

    $this->artisan('outbox:relay', ['--once' => true])
        ->expectsOutput('publicadas=1 falhas=0')
        ->assertSuccessful();

    expect(OutboxMessage::query()->whereNotNull('published_at')->count())->toBe(1);
});

it('registra o evento no log quando roda sem broker', function () {
    Log::spy();
    $message = new OutboxMessage(['event_type' => 'travel_request.approved', 'correlation_id' => 'c-1']);
    $message->id = 'm-1';

    app(LogPublisher::class)->publish($message);

    Log::shouldHaveReceived('info')->with('event.published', Mockery::on(fn ($ctx) => $ctx['message_id'] === 'm-1'));
});
