<?php

use App\Imports\SpreadsheetReader;
use App\Imports\UnreadableSpreadsheet;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function tempFile(string $contents, string $extension): string
{
    $path = tempnam(sys_get_temp_dir(), 'ct').'.'.$extension;
    file_put_contents($path, $contents);

    return $path;
}

function tempXlsx(callable $build): string
{
    $book = new Spreadsheet;
    $build($book);
    $path = tempnam(sys_get_temp_dir(), 'ct').'.xlsx';
    (new Xlsx($book))->save($path);

    return $path;
}

it('reads CSV rows keyed by their real row numbers, skipping blank lines', function () {
    $path = tempFile("Date,Description,Amount\n03/10/2026,SHOP,12.50\n\n04/10/2026,CAFE,3.20\n", 'csv');

    $rows = (new SpreadsheetReader)->read($path, 'statement.csv');

    expect(array_keys($rows))->toBe([1, 2, 4])
        ->and($rows[1])->toBe(['Date', 'Description', 'Amount'])
        ->and($rows[4][1])->toBe('CAFE');
});

it('keeps CSV values as text so leading zeros survive', function () {
    $path = tempFile("Card,Amount\n0012,-12.50\n", 'csv');

    $rows = (new SpreadsheetReader)->read($path, 'statement.csv');

    expect($rows[2])->toBe(['0012', '-12.50']);
});

it('strips a UTF-8 byte order mark from the first header', function () {
    $path = tempFile("\xEF\xBB\xBFDate,Amount\n03/10/2026,1.00\n", 'csv');

    expect((new SpreadsheetReader)->read($path, 'statement.csv')[1][0])->toBe('Date');
});

it('reads Windows-encoded CSVs with pound signs', function () {
    $path = tempFile(mb_convert_encoding("Amount\n£12.50\n", 'Windows-1252', 'UTF-8'), 'csv');

    expect((new SpreadsheetReader)->read($path, 'statement.csv')[2][0])->toBe('£12.50');
});

it('reads raw values from the first sheet of an XLSX file', function () {
    $path = tempXlsx(function (Spreadsheet $book) {
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([['Date', 'Description', 'Amount'], [46298, 'SHOP', 12.5]]);
        $book->createSheet()->setCellValue('A1', 'ignored second sheet');
    });

    $rows = (new SpreadsheetReader)->read($path, 'statement.xlsx');

    expect($rows)->toHaveCount(2)
        ->and($rows[2][0])->toEqual(46298)
        ->and($rows[2][2])->toEqual(12.5);
});

it('rejects unsupported file types', function () {
    $path = tempFile('hello', 'pdf');

    expect(fn () => (new SpreadsheetReader)->read($path, 'statement.pdf'))
        ->toThrow(UnreadableSpreadsheet::class, 'CSV or Excel');
});

it('rejects a corrupt spreadsheet', function () {
    $path = tempFile('not really a zip file', 'xlsx');

    expect(fn () => (new SpreadsheetReader)->read($path, 'statement.xlsx'))
        ->toThrow(UnreadableSpreadsheet::class);
});

it('rejects files with too many rows', function () {
    $path = tempFile("Date,Amount\n".str_repeat("03/10/2026,1.00\n", SpreadsheetReader::MAX_ROWS + 1), 'csv');

    expect(fn () => (new SpreadsheetReader)->read($path, 'statement.csv'))
        ->toThrow(UnreadableSpreadsheet::class, 'rows');
});

it('splits on commas even when a title line above the headers contains spaces', function () {
    $path = tempFile("Corporate card statement\nDate,Card Member,Amount\n03/10/2026,S CARDHOLDER,1.00\n", 'csv');

    expect((new SpreadsheetReader)->read($path, 'statement.csv')[2])->toBe(['Date', 'Card Member', 'Amount']);
});

it('reads semicolon- and tab-separated exports', function (string $separator) {
    $path = tempFile("Date{$separator}Card Member{$separator}Amount\n03/10/2026{$separator}S CARDHOLDER{$separator}1,00\n", 'csv');

    expect((new SpreadsheetReader)->read($path, 'statement.csv')[2])->toBe(['03/10/2026', 'S CARDHOLDER', '1,00']);
})->with(['semicolon' => ';', 'tab' => "\t"]);

it('rejects an oversized file before loading it into memory', function () {
    $csv = tempFile("Date,Description,Card Member,Account #,Amount,Category,Reference\n".str_repeat("03/10/2026,SOME MERCHANT LTD,S CARDHOLDER,XXXX-XXXXXX-01001,12.50,Travel,REF123\n", 40000), 'csv');
    $script = tempFile(sprintf(
        '<?php require %s; try { (new App\Imports\SpreadsheetReader)->read(%s, "statement.csv"); echo "loaded"; } catch (App\Imports\UnreadableSpreadsheet $e) { echo "rejected"; }',
        var_export(dirname(__DIR__, 3).'/vendor/autoload.php', true),
        var_export($csv, true),
    ), 'php');

    // A separate process with the default 128M limit, so a memory blow-up fails this test instead of the whole run.
    exec(PHP_BINARY.' -d memory_limit=128M '.escapeshellarg($script).' 2>&1', $output, $exitCode);

    expect($exitCode)->toBe(0)->and(implode("\n", $output))->toBe('rejected');
});
