<?php

declare(strict_types=1);

namespace App\Domain\Budget;

use App\Domain\Travel\Enums\TravelRequestStatus;
use App\Models\CostCenter;
use App\Models\TravelRequest;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Orçamento mensal por centro de custo.
 *
 * - Leitura (dashboard): cacheada no Redis por alguns minutos e invalidada a
 *   cada aprovação/cancelamento.
 * - Decisão (aprovar): NUNCA usa o cache. Lê do banco sob lock da linha do
 *   centro de custo, para que duas aprovações simultâneas não estourem o
 *   orçamento. Ver docs/adr/0003.
 */
final readonly class BudgetLedger
{
    private const CACHE_TTL_SECONDS = 300;

    public function __construct(private Cache $cache) {}

    public function summary(CostCenter $costCenter, CarbonImmutable $month): BudgetSummary
    {
        /** @var array{budget: int, committed: int} $data */
        $data = $this->cache->remember(
            $this->cacheKey($costCenter, $month),
            self::CACHE_TTL_SECONDS,
            fn (): array => [
                'budget' => $costCenter->monthly_budget_cents,
                'committed' => $this->committedCents($costCenter, $month),
            ],
        );

        return new BudgetSummary($costCenter->code, $month, $data['budget'], $data['committed']);
    }

    /**
     * Soma do que já foi aprovado/reservado para viagens que partem no mês.
     * Deve ser chamada dentro da transação que segura o lock do centro de custo.
     *
     * Sem withoutGlobalScope('company'): o centro de custo já foi carregado com o
     * escopo, então o filtro por empresa não muda o resultado, e manter o escopo
     * segue a regra do ADR 0004 para código que roda com usuário logado.
     */
    public function committedCents(CostCenter $costCenter, CarbonImmutable $month): int
    {
        return (int) TravelRequest::query()
            ->where('cost_center_id', $costCenter->id)
            ->whereIn('status', TravelRequestStatus::committingBudget())
            ->whereBetween('departure_on', [
                $month->startOfMonth()->toDateString(),
                $month->endOfMonth()->toDateString(),
            ])
            ->sum('estimated_cost_cents');
    }

    public function forget(CostCenter|int $costCenter, CarbonImmutable $month): void
    {
        $id = $costCenter instanceof CostCenter ? $costCenter->id : $costCenter;
        $this->cache->forget(sprintf('budget:%d:%s', $id, $month->format('Y-m')));
    }

    private function cacheKey(CostCenter $costCenter, CarbonImmutable $month): string
    {
        return sprintf('budget:%d:%s', $costCenter->id, $month->format('Y-m'));
    }
}
