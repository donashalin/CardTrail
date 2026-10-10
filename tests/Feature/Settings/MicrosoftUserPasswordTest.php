<?php

use App\Models\User;

it('redirects Microsoft 365 users away from the password page', function () {
    $this->actingAs(User::factory()->microsoft()->create())
        ->get('/settings/password')
        ->assertRedirect(route('profile.edit'));
});

it('forbids Microsoft 365 users from setting a password', function () {
    $user = User::factory()->microsoft()->create();

    $this->actingAs($user)
        ->put('/settings/password', [
            'current_password' => '',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ])
        ->assertForbidden();

    expect($user->fresh()->password)->toBeNull();
});

it('still lets password users open the password page', function () {
    $this->actingAs(User::factory()->create())
        ->get('/settings/password')
        ->assertOk();
});
