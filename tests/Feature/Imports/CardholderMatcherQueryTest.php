<?php

use App\Imports\CardholderMatcher;
use App\Models\CardIdentifier;
use App\Models\User;

it('only matches active cardholders', function () {
    $active = User::factory()->create();
    CardIdentifier::factory()->for($active)->create(['last4' => '1001']);

    $inactive = User::factory()->inactive()->create();
    CardIdentifier::factory()->for($inactive)->create(['last4' => '2002']);

    $adminOnly = User::factory()->admin()->create(['is_cardholder' => false]);
    CardIdentifier::factory()->for($adminOnly)->create(['last4' => '3003']);

    $matcher = CardholderMatcher::forActiveCardholders();

    expect($matcher->match('1001', null))->toBe($active->id)
        ->and($matcher->match('2002', null))->toBeNull()
        ->and($matcher->match('3003', null))->toBeNull();
});
