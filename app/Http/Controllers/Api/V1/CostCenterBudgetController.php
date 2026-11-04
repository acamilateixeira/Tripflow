<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Budget\BudgetLedger;
use App\Http\Controllers\Controller;
use App\Models\CostCenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CostCenterBudgetController extends Controller
{
    public function __invoke(Request $request, CostCenter $costCenter, BudgetLedger $ledger): JsonResponse
    {
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);

        // Validado acima como Y-m; o "!" zera os campos não informados (evita pular de mês no dia 31).
        $month = isset($data['month'])
            ? CarbonImmutable::createFromFormat('!Y-m', $data['month'])
            : null;
        $month = ($month ?? CarbonImmutable::now())->startOfMonth();

        $summary = $ledger->summary($costCenter, $month);

        return new JsonResponse(['data' => [
            'cost_center' => $summary->costCenterCode,
            'month' => $summary->month->format('Y-m'),
            'budget_cents' => $summary->budgetCents,
            'committed_cents' => $summary->committedCents,
            'available_cents' => $summary->availableCents(),
            'usage_percent' => $summary->usagePercent(),
        ]]);
    }
}
