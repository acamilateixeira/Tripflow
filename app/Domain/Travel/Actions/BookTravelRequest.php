<?php

declare(strict_types=1);

namespace App\Domain\Travel\Actions;

use App\Domain\Travel\Enums\BookingType;
use App\Domain\Travel\Enums\TravelRequestStatus;
use App\Domain\Travel\Events\TravelRequestEvent;
use App\Domain\Travel\Exceptions\DomainException;
use App\Domain\Travel\Exceptions\InvalidTransition;
use App\Domain\Travel\Suppliers\SupplierGateway;
use App\Infrastructure\Locks;
use App\Infrastructure\Outbox\Outbox;
use App\Models\Booking;
use App\Models\TravelRequest;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;

/**
 * Emite as reservas (voo, hotel, carro) junto aos fornecedores.
 *
 * A chamada ao fornecedor é externa e lenta: não dá para segurá-la dentro de
 * uma transação com lock de linha no banco. Por isso a exclusão mútua é feita
 * com um lock distribuído no Redis (Cache::lock), e só a gravação do resultado
 * acontece na transação. Ver docs/adr/0003.
 */
final readonly class BookTravelRequest
{
    private const LOCK_SECONDS = 30;

    private const WAIT_SECONDS = 5;

    public function __construct(
        private ConnectionInterface $db,
        private Cache $cache,
        private Outbox $outbox,
        private SupplierGateway $suppliers,
    ) {}

    /**
     * @param  list<array{type: string, supplier: string, amount_cents: int}>  $items
     */
    public function handle(TravelRequest $request, array $items): TravelRequest
    {
        $lock = Locks::from($this->cache)->lock("travel_request:{$request->id}:booking", self::LOCK_SECONDS);

        try {
            return $lock->block(self::WAIT_SECONDS, function () use ($request, $items): TravelRequest {
                $request->refresh();

                if (! $request->status->canTransitionTo(TravelRequestStatus::Booked)) {
                    throw InvalidTransition::between($request->status, TravelRequestStatus::Booked);
                }

                // Chamadas externas fora da transação.
                $confirmations = array_map(
                    fn (array $item) => $this->suppliers->book(
                        $request,
                        BookingType::from($item['type']),
                        $item['supplier'],
                        $item['amount_cents'],
                    ),
                    $items,
                );

                return $this->db->transaction(function () use ($request, $confirmations): TravelRequest {
                    $request = TravelRequest::query()->lockForUpdate()->findOrFail($request->id);

                    foreach ($confirmations as $confirmation) {
                        Booking::query()->create([
                            'travel_request_id' => $request->id,
                            'type' => $confirmation->type,
                            'supplier' => $confirmation->supplier,
                            'locator' => $confirmation->locator,
                            'amount_cents' => $confirmation->amountCents,
                        ]);
                    }

                    $request->transitionTo(TravelRequestStatus::Booked);
                    $request->save();

                    $total = array_sum(array_map(fn ($c) => $c->amountCents, $confirmations));
                    $this->outbox->record(TravelRequestEvent::booked($request, $total));

                    return $request;
                });
            });
        } catch (LockTimeoutException) {
            throw new DomainException('Já existe uma emissão em andamento para esta solicitação.', 'booking_in_progress');
        }
    }
}
