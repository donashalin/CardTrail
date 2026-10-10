<?php

use App\Auth\Fake\FakeIdentityProvider;
use App\Auth\IdentityProvider;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

/** Simulate the fake provider's sign-in form, then follow the callback. */
function fakeSignIn(string $email, ?string $externalId = null)
{
    return test()->post(route('identity.fake.store'), array_filter([
        'email' => $email,
        'external_id' => $externalId,
    ]));
}

it('sends active Microsoft 365 users from the email step to the identity provider', function () {
    User::factory()->microsoft()->create(['email' => 'morgan@cardtrail.test']);

    $this->post('/login/email', ['email' => 'morgan@cardtrail.test'])
        ->assertRedirect(route('identity.redirect'));
});

it('uses an Inertia location visit for Inertia requests so external redirects work', function () {
    User::factory()->microsoft()->create(['email' => 'morgan@cardtrail.test']);

    $this->withHeaders(['X-Inertia' => 'true'])
        ->post('/login/email', ['email' => 'morgan@cardtrail.test'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', route('identity.redirect'));
});

it('resolves the fake driver in testing', function () {
    expect(app(IdentityProvider::class))->toBeInstanceOf(FakeIdentityProvider::class);
});

it('refuses to resolve the fake driver in production', function () {
    app()->instance('env', 'production');

    expect(fn () => app(IdentityProvider::class))
        ->toThrow(RuntimeException::class, 'only available in local and testing');

    app()->instance('env', 'testing');
});

it('rejects an unknown driver name', function () {
    config(['cardtrail.identity.driver' => 'nope']);

    expect(fn () => app(IdentityProvider::class))
        ->toThrow(InvalidArgumentException::class, 'Unknown identity driver [nope]');
});

it('redirects to the fake sign-in page', function () {
    $this->get(route('identity.redirect'))->assertRedirect(route('identity.fake'));
});

it('prefills the fake sign-in page with the email from the email step', function () {
    $this->withSession(['login.email' => 'morgan@cardtrail.test'])
        ->get(route('identity.fake'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/FakeIdentity')->where('email', 'morgan@cardtrail.test'));
});

it('signs in a Microsoft 365 user and stores their external id on first sign-in', function () {
    $user = User::factory()->microsoft()->create(['email' => 'morgan@cardtrail.test']);

    fakeSignIn('Morgan@CardTrail.test', 'ext-morgan')->assertRedirect(route('identity.callback'));
    $this->get(route('identity.callback'))->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->external_id)->toBe('ext-morgan');
    expect(Activity::where('event', 'login')->sole()->description)->toBe('Signed in with Microsoft 365');
});

it('signs in by external id even if the email has changed', function () {
    $user = User::factory()->microsoft()->create(['email' => 'new@cardtrail.test', 'external_id' => 'ext-1']);

    fakeSignIn('old@cardtrail.test', 'ext-1');
    $this->get(route('identity.callback'));

    $this->assertAuthenticatedAs($user);
});

it('rejects a matching email whose external id differs from the stored one', function () {
    User::factory()->microsoft()->create(['email' => 'morgan@cardtrail.test', 'external_id' => 'ext-real']);

    fakeSignIn('morgan@cardtrail.test', 'ext-imposter');
    $this->get(route('identity.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('error');

    $this->assertGuest();
});

it('rejects users who are inactive, not set to Microsoft 365, or unknown', function (string $email) {
    User::factory()->microsoft()->inactive()->create(['email' => 'inactive@cardtrail.test']);
    User::factory()->create(['email' => 'password@cardtrail.test']);

    fakeSignIn($email);
    $this->get(route('identity.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('error');

    $this->assertGuest();
})->with(['inactive@cardtrail.test', 'password@cardtrail.test', 'stranger@cardtrail.test']);

it('fails gracefully when the provider cannot verify the sign-in', function () {
    $this->get(route('identity.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('error');

    $this->assertGuest();
});
