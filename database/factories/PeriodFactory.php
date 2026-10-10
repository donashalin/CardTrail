<?php

namespace Database\Factories;

use App\Enums\PeriodStatus;
use App\Models\Period;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Period>
 */
class PeriodFactory extends Factory
{
    /** Each new period goes one month further back, starting a year ago, so months never clash with dates tests choose. */
    private static int $monthsBack = 12;

    public function definition(): array
    {
        $month = CarbonImmutable::now()->startOfMonth()->subMonths(++self::$monthsBack);

        return [
            'label' => $month->format('F Y'),
            'month' => $month->format('Y-m-d'),
            'due_date' => $month->addMonth()->day(15)->format('Y-m-d'),
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['status' => PeriodStatus::Published, 'published_at' => now()]);
    }

    public function closed(): static
    {
        return $this->state(fn () => ['status' => PeriodStatus::Closed, 'published_at' => now()]);
    }
}
