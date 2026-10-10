<?php

namespace Database\Factories;

use App\Enums\ReconciliationStatus;
use App\Models\Period;
use App\Models\Reconciliation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reconciliation>
 */
class ReconciliationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'period_id' => Period::factory(),
            'user_id' => User::factory(),
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn () => ['status' => ReconciliationStatus::Submitted, 'submitted_at' => now()]);
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => ReconciliationStatus::Approved, 'submitted_at' => now(), 'approved_at' => now()]);
    }
}
