<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Travel\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
final class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'role' => UserRole::Traveler,
            'email_verified_at' => now(),
            'password' => 'password',
        ];
    }

    public function approver(): self
    {
        return $this->state(['role' => UserRole::Approver]);
    }

    public function admin(): self
    {
        return $this->state(['role' => UserRole::Admin]);
    }
}
