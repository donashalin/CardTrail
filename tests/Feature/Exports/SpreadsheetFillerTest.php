<?php

use App\Exports\SpreadsheetFiller;
use App\Imports\SpreadsheetReader;
use App\Models\Import;
use App\Models\Receipt;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

beforeEach(fn () => Storage::fake('local'));

/** Read filled bytes back with our own reader (CSV cells stay text). Rows keyed by row number, cells 0-based. */
function readFilled(string $bytes, string $extension): array
{
    $path = tempnam(sys_get_temp_dir(), 'filled').'.'.$extension;
    file_put_contents($path, $bytes);

    try {
        return (new SpreadsheetReader)->read($path, "filled.{$extension}");
    } finally {
        unlink($path);
    }
}

function threeRowCsvImport(): Import
{
    return importFromCsv(statementCsv([
        ['03/10/2026', 'DEPOT CAFE', 'S CARDHOLDER', '1001', '12.50'],
        ['04/10/2026', 'TRAIN', 'S CARDHOLDER', '1001', '45.20'],
        ['05/10/2026', 'HOTEL', 'J MANAGER', '1002', '120.00'],
    ]));
}

it('appends a Purpose column to a CSV and fills only the lines given', function () {
    $import = threeRowCsvImport();
    $cafe = Transaction::factory()->for($import)->create(['row_number' => 2, 'purpose' => 'Supplier meeting']);
    $train = Transaction::factory()->for($import)->create(['row_number' => 3, 'purpose' => 'Site visit', 'no_receipt' => true, 'no_receipt_reason' => 'Ticket machine broken']);
    Transaction::factory()->for($import)->create(['row_number' => 4, 'purpose' => 'Not in this export']);

    $rows = readFilled(app(SpreadsheetFiller::class)->fill($import, [$cafe, $train]), 'csv');

    expect($rows[1][5])->toBe('Purpose')
        ->and($rows[2][5])->toBe('Supplier meeting')
        ->and($rows[3][5])->toBe('Site visit (No receipt: Ticket machine broken)')
        ->and($rows[4][5] ?? null)->toBeNull()
        ->and($rows[2][1])->toBe('DEPOT CAFE')
        ->and($rows[4][4])->toBe('120.00')
        ->and($rows[3][3])->toBe('1001');
});

it('keeps the CSV’s separator and adds a byte-order mark so Excel shows £ correctly', function () {
    $import = importFromCsv("Date;Description;Amount\n03/10/2026;CAFÉ;£12,50\n", [
        'column_mapping' => ['date' => 0, 'description' => 1, 'amount' => 2],
    ]);
    $line = Transaction::factory()->for($import)->create(['row_number' => 2, 'purpose' => 'Coffee for visitors']);

    $bytes = app(SpreadsheetFiller::class)->fill($import, [$line]);

    expect($bytes)->toStartWith("\xEF\xBB\xBF")
        ->toContain('Date;Description;Amount;Purpose')
        ->toContain('CAFÉ;£12,50;Coffee for visitors');
});

it('defuses purposes that a spreadsheet app would run as a formula', function () {
    expect(SpreadsheetFiller::defuse('=HYPERLINK("http://example.test")'))->toBe('\'=HYPERLINK("http://example.test")')
        ->and(SpreadsheetFiller::defuse('+44 call'))->toBe("'+44 call")
        ->and(SpreadsheetFiller::defuse('@SUM(A1)'))->toBe("'@SUM(A1)")
        ->and(SpreadsheetFiller::defuse('-£5 refund note'))->toBe('-£5 refund note')
        ->and(SpreadsheetFiller::defuse('Client lunch'))->toBe('Client lunch');
});

it('leaves out the no-receipt note when a receipt was attached anyway', function () {
    $line = lineFor(User::factory()->create(), ['purpose' => 'Parking', 'no_receipt' => true, 'no_receipt_reason' => 'Lost']);
    Receipt::factory()->attachedTo($line)->create();

    expect(SpreadsheetFiller::purposeText($line))->toBe('Parking')
        ->and(SpreadsheetFiller::purposeText(new Transaction(['purpose' => '  '])))->toBeNull();
});

it('writes into a workbook’s mapped purpose column as plain text, leaving everything else alone', function () {
    $book = new Spreadsheet;
    $book->getActiveSheet()->fromArray([
        ['Amex statement'],
        ['Date', 'Description', 'Amount', 'Notes'],
        ['03/10/2026', 'DEPOT CAFE', 12.5, null],
        ['04/10/2026', 'FORMULA SHOP', 9.99, null],
    ]);
    $source = tempnam(sys_get_temp_dir(), 'book').'.xlsx';
    (new Xlsx($book))->save($source);

    $import = Import::factory()->create([
        'original_filename' => 'statement.xlsx',
        'stored_path' => 'imports/statement.xlsx',
        'header_row' => 2,
        'column_mapping' => ['date' => 0, 'description' => 1, 'amount' => 2, 'purpose' => 3],
    ]);
    Storage::disk('local')->put($import->stored_path, file_get_contents($source));
    $cafe = Transaction::factory()->for($import)->create(['row_number' => 3, 'purpose' => 'Supplier meeting']);
    $shop = Transaction::factory()->for($import)->create(['row_number' => 4, 'purpose' => '=1+1']);

    $out = tempnam(sys_get_temp_dir(), 'out').'.xlsx';
    file_put_contents($out, app(SpreadsheetFiller::class)->fill($import, [$cafe, $shop]));
    $sheet = IOFactory::load($out)->getSheet(0);

    expect($sheet->getCell('D3')->getValue())->toBe('Supplier meeting')
        ->and($sheet->getCell('D4')->getValue())->toBe('=1+1')
        ->and($sheet->getCell('D4')->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($sheet->getCell('A1')->getValue())->toBe('Amex statement')
        ->and($sheet->getCell('C3')->getValue())->toBe(12.5)
        ->and($sheet->getCell('E2')->getValue())->toBeNull();
});
