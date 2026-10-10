<?php

use App\Models\User;
use App\Notifications\UserInvited;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

function adminPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Sam Cardholder',
        'email' => 'sam@cardtrail.test',
        'is_admin' => false,
        'is_cardholder' => true,
        'use_microsoft' => false,
        'active' => true,
        'card_identifiers' => [],
    ], $overrides);
}

beforeEach(function () {
    Notification::fake();
    $this->admin = User::factory()->admin()->create();
});

it('emails a set-password link to new password users', function () {
    $this->actingAs($this->admin)->post(route('admin.users.store'), adminPayload());

    $sam = User::where('email', 'sam@cardtrail.test')->sole();
    Notification::assertSentTo($sam, UserInvited::class, fn (UserInvited $n) => $n->token !== null);
});

it('emails a sign-in link (no token) to new Microsoft 365 users', function () {
    $this->actingAs($this->admin)->post(route('admin.users.store'), adminPayload(['use_microsoft' => true]));

    $sam = User::where('email', 'sam@cardtrail.test')->sole();
    Notification::assertSentTo($sam, UserInvited::class, fn (UserInvited $n) => $n->token === null);
});

it('emails a set-password link when a passwordless user is switched from Microsoft 365 to password', function () {
    $sam = User::factory()->microsoft()->create(['email' => 'sam@cardtrail.test']);

    $this->actingAs($this->admin)
        ->put(route('admin.users.update', $sam), adminPayload(['use_microsoft' => false]))
        ->assertSessionHas('success', fn ($message) => str_contains($message, 'set a password'));

    Notification::assertSentTo($sam, UserInvited::class, fn (UserInvited $n) => $n->token !== null);
});

it('does not re-invite on ordinary edits', function () {
    $sam = User::factory()->create(['email' => 'sam@cardtrail.test']);

    $this->actingAs($this->admin)->put(route('admin.users.update', $sam), adminPayload(['name' => 'Samantha']));

    Notification::assertNothingSentTo($sam);
});

it('lets an admin resend an invitation', function () {
    $sam = User::factory()->create();

    $this->actingAs($this->admin)
        ->post(route('admin.users.invite', $sam))
        ->assertRedirect()
        ->assertSessionHas('success');

    Notification::assertSentTo($sam, UserInvited::class);
});

it('will not resend an invitation to an inactive user', function () {
    $gone = User::factory()->inactive()->create();

    $this->actingAs($this->admin)
        ->post(route('admin.users.invite', $gone))
        ->assertSessionHas('error');

    Notification::assertNothingSentTo($gone);
});

it('links to the set-password page from the invitation email', function () {
    $sam = User::factory()->create(['email' => 'sam@cardtrail.test', 'password' => null]);
    $token = Password::broker('invites')->createToken($sam);

    $mail = (new UserInvited($token))->toMail($sam);

    expect($mail->actionUrl)->toBe(route('invite.show', ['token' => $token, 'email' => 'sam@cardtrail.test']));
});

it('shows the set-password page', function () {
    $this->get(route('invite.show', ['token' => 'abc', 'email' => 'sam@cardtrail.test']))
        ->assertInertia(fn ($page) => $page
            ->component('auth/AcceptInvite')
            ->where('token', 'abc')
            ->where('email', 'sam@cardtrail.test'));
});

it('sets a password from a valid invitation and allows sign-in', function () {
    $sam = User::factory()->create(['email' => 'sam@cardtrail.test', 'password' => null]);
    $token = Password::broker('invites')->createToken($sam);

    $this->post(route('invite.store'), [
        'token' => $token,
        'email' => ' Sam@CardTrail.test',
        'password' => 'a-strong-password',
        'password_confirmation' => 'a-strong-password',
    ])->assertRedirect(route('login'))->assertSessionHas('status');

    $this->post('/login', ['email' => 'sam@cardtrail.test', 'password' => 'a-strong-password']);
    $this->assertAuthenticatedAs($sam);
});

it('rejects an expired invitation', function () {
    $sam = User::factory()->create(['email' => 'sam@cardtrail.test', 'password' => null]);
    $token = Password::broker('invites')->createToken($sam);

    $this->travel(8)->days();

    $this->post(route('invite.store'), [
        'token' => $token,
        'email' => 'sam@cardtrail.test',
        'password' => 'a-strong-password',
        'password_confirmation' => 'a-strong-password',
    ])->assertSessionHasErrors('email');

    expect($sam->fresh()->password)->toBeNull();
});

it('rejects invitations for inactive or Microsoft 365 users', function (string $state) {
    $sam = User::factory()->{$state}()->create(['email' => 'sam@cardtrail.test']);
    $token = Password::broker('invites')->createToken($sam);

    $this->post(route('invite.store'), [
        'token' => $token,
        'email' => 'sam@cardtrail.test',
        'password' => 'a-strong-password',
        'password_confirmation' => 'a-strong-password',
    ])->assertSessionHasErrors('email');
})->with(['inactive', 'microsoft']);
