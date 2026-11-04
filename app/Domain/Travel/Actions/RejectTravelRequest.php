<?php

declare(strict_types=1);

namespace App\Domain\Travel\Actions;

use App\Domain\Travel\Enums\TravelRequestStatus;
use App\Domain\Travel\Events\TravelRequestEvent;
use App\Infrastructure\Outbox\Outbox;
use App\Models\TravelRequest;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;

final readonly class RejectTravelRequest
{
    public function __construct(
        private ConnectionInterface $db,
        private Outbox $outbox,
    ) {}

    public function handle(TravelRequest $request, User $approver, string $reason): TravelRequest
    {
        return $this->db->transaction(function () use ($request, $approver, $reason): TravelRequest {
            $request = TravelRequest::query()->lockForUpdate()->findOrFail($request->id);

            $request->transitionTo(TravelRequestStatus::Rejected);
            $request->approver_id = $approver->id;
            $request->rejection_reason = $reason;
            $request->decided_at = now()->toImmutable();
            $request->save();

            $this->outbox->record(TravelRequestEvent::rejected($request));

            return $request;
        });
    }
}
