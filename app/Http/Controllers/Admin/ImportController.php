<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PeriodStatus;
use App\Http\Controllers\Controller;
use App\Imports\ImportNotReady;
use App\Imports\ImportRemover;
use App\Imports\ImportSheet;
use App\Imports\UnreadableSpreadsheet;
use App\Models\ColumnMapping;
use App\Models\Import;
use App\Models\Period;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ImportController extends Controller
{
    public function create(Period $period): Response
    {
        abort_if($period->status === PeriodStatus::Closed, 403);

        return Inertia::render('admin/imports/Create', [
            'period' => $period->only(['id', 'label']),
        ]);
    }

    public function store(Request $request, Period $period, ImportSheet $sheet): RedirectResponse
    {
        abort_if($period->status === PeriodStatus::Closed, 403);

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240'],
        ], [
            'file.mimes' => 'Upload a CSV or Excel file (.csv, .xlsx or .xls).',
            'file.max' => 'The file must be smaller than 10 MB.',
        ]);

        $file = $request->file('file');
        $import = $period->imports()->create([
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $file->store("imports/{$period->id}", 'local'),
            'file_hash' => hash_file('sha256', $file->getRealPath()),
            'uploaded_by' => $request->user()->id,
        ]);

        try {
            $rows = $sheet->rows($import);
        } catch (UnreadableSpreadsheet $e) {
            return $this->discard($import, $e->getMessage());
        }

        if ($rows === []) {
            return $this->discard($import, 'This file has no rows.');
        }

        if ($match = ColumnMapping::matchFirstRows($rows)) {
            [$saved, $headerRow] = $match;
            $import->update(['header_row' => $headerRow, 'column_mapping' => $saved->mapping]);

            return to_route('admin.imports.preview', $import)
                ->with('success', "Recognised the “{$saved->name}” layout. Check the rows below.");
        }

        $import->update(['header_row' => array_key_first($rows)]);

        return to_route('admin.imports.mapping.edit', $import);
    }

    public function destroy(Request $request, Import $import, ImportRemover $remover): RedirectResponse
    {
        try {
            $remover->remove($import, $request->user());
        } catch (ImportNotReady $e) {
            return back()->with('error', $e->getMessage());
        }

        return to_route('admin.periods.show', $import->period_id)->with('success', "Removed {$import->original_filename}.");
    }

    private function discard(Import $import, string $message): RedirectResponse
    {
        Storage::disk('local')->delete($import->stored_path);
        $import->delete();

        return back()->withErrors(['file' => $message]);
    }
}
