<?php

namespace App\Receipts;

use App\Enums\ImportStatus;
use App\Exports\ReceiptFileNamer;
use App\Models\Import;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Str;

/** Where a receipt lives in storage: "sam-cardholder/2026-09/Row9", or "sam-cardholder/Inbox" until it's matched. */
final class ReceiptFolders
{
    public static function for(User $owner, ?Transaction $line): string
    {
        if ($line === null) {
            return self::person($owner).'/Inbox';
        }

        // Filed under whoever the line belongs to, even if an admin added the receipt.
        $person = $line->reconciliation?->user ?? $owner;

        // Numbered like the export: the period's second statement is "Import2".
        $ordinal = Import::query()
            ->where('period_id', $line->period_id)
            ->where('status', ImportStatus::Confirmed)
            ->where('id', '<=', $line->import_id)
            ->count();

        return self::person($person).'/'.$line->period->month->format('Y-m').'/'.ReceiptFileNamer::row(max(1, $ordinal), $line->row_number);
    }

    public static function person(User $user): string
    {
        return Str::slug($user->name) ?: "user-{$user->id}";
    }

    /** The original name without its extension, made safe for any filesystem. */
    public static function baseName(string $originalName): string
    {
        $base = ltrim(ReceiptFileNamer::clean(pathinfo($originalName, PATHINFO_FILENAME)), ' .');

        // Kept short: some filesystems cap a file name at 255 bytes.
        return rtrim(Str::limit($base, 100, ''), ' .') ?: 'Receipt';
    }
}
