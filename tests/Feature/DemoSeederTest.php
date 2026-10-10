<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;

it('seeds fake demo accounts', function () {
    $this->seed(DemoSeeder::class);

    expect(User::where('email', 'admin@cardtrail.test')->sole()->is_admin)->toBeTrue()
        ->and(User::where('email', 'morgan@cardtrail.test')->sole()->use_microsoft)->toBeTrue()
        ->and(User::where('email', 'sam@cardtrail.test')->sole()->cardIdentifiers->first()->last4)->toBe('1001')
        ->and(User::where('email', 'like', '%@cardtrail.test')->count())->toBe(User::count());
});

it('can be run twice without duplicating accounts', function () {
    $this->seed(DemoSeeder::class);
    $this->seed(DemoSeeder::class);

    expect(User::count())->toBe(4);
});

it('never seeds demo accounts outside local and testing', function () {
    app()->instance('env', 'production');

    // Call the seeder directly: db:seed would stop to ask for confirmation in production.
    app(DatabaseSeeder::class)->run();

    app()->instance('env', 'testing');

    expect(User::count())->toBe(0);
});
