<?php

namespace App\Exports;

use App\Enums\ImportStatus;
use App\Models\Export;
use App\Models\Import;
use App\Models\Receipt;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
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
        $files = $this->receiptFiles($lines, $ordinals, $root);
        $zipPath = tempnam(sys_get_temp_dir(), 'export');
        $temporary = [];
        $zip = new ZipArchive;

        try {
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Couldn’t create the ZIP file.');
            }

            foreach ($imports as $import) {
                $name = $this->spreadsheetName($import, $imports, $ordinals[$import->id]);
                $zip->addFromString($root.$name, $this->filler->fill($import, $lines->where('import_id', $import->id)));
            }

            foreach ($files as $entry => $receipt) {
                $zip->addFile($this->localCopy($receipt, $temporary), $entry);
            }

            if (! $zip->close()) {
                throw new RuntimeException('Couldn’t finish writing the ZIP file.');
            }
        } catch (Throwable $e) {
            // An abandoned ZipArchive writes what it has on destruction: discard it so no partial copy of receipts is left behind.
            @$zip->unchangeAll();
            @$zip->close();
            @unlink($zipPath);

            throw $e;
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

    /**
     * Every receipt's place in the ZIP, checked up front so a missing file fails the export before anything is written.
     *
     * @return array<string, Receipt> entry name => receipt
     */
    private function receiptFiles(Collection $lines, Collection $ordinals, string $root): array
    {
        $files = [];
        $missing = [];

        foreach ($lines->whereNotNull('reconciliation_id') as $line) {
            $folder = $root !== '' ? $root : ReceiptFileNamer::clean($line->reconciliation->user->name).'/';

            foreach ($line->receipts->values() as $index => $receipt) {
                $name = ReceiptFileNamer::name($ordinals[$line->import_id], $line->row_number, $index + 1, pathinfo($receipt->path, PATHINFO_EXTENSION));

                if (! Storage::disk($receipt->disk)->exists($receipt->path)) {
                    $missing[] = "row {$line->row_number} ({$name}, {$line->reconciliation->user->name})";
                }

                $files[$folder.$name] = $receipt;
            }
        }

        if ($missing !== []) {
            throw new RuntimeException('These receipts are missing from storage: '.implode('; ', $missing).'.');
        }

        return $files;
    }

    /** A local path ZipArchive can read when it closes. Files on remote disks are copied to a temp file first. */
    private function localCopy(Receipt $receipt, array &$temporary): string
    {
        $disk = Storage::disk($receipt->disk);
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
