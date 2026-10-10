<?php

namespace App\Http\Controllers;

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class TransactionCommentController extends Controller
{
    public function store(Request $request, Transaction $transaction): RedirectResponse
    {
        Gate::authorize('comment', $transaction);

        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        DB::transaction(function () use ($request, $transaction, $data) {
            $line = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            $line->comments()->create(['user_id' => $request->user()->id, 'body' => $data['body']]);

            // A cardholder answering a query in words has dealt with it, as long as the line is complete.
            if ($line->status === TransactionStatus::Queried && $request->user()->can('update', $line)) {
                $line->refreshStatus();
                $line->save();
            }

            activity('lines')
                ->causedBy($request->user())
                ->performedOn($line)
                ->event('commented')
                ->withProperties(['body' => $data['body']])
                ->log("Commented on row {$line->row_number}");
        });

        return back()->with('success', 'Comment added.');
    }
}
