<?php

namespace App\Imports;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Throwable;

final class SpreadsheetReader
{
    public const MAX_ROWS = 5000;

    /**
     * Read the first worksheet. Rows are keyed by their real row number; blank rows are left out.
     *
     * @return array<int, list<mixed>>
     *
     * @throws UnreadableSpreadsheet
     */
    public function read(string $absolutePath, string $originalName): array
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $reader = $this->readerFor($extension, $absolutePath);

        $reader->setReadDataOnly(true);
        $this->guardSize($reader, $absolutePath);

        $book = $this->load($reader, $absolutePath);
        $sheet = $book->getSheet(0);

        if ($sheet->getHighestDataRow() > self::MAX_ROWS + 1) {
            $book->disconnectWorksheets();
            throw new UnreadableSpreadsheet('This file has more than '.self::MAX_ROWS.' rows. Split it into smaller files.');
        }

        $rows = [];
        foreach ($sheet->toArray(null, false, false, true) as $rowNumber => $cells) {
            $values = array_values($cells);

            if (collect($values)->every(fn ($value) => $value === null || (is_string($value) && trim($value) === ''))) {
                continue;
            }

            $rows[(int) $rowNumber] = $values;
        }

        $book->disconnectWorksheets();

        return $rows;
    }

    /**
     * The whole workbook, formatting included, for writing purposes back into a copy (exports).
     * Rows keep the same numbers as read() gives them.
     *
     * @throws UnreadableSpreadsheet
     */
    public function workbook(string $absolutePath, string $originalName): Spreadsheet
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        return $this->load($this->readerFor($extension, $absolutePath), $absolutePath);
    }

    /**
     * PhpSpreadsheet's own guess can pick a space when a title line sits above the headers,
     * so choose between the separators exports really use by counting them in the first lines.
     */
    public static function delimiter(string $path): string
    {
        $sample = (string) file_get_contents($path, length: 16384);
        $counts = [];

        foreach ([',', ';', "\t", '|'] as $candidate) {
            $counts[$candidate] = substr_count($sample, $candidate);
        }

        arsort($counts);

        return reset($counts) > 0 ? array_key_first($counts) : ',';
    }

    private function readerFor(string $extension, string $path): Csv|Xlsx|Xls
    {
        return match ($extension) {
            'csv', 'txt' => $this->csvReader($path),
            'xlsx' => new Xlsx,
            'xls' => new Xls,
            default => throw new UnreadableSpreadsheet('Upload a CSV or Excel file (.csv, .xlsx or .xls).'),
        };
    }

    /** Count rows without loading the cells, so a huge file is refused instead of exhausting memory. */
    private function guardSize(Csv|Xlsx|Xls $reader, string $path): void
    {
        try {
            $rows = $reader->listWorksheetInfo($path)[0]['totalRows'] ?? 0;
        } catch (Throwable $e) {
            throw new UnreadableSpreadsheet("We couldn't read this file. Check it opens in Excel and try again.", previous: $e);
        }

        if ($rows > self::MAX_ROWS + 1) {
            throw new UnreadableSpreadsheet('This file has more than '.self::MAX_ROWS.' rows. Split it into smaller files.');
        }
    }

    private function csvReader(string $path): Csv
    {
        $reader = new Csv;
        $reader->setInputEncoding(Csv::GUESS_ENCODING);
        $reader->setFallbackEncoding('CP1252');
        $reader->setDelimiter(self::delimiter($path));

        return $reader;
    }

    private function load(IReader $reader, string $path): Spreadsheet
    {
        // CSV cells stay as text (so "0012" keeps its zeros); our own parsers read numbers and dates.
        $previousBinder = Cell::getValueBinder();
        if ($reader instanceof Csv) {
            Cell::setValueBinder(new StringValueBinder);
        }

        try {
            return $reader->load($path);
        } catch (Throwable $e) {
            throw new UnreadableSpreadsheet("We couldn't read this file. Check it opens in Excel and try again.", previous: $e);
        } finally {
            Cell::setValueBinder($previousBinder);
        }
    }
}
