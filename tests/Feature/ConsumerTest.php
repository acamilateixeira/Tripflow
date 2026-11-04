<?php

use App\Infrastructure\Messaging\Consumers\NotifyTravelerOnDecision;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('notifica o viajante uma única vez mesmo com reentrega', function () {
    $traveler = User::factory()->create();
    $id = (string) Str::uuid();
    $handler = app(NotifyTravelerOnDecision::class);

    $first = $handler->handle($id, 'travel_request.approved', ['traveler_id' => $traveler->id]);
    $redelivery = $handler->handle($id, 'travel_request.approved', ['traveler_id' => $traveler->id]);

    expect($first)->toBeTrue()
        ->and($redelivery)->toBeFalse()
        ->and(DB::table('notifications_log')->where('user_id', $traveler->id)->count())->toBe(1);
});

it('ignora eventos que não interessam ao consumidor', function () {
    $traveler = User::factory()->create();

    expect(app(NotifyTravelerOnDecision::class)->handle((string) Str::uuid(), 'travel_request.submitted', ['traveler_id' => $traveler->id]))
        ->toBeFalse();
});
