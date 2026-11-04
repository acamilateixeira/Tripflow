<?php

declare(strict_types=1);

namespace App\Domain\Travel\Actions;

use App\Domain\Travel\Enums\TravelRequestStatus;
use App\Domain\Travel\Events\TravelRequestEvent;
use App\Infrastructure\Outbox\Outbox;
use App\Models\TravelRequest;
use Illuminate\Database\ConnectionInterface;

final readonly class SubmitTravelRequest
{
    public function __construct(
        private ConnectionInterface $db,
        private Outbox $outbox,
    ) {}

    public function handle(TravelRequest $request): TravelRequest
    {
        return $this->db->transaction(function () use ($request): TravelRequest {
            $request = TravelRequest::query()->lockForUpdate()->findOrFail($request->id);

            $request->transitionTo(TravelRequestStatus::PendingApproval);
            $request->submitted_at = now()->toImmutable();
            $request->save();

            $this->outbox->record(TravelRequestEvent::submitted($request));

            return $request;
        });
    }
}
