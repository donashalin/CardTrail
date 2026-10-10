<?php

namespace App\Exports;

use App\Enums\ImportStatus;
use App\Models\Export;
use App\Models\Import;
use App\Models\Receipt;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/** Builds an export's ZIP in today's layout: filled spreadsheet(s) plus receipts named by row. */
final class ExportBuilder
{
    public function __construct(private SpreadsheetFiller $filler) {}

    /** @return string path to a temporary ZIP file; the caller moves or deletes it */
    public function build(Export $export): string
    {
        $period = $export->period;
        $imports = $period->imports()->where('status', ImportStatus::Confirmed)->orderBy('id')->get()->values();
        $ordinals = $imports->mapWithKeys(fn (Import $import, int $index) => [$import->id => $index + 1]);

        $lines = $period->transactions()
            ->with(['reconciliation.user:id,name', 'receipts'])
            ->withCount('receipts')
            ->when($export->user_id, fn ($query) => $query->whereHas('reconciliation', fn ($query) => $query->where('user_id', $export->user_id)))
            ->orderBy('import_id')
            ->orderBy('row_number')
            ->get();

        $root = $export->user_id ? ReceiptFileNamer::clean("{$period->label} - {$export->user->name}").'/' : '';
        $zipPath = tempnam(sys_get_temp_dir(), 'export');
        $temporary = [];
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Couldn’t create the ZIP file.');
        }

        try {
            foreach ($imports as $import) {
                $name = $this->spreadsheetName($import, $imports, $ordinals[$import->id]);
                $zip->addFromString($root.$name, $this->filler->fill($import, $lines->where('import_id', $import->id)));
            }

            foreach ($lines->whereNotNull('reconciliation_id') as $line) {
                $folder = $root !== '' ? $root : ReceiptFileNamer::clean($line->reconciliation->user->name).'/';

                foreach ($line->receipts->values() as $index => $receipt) {
                    $name = ReceiptFileNamer::name($ordinals[$line->import_id], $line->row_number, $index + 1, pathinfo($receipt->path, PATHINFO_EXTENSION));
                    $zip->addFile($this->localCopy($receipt, $line, $temporary), $folder.$name);
                }
            }

            $zip->close();
        } finally {
            foreach ($temporary as $file) {
                @unlink($file);
            }
        }

        return $zipPath;
    }

    /** The original file name; when two imports share one, later ones get "Import2 - ". */
    private function spreadsheetName(Import $import, Collection $imports, int $ordinal): string
    {
        $name = ReceiptFileNamer::clean($import->original_filename);
        $shared = $imports->filter(fn (Import $other) => ReceiptFileNamer::clean($other->original_filename) === $name)->count() > 1;

        return $shared && $ordinal > 1 ? "Import{$ordinal} - {$name}" : $name;
    }

    /** A local path ZipArchive can read when it closes. Files on remote disks are copied to a temp file first. */
    private function localCopy(Receipt $receipt, Transaction $line, array &$temporary): string
    {
        $disk = Storage::disk($receipt->disk);

        if (! $disk->exists($receipt->path)) {
            throw new RuntimeException("The receipt for row {$line->row_number} ({$line->reconciliation->user->name}) is missing from storage.");
        }

        $path = $disk->path($receipt->path);

        if (is_file($path)) {
            return $path;
        }

        $copy = tempnam(sys_get_temp_dir(), 'receipt');
        file_put_contents($copy, $disk->get($receipt->path));
        $temporary[] = $copy;

        return $copy;
    }
}
