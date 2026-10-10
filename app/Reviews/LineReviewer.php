<?php

namespace App\Reviews;

use App\Enums\PeriodStatus;
use App\Enums\ReconciliationStatus;
use App\Enums\TransactionStatus;
use App\Events\LineQueried;
use App\Models\Comment;
use App\Models\Period;
use App\Models\Reconciliation;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Approve and query lines. Locks period → reconciliation → line, the same order as imports and reassignment. */
final class LineReviewer
{
    /** @throws CannotReview */
    public function approve(Transaction $line, User $admin): Transaction
    {
        return DB::transaction(function () use ($line, $admin) {
            [$period, $reconciliation, $line] = $this->lock($line);
            $this->ensureReviewable($period, $reconciliation);

            if ($line->status !== TransactionStatus::Done) {
                throw new CannotReview('Only completed lines can be approved.');
            }

            $this->markApproved($line, $admin);
            $this->approveIfComplete($reconciliation, $admin);

            activity('reviews')
                ->causedBy($admin)
                ->performedOn($line)
                ->event('line_approved')
                ->log("Approved row {$line->row_number}");

            return $line;
        });
    }

    /** @throws CannotReview */
    public function approveRemaining(Reconciliation $reconciliation, User $admin): int
    {
        return DB::transaction(function () use ($reconciliation, $admin) {
            $period = Period::query()->whereKey($reconciliation->period_id)->lockForUpdate()->firstOrFail();
            $reconciliation = Reconciliation::query()->whereKey($reconciliation->id)->lockForUpdate()->firstOrFail();
            $this->ensureReviewable($period, $reconciliation);

            $lines = $reconciliation->transactions()->where('status', TransactionStatus::Done)->lockForUpdate()->get();

            foreach ($lines as $line) {
                $this->markApproved($line, $admin);
            }

            $this->approveIfComplete($reconciliation, $admin);

            activity('reviews')
                ->causedBy($admin)
                ->performedOn($reconciliation)
                ->event('lines_approved')
                ->withProperties(['count' => $lines->count()])
                ->log('Approved '.$lines->count().' '.Str::plural('line', $lines->count()));

            return $lines->count();
        });
    }

    /** @throws CannotReview */
    public function query(Transaction $line, User $admin, string $body): Comment
    {
        return DB::transaction(function () use ($line, $admin, $body) {
            [$period, $reconciliation, $line] = $this->lock($line);

            if ($period->status !== PeriodStatus::Published) {
                throw new CannotReview('This period isn’t open, so lines can’t be queried.');
            }

            if (! in_array($reconciliation->status, [ReconciliationStatus::Submitted, ReconciliationStatus::Approved], true)) {
                throw new CannotReview('Lines can be queried once the cardholder has submitted.');
            }

            if (! in_array($line->status, [TransactionStatus::Done, TransactionStatus::Approved], true)) {
                throw new CannotReview('This line is already waiting on the cardholder.');
            }

            $line->update(['status' => TransactionStatus::Queried, 'approved_at' => null, 'approved_by' => null]);
            $reconciliation->update(['status' => ReconciliationStatus::InProgress, 'approved_at' => null, 'approved_by' => null]);
            $comment = $line->comments()->create(['user_id' => $admin->id, 'body' => $body]);

            LineQueried::dispatch($line, $comment);

            activity('reviews')
                ->causedBy($admin)
                ->performedOn($line)
                ->event('line_queried')
                ->withProperties(['body' => $body])
                ->log("Queried row {$line->row_number}");

            return $comment;
        });
    }

    /** @return array{0: Period, 1: Reconciliation, 2: Transaction} */
    private function lock(Transaction $line): array
    {
        if (! $line->reconciliation_id) {
            throw new CannotReview('This line isn’t assigned to anyone.');
        }

        $period = Period::query()->whereKey($line->period_id)->lockForUpdate()->firstOrFail();
        $reconciliation = Reconciliation::query()->whereKey($line->reconciliation_id)->lockForUpdate()->firstOrFail();
        $line = Transaction::query()->whereKey($line->id)->lockForUpdate()->firstOrFail();

        if ($line->reconciliation_id !== $reconciliation->id) {
            throw new CannotReview('This line has just moved to someone else. Refresh and try again.');
        }

        return [$period, $reconciliation, $line];
    }

    private function ensureReviewable(Period $period, Reconciliation $reconciliation): void
    {
        if ($period->status !== PeriodStatus::Published) {
            throw new CannotReview('This period isn’t open for review.');
        }

        if ($reconciliation->status !== ReconciliationStatus::Submitted) {
            throw new CannotReview('Lines can be approved once the cardholder has submitted.');
        }
    }

    private function markApproved(Transaction $line, User $admin): void
    {
        $line->update(['status' => TransactionStatus::Approved, 'approved_at' => now(), 'approved_by' => $admin->id]);
    }

    private function approveIfComplete(Reconciliation $reconciliation, User $admin): void
    {
        if ($reconciliation->transactions()->where('status', '!=', TransactionStatus::Approved)->exists()) {
            return;
        }

        $reconciliation->update(['status' => ReconciliationStatus::Approved, 'approved_at' => now(), 'approved_by' => $admin->id]);

        activity('reviews')
            ->causedBy($admin)
            ->performedOn($reconciliation)
            ->event('reconciliation_approved')
            ->log('Approved the month');
    }
}
