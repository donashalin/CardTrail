<?php

namespace App\Listeners;

use App\Events\ExportFailed;
use App\Events\ExportReady;
use App\Events\LineQueried;
use App\Events\PeriodPublished;
use App\Events\ReconciliationLinesAdded;
use App\Events\ReconciliationSubmitted;
use App\Models\Reconciliation;
use App\Models\User;
use App\Notifications\ExportFailedNotification;
use App\Notifications\ExportReadyNotification;
use App\Notifications\LineQueriedNotification;
use App\Notifications\LinesAdded;
use App\Notifications\MonthReady;
use App\Notifications\ReconciliationSubmittedNotification;

/** Who hears about what (spec §6.7). Inactive users are never notified. Found by Laravel's event discovery. */
class SendNotifications
{
    public function handlePeriodPublished(PeriodPublished $event): void
    {
        $event->period->reconciliations()->with('user')->get()
            ->filter(fn (Reconciliation $reconciliation) => $reconciliation->user->active)
            ->each(fn (Reconciliation $reconciliation) => $reconciliation->user->notify(new MonthReady($reconciliation)));
    }

    public function handleLinesAdded(ReconciliationLinesAdded $event): void
    {
        $user = $event->reconciliation->user;

        if ($user->active) {
            $user->notify(new LinesAdded($event->reconciliation, $event->count));
        }
    }

    public function handleLineQueried(LineQueried $event): void
    {
        $user = $event->line->reconciliation->user;

        if ($user->active) {
            $user->notify(new LineQueriedNotification($event->line, $event->comment));
        }
    }

    public function handleSubmitted(ReconciliationSubmitted $event): void
    {
        User::query()->active()
            ->where('is_admin', true)
            ->whereKeyNot($event->reconciliation->user_id)
            ->get()
            ->each(fn (User $admin) => $admin->notify(new ReconciliationSubmittedNotification($event->reconciliation, $event->resubmitted)));
    }

    public function handleExportReady(ExportReady $event): void
    {
        if ($event->export->requester->active) {
            $event->export->requester->notify(new ExportReadyNotification($event->export));
        }
    }

    public function handleExportFailed(ExportFailed $event): void
    {
        if ($event->export->requester->active) {
            $event->export->requester->notify(new ExportFailedNotification($event->export));
        }
    }
}
