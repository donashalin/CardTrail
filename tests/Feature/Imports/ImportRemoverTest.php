<?php

use App\Imports\ImportNotReady;
use App\Imports\ImportRemover;
use App\Models\Import;
use App\Models\Period;
use App\Models\Reconciliation;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->admin = User::factory()->admin()->create();
});

it('removes an unfinished import and its file', function () {
    $import = importFromCsv(statementCsv([['03/09/2026', 'SHOP', 'X', '1001', '1.00']]));

    app(ImportRemover::class)->remove($import, $this->admin);

    expect(Import::count())->toBe(0);
    Storage::disk('local')->assertMissing($import->stored_path);
});

it('removes a confirmed import from a draft period with its lines and empty reconciliations', function () {
    $period = Period::factory()->create();
    $import = Import::factory()->for($period)->confirmed()->create();
    $other = Import::factory()->for($period)->confirmed()->create();
    $emptied = Reconciliation::factory()->for($period)->create();
    $kept = Reconciliation::factory()->for($period)->create();
    Transaction::factory()->for($import)->create(['reconciliation_id' => $emptied->id]);
    Transaction::factory()->for($import)->create(['reconciliation_id' => $kept->id]);
    Transaction::factory()->for($other)->create(['reconciliation_id' => $kept->id]);

    app(ImportRemover::class)->remove($import, $this->admin);

    expect(Transaction::count())->toBe(1)
        ->and(Reconciliation::pluck('id')->all())->toBe([$kept->id]);
});

it('refuses to remove a confirmed import once the period is published', function () {
    $import = Import::factory()->for(Period::factory()->published())->confirmed()->create();

    expect(fn () => app(ImportRemover::class)->remove($import, $this->admin))->toThrow(ImportNotReady::class);
    expect(Import::count())->toBe(1);
});
