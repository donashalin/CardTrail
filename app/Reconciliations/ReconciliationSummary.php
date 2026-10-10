<?php

namespace App\Reconciliations;

use App\Enums\PeriodStatus;
use App\Enums\ReconciliationStatus;
use App\Enums\TransactionStatus;
use App\Models\Reconciliation;
use Illuminate\Database\Eloquent\Builder;

/** Progress figures for one cardholder's month, shared by the home screen and the line list. */
final class ReconciliationSummary
{
    /** Adds the period and the counts present() reads. */
    public static function withCounts(Builder $query): Builder
    {
        return $query->with('period')->withCount([
            'transactions as lines',
            'transactions as done' => fn ($query) => $query->whereIn('status', [TransactionStatus::Done, TransactionStatus::Approved]),
            'transactions as queried' => fn ($query) => $query->where('status', TransactionStatus::Queried),
        ]);
    }

    /** @return array<string, mixed> */
    public static function present(Reconciliation $reconciliation): array
    {
        $period = $reconciliation->period;

        return [
            'id' => $reconciliation->id,
            'status' => $reconciliation->status->value,
            'submitted_at' => $reconciliation->submitted_at?->toIso8601String(),
            'lines' => (int) $reconciliation->lines,
            'done' => (int) $reconciliation->done,
            'queried' => (int) $reconciliation->queried,
            'overdue' => $period->status === PeriodStatus::Published
                && $period->due_date->lt(today())
                && $reconciliation->status === ReconciliationStatus::InProgress,
            'period' => [
                'id' => $period->id,
                'label' => $period->label,
                'due_date' => $period->due_date->format('Y-m-d'),
                'status' => $period->status->value,
            ],
        ];
    }
}
