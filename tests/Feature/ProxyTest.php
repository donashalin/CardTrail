<?php

use Illuminate\Support\Facades\Route;

beforeEach(fn () => Route::get('/_proxy-check', fn () => url('/dashboard')));

it('builds https links behind a proxy on this machine, like tailscale serve', function () {
    $this->get('/_proxy-check', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'cardtrail.example.ts.net'])
        ->assertSee('https://cardtrail.example.ts.net/dashboard', false);
});

it('ignores forwarding headers from other machines', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->get('/_proxy-check', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'evil.example'])
        ->assertDontSee('evil.example', false);
});
