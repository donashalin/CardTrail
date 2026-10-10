<?php

use App\Models\User;

it('shows the profile page', function () {
    $this->actingAs(User::factory()->create())
        ->get('/settings/profile')
        ->assertOk();
});

it('lets users change their name', function () {
    $user = User::factory()->create(['name' => 'Sam']);

    $this->actingAs($user)
        ->patch('/settings/profile', ['name' => 'Samantha'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/settings/profile');

    expect($user->fresh()->name)->toBe('Samantha');
});

it('ignores attempts to change email from the profile page', function () {
    $user = User::factory()->create(['email' => 'sam@cardtrail.test']);

    $this->actingAs($user)->patch('/settings/profile', [
        'name' => 'Sam',
        'email' => 'someone-else@cardtrail.test',
    ]);

    expect($user->fresh()->email)->toBe('sam@cardtrail.test');
});

it('does not allow users to delete their own account', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->delete('/settings/profile', ['password' => 'password'])
        ->assertStatus(405);

    expect($user->fresh())->not->toBeNull();
});
