<?php

use App\Enums\ReconciliationStatus;
use App\Enums\TransactionStatus;
use App\Events\ReconciliationSubmitted;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Event::fake([ReconciliationSubmitted::class]);
    $this->sam = User::factory()->create();
});

it('submits a month once every line is done', function () {
    $line = lineFor($this->sam, ['status' => TransactionStatus::Done]);
    lineFor($this->sam, ['status' => TransactionStatus::Approved], $line->period);
    $reconciliation = $line->reconciliation;

    $this->actingAs($this->sam)->post(route('reconciliations.submit', $reconciliation))
        ->assertRedirect(route('reconciliations.show', $reconciliation))
        ->assertSessionHas('success');

    expect($reconciliation->fresh())
        ->status->toBe(ReconciliationStatus::Submitted)
        ->submitted_at->not->toBeNull();
    Event::assertDispatched(ReconciliationSubmitted::class, fn ($event) => $event->reconciliation->is($reconciliation) && ! $event->resubmitted);
    expect(Activity::query()->where('event', 'submitted')->exists())->toBeTrue();
});

it('refuses to submit while a line is still to do or queried', function (TransactionStatus $status) {
    $line = lineFor($this->sam, ['status' => TransactionStatus::Done]);
    lineFor($this->sam, ['status' => $status], $line->period);

    $this->actingAs($this->sam)->post(route('reconciliations.submit', $line->reconciliation))
        ->assertSessionHas('error');

    expect($line->reconciliation->fresh()->status)->toBe(ReconciliationStatus::InProgress);
    Event::assertNotDispatched(ReconciliationSubmitted::class);
})->with([TransactionStatus::Todo, TransactionStatus::Queried]);

it('marks a second submission as a resubmission', function () {
    $line = lineFor($this->sam, ['status' => TransactionStatus::Done]);
    $line->reconciliation->update(['submitted_at' => now()->subDay()]);

    $this->actingAs($this->sam)->post(route('reconciliations.submit', $line->reconciliation));

    Event::assertDispatched(ReconciliationSubmitted::class, fn ($event) => $event->resubmitted);
    expect(Activity::query()->where('event', 'resubmitted')->exists())->toBeTrue();
});

it('explains rather than errors when a month is already submitted', function () {
    $line = lineFor($this->sam, ['status' => TransactionStatus::Done]);
    $line->reconciliation->update(['status' => ReconciliationStatus::Submitted]);

    $this->actingAs($this->sam)->post(route('reconciliations.submit', $line->reconciliation))
        ->assertSessionHas('error');
});

it('only lets the owner submit', function () {
    $line = lineFor($this->sam, ['status' => TransactionStatus::Done]);

    $this->actingAs(User::factory()->create())->post(route('reconciliations.submit', $line->reconciliation))->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->post(route('reconciliations.submit', $line->reconciliation))->assertForbidden();

    expect($line->reconciliation->fresh()->status)->toBe(ReconciliationStatus::InProgress);
});
