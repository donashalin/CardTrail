<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ExportStatus;
use App\Enums\PeriodStatus;
use App\Http\Controllers\Controller;
use App\Jobs\BuildExport;
use App\Models\Export;
use App\Models\Period;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function store(Request $request, Period $period): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'integer', Rule::exists('reconciliations', 'user_id')->where('period_id', $period->id)],
        ]);

        if ($period->status === PeriodStatus::Draft) {
            return back()->with('error', 'Publish the period before exporting it.');
        }

        $export = $period->exports()->create([
            'user_id' => $data['user_id'] ?? null,
            'requested_by' => $request->user()->id,
        ]);

        BuildExport::dispatch($export);

        return back()->with('success', 'Building the export. It will appear under Exports when it’s ready.');
    }

    public function show(Export $export): StreamedResponse
    {
        abort_unless($export->status === ExportStatus::Ready && $export->path && Storage::disk('local')->exists($export->path), 404);

        return Storage::disk('local')->download($export->path, $export->filename);
    }
}
