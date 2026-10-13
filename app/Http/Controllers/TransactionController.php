<?php

namespace App\Http\Controllers;

use App\Enums\TransactionStatus;
use App\Models\Comment;
use App\Models\Receipt;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    public function show(Request $request, Transaction $transaction): Response
    {
        Gate::authorize('view', $transaction);

        $user = $request->user();
        $transaction->load(['period', 'reconciliation.user:id,name', 'receipts', 'comments.user:id,name']);
        $editable = $user->can('update', $transaction);
        $siblings = $this->siblings($transaction);
        $position = $siblings->search($transaction->id);

        return Inertia::render('transactions/Show', [
            'line' => [
                'id' => $transaction->id,
                'row_number' => $transaction->row_number,
                'date' => $transaction->transacted_on->format('Y-m-d'),
                'description' => $transaction->description,
                'amount' => $transaction->amount,
                'card_ref' => $transaction->card_ref,
                'extra' => (object) ($transaction->extra ?? []),
                'purpose' => $transaction->purpose ?? '',
                'no_receipt' => $transaction->no_receipt,
                'no_receipt_reason' => $transaction->no_receipt_reason ?? '',
                'status' => $transaction->status->value,
            ],
            'receipts' => $transaction->receipts->map(fn (Receipt $receipt) => $receipt->toListItem()),
            'comments' => $transaction->comments->map(fn (Comment $comment) => [
                'id' => $comment->id,
                'body' => $comment->body,
                'author' => $comment->user->name,
                'mine' => $comment->user_id === $user->id,
                'created_at' => $comment->created_at->toIso8601String(),
            ]),
            'reconciliation' => $transaction->reconciliation ? [
                'id' => $transaction->reconciliation->id,
                'owner' => $transaction->reconciliation->user->name,
                'status' => $transaction->reconciliation->status->value,
            ] : null,
            'period' => ['label' => $transaction->period->label, 'status' => $transaction->period->status->value],
            'editable' => $editable,
            'can_comment' => $user->can('comment', $transaction),
            'suggestions' => $editable ? $this->suggestions($user) : [],
            'nav' => [
                'position' => $position === false ? null : $position + 1,
                'total' => $siblings->count(),
                'previous' => $position ? $siblings[$position - 1] : null,
                'next' => $position === false ? null : $siblings->get($position + 1),
            ],
        ]);
    }

    public function update(Request $request, Transaction $transaction): RedirectResponse
    {
        $data = $request->validate([
            'purpose' => ['nullable', 'string', 'max:1000'],
            'no_receipt' => ['required', 'boolean'],
            // Credits hide the no-receipt box, so they never need a reason.
            'no_receipt_reason' => [Rule::requiredIf($request->boolean('no_receipt') && $transaction->amount >= 0), 'nullable', 'string', 'max:500'],
            'next' => ['sometimes', 'boolean'],
        ], [
            'no_receipt_reason.required' => 'Say why there’s no receipt, or untick “I don’t have a receipt”.',
        ]);

        $line = DB::transaction(function () use ($request, $transaction, $data) {
            $line = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('update', $line);

            // Blank strings arrive as null (ConvertEmptyStringsToNull). The reason is kept even when the box is unticked.
            $line->fill([
                'purpose' => $data['purpose'] ?? null,
                'no_receipt' => (bool) $data['no_receipt'],
                'no_receipt_reason' => $data['no_receipt_reason'] ?? null,
            ]);

            if ($line->isDirty()) {
                $changed = array_keys($line->getDirty());
                $line->refreshStatus();
                $line->save();

                activity('lines')
                    ->causedBy($request->user())
                    ->performedOn($line)
                    ->event('line_updated')
                    ->withProperties(['changed' => $changed, 'status' => $line->status->value])
                    ->log("Updated row {$line->row_number}");
            }

            return $line;
        });

        if (! $request->boolean('next')) {
            return back();
        }

        $next = $this->nextNeedingWork($line);

        if ($next) {
            return to_route('transactions.show', $next);
        }

        $allDone = ! Transaction::query()
            ->where('reconciliation_id', $line->reconciliation_id)
            ->whereIn('status', [TransactionStatus::Todo, TransactionStatus::Queried])
            ->exists();

        return to_route('reconciliations.show', $line->reconciliation_id)->with('success', $allDone
            ? 'Every line is done. Submit when you’re ready.'
            : 'That line still needs finishing before you can submit.');
    }

    /** @return Collection<int, int> ids of the lines on the same reconciliation, in statement order */
    private function siblings(Transaction $line): Collection
    {
        if (! $line->reconciliation_id) {
            return collect([$line->id]);
        }

        return Transaction::query()
            ->where('reconciliation_id', $line->reconciliation_id)
            ->orderBy('import_id')
            ->orderBy('row_number')
            ->pluck('id');
    }

    /** The next line after this one (wrapping round) that is still to do or queried. */
    private function nextNeedingWork(Transaction $line): ?int
    {
        $open = Transaction::query()
            ->where('reconciliation_id', $line->reconciliation_id)
            ->whereIn('status', [TransactionStatus::Todo, TransactionStatus::Queried])
            ->whereKeyNot($line->id)
            ->orderBy('import_id')
            ->orderBy('row_number')
            ->get(['id', 'import_id', 'row_number']);

        $after = $open->first(fn (Transaction $other) => [$other->import_id, $other->row_number] > [$line->import_id, $line->row_number]);

        return ($after ?? $open->first())?->id;
    }

    /** @return list<string> the cardholder's recent purposes, newest first */
    private function suggestions(User $user): array
    {
        return Transaction::query()
            ->whereHas('reconciliation', fn ($query) => $query->where('user_id', $user->id))
            ->whereNotNull('purpose')
            ->latest('updated_at')
            ->limit(50)
            ->pluck('purpose')
            ->unique(fn (string $purpose) => mb_strtolower($purpose))
            ->take(8)
            ->values()
            ->all();
    }
}
