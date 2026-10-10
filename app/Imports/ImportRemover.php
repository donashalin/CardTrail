<?php

namespace App\Imports;

use App\Enums\ImportStatus;
use App\Enums\PeriodStatus;
use App\Models\Import;
use App\Models\Reconciliation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class ImportRemover
{
    /** @throws ImportNotReady */
    public function remove(Import $import, User $admin): void
    {
        $period = $import->period;

        if ($import->status === ImportStatus::Confirmed && $period->status !== PeriodStatus::Draft) {
            throw new ImportNotReady('Cardholders may already be working on these lines. Files can only be removed before the period is published.');
        }

        DB::transaction(function () use ($import, $period, $admin) {
            $reconciliationIds = $import->transactions()->whereNotNull('reconciliation_id')->distinct()->pluck('reconciliation_id');

            $import->transactions()->delete();
            Reconciliation::query()->whereIn('id', $reconciliationIds)->whereDoesntHave('transactions')->delete();
            $import->delete();

            if ($import->status === ImportStatus::Confirmed) {
                activity('periods')
                    ->causedBy($admin)
                    ->performedOn($period)
                    ->event('import_removed')
                    ->withProperties(['file' => $import->original_filename])
                    ->log("Removed {$import->original_filename}");
            }
        });

        Storage::disk('local')->delete($import->stored_path);
    }
}
