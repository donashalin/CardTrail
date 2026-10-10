<?php

namespace App\Http\Controllers;

use App\Models\Reconciliation;
use App\Reconciliations\CannotSubmit;
use App\Reconciliations\ReconciliationSubmitter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SubmitReconciliationController extends Controller
{
    public function __invoke(Request $request, Reconciliation $reconciliation, ReconciliationSubmitter $submitter): RedirectResponse
    {
        // Only the owner may submit; whether it can be submitted right now is explained, not refused with a 403.
        abort_unless($reconciliation->user_id === $request->user()->id, 403);

        try {
            $submitter->submit($reconciliation, $request->user());
        } catch (CannotSubmit $e) {
            return back()->with('error', $e->getMessage());
        }

        return to_route('reconciliations.show', $reconciliation)
            ->with('success', "{$reconciliation->period->label} is submitted. Accounts will be in touch if they have questions.");
    }
}
