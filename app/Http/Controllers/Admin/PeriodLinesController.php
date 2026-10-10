<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Period;
use App\Models\Reconciliation;
use App\Models\Transaction;
use App\Models\User;
use App\Periods\CannotReassign;
use App\Periods\TransactionAssigner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PeriodLinesController extends Controller
{
    public function index(Request $request, Period $period): Response
    {
        $unassignedCount = $period->unassignedTransactions()->count();
        $owner = (string) $request->query('owner', $unassignedCount > 0 ? 'unassigned' : 'all');

        $lines = $period->transactions()
            ->with('reconciliation.user:id,name')
            ->when($owner === 'unassigned', fn ($query) => $query->whereNull('reconciliation_id'))
            ->when(ctype_digit($owner), fn ($query) => $query->whereHas('reconciliation', fn ($query) => $query->where('user_id', (int) $owner)))
            ->orderBy('import_id')
            ->orderBy('row_number')
            ->get()
            ->map(fn (Transaction $line) => [
                'id' => $line->id,
                'row_number' => $line->row_number,
                'date' => $line->transacted_on->format('Y-m-d'),
                'description' => $line->description,
                'amount' => $line->amount,
                'card_ref' => $line->card_ref,
                'status' => $line->status->value,
                'owner' => $line->reconciliation?->user?->only(['id', 'name']),
            ]);

        return Inertia::render('admin/periods/Lines', [
            'period' => [...$period->only(['id', 'label']), 'status' => $period->status->value],
            'owner' => $owner,
            'lines' => $lines,
            'unassigned_count' => $unassignedCount,
            'owners' => $period->reconciliations()->with('user:id,name')->withCount('transactions')->get()
                ->map(fn (Reconciliation $reconciliation) => ['id' => $reconciliation->user->id, 'name' => $reconciliation->user->name, 'lines' => $reconciliation->transactions_count])
                ->sortBy(fn (array $entry) => mb_strtolower($entry['name']))
                ->values(),
            'cardholders' => User::query()->where('active', true)->where('is_cardholder', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, Period $period, TransactionAssigner $assigner): RedirectResponse
    {
        $data = $request->validate([
            'transaction_ids' => ['required', 'array', 'min:1'],
            'transaction_ids.*' => ['integer'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $to = isset($data['user_id']) ? User::find($data['user_id']) : null;

        try {
            $moved = $assigner->assign($period, array_map('intval', $data['transaction_ids']), $to, $request->user());
        } catch (CannotReassign $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Moved {$moved} ".Str::plural('line', $moved).' to '.($to?->name ?? 'Unassigned').'.');
    }
}
