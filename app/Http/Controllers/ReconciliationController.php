<?php

namespace App\Http\Controllers;

use App\Models\Reconciliation;
use App\Models\Transaction;
use App\Reconciliations\ReconciliationSummary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ReconciliationController extends Controller
{
    public function show(Request $request, Reconciliation $reconciliation): Response
    {
        Gate::authorize('view', $reconciliation);

        $user = $request->user();
        $reconciliation = ReconciliationSummary::withCounts(Reconciliation::query()->whereKey($reconciliation->id))
            ->with('user:id,name')
            ->firstOrFail();

        $lines = $reconciliation->transactions()
            ->withCount('receipts')
            ->orderBy('import_id')
            ->orderBy('row_number')
            ->get();

        return Inertia::render('reconciliations/Show', [
            'reconciliation' => [
                ...ReconciliationSummary::present($reconciliation),
                'owner' => $reconciliation->user->name,
                'mine' => $reconciliation->user_id === $user->id,
                'can_submit' => $user->can('submit', $reconciliation)
                    && $reconciliation->lines > 0
                    && $reconciliation->done === $reconciliation->lines,
            ],
            'lines' => $lines->map(fn (Transaction $line) => [
                'id' => $line->id,
                'row_number' => $line->row_number,
                'date' => $line->transacted_on->format('Y-m-d'),
                'description' => $line->description,
                'amount' => $line->amount,
                'status' => $line->status->value,
                'purpose' => $line->purpose,
                'receipts' => $line->receipts_count,
                'no_receipt' => $line->no_receipt,
            ]),
        ]);
    }
}
