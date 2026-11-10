<?php

declare(strict_types=1);

namespace App\Domain\Travel\Actions;

use App\Domain\Budget\BudgetLedger;
use App\Domain\Travel\Enums\TravelRequestStatus;
use App\Domain\Travel\Events\TravelRequestEvent;
use App\Domain\Travel\Exceptions\DomainException;
use App\Infrastructure\Locks;
use App\Infrastructure\Outbox\Outbox;
use App\Models\TravelRequest;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;

/**
 * Cancela a solicitação.
 *
 * Usa o mesmo lock da emissão. Sem ele, um cancelamento podia entrar enquanto
 * a emissão esperava o fornecedor: a viagem virava "cancelled", a emissão
 * falhava na transição para "booked" e as reservas já confirmadas ficavam no
 * fornecedor sem registro no sistema. Ver docs/adr/0005.
 */
final readonly class CancelTravelRequest
{
    public function __construct(
        private ConnectionInterface $db,
        private Cache $cache,
        private Outbox $outbox,
        private BudgetLedger $ledger,
        private int $lockWaitSeconds = 5,
    ) {}

    public function handle(TravelRequest $request): TravelRequest
    {
        $lock = Locks::from($this->cache)->lock(BookTravelRequest::lockKey($request->id), BookTravelRequest::LOCK_SECONDS);

        try {
            $cancelled = $lock->block($this->lockWaitSeconds, fn (): TravelRequest => $this->db->transaction(function () use ($request): TravelRequest {
                $request = TravelRequest::query()->lockForUpdate()->findOrFail($request->id);

                $request->transitionTo(TravelRequestStatus::Cancelled);
                $request->cancelled_at = now()->toImmutable();
                $request->save();

                // Quem estiver consumindo (ex.: integração com fornecedor) cancela as reservas.
                $this->outbox->record(TravelRequestEvent::cancelled($request));

                return $request;
            }));
        } catch (LockTimeoutException) {
            throw new DomainException('Já existe uma emissão ou um cancelamento em andamento para esta solicitação.', 'booking_in_progress');
        }

        $this->ledger->forget($cancelled->cost_center_id, $cancelled->departure_on);

        return $cancelled;
    }
}
