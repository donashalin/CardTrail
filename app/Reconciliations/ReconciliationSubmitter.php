<?php

namespace App\Reconciliations;

use App\Enums\ReconciliationStatus;
use App\Enums\TransactionStatus;
use App\Events\ReconciliationSubmitted;
use App\Models\Reconciliation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ReconciliationSubmitter
{
    /** @throws CannotSubmit */
    public function submit(Reconciliation $reconciliation, User $user): Reconciliation
    {
        return DB::transaction(function () use ($reconciliation, $user) {
            // Locked so lines arriving at the same moment (late import, reassignment) can't slip in unseen.
            $reconciliation = Reconciliation::query()->whereKey($reconciliation->id)->lockForUpdate()->firstOrFail();

            if (! $user->can('submit', $reconciliation)) {
                throw new CannotSubmit('This month can’t be submitted right now.');
            }

            if (! $reconciliation->transactions()->exists()) {
                throw new CannotSubmit('There are no lines to submit.');
            }

            $open = $reconciliation->transactions()->whereIn('status', [TransactionStatus::Todo, TransactionStatus::Queried])->count();

            if ($open > 0) {
                throw new CannotSubmit("{$open} ".Str::plural('line', $open).' still need finishing before you can submit.');
            }

            $resubmitted = $reconciliation->submitted_at !== null;
            $reconciliation->update(['status' => ReconciliationStatus::Submitted, 'submitted_at' => now()]);

            ReconciliationSubmitted::dispatch($reconciliation, $resubmitted);

            activity('reconciliations')
                ->causedBy($user)
                ->performedOn($reconciliation)
                ->event($resubmitted ? 'resubmitted' : 'submitted')
                ->log(($resubmitted ? 'Resubmitted ' : 'Submitted ').$reconciliation->period->label);

            return $reconciliation;
        });
    }
}
