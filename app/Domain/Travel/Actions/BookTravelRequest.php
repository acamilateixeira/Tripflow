<?php

declare(strict_types=1);

namespace App\Domain\Travel\Actions;

use App\Domain\Travel\Enums\BookingType;
use App\Domain\Travel\Enums\TravelRequestStatus;
use App\Domain\Travel\Events\TravelRequestEvent;
use App\Domain\Travel\Exceptions\DomainException;
use App\Domain\Travel\Exceptions\InvalidTransition;
use App\Domain\Travel\Suppliers\BookingConfirmation;
use App\Domain\Travel\Suppliers\SupplierGateway;
use App\Infrastructure\Locks;
use App\Infrastructure\Outbox\Outbox;
use App\Models\Booking;
use App\Models\TravelRequest;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Emite as reservas (voo, hotel, carro) junto aos fornecedores.
 *
 * A chamada ao fornecedor é externa e lenta: não dá para segurá-la dentro de
 * uma transação com lock de linha no banco. Por isso a exclusão mútua é feita
 * com um lock distribuído no Redis (Cache::lock), e só a gravação do resultado
 * acontece na transação. O cancelamento usa o mesmo lock. Ver docs/adr/0003.
 *
 * Se a emissão não termina (um fornecedor falha depois de outro ter confirmado,
 * ou a gravação falha), as reservas já confirmadas são canceladas no fornecedor
 * antes de o erro subir. Ver docs/adr/0005.
 */
final readonly class BookTravelRequest
{
    public const LOCK_SECONDS = 30;

    private const WAIT_SECONDS = 5;

    public function __construct(
        private ConnectionInterface $db,
        private Cache $cache,
        private Outbox $outbox,
        private SupplierGateway $suppliers,
        private LoggerInterface $logger,
    ) {}

    /** Chave do lock que serializa emissão e cancelamento de uma solicitação. */
    public static function lockKey(int $travelRequestId): string
    {
        return "travel_request:{$travelRequestId}:booking";
    }

    /**
     * @param  list<array{type: string, supplier: string, amount_cents: int}>  $items
     */
    public function handle(TravelRequest $request, array $items): TravelRequest
    {
        $lock = Locks::from($this->cache)->lock(self::lockKey($request->id), self::LOCK_SECONDS);

        try {
            return $lock->block(self::WAIT_SECONDS, function () use ($request, $items): TravelRequest {
                $request->refresh();

                if (! $request->status->canTransitionTo(TravelRequestStatus::Booked)) {
                    throw InvalidTransition::between($request->status, TravelRequestStatus::Booked);
                }

                /** @var list<BookingConfirmation> $confirmations */
                $confirmations = [];

                try {
                    // Chamadas externas fora da transação.
                    foreach ($items as $item) {
                        $confirmations[] = $this->suppliers->book(
                            $request,
                            BookingType::from($item['type']),
                            $item['supplier'],
                            $item['amount_cents'],
                        );
                    }

                    return $this->record($request, $confirmations);
                } catch (Throwable $e) {
                    $this->compensate($request, $confirmations, $e);

                    throw $e;
                }
            });
        } catch (LockTimeoutException) {
            throw new DomainException('Já existe uma emissão ou um cancelamento em andamento para esta solicitação.', 'booking_in_progress');
        }
    }

    /**
     * @param  list<BookingConfirmation>  $confirmations
     */
    private function record(TravelRequest $request, array $confirmations): TravelRequest
    {
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

            $total = array_sum(array_map(fn (BookingConfirmation $c) => $c->amountCents, $confirmations));
            $this->outbox->record(TravelRequestEvent::booked($request, $total));

            return $request;
        });
    }

    /**
     * Desfaz no fornecedor o que já foi confirmado. Uma falha aqui não esconde
     * o erro original: fica registrada com o localizador para tratamento manual.
     *
     * @param  list<BookingConfirmation>  $confirmations
     */
    private function compensate(TravelRequest $request, array $confirmations, Throwable $cause): void
    {
        foreach ($confirmations as $confirmation) {
            try {
                $this->suppliers->cancel($confirmation);

                $this->logger->warning('booking.compensated', [
                    'travel_request_id' => $request->id,
                    'supplier' => $confirmation->supplier,
                    'locator' => $confirmation->locator,
                    'cause' => $cause->getMessage(),
                ]);
            } catch (Throwable $e) {
                $this->logger->critical('booking.compensation_failed', [
                    'travel_request_id' => $request->id,
                    'supplier' => $confirmation->supplier,
                    'locator' => $confirmation->locator,
                    'cause' => $cause->getMessage(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
