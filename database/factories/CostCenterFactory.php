<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use App\Models\CostCenter;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CostCenter> */
final class CostCenterFactory extends Factory
{
    protected $model = CostCenter::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'code' => strtoupper(fake()->unique()->bothify('CC-###')),
            'name' => fake()->randomElement(['Comercial', 'Tecnologia', 'Operações', 'Financeiro']),
            'monthly_budget_cents' => 5_000_000, // R$ 50.000,00
        ];
    }
}
