<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ImportStatus;
use App\Http\Controllers\Controller;
use App\Imports\ImportCommitter;
use App\Imports\ImportNotReady;
use App\Imports\ImportPreviewer;
use App\Imports\PreviewRow;
use App\Models\Import;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ImportPreviewController extends Controller
{
    public function show(Import $import, ImportPreviewer $previewer): Response|RedirectResponse
    {
        abort_unless($import->status === ImportStatus::Draft, 404);

        if ($import->column_mapping === null) {
            return to_route('admin.imports.mapping.edit', $import);
        }

        $preview = $previewer->preview($import);
        $names = User::query()
            ->whereIn('id', array_filter(array_map(fn (PreviewRow $row) => $row->userId, $preview->rows)))
            ->pluck('name', 'id')
            ->all();
        $duplicate = $import->duplicateOf();

        return Inertia::render('admin/imports/Preview', [
            'upload' => [
                'id' => $import->id,
                'original_filename' => $import->original_filename,
                'period' => [...$import->period->only(['id', 'label']), 'status' => $import->period->status->value],
            ],
            'rows' => array_map(fn (PreviewRow $row) => $row->toArray($names), $preview->rows),
            'summary' => $preview->summary($names),
            'duplicate_file' => $duplicate ? [
                'original_filename' => $duplicate->original_filename,
                'created_at' => $duplicate->created_at->toIso8601String(),
            ] : null,
        ]);
    }

    public function store(Request $request, Import $import, ImportCommitter $committer): RedirectResponse
    {
        $data = $request->validate([
            'skip' => ['array'],
            'skip.*' => ['integer'],
            'include_duplicates' => ['array'],
            'include_duplicates.*' => ['integer'],
            'fixes' => ['array'],
            'fixes.*.date' => ['nullable', 'string', 'max:50'],
            'fixes.*.amount' => ['nullable', 'string', 'max:50'],
            'fixes.*.description' => ['nullable', 'string', 'max:500'],
        ]);

        $fixes = collect($data['fixes'] ?? [])
            ->mapWithKeys(fn (array $fix, $rowNumber) => [(int) $rowNumber => array_filter($fix, fn ($value) => $value !== null)])
            ->all();

        try {
            $result = $committer->commit(
                $import,
                $request->user(),
                array_map('intval', $data['skip'] ?? []),
                array_map('intval', $data['include_duplicates'] ?? []),
                $fixes,
            );
        } catch (ImportNotReady $e) {
            throw ValidationException::withMessages(['rows' => $e->getMessage()]);
        }

        return to_route('admin.periods.show', $import->period_id)->with('success', $result->message());
    }
}
