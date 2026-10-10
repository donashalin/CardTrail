<?php

namespace Database\Factories;

use App\Models\CardIdentifier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CardIdentifier>
 */
class CardIdentifierFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'last4' => fake()->numerify('####'),
            'cardholder_name' => null,
        ];
    }

    public function nameOnly(string $name): static
    {
        return $this->state(fn () => ['last4' => null, 'cardholder_name' => $name]);
    }
}
