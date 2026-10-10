<?php

namespace App\Http\Controllers;

use App\Enums\PeriodStatus;
use App\Models\Reconciliation;
use App\Reconciliations\ReconciliationSummary;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        $reconciliations = ReconciliationSummary::withCounts(Reconciliation::query()->whereBelongsTo($user))
            ->whereHas('period', fn ($query) => $query->where('status', '!=', PeriodStatus::Draft))
            ->get()
            ->sortByDesc(fn (Reconciliation $reconciliation) => $reconciliation->period->month->format('Y-m-d'))
            ->values();

        return Inertia::render('Dashboard', [
            'current' => $reconciliations
                ->filter(fn (Reconciliation $reconciliation) => $reconciliation->period->status === PeriodStatus::Published)
                ->map(ReconciliationSummary::present(...))
                ->values(),
            'past' => $reconciliations
                ->filter(fn (Reconciliation $reconciliation) => $reconciliation->period->status === PeriodStatus::Closed)
                ->take(12)
                ->map(ReconciliationSummary::present(...))
                ->values(),
            'inbox_count' => $user->receipts()->inInbox()->count(),
        ]);
    }
}
