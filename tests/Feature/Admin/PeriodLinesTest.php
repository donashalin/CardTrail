<?php

use App\Enums\ReconciliationStatus;
use App\Enums\TransactionStatus;
use App\Events\ReconciliationLinesAdded;
use App\Models\Import;
use App\Models\Period;
use App\Models\Reconciliation;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['is_cardholder' => false]);
    $this->period = Period::factory()->create();
    $this->import = Import::factory()->for($this->period)->confirmed()->create();
    $this->sam = User::factory()->create(['name' => 'Sam']);
    $this->jo = User::factory()->create(['name' => 'Jo']);
});

function line(array $attributes = []): Transaction
{
    return Transaction::factory()->for(test()->import)->create($attributes);
}

function assignLines(array $ids, ?int $userId)
{
    return test()->actingAs(test()->admin)->put(route('admin.periods.lines.assign', test()->period), [
        'transaction_ids' => $ids,
        'user_id' => $userId,
    ]);
}

it('lists unassigned lines by default with cardholders to choose from', function () {
    $loose = line(['description' => 'LOOSE']);
    $samRec = Reconciliation::factory()->for($this->period)->for($this->sam)->create();
    line(['reconciliation_id' => $samRec->id]);
    User::factory()->inactive()->create(['name' => 'Gone']);

    $this->actingAs($this->admin)
        ->get(route('admin.periods.lines', $this->period))
        ->assertInertia(fn ($page) => $page
            ->component('admin/periods/Lines')
            ->where('owner', 'unassigned')
            ->has('lines', 1)
            ->where('lines.0.id', $loose->id)
            ->where('unassigned_count', 1)
            ->where('cardholders', fn ($list) => collect($list)->pluck('name')->all() === ['Jo', 'Sam']));
});

it('filters lines by owner', function () {
    $samRec = Reconciliation::factory()->for($this->period)->for($this->sam)->create();
    $samLine = line(['reconciliation_id' => $samRec->id]);
    line();

    $this->actingAs($this->admin)
        ->get(route('admin.periods.lines', [$this->period, 'owner' => $this->sam->id]))
        ->assertInertia(fn ($page) => $page->has('lines', 1)->where('lines.0.id', $samLine->id)->where('lines.0.owner.name', 'Sam'));

    $this->actingAs($this->admin)
        ->get(route('admin.periods.lines', [$this->period, 'owner' => 'all']))
        ->assertInertia(fn ($page) => $page->has('lines', 2));
});

it('assigns unassigned lines to a cardholder, creating their reconciliation', function () {
    $a = line();
    $b = line();

    assignLines([$a->id, $b->id], $this->sam->id)
        ->assertRedirect()
        ->assertSessionHas('success', 'Moved 2 lines to Sam.');

    $reconciliation = Reconciliation::where('user_id', $this->sam->id)->sole();
    expect($a->fresh()->reconciliation_id)->toBe($reconciliation->id)
        ->and($b->fresh()->reconciliation_id)->toBe($reconciliation->id);
});

it('moves a line between people, resets it, and removes the emptied reconciliation', function () {
    $samRec = Reconciliation::factory()->for($this->period)->for($this->sam)->create();
    $moved = line(['reconciliation_id' => $samRec->id, 'purpose' => 'Hotel', 'status' => TransactionStatus::Done, 'no_receipt' => true, 'no_receipt_reason' => 'Lost']);

    assignLines([$moved->id], $this->jo->id);

    $moved->refresh();
    expect($moved->reconciliation->user_id)->toBe($this->jo->id)
        ->and($moved->purpose)->toBeNull()
        ->and($moved->no_receipt)->toBeFalse()
        ->and($moved->no_receipt_reason)->toBeNull()
        ->and($moved->status)->toBe(TransactionStatus::Todo)
        ->and(Reconciliation::find($samRec->id))->toBeNull();
});

it('can move lines back to unassigned', function () {
    $samRec = Reconciliation::factory()->for($this->period)->for($this->sam)->create();
    $moved = line(['reconciliation_id' => $samRec->id]);

    assignLines([$moved->id], null)->assertSessionHas('success', 'Moved 1 line to Unassigned.');

    expect($moved->fresh()->reconciliation_id)->toBeNull();
});

it('reopens the receiving reconciliation and announces it in a published period', function () {
    Event::fake([ReconciliationLinesAdded::class]);
    $this->period->update(['status' => 'published']);
    $joRec = Reconciliation::factory()->for($this->period)->for($this->jo)->submitted()->create();

    assignLines([line()->id], $this->jo->id);

    expect($joRec->fresh()->status)->toBe(ReconciliationStatus::InProgress);
    Event::assertDispatched(ReconciliationLinesAdded::class, fn ($event) => $event->reconciliation->is($joRec) && $event->count === 1);
});

it('refuses to move approved lines', function () {
    $samRec = Reconciliation::factory()->for($this->period)->for($this->sam)->create();
    $approved = line(['reconciliation_id' => $samRec->id, 'status' => TransactionStatus::Approved]);

    assignLines([$approved->id], $this->jo->id)->assertSessionHas('error');

    expect($approved->fresh()->reconciliation_id)->toBe($samRec->id);
});

it('refuses targets who are inactive or not cardholders', function (string $who) {
    $target = match ($who) {
        'inactive' => User::factory()->inactive()->create(),
        'admin only' => User::factory()->admin()->create(['is_cardholder' => false]),
    };

    assignLines([line()->id], $target->id)->assertSessionHas('error');
})->with(['inactive', 'admin only']);

it('refuses lines from another period', function () {
    $other = Transaction::factory()->create();

    assignLines([$other->id], $this->sam->id)->assertSessionHas('error');

    expect($other->fresh()->reconciliation_id)->toBeNull();
});

it('refuses changes to a closed period', function () {
    $loose = line();
    $this->period->update(['status' => 'closed']);

    assignLines([$loose->id], $this->sam->id)->assertSessionHas('error');
});

it('logs reassignments', function () {
    assignLines([line()->id], $this->sam->id);

    expect(Activity::where('log_name', 'periods')->where('event', 'lines_reassigned')->sole()->causer_id)->toBe($this->admin->id);
});
