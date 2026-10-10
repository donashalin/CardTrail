<?php

namespace App\Http\Controllers;

use App\Models\Receipt;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReceiptController extends Controller
{
    /** Receipts are never publicly addressable; every view passes through the policy. */
    public function show(Receipt $receipt): StreamedResponse
    {
        Gate::authorize('view', $receipt);

        return Storage::disk($receipt->disk)->response(
            $receipt->path,
            $receipt->downloadName(),
            [
                'Content-Type' => $receipt->mime,
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, max-age=3600',
            ],
            $receipt->viewableInline() ? 'inline' : 'attachment',
        );
    }

    public function destroy(Request $request, Receipt $receipt): RedirectResponse
    {
        Gate::authorize('manage', $receipt);

        DB::transaction(function () use ($request, $receipt) {
            $line = $receipt->transaction_id
                ? Transaction::query()->whereKey($receipt->transaction_id)->lockForUpdate()->first()
                : null;

            $receipt->delete();

            if ($line) {
                $line->refreshStatus();
                $line->save();
            }

            activity('receipts')
                ->causedBy($request->user())
                ->event('receipt_deleted')
                ->withProperties(['name' => $receipt->original_name, 'transaction_id' => $line?->id])
                ->log("Deleted receipt {$receipt->original_name}");
        });

        return back()->with('success', 'Receipt deleted.');
    }
}
