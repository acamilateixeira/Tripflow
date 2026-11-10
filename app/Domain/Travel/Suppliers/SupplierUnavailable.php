<?php

declare(strict_types=1);

namespace App\Domain\Travel\Suppliers;

use RuntimeException;

/**
 * O fornecedor falhou, recusou ou não respondeu dentro do prazo do adapter.
 * Renderizada como 503 (não é erro do cliente) e, por ser 5xx, a resposta não
 * fica guardada pela idempotência: o cliente pode tentar de novo com a mesma chave.
 */
final class SupplierUnavailable extends RuntimeException
{
    public static function forSupplier(string $supplier, string $reason): self
    {
        return new self(sprintf('Fornecedor %s indisponível: %s', $supplier, $reason));
    }
}
