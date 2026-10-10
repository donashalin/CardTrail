<?php

use App\Enums\ImportStatus;
use App\Enums\PeriodStatus;
use App\Enums\ReconciliationStatus;
use App\Enums\TransactionStatus;
use App\Models\Import;
use App\Models\Period;
use App\Models\Reconciliation;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\QueryException;

it('defaults a new period to draft with reminders 3 days before and on the day', function () {
    $period = Period::factory()->create();

    expect($period->status)->toBe(PeriodStatus::Draft)
        ->and($period->reminder_offsets)->toBe([3, 0])
        ->and($period->fresh()->status)->toBe(PeriodStatus::Draft)
        ->and($period->fresh()->reminder_offsets)->toBe([3, 0]);
});

it('allows only one period per month', function () {
    Period::factory()->create(['month' => '2026-09-01']);

    expect(fn () => Period::factory()->create(['month' => '2026-09-01']))
        ->toThrow(QueryException::class);
});

it('serialises period dates without a time', function () {
    $period = Period::factory()->create(['month' => '2026-09-01', 'due_date' => '2026-10-15']);

    expect($period->fresh()->toArray())->toMatchArray(['month' => '2026-09-01', 'due_date' => '2026-10-15']);
});

it('links periods, imports, reconciliations and transactions', function () {
    $period = Period::factory()->create();
    $import = Import::factory()->for($period)->confirmed()->create();
    $reconciliation = Reconciliation::factory()->for($period)->create();
    $assigned = Transaction::factory()->for($import)->create(['reconciliation_id' => $reconciliation->id]);
    $unassigned = Transaction::factory()->for($import)->create();

    expect($period->imports->pluck('id')->all())->toBe([$import->id])
        ->and($period->reconciliations->pluck('id')->all())->toBe([$reconciliation->id])
        ->and($period->transactions)->toHaveCount(2)
        ->and($period->unassignedTransactions->pluck('id')->all())->toBe([$unassigned->id])
        ->and($reconciliation->transactions->pluck('id')->all())->toBe([$assigned->id])
        ->and($assigned->period_id)->toBe($period->id)
        ->and($import->transactions)->toHaveCount(2);
});

it('defaults imports to draft and reconciliations to in progress', function () {
    expect(Import::factory()->create()->fresh()->status)->toBe(ImportStatus::Draft)
        ->and(Reconciliation::factory()->create()->fresh()->status)->toBe(ReconciliationStatus::InProgress);
});

it('allows one reconciliation per person per period', function () {
    $period = Period::factory()->create();
    $user = User::factory()->create();
    Reconciliation::factory()->for($period)->for($user)->create();

    expect(fn () => Reconciliation::factory()->for($period)->for($user)->create())
        ->toThrow(QueryException::class);
});

it('stores amounts as integer pence and keeps extra columns', function () {
    $transaction = Transaction::factory()->create([
        'amount' => -1250,
        'extra' => ['Category' => 'Travel'],
        'transacted_on' => '2026-09-03',
    ]);

    $fresh = $transaction->fresh();
    expect($fresh->amount)->toBe(-1250)
        ->and($fresh->extra)->toBe(['Category' => 'Travel'])
        ->and($fresh->toArray()['transacted_on'])->toBe('2026-09-03')
        ->and($fresh->status)->toBe(TransactionStatus::Todo)
        ->and($fresh->no_receipt)->toBeFalse();
});
