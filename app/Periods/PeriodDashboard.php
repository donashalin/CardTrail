<?php

namespace App\Periods;

use App\Enums\PeriodStatus;
use App\Enums\ReconciliationStatus;
use App\Enums\TransactionStatus;
use App\Models\Import;
use App\Models\Period;
use App\Models\Reconciliation;

/** Everything the period dashboard shows, in one place. */
final class PeriodDashboard
{
    /** @return array<string, mixed> */
    public static function for(Period $period): array
    {
        $pastDue = $period->status === PeriodStatus::Published && $period->due_date->lt(today());

        $reconciliations = $period->reconciliations()
            ->with('user:id,name,active')
            ->withCount([
                'transactions as lines',
                'transactions as done' => fn ($query) => $query->whereIn('status', [TransactionStatus::Done, TransactionStatus::Approved]),
                'transactions as queried' => fn ($query) => $query->where('status', TransactionStatus::Queried),
            ])
            ->get()
            ->sortBy(fn (Reconciliation $reconciliation) => mb_strtolower($reconciliation->user->name))
            ->values();

        return [
            'period' => [
                'id' => $period->id,
                'label' => $period->label,
                'month' => $period->month->format('Y-m-d'),
                'due_date' => $period->due_date->format('Y-m-d'),
                'status' => $period->status->value,
                'published_at' => $period->published_at?->toIso8601String(),
            ],
            'reconciliations' => $reconciliations->map(fn (Reconciliation $reconciliation) => [
                'id' => $reconciliation->id,
                'user' => $reconciliation->user->only(['id', 'name', 'active']),
                'status' => $reconciliation->status->value,
                'lines' => $reconciliation->lines,
                'done' => $reconciliation->done,
                'queried' => $reconciliation->queried,
                'overdue' => $pastDue && $reconciliation->status === ReconciliationStatus::InProgress,
            ])->all(),
            'imports' => $period->imports()->latest('id')->get()->map(fn (Import $import) => [
                'id' => $import->id,
                'original_filename' => $import->original_filename,
                'status' => $import->status->value,
                'row_count' => $import->row_count,
                'created_at' => $import->created_at->toIso8601String(),
            ])->all(),
            'unassigned_count' => $period->unassignedTransactions()->count(),
            'totals' => [
                'lines' => $period->transactions()->count(),
                'amount' => (int) $period->transactions()->sum('amount'),
            ],
        ];
    }
}
