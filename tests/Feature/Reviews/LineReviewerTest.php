<?php

use App\Enums\ReconciliationStatus;
use App\Enums\TransactionStatus;
use App\Events\LineQueried;
use App\Events\ReconciliationLinesAdded;
use App\Models\Period;
use App\Models\Transaction;
use App\Models\User;
use App\Periods\TransactionAssigner;
use App\Reviews\CannotReview;
use App\Reviews\LineReviewer;
use Illuminate\Support\Facades\Event;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Event::fake([LineQueried::class]);
    $this->sam = User::factory()->create();
    $this->admin = User::factory()->admin()->create(['is_cardholder' => false]);
    $this->reviewer = app(LineReviewer::class);
});

/** A line on Sam's submitted month. */
function reviewLine(array $attributes = [], ?Period $period = null)
{
    $line = lineFor(test()->sam, ['status' => TransactionStatus::Done, 'purpose' => 'Client lunch', ...$attributes], $period);
    $line->reconciliation->update(['status' => ReconciliationStatus::Submitted, 'submitted_at' => now()]);

    return $line->fresh();
}

it('approves a completed line, and the month once every line is approved', function () {
    $first = reviewLine();
    $second = reviewLine([], $first->period);

    $this->reviewer->approve($first, $this->admin);

    expect($first->fresh())
        ->status->toBe(TransactionStatus::Approved)
        ->approved_by->toBe($this->admin->id)
        ->approved_at->not->toBeNull()
        ->and($first->reconciliation->fresh()->status)->toBe(ReconciliationStatus::Submitted);

    $this->reviewer->approve($second, $this->admin);

    expect($first->reconciliation->fresh())
        ->status->toBe(ReconciliationStatus::Approved)
        ->approved_by->toBe($this->admin->id);
    expect(Activity::query()->where('event', 'line_approved')->count())->toBe(2);
});

it('refuses to approve until the month is submitted', function () {
    $line = reviewLine();
    $line->reconciliation->update(['status' => ReconciliationStatus::InProgress]);

    expect(fn () => $this->reviewer->approve($line, $this->admin))->toThrow(CannotReview::class);
    expect($line->fresh()->status)->toBe(TransactionStatus::Done);
});

it('refuses to approve a line that is not complete, or in a closed month', function () {
    $todo = reviewLine(['status' => TransactionStatus::Todo]);
    $closed = reviewLine([], Period::factory()->closed()->create());

    expect(fn () => $this->reviewer->approve($todo, $this->admin))->toThrow(CannotReview::class)
        ->and(fn () => $this->reviewer->approve($closed, $this->admin))->toThrow(CannotReview::class);
});

it('approves every remaining completed line at once', function () {
    $first = reviewLine();
    reviewLine(['status' => TransactionStatus::Approved], $first->period);
    reviewLine([], $first->period);

    expect($this->reviewer->approveRemaining($first->reconciliation, $this->admin))->toBe(2)
        ->and($first->reconciliation->fresh()->status)->toBe(ReconciliationStatus::Approved);
});

it('queries a line: the line reopens, the month goes back to the cardholder and the question is kept', function () {
    $line = reviewLine();

    $comment = $this->reviewer->query($line, $this->admin, 'Who attended?');

    expect($line->fresh()->status)->toBe(TransactionStatus::Queried)
        ->and($line->reconciliation->fresh()->status)->toBe(ReconciliationStatus::InProgress)
        ->and($comment)->body->toBe('Who attended?')->user_id->toBe($this->admin->id)
        ->and($this->sam->can('update', $line->fresh()))->toBeTrue();
    Event::assertDispatched(LineQueried::class, fn ($event) => $event->line->is($line) && $event->comment->is($comment));
    expect(Activity::query()->where('event', 'line_queried')->exists())->toBeTrue();
});

it('reopens an approved month when one of its lines is queried', function () {
    $line = reviewLine();
    $this->reviewer->approve($line, $this->admin);

    $this->reviewer->query($line->fresh(), $this->admin, 'Is this personal?');

    expect($line->fresh())
        ->status->toBe(TransactionStatus::Queried)
        ->approved_at->toBeNull()
        ->approved_by->toBeNull()
        ->and($line->reconciliation->fresh())
        ->status->toBe(ReconciliationStatus::InProgress)
        ->approved_at->toBeNull();
});

it('refuses to query a line still with the cardholder, or before they submit', function () {
    $queried = reviewLine(['status' => TransactionStatus::Queried]);
    $inProgress = reviewLine();
    $inProgress->reconciliation->update(['status' => ReconciliationStatus::InProgress]);

    expect(fn () => $this->reviewer->query($queried, $this->admin, 'Again?'))->toThrow(CannotReview::class)
        ->and(fn () => $this->reviewer->query($inProgress->fresh(), $this->admin, 'Hmm?'))->toThrow(CannotReview::class);
    Event::assertNotDispatched(LineQueried::class);
});

it('refuses to approve after a late reassignment reopens the month, until it is resubmitted', function () {
    Event::fake([LineQueried::class, ReconciliationLinesAdded::class]);
    $line = reviewLine();
    $late = Transaction::factory()->for($line->import)->create(['reconciliation_id' => null]);

    app(TransactionAssigner::class)->assign($line->period, [$late->id], $this->sam, $this->admin);

    expect($line->reconciliation->fresh()->status)->toBe(ReconciliationStatus::InProgress)
        ->and(fn () => $this->reviewer->approve($line->fresh(), $this->admin))->toThrow(CannotReview::class);
    expect($line->fresh()->status)->toBe(TransactionStatus::Done);
});
