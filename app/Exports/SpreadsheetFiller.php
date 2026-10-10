<?php

namespace App\Exports;

use App\Imports\ColumnMap;
use App\Imports\SpreadsheetReader;
use App\Models\Import;
use App\Models\Transaction;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Writes purposes into a copy of an import's original file. Rows are never added, removed or reordered. */
final class SpreadsheetFiller
{
    public function __construct(private SpreadsheetReader $reader) {}

    /**
     * @param  iterable<Transaction>  $lines  lines from this import whose purposes should be written in
     * @return string the filled file, in the same format as the original
     */
    public function fill(Import $import, iterable $lines): string
    {
        $path = Storage::disk('local')->path($import->stored_path);
        $extension = strtolower(pathinfo($import->original_filename, PATHINFO_EXTENSION));
        $csv = in_array($extension, ['csv', 'txt'], true);

        $book = $this->reader->workbook($path, $import->original_filename);
        $sheet = $book->getSheet(0);
        $map = ColumnMap::fromArray($import->column_mapping);
        $column = $map->purpose !== null ? $map->purpose + 1 : $this->appendPurposeColumn($sheet, $import->header_row);

        foreach ($lines as $line) {
            $text = self::purposeText($line);

            if ($text !== null) {
                $sheet->setCellValueExplicit([$column, $line->row_number], $csv ? self::defuse($text) : $text, DataType::TYPE_STRING);
            }
        }

        $writer = match (true) {
            $csv => (new Csv($book))->setDelimiter(SpreadsheetReader::delimiter($path))->setEnclosureRequired(false)->setUseBOM(true),
            $extension === 'xls' => new Xls($book),
            default => new Xlsx($book),
        };

        $temp = tempnam(sys_get_temp_dir(), 'fill');

        try {
            $writer->save($temp);

            return (string) file_get_contents($temp);
        } finally {
            @unlink($temp);
            $book->disconnectWorksheets();
        }
    }

    /** "Client lunch", or "Parking (No receipt: machine broken)" when the line has no receipt. Null without a purpose. */
    public static function purposeText(Transaction $line): ?string
    {
        $purpose = trim((string) $line->purpose);

        if ($purpose === '') {
            return null;
        }

        $reason = trim((string) $line->no_receipt_reason);
        $hasReceipt = ($line->receipts_count ?? ($line->exists ? $line->receipts()->count() : 0)) > 0;

        return $line->no_receipt && $reason !== '' && ! $hasReceipt ? "{$purpose} (No receipt: {$reason})" : $purpose;
    }

    /** Stop a spreadsheet app treating text in a CSV as a formula when it is opened. */
    public static function defuse(string $text): string
    {
        return preg_match('/^[=+@\t\r]/', $text) ? "'".$text : $text;
    }

    private function appendPurposeColumn(Worksheet $sheet, int $headerRow): int
    {
        $column = Coordinate::columnIndexFromString($sheet->getHighestDataColumn()) + 1;
        $sheet->setCellValueExplicit([$column, $headerRow], 'Purpose', DataType::TYPE_STRING);

        return $column;
    }
}
