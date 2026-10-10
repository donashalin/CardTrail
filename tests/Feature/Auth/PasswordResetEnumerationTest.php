<?php

use App\Models\User;

it('gives the same reset error for unknown emails and bad tokens so accounts are not revealed', function () {
    User::factory()->create(['email' => 'sam@cardtrail.test']);

    $attempt = fn (string $email) => $this->post('/reset-password', [
        'token' => 'junk',
        'email' => $email,
        'password' => 'a-strong-password',
        'password_confirmation' => 'a-strong-password',
    ])->baseResponse->getSession()->get('errors')->first('email');

    expect($attempt('nobody@cardtrail.test'))->toBe($attempt('sam@cardtrail.test'));
});

it('rate-limits password reset attempts', function () {
    foreach (range(1, 10) as $i) {
        $this->post('/reset-password', ['token' => 'junk', 'email' => 'sam@cardtrail.test', 'password' => 'x', 'password_confirmation' => 'x']);
    }

    $this->post('/reset-password', ['token' => 'junk', 'email' => 'sam@cardtrail.test', 'password' => 'x', 'password_confirmation' => 'x'])
        ->assertStatus(429);
});
