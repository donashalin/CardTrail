<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PeriodStatus;
use App\Enums\ReconciliationStatus;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Receipt;
use App\Models\Reconciliation;
use App\Models\Transaction;
use App\Reconciliations\ReconciliationSummary;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReviewController extends Controller
{
    public function show(Request $request, Reconciliation $reconciliation): Response
    {
        $reconciliation = ReconciliationSummary::withCounts(Reconciliation::query()->whereKey($reconciliation->id))
            ->with('user:id,name,active')
            ->firstOrFail();

        $lines = $reconciliation->transactions()->withCount('receipts')->orderBy('import_id')->orderBy('row_number')->get();
        $approvable = $lines->where('status', TransactionStatus::Done)->count();
        $selected = $lines->firstWhere('id', (int) $request->query('line'))
            ?? $lines->firstWhere('status', TransactionStatus::Done)
            ?? $lines->first();

        return Inertia::render('admin/review/Show', [
            'reconciliation' => [
                ...ReconciliationSummary::present($reconciliation),
                'owner' => $reconciliation->user->name,
                'owner_active' => $reconciliation->user->active,
                'approvable' => $approvable,
                'can_approve_all' => $reconciliation->status === ReconciliationStatus::Submitted
                    && $reconciliation->period->status === PeriodStatus::Published,
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
            'selected' => $selected ? $this->detail($reconciliation, $selected, $request) : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function detail(Reconciliation $reconciliation, Transaction $line, Request $request): array
    {
        $line->load(['receipts', 'comments.user:id,name']);
        $open = $reconciliation->period->status === PeriodStatus::Published;

        return [
            'id' => $line->id,
            'row_number' => $line->row_number,
            'date' => $line->transacted_on->format('Y-m-d'),
            'description' => $line->description,
            'amount' => $line->amount,
            'card_ref' => $line->card_ref,
            'extra' => (object) ($line->extra ?? []),
            'purpose' => $line->purpose,
            'no_receipt' => $line->no_receipt,
            'no_receipt_reason' => $line->no_receipt_reason,
            'status' => $line->status->value,
            'receipts' => $line->receipts->map(fn (Receipt $receipt) => $receipt->toListItem()),
            'comments' => $line->comments->map(fn (Comment $comment) => [
                'id' => $comment->id,
                'body' => $comment->body,
                'author' => $comment->user->name,
                'mine' => $comment->user_id === $request->user()->id,
                'created_at' => $comment->created_at->toIso8601String(),
            ]),
            'can_approve' => $open
                && $reconciliation->status === ReconciliationStatus::Submitted
                && $line->status === TransactionStatus::Done,
            'can_query' => $open
                && in_array($reconciliation->status, [ReconciliationStatus::Submitted, ReconciliationStatus::Approved], true)
                && in_array($line->status, [TransactionStatus::Done, TransactionStatus::Approved], true),
            'can_comment' => $open,
        ];
    }
}
