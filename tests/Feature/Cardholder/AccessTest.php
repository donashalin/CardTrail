<?php

use App\Enums\ReconciliationStatus;
use App\Enums\TransactionStatus;
use App\Models\Period;
use App\Models\Receipt;
use App\Models\User;

beforeEach(function () {
    $this->sam = User::factory()->create();
    $this->jo = User::factory()->create();
    $this->admin = User::factory()->admin()->create(['is_cardholder' => false]);
});

it('lets cardholders see only their own lines', function () {
    $line = lineFor($this->sam);

    expect($this->sam->can('view', $line))->toBeTrue()
        ->and($this->jo->can('view', $line))->toBeFalse()
        ->and($this->admin->can('view', $line))->toBeTrue()
        ->and($this->sam->can('view', $line->reconciliation))->toBeTrue()
        ->and($this->jo->can('view', $line->reconciliation))->toBeFalse();
});

it('hides lines from cardholders until the period is published', function () {
    $line = lineFor($this->sam, [], Period::factory()->create());

    expect($this->sam->can('view', $line))->toBeFalse()
        ->and($this->sam->can('view', $line->reconciliation))->toBeFalse()
        ->and($this->admin->can('view', $line))->toBeTrue();
});

it('lets only the owner edit while their reconciliation is in progress', function () {
    $line = lineFor($this->sam);

    expect($this->sam->can('update', $line))->toBeTrue()
        ->and($this->jo->can('update', $line))->toBeFalse()
        ->and($this->admin->can('update', $line))->toBeFalse();
});

it('locks lines once submitted, apart from queried ones', function () {
    $done = lineFor($this->sam, ['status' => TransactionStatus::Done]);
    $queried = lineFor($this->sam, ['status' => TransactionStatus::Queried], $done->period);
    $done->reconciliation->update(['status' => ReconciliationStatus::Submitted]);

    expect($this->sam->can('update', $done->fresh()))->toBeFalse()
        ->and($this->sam->can('update', $queried->fresh()))->toBeTrue();
});

it('never lets anyone edit approved lines or closed periods', function () {
    $approved = lineFor($this->sam, ['status' => TransactionStatus::Approved]);
    $closed = lineFor($this->sam, [], Period::factory()->closed()->create());

    expect($this->sam->can('update', $approved))->toBeFalse()
        ->and($this->sam->can('update', $closed))->toBeFalse()
        ->and($this->sam->can('view', $closed))->toBeTrue()
        ->and($this->sam->can('comment', $closed))->toBeFalse()
        ->and($this->admin->can('comment', $closed))->toBeFalse();
});

it('lets the owner and admins comment on an open line', function () {
    $line = lineFor($this->sam);

    expect($this->sam->can('comment', $line))->toBeTrue()
        ->and($this->admin->can('comment', $line))->toBeTrue()
        ->and($this->jo->can('comment', $line))->toBeFalse();
});

it('keeps receipts private to their owner and admins', function () {
    $receipt = Receipt::factory()->for($this->sam)->create();

    expect($this->sam->can('view', $receipt))->toBeTrue()
        ->and($this->jo->can('view', $receipt))->toBeFalse()
        ->and($this->admin->can('view', $receipt))->toBeTrue();
});

it('lets the owner manage inbox receipts and receipts on lines they can edit', function () {
    $inbox = Receipt::factory()->for($this->sam)->create();
    $line = lineFor($this->sam);
    $attached = Receipt::factory()->attachedTo($line)->create();

    expect($this->sam->can('manage', $inbox))->toBeTrue()
        ->and($this->sam->can('manage', $attached))->toBeTrue()
        ->and($this->jo->can('manage', $inbox))->toBeFalse()
        ->and($this->admin->can('manage', $inbox))->toBeFalse();

    $line->reconciliation->update(['status' => ReconciliationStatus::Submitted]);

    expect($this->sam->can('manage', $attached->fresh()))->toBeFalse();
});

it('only lets the owner submit a reconciliation that is in progress in a published period', function () {
    $line = lineFor($this->sam);
    $reconciliation = $line->reconciliation;

    expect($this->sam->can('submit', $reconciliation))->toBeTrue()
        ->and($this->jo->can('submit', $reconciliation))->toBeFalse()
        ->and($this->admin->can('submit', $reconciliation))->toBeFalse();

    $reconciliation->update(['status' => ReconciliationStatus::Submitted]);
    expect($this->sam->can('submit', $reconciliation->fresh()))->toBeFalse();

    $draft = lineFor($this->sam, [], Period::factory()->create());
    expect($this->sam->can('submit', $draft->reconciliation))->toBeFalse();
});
