<?php

use App\Imports\ImportPreviewer;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Storage;

it('ships a demo statement that exercises matching, problems, duplicates and masking', function () {
    Storage::fake('local');
    $this->seed(DemoSeeder::class);

    $import = importFromCsv(file_get_contents(resource_path('demo/demo-statement.csv')), [
        'column_mapping' => ['date' => 0, 'description' => 1, 'cardholder' => 2, 'card' => 3, 'amount' => 4, 'purpose' => null],
    ]);

    $summary = app(ImportPreviewer::class)->preview($import)->summary([]);
    $rows = collect(app(ImportPreviewer::class)->preview($import)->rows);

    expect($summary['errors'])->toBe(1)
        ->and($summary['duplicates'])->toBe(1)
        ->and($summary['unassigned'])->toBe(1)
        ->and(collect($summary['by_user'])->sum('count'))->toBeGreaterThanOrEqual(8)
        ->and($rows->contains(fn ($row) => ($row->parsed->amount ?? 0) < 0))->toBeTrue()
        ->and($rows->contains(fn ($row) => in_array('**** 0005', $row->parsed->extra, true)))->toBeTrue();
});
