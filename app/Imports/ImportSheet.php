<?php

namespace App\Imports;

use App\Models\Import;
use Illuminate\Support\Facades\Storage;

/** Reads an import's stored file once per request and hands out its rows and headers. */
final class ImportSheet
{
    /** @var array<int, array<int, list<mixed>>> */
    private array $cache = [];

    public function __construct(private SpreadsheetReader $reader) {}

    /** @return array<int, list<mixed>> */
    public function rows(Import $import): array
    {
        return $this->cache[$import->id] ??= $this->reader->read(
            Storage::disk('local')->path($import->stored_path),
            $import->original_filename,
        );
    }

    /** @return array<int, string> header text by column index */
    public function headers(Import $import, int $headerRow): array
    {
        return array_map(RowParser::text(...), $this->rows($import)[$headerRow] ?? []);
    }

    /** @return array<int, list<mixed>> rows below the header row */
    public function dataRows(Import $import, int $headerRow): array
    {
        return array_filter($this->rows($import), fn (int $rowNumber) => $rowNumber > $headerRow, ARRAY_FILTER_USE_KEY);
    }
}
