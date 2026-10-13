<?php

namespace App\Periods;

use App\Enums\PeriodStatus;
use App\Enums\TransactionStatus;
use App\Jobs\PlaceReceipt;
use App\Models\Comment;
use App\Models\Period;
use App\Models\Receipt;
use App\Models\Reconciliation;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Moves lines to a cardholder (or back to Unassigned). Work done on a moved line belongs to its old owner, so it starts fresh. */
final class TransactionAssigner
{
    /**
     * @param  list<int>  $transactionIds
     *
     * @throws CannotReassign
     */
    public function assign(Period $period, array $transactionIds, ?User $to, User $admin): int
    {
        if ($to && (! $to->active || ! $to->is_cardholder)) {
            throw new CannotReassign("{$to->name} isn't an active cardholder.");
        }

        return DB::transaction(function () use ($period, $transactionIds, $to, $admin) {
            $period = Period::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();

            if ($period->status === PeriodStatus::Closed) {
                throw new CannotReassign('This period is closed.');
            }

            $lines = Transaction::query()->whereIn('id', $transactionIds)->lockForUpdate()->get();

            if ($lines->count() !== count(array_unique($transactionIds)) || $lines->contains(fn (Transaction $line) => $line->period_id !== $period->id)) {
                throw new CannotReassign('Some of those lines are not in this period.');
            }

            if ($lines->contains(fn (Transaction $line) => $line->status === TransactionStatus::Approved)) {
                throw new CannotReassign('Approved lines can’t be moved.');
            }

            $target = $to ? Reconciliation::firstOrCreate(['period_id' => $period->id, 'user_id' => $to->id]) : null;
            $moving = $lines->reject(fn (Transaction $line) => $line->reconciliation_id === $target?->id);
            $sources = $moving->pluck('reconciliation_id')->filter()->unique();

            // Receipts belong to whoever uploaded them, and a query thread is about their work: neither travels with the line.
            $unlinked = Receipt::query()->whereIn('transaction_id', $moving->pluck('id'));
            PlaceReceipt::dispatchFor(clone $unlinked);
            $unlinked->update(['transaction_id' => null]);
            Comment::query()->whereIn('transaction_id', $moving->pluck('id'))->delete();

            foreach ($moving as $line) {
                $line->update([
                    'reconciliation_id' => $target?->id,
                    'purpose' => null,
                    'no_receipt' => false,
                    'no_receipt_reason' => null,
                    'status' => TransactionStatus::Todo,
                ]);
            }

            Reconciliation::query()->whereIn('id', $sources)->whereDoesntHave('transactions')->delete();

            // Moving out the last unapproved line completes a submitted month.
            Reconciliation::query()->whereIn('id', $sources)->lockForUpdate()->get()
                ->each(fn (Reconciliation $source) => $source->approveIfComplete($admin));

            if ($target && $moving->isNotEmpty()) {
                $target->linesAdded($moving->count());
            }

            activity('periods')
                ->causedBy($admin)
                ->performedOn($period)
                ->event('lines_reassigned')
                ->withProperties(['transaction_ids' => $moving->pluck('id')->all(), 'to_user_id' => $to?->id])
                ->log('Moved '.$moving->count().' lines to '.($to?->name ?? 'Unassigned'));

            return $moving->count();
        });
    }
}
