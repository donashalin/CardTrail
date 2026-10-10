<?php

use App\Enums\ReconciliationStatus;
use App\Enums\TransactionStatus;
use App\Models\Comment;
use App\Models\Receipt;
use App\Models\User;

beforeEach(function () {
    $this->sam = User::factory()->create(['name' => 'Sam']);
    $this->admin = User::factory()->admin()->create(['name' => 'Alex', 'is_cardholder' => false]);
});

function submittedMonth(array ...$lines): array
{
    $made = [];
    $period = null;

    foreach ($lines as $attributes) {
        $line = lineFor(test()->sam, ['status' => TransactionStatus::Done, 'purpose' => 'Client lunch', ...$attributes], $period);
        $period = $line->period;
        $made[] = $line;
    }

    $made[0]->reconciliation->update(['status' => ReconciliationStatus::Submitted, 'submitted_at' => now()]);

    return array_map(fn ($line) => $line->fresh(), $made);
}

it('shows the month with the first line waiting for review selected', function () {
    [$approved, $waiting] = submittedMonth(['row_number' => 2, 'status' => TransactionStatus::Approved], ['row_number' => 3, 'description' => 'DEPOT CAFE']);
    Receipt::factory()->attachedTo($waiting)->create(['original_name' => 'cafe.pdf']);
    Comment::factory()->for($waiting)->for($this->sam)->create(['body' => 'Team breakfast']);

    $this->actingAs($this->admin)->get(route('admin.reconciliations.review', $waiting->reconciliation))
        ->assertInertia(fn ($page) => $page
            ->component('admin/review/Show')
            ->where('reconciliation.owner', 'Sam')
            ->where('reconciliation.approvable', 1)
            ->has('lines', 2)
            ->where('selected.id', $waiting->id)
            ->where('selected.receipts.0.name', 'cafe.pdf')
            ->where('selected.comments.0.body', 'Team breakfast')
            ->where('selected.can_approve', true)
            ->where('selected.can_query', true));
});

it('selects the line asked for', function () {
    [$first, $second] = submittedMonth([], []);

    $this->actingAs($this->admin)->get(route('admin.reconciliations.review', [$first->reconciliation, 'line' => $second->id]))
        ->assertInertia(fn ($page) => $page->where('selected.id', $second->id));
});

it('keeps the review screen to admins', function () {
    [$line] = submittedMonth([]);

    $this->actingAs($this->sam)->get(route('admin.reconciliations.review', $line->reconciliation))->assertForbidden();
});

it('approves a line and moves on to the next one waiting', function () {
    [$first, $second] = submittedMonth(['row_number' => 2], ['row_number' => 3]);

    $this->actingAs($this->admin)->post(route('admin.lines.approve', $first))
        ->assertRedirect(route('admin.reconciliations.review', [$first->reconciliation, 'line' => $second->id]))
        ->assertSessionHas('success');

    expect($first->fresh()->status)->toBe(TransactionStatus::Approved);
});

it('queries a line with a question', function () {
    [$line] = submittedMonth([]);

    $this->actingAs($this->admin)->post(route('admin.lines.query', $line), ['body' => ''])->assertSessionHasErrors('body');
    $this->actingAs($this->admin)->post(route('admin.lines.query', $line), ['body' => 'Receipt is for a different amount?'])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($line->fresh()->status)->toBe(TransactionStatus::Queried)
        ->and($line->comments()->sole()->body)->toBe('Receipt is for a different amount?');
});

it('approves all remaining lines', function () {
    [$line] = submittedMonth([], [], []);

    $this->actingAs($this->admin)->post(route('admin.reconciliations.approve', $line->reconciliation))
        ->assertSessionHas('success', 'Approved 3 lines. Sam’s month is approved.');

    expect($line->reconciliation->fresh()->status)->toBe(ReconciliationStatus::Approved);
});

it('explains why an action is refused', function () {
    [$line] = submittedMonth([]);
    $line->reconciliation->update(['status' => ReconciliationStatus::InProgress]);

    $this->actingAs($this->admin)->post(route('admin.lines.approve', $line))
        ->assertSessionHas('error', 'Lines can be approved once the cardholder has submitted.');
});
