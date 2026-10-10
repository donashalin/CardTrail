<?php

use App\Models\CardIdentifier;
use App\Models\User;

it('defaults new users to an active, password-based cardholder', function () {
    $user = User::factory()->create()->fresh();

    expect($user->active)->toBeTrue()
        ->and($user->is_cardholder)->toBeTrue()
        ->and($user->is_admin)->toBeFalse()
        ->and($user->use_microsoft)->toBeFalse()
        ->and($user->external_id)->toBeNull();
});

it('stores emails lower-cased and trimmed', function () {
    $user = User::factory()->create(['email' => '  Jane.Doe@CardTrail.TEST ']);

    expect($user->fresh()->email)->toBe('jane.doe@cardtrail.test');
});

it('allows Microsoft 365 users to have no password', function () {
    $user = User::factory()->microsoft()->create()->fresh();

    expect($user->password)->toBeNull()
        ->and($user->use_microsoft)->toBeTrue();
});

it('has many card identifiers', function () {
    $user = User::factory()->create();
    CardIdentifier::factory()->for($user)->create(['last4' => '1234']);
    CardIdentifier::factory()->for($user)->nameOnly('J DOE')->create();

    expect($user->cardIdentifiers)->toHaveCount(2)
        ->and($user->cardIdentifiers->pluck('last4')->all())->toContain('1234')
        ->and($user->cardIdentifiers->pluck('cardholder_name')->all())->toContain('J DOE');
});

it('deletes card identifiers with their user', function () {
    $user = User::factory()->create();
    CardIdentifier::factory()->for($user)->create();

    $user->delete();

    expect(CardIdentifier::count())->toBe(0);
});

it('hides secrets when serialised', function () {
    $user = User::factory()->create(['external_id' => 'ext-123']);

    expect($user->toArray())->not->toHaveKeys(['password', 'remember_token', 'external_id']);
});

it('scopes to active users', function () {
    User::factory()->create(['email' => 'on@cardtrail.test']);
    User::factory()->inactive()->create(['email' => 'off@cardtrail.test']);

    expect(User::active()->pluck('email')->all())->toBe(['on@cardtrail.test']);
});
