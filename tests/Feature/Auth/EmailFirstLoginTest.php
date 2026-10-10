<?php

use App\Models\User;
use Spatie\Activitylog\Models\Activity;

it('shows the email step', function () {
    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/Login'));
});

it('sends password users to the password step', function () {
    User::factory()->create(['email' => 'sam@cardtrail.test']);

    $this->post('/login/email', ['email' => 'sam@cardtrail.test'])
        ->assertRedirect(route('login.password'))
        ->assertSessionHas('login.email', 'sam@cardtrail.test');
});

it('normalises the email at the email step', function () {
    User::factory()->create(['email' => 'sam@cardtrail.test']);

    $this->post('/login/email', ['email' => '  Sam@CardTrail.TEST '])
        ->assertSessionHas('login.email', 'sam@cardtrail.test');
});

it('treats unknown and inactive emails exactly like password users', function (string $email) {
    User::factory()->inactive()->create(['email' => 'gone@cardtrail.test']);
    User::factory()->microsoft()->inactive()->create(['email' => 'gone-ms@cardtrail.test']);

    $this->post('/login/email', ['email' => $email])
        ->assertRedirect(route('login.password'));
})->with(['nobody@cardtrail.test', 'gone@cardtrail.test', 'gone-ms@cardtrail.test']);

it('shows the password step with the chosen email', function () {
    $this->withSession(['login.email' => 'sam@cardtrail.test'])
        ->get('/login/password')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('auth/LoginPassword')
            ->where('email', 'sam@cardtrail.test'));
});

it('sends people back to the email step if the password step has no email', function () {
    $this->get('/login/password')->assertRedirect(route('login'));
});

it('signs in a password user regardless of email case', function () {
    $user = User::factory()->create(['email' => 'sam@cardtrail.test']);

    $this->post('/login', ['email' => ' SAM@cardtrail.test', 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

it('logs successful password sign-ins', function () {
    $user = User::factory()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    $activity = Activity::where('log_name', 'auth')->where('event', 'login')->sole();
    expect($activity->causer_id)->toBe($user->id)
        ->and($activity->description)->toBe('Signed in with password');
});

it('refuses password sign-in for inactive users', function () {
    $user = User::factory()->inactive()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('refuses password sign-in for Microsoft 365 users even if they have a password', function () {
    $user = User::factory()->create(['use_microsoft' => true]);

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('signs out a user who was deactivated during their session', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/dashboard')->assertOk();

    $user->update(['active' => false]);

    $this->get('/dashboard')
        ->assertRedirect(route('login'))
        ->assertSessionHas('error');
    $this->assertGuest();
});

it('has no public registration', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', [])->assertNotFound();
});
