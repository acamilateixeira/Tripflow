<?php

declare(strict_types=1);

namespace App\Domain\Travel\Suppliers;

use App\Domain\Travel\Enums\BookingType;
use App\Models\TravelRequest;

/**
 * Porta para fornecedores (GDS de aéreo, rede hoteleira, locadora).
 * A implementação real seria um adapter HTTP por fornecedor; aqui há um
 * fake determinístico para desenvolvimento e testes.
 *
 * Contrato para os adapters:
 * - falha, recusa ou timeout do fornecedor vira SupplierUnavailable;
 * - o timeout HTTP do adapter fica abaixo do TTL do lock de emissão (ver docs/adr/0003);
 * - cancel() desfaz uma reserva confirmada e é usado como compensação quando
 *   a emissão não pode ser concluída (ver docs/adr/0005).
 */
interface SupplierGateway
{
    /** @throws SupplierUnavailable */
    public function book(TravelRequest $request, BookingType $type, string $supplier, int $amountCents): BookingConfirmation;

    /** @throws SupplierUnavailable */
    public function cancel(BookingConfirmation $confirmation): void;
}
