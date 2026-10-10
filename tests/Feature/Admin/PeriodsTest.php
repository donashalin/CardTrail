<?php

use App\Enums\PeriodStatus;
use App\Enums\TransactionStatus;
use App\Models\Import;
use App\Models\Period;
use App\Models\Reconciliation;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['is_cardholder' => false]);
});

function periodPayload(array $overrides = []): array
{
    return array_merge([
        'label' => '',
        'month' => '2026-09',
        'due_date' => '2026-10-15',
        'reminder_offsets' => '3, 0',
    ], $overrides);
}

it('is only for admins', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.periods.index'))->assertForbidden();
});

it('lists periods newest first', function () {
    Period::factory()->create(['month' => '2026-08-01', 'label' => 'August 2026']);
    Period::factory()->create(['month' => '2026-09-01', 'label' => 'September 2026']);

    $this->actingAs($this->admin)
        ->get(route('admin.periods.index'))
        ->assertInertia(fn ($page) => $page
            ->component('admin/periods/Index')
            ->where('periods.0.label', 'September 2026')
            ->where('periods.1.label', 'August 2026'));
});

it('suggests last month and the 15th as defaults for a new period', function () {
    $this->travelTo('2026-10-10');

    $this->actingAs($this->admin)
        ->get(route('admin.periods.create'))
        ->assertInertia(fn ($page) => $page
            ->component('admin/periods/Form')
            ->where('defaults.month', '2026-09')
            ->where('defaults.due_date', '2026-10-15')
            ->where('defaults.reminder_offsets', '3, 0'));
});

it('creates a period, naming it after the month when no label is given', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.periods.store'), periodPayload())
        ->assertSessionHasNoErrors();

    $period = Period::sole();
    expect($period->label)->toBe('September 2026')
        ->and($period->month->format('Y-m-d'))->toBe('2026-09-01')
        ->and($period->due_date->format('Y-m-d'))->toBe('2026-10-15')
        ->and($period->reminder_offsets)->toBe([3, 0])
        ->and($period->status)->toBe(PeriodStatus::Draft);
    $this->assertDatabaseCount('periods', 1);
});

it('redirects to the new period\'s dashboard', function () {
    $response = $this->actingAs($this->admin)->post(route('admin.periods.store'), periodPayload());

    $response->assertRedirect(route('admin.periods.show', Period::sole()));
});

it('refuses a second period for the same month', function () {
    Period::factory()->create(['month' => '2026-09-01']);

    $this->actingAs($this->admin)
        ->post(route('admin.periods.store'), periodPayload())
        ->assertSessionHasErrors('month');
});

it('validates reminder days', function (string $offsets) {
    $this->actingAs($this->admin)
        ->post(route('admin.periods.store'), periodPayload(['reminder_offsets' => $offsets]))
        ->assertSessionHasErrors();
})->with(['abc', '3, 3', '-1', '61', '1,2,3,4,5,6']);

it('accepts no reminders at all', function () {
    $this->actingAs($this->admin)->post(route('admin.periods.store'), periodPayload(['reminder_offsets' => '']));

    expect(Period::sole()->reminder_offsets)->toBe([]);
});

it('updates a draft period', function () {
    $period = Period::factory()->create(['month' => '2026-09-01']);

    $this->actingAs($this->admin)
        ->put(route('admin.periods.update', $period), periodPayload(['label' => 'Sept', 'month' => '2026-08', 'due_date' => '2026-10-20']))
        ->assertRedirect(route('admin.periods.show', $period));

    $period->refresh();
    expect($period->label)->toBe('Sept')
        ->and($period->month->format('Y-m'))->toBe('2026-08')
        ->and($period->due_date->format('Y-m-d'))->toBe('2026-10-20');
});

it('locks the month once a period is published', function () {
    $period = Period::factory()->published()->create(['month' => '2026-09-01']);

    $this->actingAs($this->admin)
        ->put(route('admin.periods.update', $period), periodPayload(['month' => '2026-08']))
        ->assertSessionHasErrors('month');

    expect($period->fresh()->month->format('Y-m'))->toBe('2026-09');
});

it('does not allow editing a closed period', function () {
    $period = Period::factory()->closed()->create(['month' => '2026-09-01']);

    $this->actingAs($this->admin)->get(route('admin.periods.edit', $period))->assertForbidden();
    $this->actingAs($this->admin)->put(route('admin.periods.update', $period), periodPayload())->assertForbidden();
});

it('shows a dashboard with per-person progress, unassigned lines and totals', function () {
    $this->travelTo('2026-10-20');
    $period = Period::factory()->published()->create(['month' => '2026-09-01', 'due_date' => '2026-10-15']);
    $import = Import::factory()->for($period)->confirmed()->create(['original_filename' => 'sept.csv', 'row_count' => 4]);

    $sam = User::factory()->create(['name' => 'Sam']);
    $jo = User::factory()->inactive()->create(['name' => 'Jo']);
    $samRec = Reconciliation::factory()->for($period)->for($sam)->create();
    $joRec = Reconciliation::factory()->for($period)->for($jo)->submitted()->create();

    Transaction::factory()->for($import)->create(['reconciliation_id' => $samRec->id, 'amount' => 1000, 'status' => TransactionStatus::Done]);
    Transaction::factory()->for($import)->create(['reconciliation_id' => $samRec->id, 'amount' => 500, 'status' => TransactionStatus::Queried]);
    Transaction::factory()->for($import)->create(['reconciliation_id' => $joRec->id, 'amount' => 250]);
    Transaction::factory()->for($import)->create(['amount' => -100]);

    $this->actingAs($this->admin)
        ->get(route('admin.periods.show', $period))
        ->assertInertia(fn ($page) => $page
            ->component('admin/periods/Show')
            ->where('period.due_date', '2026-10-15')
            ->where('reconciliations.0.user.name', 'Jo')
            ->where('reconciliations.0.user.active', false)
            ->where('reconciliations.0.overdue', false)
            ->where('reconciliations.1.user.name', 'Sam')
            ->where('reconciliations.1.lines', 2)
            ->where('reconciliations.1.done', 1)
            ->where('reconciliations.1.queried', 1)
            ->where('reconciliations.1.overdue', true)
            ->where('imports.0.original_filename', 'sept.csv')
            ->where('unassigned_count', 1)
            ->where('totals.lines', 4)
            ->where('totals.amount', 1650));
});
