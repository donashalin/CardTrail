<?php

namespace App\Jobs;

use App\Enums\ExportStatus;
use App\Events\ExportFailed;
use App\Events\ExportReady;
use App\Exports\ExportBuilder;
use App\Exports\ExportProblem;
use App\Exports\ReceiptFileNamer;
use App\Models\Export;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class BuildExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(public Export $export) {}

    public function handle(ExportBuilder $builder): void
    {
        $export = $this->export;
        $zip = $builder->build($export);

        try {
            $filename = ReceiptFileNamer::clean($export->user ? "{$export->period->label} - {$export->user->name}" : $export->period->label).'.zip';
            $path = Storage::disk('local')->putFileAs("exports/{$export->id}", new File($zip), Str::uuid().'.zip');
        } finally {
            @unlink($zip);
        }

        $export->update([
            'status' => ExportStatus::Ready,
            'path' => $path,
            'filename' => $filename,
            'error' => null,
            'completed_at' => now(),
        ]);

        ExportReady::dispatch($export);

        activity('exports')
            ->causedBy($export->requester)
            ->performedOn($export->period)
            ->event('exported')
            ->withProperties(['export_id' => $export->id, 'user_id' => $export->user_id])
            ->log("Exported {$filename}");
    }

    public function failed(?Throwable $exception): void
    {
        $this->export->update([
            'status' => ExportStatus::Failed,
            // Admins see reasons they can act on; anything else stays in the logs.
            'error' => $exception instanceof ExportProblem
                ? Str::limit($exception->getMessage(), 500)
                : 'Something went wrong while building the export. Try again, and if it keeps failing ask IT to check the logs.',
            'completed_at' => now(),
        ]);

        ExportFailed::dispatch($this->export);
    }
}
