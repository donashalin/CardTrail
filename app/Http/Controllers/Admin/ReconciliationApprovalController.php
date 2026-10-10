<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReconciliationStatus;
use App\Http\Controllers\Controller;
use App\Models\Reconciliation;
use App\Reviews\CannotReview;
use App\Reviews\LineReviewer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ReconciliationApprovalController extends Controller
{
    public function __invoke(Request $request, Reconciliation $reconciliation, LineReviewer $reviewer): RedirectResponse
    {
        try {
            $count = $reviewer->approveRemaining($reconciliation, $request->user());
        } catch (CannotReview $e) {
            return back()->with('error', $e->getMessage());
        }

        $message = "Approved {$count} ".Str::plural('line', $count).'.';

        if ($reconciliation->fresh()->status === ReconciliationStatus::Approved) {
            $message .= " {$reconciliation->user->name}’s month is approved.";
        }

        return back()->with('success', $message);
    }
}
