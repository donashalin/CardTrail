<?php

use App\Enums\ImportStatus;
use App\Enums\ReconciliationStatus;
use App\Events\ReconciliationLinesAdded;
use App\Imports\CommitResult;
use App\Imports\ImportCommitter;
use App\Imports\ImportNotReady;
use App\Models\CardIdentifier;
use App\Models\Import;
use App\Models\Period;
use App\Models\Reconciliation;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('local');

    $this->admin = User::factory()->admin()->create(['is_cardholder' => false]);
    $this->sam = User::factory()->create(['name' => 'Sam Cardholder']);
    CardIdentifier::factory()->for($this->sam)->create(['last4' => '1001']);
});

function commitImport(Import $import, ...$args)
{
    return app(ImportCommitter::class)->commit($import, test()->admin, ...$args);
}

it('creates transactions and a reconciliation per cardholder', function () {
    $import = importFromCsv(statementCsv([
        ['03/09/2026', 'TRAINLINE', 'S CARDHOLDER', 'XXXX-XXXXXX-01001', '45.20'],
        ['04/09/2026', 'MYSTERY', 'NOBODY', '9999', '-5.00'],
    ]));

    $result = commitImport($import);

    expect($result->created)->toBe(2)
        ->and($result->unassigned)->toBe(1)
        ->and($import->fresh()->status)->toBe(ImportStatus::Confirmed)
        ->and($import->fresh()->row_count)->toBe(2);

    $reconciliation = Reconciliation::sole();
    expect($reconciliation->user_id)->toBe($this->sam->id)
        ->and($reconciliation->transactions)->toHaveCount(1);

    $line = $reconciliation->transactions->first();
    expect($line->row_number)->toBe(2)
        ->and($line->transacted_on->format('Y-m-d'))->toBe('2026-09-03')
        ->and($line->amount)->toBe(4520)
        ->and($line->card_ref)->toBe('1001')
        ->and(Transaction::whereNull('reconciliation_id')->sole()->amount)->toBe(-500);
});

it('never stores a full card number', function () {
    $import = importFromCsv(
        statementCsv([['03/09/2026', 'REFUND 378282246310005', 'S CARDHOLDER', '1001', '1.00', '4111 1111 1111 1111']], 'Date,Description,Card Member,Account #,Amount,Reference'),
    );

    commitImport($import);

    $line = Transaction::sole();
    expect($line->description)->toBe('REFUND **** 0005')
        ->and($line->extra)->toBe(['Reference' => '**** 1111']);
});

it('refuses to confirm while rows still have errors', function () {
    $import = importFromCsv(statementCsv([
        ['31/02/2026', 'SHOP', 'S CARDHOLDER', '1001', '1.00'],
        ['03/09/2026', 'SHOP', 'S CARDHOLDER', '1001', 'abc'],
    ]));

    expect(fn () => commitImport($import))->toThrow(ImportNotReady::class, 'Rows 2, 3');
    expect(Transaction::count())->toBe(0)->and($import->fresh()->status)->toBe(ImportStatus::Draft);
});

it('confirms once bad rows are fixed or skipped', function () {
    $import = importFromCsv(statementCsv([
        ['31/02/2026', 'SHOP', 'S CARDHOLDER', '1001', '1.00'],
        ['03/09/2026', 'SHOP', 'S CARDHOLDER', '1001', 'abc'],
    ]));

    $result = commitImport($import, [3], [], [2 => ['date' => '28/02/2026']]);

    expect($result->created)->toBe(1)
        ->and(Transaction::sole()->transacted_on->format('Y-m-d'))->toBe('2026-02-28');
});

it('skips duplicates unless the admin includes them', function () {
    $csv = statementCsv([
        ['03/09/2026', 'TRAINLINE', 'S CARDHOLDER', '1001', '45.20'],
        ['03/09/2026', 'TRAINLINE', 'S CARDHOLDER', '1001', '45.20'],
    ]);

    $first = commitImport(importFromCsv($csv));
    expect($first->created)->toBe(1)->and($first->skippedDuplicates)->toBe(1);

    Transaction::query()->delete();
    Import::query()->delete();

    $second = commitImport(importFromCsv($csv), [], [3]);
    expect($second->created)->toBe(2);
});

it('does not double lines when the same file is uploaded twice', function () {
    $csv = statementCsv([['03/09/2026', 'TRAINLINE', 'S CARDHOLDER', '1001', '45.20']]);
    $first = importFromCsv($csv);
    commitImport($first);

    $again = commitImport(importFromCsv($csv, ['period_id' => $first->period_id]));

    expect($again->created)->toBe(0)
        ->and($again->skippedDuplicates)->toBe(1)
        ->and(Transaction::count())->toBe(1);
});

it('refuses to confirm twice or into a closed period', function () {
    $import = importFromCsv(statementCsv([['03/09/2026', 'SHOP', 'S CARDHOLDER', '1001', '1.00']]));
    commitImport($import);

    expect(fn () => commitImport($import->fresh()))->toThrow(ImportNotReady::class, 'already');

    $closed = importFromCsv(
        statementCsv([['03/09/2026', 'SHOP', 'S CARDHOLDER', '1001', '1.00']]),
        ['period_id' => Period::factory()->closed()->create()->id],
    );
    expect(fn () => commitImport($closed))->toThrow(ImportNotReady::class, 'closed');
});

it('reopens a submitted reconciliation and announces late lines in a published period', function () {
    Event::fake([ReconciliationLinesAdded::class]);
    $period = Period::factory()->published()->create();
    $reconciliation = Reconciliation::factory()->for($period)->for($this->sam)->submitted()->create();

    commitImport(importFromCsv(statementCsv([['03/09/2026', 'LATE CHARGE', 'S CARDHOLDER', '1001', '9.99']]), ['period_id' => $period->id]));

    expect($reconciliation->fresh()->status)->toBe(ReconciliationStatus::InProgress);
    Event::assertDispatched(ReconciliationLinesAdded::class, fn ($event) => $event->reconciliation->is($reconciliation) && $event->count === 1);
});

it('reopens an approved reconciliation and clears the approval', function () {
    $period = Period::factory()->published()->create();
    $reconciliation = Reconciliation::factory()->for($period)->for($this->sam)->approved()->create(['approved_by' => $this->admin->id]);

    commitImport(importFromCsv(statementCsv([['03/09/2026', 'LATE CHARGE', 'S CARDHOLDER', '1001', '9.99']]), ['period_id' => $period->id]));

    $fresh = $reconciliation->fresh();
    expect($fresh->status)->toBe(ReconciliationStatus::InProgress)
        ->and($fresh->approved_at)->toBeNull()
        ->and($fresh->approved_by)->toBeNull();
});

it('announces nothing while the period is still a draft', function () {
    Event::fake([ReconciliationLinesAdded::class]);

    commitImport(importFromCsv(statementCsv([['03/09/2026', 'SHOP', 'S CARDHOLDER', '1001', '1.00']])));

    Event::assertNotDispatched(ReconciliationLinesAdded::class);
});

it('logs the import', function () {
    $import = importFromCsv(statementCsv([['03/09/2026', 'SHOP', 'S CARDHOLDER', '1001', '1.00']]));

    commitImport($import);

    $activity = Activity::where('log_name', 'periods')->where('event', 'import_confirmed')->sole();
    expect($activity->causer_id)->toBe($this->admin->id)
        ->and($activity->subject_id)->toBe($import->period_id);
});

it('describes the result', function () {
    expect((new CommitResult(12, 2, 1))->message())->toBe('Imported 12 lines (2 unassigned, 1 duplicate skipped).')
        ->and((new CommitResult(1, 0, 0))->message())->toBe('Imported 1 line.');
});
