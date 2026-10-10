<?php

use App\Enums\TransactionStatus;
use App\Models\Period;
use App\Models\Receipt;
use App\Models\User;

beforeEach(function () {
    $this->sam = User::factory()->create(['name' => 'Sam']);
});

it('lists the cardholder’s lines in statement order with their receipts', function () {
    $first = lineFor($this->sam, ['row_number' => 5, 'description' => 'TRAIN', 'amount' => 4520]);
    lineFor($this->sam, ['row_number' => 3, 'description' => 'HOTEL'], $first->period);
    Receipt::factory()->attachedTo($first)->create();

    $this->actingAs($this->sam)->get(route('reconciliations.show', $first->reconciliation))
        ->assertInertia(fn ($page) => $page
            ->component('reconciliations/Show')
            ->where('reconciliation.mine', true)
            ->where('reconciliation.lines', 2)
            ->where('reconciliation.can_submit', false)
            ->has('lines', 2)
            ->where('lines.0.description', 'HOTEL')
            ->where('lines.1.description', 'TRAIN')
            ->where('lines.1.amount', 4520)
            ->where('lines.1.receipts', 1));
});

it('offers submit once every line is done', function () {
    $line = lineFor($this->sam, ['status' => TransactionStatus::Done]);

    $this->actingAs($this->sam)->get(route('reconciliations.show', $line->reconciliation))
        ->assertInertia(fn ($page) => $page->where('reconciliation.can_submit', true));
});

it('keeps other cardholders and draft months out', function () {
    $line = lineFor($this->sam);
    $draft = lineFor($this->sam, [], Period::factory()->create());

    $this->actingAs(User::factory()->create())->get(route('reconciliations.show', $line->reconciliation))->assertForbidden();
    $this->actingAs($this->sam)->get(route('reconciliations.show', $draft->reconciliation))->assertForbidden();
});
