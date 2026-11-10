<?php

use App\Domain\Travel\Actions\ApproveTravelRequest;
use App\Domain\Travel\Enums\TravelRequestStatus;
use App\Models\CostCenter;
use App\Models\TravelRequest;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
 * Concorrência de verdade no MySQL, com duas conexões.
 *
 * tests/Feature/BudgetTest.php confere a regra (a segunda aprovação estoura e é
 * recusada), mas roda as aprovações uma depois da outra. Aqui uma segunda
 * conexão segura o lock da linha do centro de custo, como faria outra aprovação
 * em andamento, e o teste prova que a aprovação espera por ela em vez de ler o
 * comprometido sem lock. Se alguém remover o lockForUpdate de
 * ApproveTravelRequest, a aprovação deixa de esperar e este teste falha.
 *
 * Os dados precisam estar commitados para a outra conexão enxergar, então este
 * diretório usa truncamento em vez da transação do RefreshDatabase.
 */

uses(TestCase::class, DatabaseTruncation::class);

beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Exige MySQL (o CI sobe o serviço). SQLite não tem SELECT ... FOR UPDATE.');
    }

    config(['database.connections.concorrente' => config('database.connections.mysql')]);
});

afterEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        return;
    }

    DB::connection('concorrente')->rollBack();
    DB::purge('concorrente');
    DB::statement('SET SESSION innodb_lock_wait_timeout = DEFAULT');
});

it('a aprovação espera o lock do centro de custo segurado por outra transação', function () {
    $traveler = User::factory()->create();
    $approver = User::factory()->approver()->create(['company_id' => $traveler->company_id]);
    $cc = CostCenter::factory()->create(['company_id' => $traveler->company_id, 'monthly_budget_cents' => 500_000]);
    $request = TravelRequest::factory()->pending()->create([
        'traveler_id' => $traveler->id, 'cost_center_id' => $cc->id, 'estimated_cost_cents' => 300_000,
    ]);

    // "Outra aprovação" em andamento: segura a linha do centro de custo.
    $other = DB::connection('concorrente');
    $other->beginTransaction();
    $other->select('select id from cost_centers where id = ? for update', [$cc->id]);

    DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

    $started = microtime(true);
    try {
        app(ApproveTravelRequest::class)->handle($request, $approver);
        $this->fail('A aprovação deveria esperar o lock da outra transação.');
    } catch (QueryException $e) {
        // 1205: Lock wait timeout exceeded.
        expect($e->errorInfo[1] ?? null)->toBe(1205)
            ->and(microtime(true) - $started)->toBeGreaterThanOrEqual(0.9);
    }

    expect($request->fresh()->status)->toBe(TravelRequestStatus::PendingApproval);

    // Liberado o lock, a mesma aprovação passa.
    $other->rollBack();
    expect(app(ApproveTravelRequest::class)->handle($request, $approver)->status)
        ->toBe(TravelRequestStatus::Approved);
});
