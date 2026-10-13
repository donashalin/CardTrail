<?php

namespace App\Receipts;

use App\Models\Receipt;
use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Keeps each receipt's file in its folder, under its original name, as lines, names and months change. */
final class ReceiptFiler
{
    /** One receipt file changes at a time, so two jobs can't pick the same free name or move a file from under each other. */
    public function locked(Closure $work): mixed
    {
        return Cache::lock('receipt-filing', 120)->block(60, $work);
    }

    /** Call inside locked(). */
    public function place(Receipt $receipt): void
    {
        $receipt = Receipt::query()->with(['user', 'transaction.period', 'transaction.reconciliation.user'])->find($receipt->id);

        if ($receipt === null) {
            return;
        }

        $disk = Storage::disk($receipt->disk);
        $folder = ReceiptFolders::for($receipt->user, $receipt->transaction);
        $base = ReceiptFolders::baseName($receipt->original_name);
        $extension = strtolower(pathinfo($receipt->path, PATHINFO_EXTENSION));

        if ($this->alreadyPlaced($receipt->path, $folder, $base)) {
            return;
        }

        if (! $disk->exists($receipt->path)) {
            Log::warning('Receipt file is missing, so it could not be moved to its folder.', ['receipt_id' => $receipt->id, 'path' => $receipt->path]);

            return;
        }

        $target = $this->freeName($disk, $receipt->disk, $folder, $base, $extension);

        if (! $disk->copy($receipt->path, $target)) {
            throw new RuntimeException("Couldn't copy receipt {$receipt->id} to {$target}.");
        }

        // The new copy is recorded before the old one goes, so the receipt always points at a file that exists.
        $recorded = DB::transaction(function () use ($receipt, $target) {
            $current = Receipt::query()->whereKey($receipt->id)->lockForUpdate()->first();

            if ($current === null || $current->path !== $receipt->path) {
                return false;
            }

            $current->updateQuietly(['path' => $target]);

            return true;
        });

        $disk->delete($recorded ? $receipt->path : $target);
    }

    private function alreadyPlaced(string $path, string $folder, string $base): bool
    {
        return dirname($path) === $folder
            && preg_match('/^'.preg_quote($base, '/').'( \(\d+\))?$/u', pathinfo($path, PATHINFO_FILENAME)) === 1;
    }

    /** "Fuel.pdf", or "Fuel (2).pdf" if that's taken. */
    private function freeName(Filesystem $disk, string $diskName, string $folder, string $base, string $extension): string
    {
        for ($copy = 1; ; $copy++) {
            $candidate = $folder.'/'.$base.($copy > 1 ? " ({$copy})" : '').'.'.$extension;

            if (! $disk->exists($candidate) && ! Receipt::query()->where('disk', $diskName)->where('path', $candidate)->exists()) {
                return $candidate;
            }
        }
    }
}
