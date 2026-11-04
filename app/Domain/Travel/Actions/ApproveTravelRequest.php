<?php

declare(strict_types=1);

namespace App\Domain\Travel\Actions;

use App\Domain\Budget\BudgetLedger;
use App\Domain\Travel\Enums\TravelRequestStatus;
use App\Domain\Travel\Events\TravelRequestEvent;
use App\Domain\Travel\Exceptions\BudgetExceeded;
use App\Infrastructure\Outbox\Outbox;
use App\Models\CostCenter;
use App\Models\TravelRequest;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Aprova uma solicitação respeitando o orçamento mensal do centro de custo.
 *
 * Concorrência: duas aprovações no mesmo centro de custo são serializadas pelo
 * SELECT ... FOR UPDATE na linha do centro de custo. Sem isso, as duas leriam o
 * mesmo "comprometido", ambas passariam na validação e o orçamento estouraria.
 * O teste "não deixa a segunda aprovação estourar o orçamento" documenta o cenário.
 */
final readonly class ApproveTravelRequest
{
    public function __construct(
        private ConnectionInterface $db,
        private Outbox $outbox,
        private BudgetLedger $ledger,
    ) {}

    public function handle(TravelRequest $request, User $approver): TravelRequest
    {
        $approved = $this->db->transaction(function () use ($request, $approver): TravelRequest {
            // Ordem de locks fixa (centro de custo → solicitação) para evitar deadlock.
            $costCenter = CostCenter::query()->lockForUpdate()->findOrFail($request->cost_center_id);
            $request = TravelRequest::query()->lockForUpdate()->findOrFail($request->id);

            $request->transitionTo(TravelRequestStatus::Approved);

            $month = $request->departure_on->startOfMonth();
            $available = $costCenter->monthly_budget_cents - $this->ledger->committedCents($costCenter, $month);

            if ($request->estimated_cost_cents > $available) {
                throw BudgetExceeded::forCostCenter($costCenter->code, max(0, $available), $request->estimated_cost_cents);
            }

            $request->approver_id = $approver->id;
            $request->decided_at = now()->toImmutable();
            $request->save();

            $this->outbox->record(TravelRequestEvent::approved($request));

            return $request;
        });

        $this->ledger->forget($approved->cost_center_id, $approved->departure_on);

        return $approved;
    }
}
