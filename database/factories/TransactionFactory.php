<?php

namespace Database\Factories;

use App\Models\Import;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    private static int $row = 1;

    public function definition(): array
    {
        return [
            // import_id must come first: period_id is read from it.
            'import_id' => Import::factory()->confirmed(),
            'period_id' => fn (array $attributes) => Import::find($attributes['import_id'])->period_id,
            'reconciliation_id' => null,
            'row_number' => fn () => ++self::$row,
            'transacted_on' => fake()->dateTimeBetween('-60 days')->format('Y-m-d'),
            'description' => strtoupper(fake()->company()),
            'amount' => fake()->numberBetween(100, 50000),
            'card_ref' => '1001',
        ];
    }
}
