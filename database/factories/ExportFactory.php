<?php

namespace Database\Factories;

use App\Models\Export;
use App\Models\Period;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Export>
 */
class ExportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'period_id' => Period::factory()->published(),
            'user_id' => null,
            'requested_by' => User::factory()->admin(),
        ];
    }
}
