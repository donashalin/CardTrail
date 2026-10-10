<?php

namespace Database\Factories;

use App\Enums\ImportStatus;
use App\Models\Import;
use App\Models\Period;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Import>
 */
class ImportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'period_id' => Period::factory(),
            'original_filename' => 'statement.csv',
            'stored_path' => fn () => 'imports/'.Str::uuid().'.csv',
            'file_hash' => fn () => hash('sha256', Str::random(40)),
            'header_row' => 1,
            'column_mapping' => ['date' => 0, 'description' => 1, 'cardholder' => 2, 'card' => 3, 'amount' => 4, 'purpose' => null],
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn () => ['status' => ImportStatus::Confirmed]);
    }
}
