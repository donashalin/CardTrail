<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ImportStatus;
use App\Http\Controllers\Controller;
use App\Imports\ColumnMap;
use App\Imports\ImportSheet;
use App\Imports\Parsing\HeaderSignature;
use App\Imports\RowParser;
use App\Models\ColumnMapping;
use App\Models\Import;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ImportMappingController extends Controller
{
    public function edit(Request $request, Import $import, ImportSheet $sheet): Response
    {
        abort_unless($import->status === ImportStatus::Draft, 404);

        $rows = $sheet->rows($import);
        $headerRow = (int) $request->query('header_row', $import->header_row);
        if (! isset($rows[$headerRow])) {
            $headerRow = array_key_first($rows);
        }

        $headers = $sheet->headers($import, $headerRow);
        $labels = array_map(fn (string $text, int $index) => $text !== '' ? $text : 'Column '.($index + 1), $headers, array_keys($headers));
        $asText = fn (array $rows) => array_map(
            fn (array $cells, int $rowNumber) => ['row_number' => $rowNumber, 'cells' => array_map(RowParser::text(...), $cells)],
            $rows,
            array_keys($rows),
        );

        $mapping = $import->column_mapping !== null && $headerRow === $import->header_row
            ? $import->column_mapping
            : ColumnMap::guess($headers);

        $duplicate = $import->duplicateOf();

        return Inertia::render('admin/imports/Mapping', [
            'upload' => [
                'id' => $import->id,
                'original_filename' => $import->original_filename,
                'period' => $import->period->only(['id', 'label']),
            ],
            'header_row' => $headerRow,
            'first_rows' => $asText(array_slice($rows, 0, 10, true)),
            'header_labels' => $labels,
            'samples' => $asText(array_slice($sheet->dataRows($import, $headerRow), 0, 5, true)),
            'mapping' => array_merge(array_fill_keys(ColumnMap::FIELDS, null), $mapping),
            'duplicate_file' => $duplicate ? [
                'original_filename' => $duplicate->original_filename,
                'created_at' => $duplicate->created_at->toIso8601String(),
            ] : null,
        ]);
    }

    public function update(Request $request, Import $import, ImportSheet $sheet): RedirectResponse
    {
        abort_unless($import->status === ImportStatus::Draft, 404);

        $rows = $sheet->rows($import);
        $data = $request->validate([
            'header_row' => ['required', 'integer', Rule::in(array_keys($rows))],
            'save_as' => ['nullable', 'string', 'max:100'],
            'mapping' => ['required', 'array'],
        ]);

        $headers = $sheet->headers($import, (int) $data['header_row']);
        $columns = array_keys($headers);

        $mapping = $request->validate([
            'mapping.date' => ['required', 'integer', Rule::in($columns)],
            'mapping.description' => ['required', 'integer', Rule::in($columns)],
            'mapping.amount' => ['required', 'integer', Rule::in($columns)],
            'mapping.card' => ['nullable', 'integer', Rule::in($columns)],
            'mapping.cardholder' => ['nullable', 'integer', Rule::in($columns)],
            'mapping.purpose' => ['nullable', 'integer', Rule::in($columns)],
        ], [
            'mapping.*.required' => 'Choose which column holds this.',
            'mapping.*.in' => 'Choose a column from this file.',
        ])['mapping'];

        $used = array_filter(Arr::only($mapping, ColumnMap::FIELDS), fn ($column) => $column !== null);
        if (count($used) !== count(array_unique($used))) {
            throw ValidationException::withMessages(['mapping' => 'Each column can only be used for one field.']);
        }

        $map = ColumnMap::fromArray($mapping)->toArray();
        $import->update(['header_row' => (int) $data['header_row'], 'column_mapping' => $map]);

        if (filled($data['save_as'] ?? null)) {
            ColumnMapping::updateOrCreate(
                ['header_signature' => HeaderSignature::for($headers)],
                ['name' => $data['save_as'], 'header_row' => (int) $data['header_row'], 'mapping' => $map],
            );
        }

        return to_route('admin.imports.preview', $import);
    }
}
