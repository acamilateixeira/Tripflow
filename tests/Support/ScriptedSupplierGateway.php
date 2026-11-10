<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Travel\Enums\BookingType;
use App\Domain\Travel\Suppliers\BookingConfirmation;
use App\Domain\Travel\Suppliers\SupplierGateway;
use App\Domain\Travel\Suppliers\SupplierUnavailable;
use App\Models\TravelRequest;
use Closure;

/**
 * Fornecedor de teste com roteiro: falha numa chamada específica ou executa
 * algo "no meio" da chamada, simulando o que acontece enquanto a emissão
 * espera a resposta externa (outra requisição chegando, por exemplo).
 */
final class ScriptedSupplierGateway implements SupplierGateway
{
    /** @var list<BookingConfirmation> */
    public array $booked = [];

    /** @var list<BookingConfirmation> */
    public array $cancelled = [];

    private int $calls = 0;

    /**
     * @param  int|null  $failOnCall  número da chamada a book() que falha (1 = primeira)
     * @param  (Closure(TravelRequest): void)|null  $duringBook  roda dentro da primeira chamada a book()
     */
    public function __construct(
        private ?int $failOnCall = null,
        private ?Closure $duringBook = null,
    ) {}

    public function book(TravelRequest $request, BookingType $type, string $supplier, int $amountCents): BookingConfirmation
    {
        $this->calls++;

        if ($this->calls === 1 && $this->duringBook !== null) {
            ($this->duringBook)($request);
        }

        if ($this->calls === $this->failOnCall) {
            throw SupplierUnavailable::forSupplier($supplier, 'timeout simulado');
        }

        return $this->booked[] = new BookingConfirmation($type, $supplier, sprintf('LOC%03d', $this->calls), $amountCents);
    }

    public function cancel(BookingConfirmation $confirmation): void
    {
        $this->cancelled[] = $confirmation;
    }
}
