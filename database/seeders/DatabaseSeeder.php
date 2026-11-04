<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Company;
use App\Models\CostCenter;
use App\Models\TravelRequest;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Dados de demonstração. Credenciais (senha "password" para todos):
 *   viajante@acme.test  — viajante
 *   gestora@acme.test   — aprovadora
 *   admin@acme.test     — admin
 *   viajante@globex.test — outra empresa (para testar o isolamento)
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $acme = Company::factory()->create(['name' => 'Acme Mineração', 'document' => '11222333000181']);
        $globex = Company::factory()->create(['name' => 'Globex Agro', 'document' => '44555666000199']);

        $traveler = User::factory()->for($acme)->create(['name' => 'Ana Viajante', 'email' => 'viajante@acme.test']);
        User::factory()->for($acme)->approver()->create(['name' => 'Beatriz Gestora', 'email' => 'gestora@acme.test']);
        User::factory()->for($acme)->admin()->create(['name' => 'Carlos Admin', 'email' => 'admin@acme.test']);
        $other = User::factory()->for($globex)->create(['name' => 'Diego Globex', 'email' => 'viajante@globex.test']);

        $comercial = CostCenter::factory()->for($acme)->create(['code' => 'CC-100', 'name' => 'Comercial', 'monthly_budget_cents' => 1_000_000]);
        CostCenter::factory()->for($acme)->create(['code' => 'CC-200', 'name' => 'Tecnologia', 'monthly_budget_cents' => 3_000_000]);
        $globexCc = CostCenter::factory()->for($globex)->create(['code' => 'CC-900', 'name' => 'Operações']);

        TravelRequest::factory()->create(['traveler_id' => $traveler->id, 'cost_center_id' => $comercial->id]);
        TravelRequest::factory()->pending()->create(['traveler_id' => $traveler->id, 'cost_center_id' => $comercial->id, 'destination' => 'GRU']);
        TravelRequest::factory()->pending()->create(['traveler_id' => $other->id, 'cost_center_id' => $globexCc->id]);
    }
}
