<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PeriodStatus;
use App\Enums\ReconciliationStatus;
use App\Http\Controllers\Controller;
use App\Models\Reconciliation;
use App\Notifications\Nudge;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NudgeController extends Controller
{
    public function __invoke(Request $request, Reconciliation $reconciliation): RedirectResponse
    {
        $user = $reconciliation->user;

        if ($reconciliation->period->status !== PeriodStatus::Published || $reconciliation->status !== ReconciliationStatus::InProgress) {
            return back()->with('error', 'Only people still working on a published month can be nudged.');
        }

        if (! $user->active) {
            return back()->with('error', "{$user->name} is inactive. Reassign their lines instead.");
        }

        $user->notify(new Nudge($reconciliation, $request->user()));

        activity('reminders')
            ->causedBy($request->user())
            ->performedOn($reconciliation)
            ->event('nudged')
            ->log("Nudged {$user->name}");

        return back()->with('success', "Reminder sent to {$user->name}.");
    }
}
