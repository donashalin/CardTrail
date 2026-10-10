<?php

use App\Models\CardIdentifier;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

function userPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Sam Cardholder',
        'email' => 'sam@cardtrail.test',
        'is_admin' => false,
        'is_cardholder' => true,
        'use_microsoft' => false,
        'active' => true,
        'card_identifiers' => [
            ['id' => null, 'last4' => '1001', 'cardholder_name' => 'S CARDHOLDER'],
        ],
    ], $overrides);
}

beforeEach(function () {
    // Named so it sorts before "Sam" in the alphabetical list.
    $this->admin = User::factory()->admin()->create(['name' => 'Alex Admin', 'email' => 'admin@cardtrail.test']);
});

it('blocks guests and non-admins', function () {
    $this->get(route('admin.users.index'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())
        ->get(route('admin.users.index'))
        ->assertForbidden();
});

it('lists active users with their card identifiers', function () {
    $sam = User::factory()->create(['name' => 'Sam', 'email' => 'sam@cardtrail.test']);
    CardIdentifier::factory()->for($sam)->create(['last4' => '1001']);
    User::factory()->inactive()->create(['name' => 'Gone']);

    $this->actingAs($this->admin)
        ->get(route('admin.users.index'))
        ->assertInertia(fn ($page) => $page
            ->component('admin/users/Index')
            ->has('users.data', 2)
            ->where('users.data.1.name', 'Sam')
            ->where('users.data.1.card_identifiers.0.last4', '1001'));
});

it('shows inactive users when asked and searches by name or email', function () {
    User::factory()->inactive()->create(['name' => 'Gone Person', 'email' => 'gone@cardtrail.test']);

    $this->actingAs($this->admin)
        ->get(route('admin.users.index', ['inactive' => 1, 'q' => 'gone@']))
        ->assertInertia(fn ($page) => $page
            ->has('users.data', 1)
            ->where('users.data.0.name', 'Gone Person')
            ->where('filters.inactive', true));
});

it('creates a user with card identifiers', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.users.store'), userPayload(['email' => ' Sam@CardTrail.TEST ']))
        ->assertRedirect(route('admin.users.index'))
        ->assertSessionHas('success');

    $sam = User::where('email', 'sam@cardtrail.test')->sole();
    expect($sam->password)->toBeNull()
        ->and($sam->cardIdentifiers)->toHaveCount(1)
        ->and($sam->cardIdentifiers->first()->last4)->toBe('1001');
});

it('validates card identifiers', function (array $identifier, string $errorKey) {
    $this->actingAs($this->admin)
        ->post(route('admin.users.store'), userPayload(['card_identifiers' => [$identifier]]))
        ->assertSessionHasErrors($errorKey);
})->with([
    'three digits' => [['id' => null, 'last4' => '123', 'cardholder_name' => null], 'card_identifiers.0.last4'],
    'letters' => [['id' => null, 'last4' => '12ab', 'cardholder_name' => null], 'card_identifiers.0.last4'],
    'long name' => [['id' => null, 'last4' => null, 'cardholder_name' => str_repeat('A', 101)], 'card_identifiers.0.cardholder_name'],
]);

it('ignores completely blank card identifier rows', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.users.store'), userPayload(['card_identifiers' => [
            ['id' => null, 'last4' => '', 'cardholder_name' => '  '],
        ]]))
        ->assertSessionHasNoErrors();

    expect(CardIdentifier::count())->toBe(0);
});

it('rejects a duplicate email', function () {
    User::factory()->create(['email' => 'sam@cardtrail.test']);

    $this->actingAs($this->admin)
        ->post(route('admin.users.store'), userPayload(['email' => 'SAM@cardtrail.test']))
        ->assertSessionHasErrors('email');
});

it('shows the edit form', function () {
    $sam = User::factory()->create();
    CardIdentifier::factory()->for($sam)->create(['last4' => '4321']);

    $this->actingAs($this->admin)
        ->get(route('admin.users.edit', $sam))
        ->assertInertia(fn ($page) => $page
            ->component('admin/users/Form')
            ->where('user.id', $sam->id)
            ->where('user.card_identifiers.0.last4', '4321')
            ->where('user.has_password', true));
});

it('updates a user and syncs card identifiers', function () {
    $sam = User::factory()->create(['email' => 'sam@cardtrail.test']);
    $keep = CardIdentifier::factory()->for($sam)->create(['last4' => '1111']);
    $drop = CardIdentifier::factory()->for($sam)->create(['last4' => '2222']);

    $this->actingAs($this->admin)
        ->put(route('admin.users.update', $sam), userPayload([
            'name' => 'Samantha',
            'use_microsoft' => true,
            'card_identifiers' => [
                ['id' => $keep->id, 'last4' => '1112', 'cardholder_name' => null],
                ['id' => null, 'last4' => null, 'cardholder_name' => 'S NEW'],
            ],
        ]))
        ->assertRedirect(route('admin.users.index'));

    $sam->refresh();
    expect($sam->name)->toBe('Samantha')
        ->and($sam->use_microsoft)->toBeTrue()
        ->and($sam->cardIdentifiers->pluck('last4')->filter()->values()->all())->toBe(['1112'])
        ->and($sam->cardIdentifiers->pluck('cardholder_name')->filter()->values()->all())->toBe(['S NEW'])
        ->and(CardIdentifier::find($drop->id))->toBeNull();
});

it('does not let one user claim another user\'s card identifier by id', function () {
    $other = User::factory()->create();
    $theirs = CardIdentifier::factory()->for($other)->create(['last4' => '9999']);
    $sam = User::factory()->create(['email' => 'sam@cardtrail.test']);

    $this->actingAs($this->admin)->put(route('admin.users.update', $sam), userPayload([
        'card_identifiers' => [['id' => $theirs->id, 'last4' => '5555', 'cardholder_name' => null]],
    ]));

    expect($theirs->fresh()->last4)->toBe('9999')
        ->and($theirs->fresh()->user_id)->toBe($other->id)
        ->and($sam->cardIdentifiers()->pluck('last4')->all())->toBe(['5555']);
});

it('deactivates rather than deletes', function () {
    $sam = User::factory()->create(['email' => 'sam@cardtrail.test']);

    $this->actingAs($this->admin)->put(route('admin.users.update', $sam), userPayload(['active' => false]));

    expect($sam->fresh()->active)->toBeFalse();
});

it('stops admins removing their own admin role, deactivating themselves or switching their own sign-in method', function (array $change, string $errorKey) {
    $this->actingAs($this->admin)
        ->put(route('admin.users.update', $this->admin), userPayload([
            'name' => $this->admin->name,
            'email' => $this->admin->email,
            'is_admin' => true,
            ...$change,
        ]))
        ->assertSessionHasErrors($errorKey);

    expect($this->admin->fresh()->is_admin)->toBeTrue()
        ->and($this->admin->fresh()->active)->toBeTrue()
        ->and($this->admin->fresh()->use_microsoft)->toBeFalse();
})->with([
    'demote' => [['is_admin' => false], 'is_admin'],
    'deactivate' => [['active' => false], 'active'],
    'switch to Microsoft' => [['use_microsoft' => true], 'use_microsoft'],
]);

it('records who changed what in the activity log', function () {
    $sam = User::factory()->create(['email' => 'sam@cardtrail.test', 'name' => 'Sam']);

    $this->actingAs($this->admin)->put(route('admin.users.update', $sam), userPayload(['name' => 'Samantha']));

    $activity = Activity::where('log_name', 'users')
        ->where('subject_type', User::class)
        ->where('subject_id', $sam->id)
        ->where('event', 'updated')
        ->latest('id')
        ->first();

    expect($activity->causer_id)->toBe($this->admin->id)
        ->and($activity->properties['attributes']['name'])->toBe('Samantha')
        ->and($activity->properties['old']['name'])->toBe('Sam');
});
