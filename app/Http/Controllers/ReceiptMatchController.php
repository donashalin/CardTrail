<?php

namespace App\Http\Controllers;

use App\Enums\PeriodStatus;
use App\Enums\TransactionStatus;
use App\Imports\Parsing\AmountParser;
use App\Models\Receipt;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ReceiptMatchController extends Controller
{
    /** Lines this receipt could belong to. Nothing is ever attached automatically. */
    public function show(Request $request, Receipt $receipt): Response|RedirectResponse
    {
        Gate::authorize('manage', $receipt);

        if ($receipt->transaction_id) {
            return to_route('transactions.show', $receipt->transaction_id);
        }

        $typed = is_string($request->query('amount')) ? $request->query('amount') : '';
        $amount = $typed === '' ? null : AmountParser::parse($typed);
        $amount = $amount === null ? null : abs($amount);
        $anchor = $receipt->created_at->toImmutable()->startOfDay();

        $exact = fn (Transaction $line) => $amount !== null && abs($line->amount) === $amount;
        $daysAway = fn (Transaction $line) => abs($line->transacted_on->diffInDays($anchor));
        $amountGap = fn (Transaction $line) => $amount === null ? 0 : abs(abs($line->amount) - $amount);

        $candidates = Transaction::query()
            ->whereHas('reconciliation', fn ($query) => $query->where('user_id', $request->user()->id))
            ->whereHas('period', fn ($query) => $query->where('status', PeriodStatus::Published))
            ->where('status', '!=', TransactionStatus::Approved)
            ->with(['period', 'reconciliation'])
            ->withCount('receipts')
            ->get()
            ->filter(fn (Transaction $line) => $line->editableByOwner())
            ->sortBy([
                fn (Transaction $a, Transaction $b) => $exact($b) <=> $exact($a),
                fn (Transaction $a, Transaction $b) => $daysAway($a) <=> $daysAway($b),
                fn (Transaction $a, Transaction $b) => $amountGap($a) <=> $amountGap($b),
            ])
            ->take(50)
            ->values();

        return Inertia::render('receipts/Match', [
            'receipt' => $receipt->toListItem(),
            'amount' => $typed,
            'candidates' => $candidates->map(fn (Transaction $line) => [
                'id' => $line->id,
                'row_number' => $line->row_number,
                'date' => $line->transacted_on->format('Y-m-d'),
                'description' => $line->description,
                'amount' => $line->amount,
                'status' => $line->status->value,
                'receipts' => $line->receipts_count,
                'period' => $line->period->label,
            ]),
        ]);
    }

    public function store(Request $request, Receipt $receipt): RedirectResponse
    {
        Gate::authorize('manage', $receipt);

        $data = $request->validate(['transaction_id' => ['required', 'integer']]);

        $line = DB::transaction(function () use ($request, $receipt, $data) {
            $line = Transaction::query()->whereKey($data['transaction_id'])->lockForUpdate()->firstOrFail();
            Gate::authorize('update', $line);

            $receipt = Receipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();

            if ($receipt->transaction_id !== null) {
                return null;
            }

            $receipt->update(['transaction_id' => $line->id]);
            $line->refreshStatus();
            $line->save();

            activity('lines')
                ->causedBy($request->user())
                ->performedOn($line)
                ->event('receipt_matched')
                ->withProperties(['receipt_id' => $receipt->id])
                ->log("Matched {$receipt->original_name} to row {$line->row_number}");

            return $line;
        });

        if (! $line) {
            return back()->with('error', 'That receipt is already on a line.');
        }

        return to_route('transactions.show', $line)->with('success', "Receipt attached to row {$line->row_number}.");
    }

    public function destroy(Request $request, Receipt $receipt): RedirectResponse
    {
        Gate::authorize('manage', $receipt);

        if (! $receipt->transaction_id) {
            return back();
        }

        DB::transaction(function () use ($request, $receipt) {
            $line = Transaction::query()->whereKey($receipt->transaction_id)->lockForUpdate()->firstOrFail();

            // Checked again under the lock: the month may have been submitted since the first check.
            $receipt = Receipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('manage', $receipt);

            $receipt->update(['transaction_id' => null]);
            $line->refreshStatus();
            $line->save();

            activity('lines')
                ->causedBy($request->user())
                ->performedOn($line)
                ->event('receipt_unmatched')
                ->withProperties(['receipt_id' => $receipt->id])
                ->log("Moved {$receipt->original_name} from row {$line->row_number} to the inbox");
        });

        return back()->with('success', 'Receipt moved to your inbox.');
    }
}
