<?php

use App\Imports\ImportPreviewer;
use App\Models\CardIdentifier;
use App\Models\Import;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    $this->sam = User::factory()->create(['name' => 'Sam Cardholder']);
    CardIdentifier::factory()->for($this->sam)->create(['last4' => '1001']);
    $this->jo = User::factory()->create(['name' => 'Jo Manager']);
    CardIdentifier::factory()->for($this->jo)->create(['last4' => '1002']);
});

function previewOf(Import $import, array $fixes = [])
{
    return app(ImportPreviewer::class)->preview($import, $fixes);
}

it('parses rows and assigns them to cardholders', function () {
    $import = importFromCsv(statementCsv([
        ['03/09/2026', 'TRAINLINE', 'S CARDHOLDER', 'XXXX-XXXXXX-01001', '45.20'],
        ['04/09/2026', 'PREMIER INN', 'J MANAGER', 'XXXX-XXXXXX-01002', '89.00'],
    ]));

    $preview = previewOf($import);

    expect($preview->rows)->toHaveCount(2)
        ->and($preview->rows[0]->parsed->rowNumber)->toBe(2)
        ->and($preview->rows[0]->userId)->toBe($this->sam->id)
        ->and($preview->rows[1]->userId)->toBe($this->jo->id)
        ->and($preview->errorRowNumbers())->toBe([]);
});

it('flags rows it cannot read and leaves them unassigned', function () {
    $import = importFromCsv(statementCsv([
        ['31/02/2026', 'SHOP', 'S CARDHOLDER', '1001', '1.00'],
    ]));

    $row = previewOf($import)->rows[0];

    expect($row->parsed->isValid())->toBeFalse()
        ->and($row->userId)->toBeNull()
        ->and(previewOf($import)->errorRowNumbers())->toBe([2]);
});

it('applies fixes to rows', function () {
    $import = importFromCsv(statementCsv([
        ['31/02/2026', 'SHOP', 'S CARDHOLDER', '1001', '1.00'],
    ]));

    $row = previewOf($import, [2 => ['date' => '28/02/2026']])->rows[0];

    expect($row->parsed->isValid())->toBeTrue()->and($row->userId)->toBe($this->sam->id);
});

it('flags a repeated row within the same file', function () {
    $import = importFromCsv(statementCsv([
        ['03/09/2026', 'TRAINLINE', 'S CARDHOLDER', '1001', '45.20'],
        ['03/09/2026', 'Trainline', 'S CARDHOLDER', '1001', '45.20'],
    ]));

    $rows = previewOf($import)->rows;

    expect($rows[0]->duplicate)->toBeFalse()->and($rows[1]->duplicate)->toBeTrue();
});

it('flags rows already imported into this period from another file', function () {
    $import = importFromCsv(statementCsv([
        ['03/09/2026', 'TRAINLINE', 'S CARDHOLDER', '1001', '45.20'],
        ['05/09/2026', 'NEW SHOP', 'S CARDHOLDER', '1001', '10.00'],
    ]));
    $earlier = Import::factory()->for($import->period)->confirmed()->create();
    Transaction::factory()->for($earlier)->create([
        'transacted_on' => '2026-09-03', 'amount' => 4520, 'description' => 'TRAINLINE', 'card_ref' => '1001',
    ]);

    $rows = previewOf($import)->rows;

    expect($rows[0]->duplicate)->toBeTrue()->and($rows[1]->duplicate)->toBeFalse();
});

it('ignores lines from other periods when checking duplicates', function () {
    $import = importFromCsv(statementCsv([
        ['03/09/2026', 'TRAINLINE', 'S CARDHOLDER', '1001', '45.20'],
    ]));
    Transaction::factory()->create([
        'transacted_on' => '2026-09-03', 'amount' => 4520, 'description' => 'TRAINLINE', 'card_ref' => '1001',
    ]);

    expect(previewOf($import)->rows[0]->duplicate)->toBeFalse();
});

it('respects a header row further down the file', function () {
    $import = importFromCsv(
        "Corporate card statement\n".statementCsv([['03/09/2026', 'SHOP', 'S CARDHOLDER', '1001', '1.00']]),
        ['header_row' => 2],
    );

    $rows = previewOf($import)->rows;

    expect($rows)->toHaveCount(1)->and($rows[0]->parsed->rowNumber)->toBe(3);
});

it('summarises the preview', function () {
    $import = importFromCsv(statementCsv([
        ['03/09/2026', 'A', 'S CARDHOLDER', '1001', '1.00'],
        ['03/09/2026', 'B', 'J MANAGER', '1002', '2.00'],
        ['03/09/2026', 'C', 'J MANAGER', '1002', '3.00'],
        ['03/09/2026', 'D', 'NOBODY', '9999', '4.00'],
        ['bad', 'E', 'S CARDHOLDER', '1001', '5.00'],
        ['03/09/2026', 'A', 'S CARDHOLDER', '1001', '1.00'],
    ]));
    $names = [$this->sam->id => 'Sam Cardholder', $this->jo->id => 'Jo Manager'];

    expect(previewOf($import)->summary($names))->toBe([
        'total' => 6,
        'errors' => 1,
        'duplicates' => 1,
        'unassigned' => 1,
        'by_user' => [
            ['user_id' => $this->jo->id, 'name' => 'Jo Manager', 'count' => 2],
            ['user_id' => $this->sam->id, 'name' => 'Sam Cardholder', 'count' => 1],
        ],
    ]);
});

it('converts a row for the preview screen', function () {
    $import = importFromCsv(statementCsv([
        ['03/09/2026', 'TRAINLINE', 'S CARDHOLDER', 'XXXX-XXXXXX-01001', '45.20'],
    ]));

    expect(previewOf($import)->rows[0]->toArray([$this->sam->id => 'Sam Cardholder']))->toBe([
        'row_number' => 2,
        'date' => '2026-09-03',
        'raw_date' => '03/09/2026',
        'description' => 'TRAINLINE',
        'amount' => 4520,
        'raw_amount' => '45.20',
        'card_ref' => '1001',
        'user_id' => $this->sam->id,
        'user_name' => 'Sam Cardholder',
        'duplicate' => false,
        'errors' => [],
        'extra' => [],
    ]);
});
