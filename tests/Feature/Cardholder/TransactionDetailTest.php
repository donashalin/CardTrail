<?php

use App\Enums\ReconciliationStatus;
use App\Enums\TransactionStatus;
use App\Models\Comment;
use App\Models\Period;
use App\Models\Receipt;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->sam = User::factory()->create(['name' => 'Sam']);
    $this->admin = User::factory()->admin()->create(['name' => 'Alex', 'is_cardholder' => false]);
});

function saveLine($line, array $fields)
{
    return test()->actingAs(test()->sam)->put(route('transactions.update', $line), [
        'purpose' => null,
        'no_receipt' => false,
        'no_receipt_reason' => null,
        ...$fields,
    ]);
}

it('shows the line with its extra columns, receipts, thread and neighbours', function () {
    $first = lineFor($this->sam, ['row_number' => 2]);
    $line = lineFor($this->sam, ['row_number' => 3, 'description' => 'DEPOT CAFE', 'extra' => ['Reference' => 'AB12']], $first->period);
    $last = lineFor($this->sam, ['row_number' => 4], $first->period);
    lineFor($this->sam, ['row_number' => 9, 'purpose' => 'Fuel for van'], $first->period);
    Receipt::factory()->attachedTo($line)->create(['original_name' => 'cafe.pdf']);
    Comment::factory()->for($line)->for($this->admin)->create(['body' => 'Who attended?']);

    $this->actingAs($this->sam)->get(route('transactions.show', $line))
        ->assertInertia(fn ($page) => $page
            ->component('transactions/Show')
            ->where('line.description', 'DEPOT CAFE')
            ->where('line.extra.Reference', 'AB12')
            ->where('receipts.0.name', 'cafe.pdf')
            ->where('comments.0.body', 'Who attended?')
            ->where('comments.0.author', 'Alex')
            ->where('comments.0.mine', false)
            ->where('editable', true)
            ->where('suggestions', ['Fuel for van'])
            ->where('nav.previous', $first->id)
            ->where('nav.next', $last->id)
            ->where('nav.position', 2)
            ->where('nav.total', 4));
});

it('keeps other cardholders out of a line', function () {
    $line = lineFor($this->sam);
    $jo = User::factory()->create();

    $this->actingAs($jo)->get(route('transactions.show', $line))->assertForbidden();
    $this->actingAs($jo)->put(route('transactions.update', $line), ['purpose' => 'Mine now', 'no_receipt' => false])->assertForbidden();
    expect($line->fresh()->purpose)->toBeNull();
});

it('autosaves the purpose and keeps the line to do until there is a receipt or reason', function () {
    $line = lineFor($this->sam);

    saveLine($line, ['purpose' => 'Client lunch'])->assertRedirect();
    expect($line->fresh())->purpose->toBe('Client lunch')->status->toBe(TransactionStatus::Todo);

    saveLine($line, ['purpose' => 'Client lunch', 'no_receipt' => true, 'no_receipt_reason' => 'Card machine receipt lost'])->assertRedirect();
    expect($line->fresh()->status)->toBe(TransactionStatus::Done);

    expect(Activity::query()->where('event', 'line_updated')->count())->toBe(2);
});

it('does not log a save that changed nothing', function () {
    $line = lineFor($this->sam, ['purpose' => 'Client lunch']);

    saveLine($line, ['purpose' => 'Client lunch']);

    expect(Activity::query()->where('event', 'line_updated')->count())->toBe(0);
});

it('goes to the next line that still needs work on save and next', function () {
    $first = lineFor($this->sam, ['row_number' => 2]);
    lineFor($this->sam, ['row_number' => 3, 'status' => TransactionStatus::Done], $first->period);
    $open = lineFor($this->sam, ['row_number' => 4], $first->period);

    saveLine($first, ['purpose' => 'Parking', 'next' => true])
        ->assertRedirect(route('transactions.show', $open));
});

it('returns to the list when no other line needs work', function () {
    $line = lineFor($this->sam, ['amount' => -500]);

    saveLine($line, ['purpose' => 'Refund', 'next' => true])
        ->assertRedirect(route('reconciliations.show', $line->reconciliation_id))
        ->assertSessionHas('success');
});

it('refuses edits once submitted, except on queried lines', function () {
    $line = lineFor($this->sam, ['status' => TransactionStatus::Done, 'purpose' => 'Hotel']);
    $queried = lineFor($this->sam, ['status' => TransactionStatus::Queried, 'purpose' => 'Taxi'], $line->period);
    $line->reconciliation->update(['status' => ReconciliationStatus::Submitted]);

    saveLine($line, ['purpose' => 'Changed'])->assertForbidden();
    saveLine($queried, ['purpose' => 'Taxi from station to site', 'no_receipt' => true, 'no_receipt_reason' => 'Paid by card, no slip'])->assertRedirect();

    expect($line->fresh()->purpose)->toBe('Hotel')
        ->and($queried->fresh()->status)->toBe(TransactionStatus::Done);
});

it('lets the owner answer a query in words, which clears it', function () {
    $line = lineFor($this->sam, ['status' => TransactionStatus::Queried, 'purpose' => 'Team lunch', 'no_receipt' => true, 'no_receipt_reason' => 'Lost']);

    $this->actingAs($this->sam)->post(route('transactions.comments.store', $line), ['body' => 'Four of us from the depot.'])
        ->assertRedirect();

    expect($line->fresh()->status)->toBe(TransactionStatus::Done)
        ->and($line->comments()->first())->body->toBe('Four of us from the depot.')->user_id->toBe($this->sam->id);
});

it('keeps a query open when the reply leaves the line incomplete', function () {
    $line = lineFor($this->sam, ['status' => TransactionStatus::Queried]);

    $this->actingAs($this->sam)->post(route('transactions.comments.store', $line), ['body' => 'Will find the receipt.']);

    expect($line->fresh()->status)->toBe(TransactionStatus::Queried);
});

it('refuses comments from other cardholders, on closed months, and empty ones', function () {
    $line = lineFor($this->sam);
    $closed = lineFor($this->sam, [], Period::factory()->closed()->create());

    $this->actingAs(User::factory()->create())->post(route('transactions.comments.store', $line), ['body' => 'Hi'])->assertForbidden();
    $this->actingAs($this->sam)->post(route('transactions.comments.store', $closed), ['body' => 'Hi'])->assertForbidden();
    $this->actingAs($this->sam)->post(route('transactions.comments.store', $line), ['body' => ''])->assertSessionHasErrors('body');
});

it('saves and stays on the line when Save is clicked', function () {
    $line = lineFor($this->sam);

    $this->actingAs($this->sam)->from(route('transactions.show', $line))->put(route('transactions.update', $line), [
        'purpose' => 'Parking',
        'no_receipt' => true,
        'no_receipt_reason' => 'Machine broken',
    ])->assertRedirect(route('transactions.show', $line));

    expect($line->fresh()->status)->toBe(TransactionStatus::Done);
});

it('keeps a line queried when the accountant adds to the thread', function () {
    $line = lineFor($this->sam, ['status' => TransactionStatus::Queried, 'purpose' => 'Team lunch', 'no_receipt' => true, 'no_receipt_reason' => 'Lost']);
    $line->reconciliation->update(['status' => ReconciliationStatus::Submitted]);

    $this->actingAs($this->admin)->post(route('transactions.comments.store', $line), ['body' => 'Who attended?'])->assertRedirect();

    expect($line->fresh()->status)->toBe(TransactionStatus::Queried);
});

it('needs a reason before saving a line marked as having no receipt', function (?string $reason) {
    $line = lineFor($this->sam);

    $this->actingAs($this->sam)->from(route('transactions.show', $line))->put(route('transactions.update', $line), [
        'purpose' => 'Parking',
        'no_receipt' => true,
        'no_receipt_reason' => $reason,
        'next' => true,
    ])->assertRedirect(route('transactions.show', $line))
        ->assertSessionHasErrors(['no_receipt_reason' => 'Say why there’s no receipt, or untick “I don’t have a receipt”.']);

    expect($line->fresh())->purpose->toBeNull()->no_receipt->toBeFalse();
})->with([null, '', '   ']);

it('doesn’t ask for a no-receipt reason on a credit, which hides that box', function () {
    $line = lineFor($this->sam, ['amount' => -2499, 'no_receipt' => true]);

    $this->actingAs($this->sam)->put(route('transactions.update', $line), ['purpose' => 'Refund for returned cable', 'no_receipt' => true, 'no_receipt_reason' => ''])
        ->assertSessionHasNoErrors();

    expect($line->fresh()->status)->toBe(TransactionStatus::Done);
});
