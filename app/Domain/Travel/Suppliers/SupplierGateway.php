<?php

declare(strict_types=1);

namespace App\Domain\Travel\Suppliers;

use App\Domain\Travel\Enums\BookingType;
use App\Models\TravelRequest;

/**
 * Porta para fornecedores (GDS de aéreo, rede hoteleira, locadora).
 * A implementação real seria um adapter HTTP por fornecedor; aqui há um
 * fake determinístico para desenvolvimento e testes.
 */
interface SupplierGateway
{
    public function book(TravelRequest $request, BookingType $type, string $supplier, int $amountCents): BookingConfirmation;
}
