<?php

declare(strict_types=1);

namespace App\Domain\Travel\Actions;

use App\Domain\Budget\BudgetLedger;
use App\Domain\Travel\Enums\TravelRequestStatus;
use App\Domain\Travel\Events\TravelRequestEvent;
use App\Infrastructure\Outbox\Outbox;
use App\Models\TravelRequest;
use Illuminate\Database\ConnectionInterface;

final readonly class CancelTravelRequest
{
    public function __construct(
        private ConnectionInterface $db,
        private Outbox $outbox,
        private BudgetLedger $ledger,
    ) {}

    public function handle(TravelRequest $request): TravelRequest
    {
        $cancelled = $this->db->transaction(function () use ($request): TravelRequest {
            $request = TravelRequest::query()->lockForUpdate()->findOrFail($request->id);

            $request->transitionTo(TravelRequestStatus::Cancelled);
            $request->cancelled_at = now()->toImmutable();
            $request->save();

            // Quem estiver consumindo (ex.: integração com fornecedor) cancela as reservas.
            $this->outbox->record(TravelRequestEvent::cancelled($request));

            return $request;
        });

        $this->ledger->forget($cancelled->cost_center_id, $cancelled->departure_on);

        return $cancelled;
    }
}
