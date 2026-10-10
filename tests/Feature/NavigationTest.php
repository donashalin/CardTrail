<?php

use App\Models\User;

it('shares role flags with the frontend so the menu can adapt', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('auth.user.is_admin', true)
            ->where('auth.user.is_cardholder', true)
            ->missing('auth.user.password')
            ->missing('auth.user.external_id'));
});

it('shows the dashboard to cardholders without admin access', function () {
    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('auth.user.is_admin', false));
});
