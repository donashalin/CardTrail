<?php

use App\Enums\TransactionStatus;
use App\Models\Period;
use App\Models\Receipt;
use App\Models\User;

it('shows a cardholder their published months with progress and past months', function () {
    $sam = User::factory()->create();
    $period = Period::factory()->published()->create(['label' => 'September 2026', 'due_date' => today()->addWeek()]);
    lineFor($sam, ['status' => TransactionStatus::Done], $period);
    lineFor($sam, [], $period);
    lineFor($sam, ['status' => TransactionStatus::Queried], $period);
    lineFor($sam, [], Period::factory()->create(['label' => 'Draft month']));
    lineFor($sam, ['status' => TransactionStatus::Approved], Period::factory()->closed()->create(['label' => 'June 2026']));
    lineFor(User::factory()->create(), [], $period);

    $this->actingAs($sam)->get('/dashboard')->assertInertia(fn ($page) => $page
        ->component('Dashboard')
        ->has('current', 1)
        ->where('current.0.period.label', 'September 2026')
        ->where('current.0.lines', 3)
        ->where('current.0.done', 1)
        ->where('current.0.queried', 1)
        ->where('current.0.overdue', false)
        ->has('past', 1)
        ->where('past.0.period.label', 'June 2026'));
});

it('flags a month as overdue once the due date has passed', function () {
    $sam = User::factory()->create();
    lineFor($sam, [], Period::factory()->published()->create(['due_date' => today()->subDay()]));

    $this->actingAs($sam)->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('current.0.overdue', true));
});

it('counts receipts waiting in the inbox', function () {
    $sam = User::factory()->create();
    Receipt::factory()->for($sam)->count(2)->create();
    Receipt::factory()->attachedTo(lineFor($sam))->create();

    $this->actingAs($sam)->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('inbox_count', 2));
});
