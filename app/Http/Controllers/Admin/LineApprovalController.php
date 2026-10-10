<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Reviews\CannotReview;
use App\Reviews\LineReviewer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LineApprovalController extends Controller
{
    public function __invoke(Request $request, Transaction $transaction, LineReviewer $reviewer): RedirectResponse
    {
        try {
            $line = $reviewer->approve($transaction, $request->user());
        } catch (CannotReview $e) {
            return back()->with('error', $e->getMessage());
        }

        $next = Transaction::query()
            ->where('reconciliation_id', $line->reconciliation_id)
            ->where('status', TransactionStatus::Done)
            ->orderBy('import_id')
            ->orderBy('row_number')
            ->value('id');

        return to_route('admin.reconciliations.review', [$line->reconciliation_id, 'line' => $next ?? $line->id])
            ->with('success', "Approved row {$line->row_number}.");
    }
}
