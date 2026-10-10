<?php

namespace App\Periods;

use App\Enums\ExportStatus;
use App\Enums\PeriodStatus;
use App\Enums\ReconciliationStatus;
use App\Enums\TransactionStatus;
use App\Models\Export;
use App\Models\Import;
use App\Models\Period;
use App\Models\Reconciliation;

/** Everything the period dashboard shows, in one place. */
final class PeriodDashboard
{
    /**
     * Each part is a closure so a partial reload (the export list polling) only works out what it asks for.
     *
     * @return array<string, mixed>
     */
    public static function for(Period $period): array
    {
        return [
            'period' => [
                'id' => $period->id,
                'label' => $period->label,
                'month' => $period->month->format('Y-m-d'),
                'due_date' => $period->due_date->format('Y-m-d'),
                'status' => $period->status->value,
                'published_at' => $period->published_at?->toIso8601String(),
            ],
            'reconciliations' => fn () => self::reconciliations($period),
            'imports' => fn () => $period->imports()->latest('id')->get()->map(fn (Import $import) => [
                'id' => $import->id,
                'original_filename' => $import->original_filename,
                'status' => $import->status->value,
                'row_count' => $import->row_count,
                'created_at' => $import->created_at->toIso8601String(),
            ])->all(),
            'exports' => fn () => $period->exports()->with(['user:id,name', 'requester:id,name'])->latest('id')->limit(10)->get()->map(fn (Export $export) => [
                'id' => $export->id,
                'status' => $export->status->value,
                'scope' => $export->user?->name ?? 'Whole period',
                'filename' => $export->filename,
                'error' => $export->error,
                'requested_by' => $export->requester->name,
                'created_at' => $export->created_at->toIso8601String(),
                'download_url' => $export->status === ExportStatus::Ready ? route('admin.exports.show', $export) : null,
            ])->all(),
            'unassigned_count' => fn () => $period->unassignedTransactions()->count(),
            'totals' => fn () => [
                'lines' => $period->transactions()->count(),
                'amount' => (int) $period->transactions()->sum('amount'),
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function reconciliations(Period $period): array
    {
        $pastDue = $period->status === PeriodStatus::Published && $period->due_date->lt(today());

        $reconciliations = $period->reconciliations()
            ->with('user:id,name,active')
            ->withCount([
                'transactions as lines',
                'transactions as done' => fn ($query) => $query->whereIn('status', [TransactionStatus::Done, TransactionStatus::Approved]),
                'transactions as queried' => fn ($query) => $query->where('status', TransactionStatus::Queried),
                'transactions as approved' => fn ($query) => $query->where('status', TransactionStatus::Approved),
            ])
            ->get()
            ->sortBy(fn (Reconciliation $reconciliation) => mb_strtolower($reconciliation->user->name))
            ->values();

        return $reconciliations->map(fn (Reconciliation $reconciliation) => [
            'id' => $reconciliation->id,
            'user' => $reconciliation->user->only(['id', 'name', 'active']),
            'status' => $reconciliation->status->value,
            'lines' => $reconciliation->lines,
            'done' => $reconciliation->done,
            'queried' => $reconciliation->queried,
            'approved' => $reconciliation->approved,
            'overdue' => $pastDue && $reconciliation->status === ReconciliationStatus::InProgress,
        ])->all();
    }
}
