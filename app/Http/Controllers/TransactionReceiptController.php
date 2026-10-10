<?php

namespace App\Http\Controllers;

use App\Enums\ReceiptSource;
use App\Models\Transaction;
use App\Receipts\ReceiptStore;
use App\Receipts\ReceiptType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TransactionReceiptController extends Controller
{
    public function store(Request $request, Transaction $transaction, ReceiptStore $store): RedirectResponse
    {
        Gate::authorize('update', $transaction);

        $data = $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:10'],
            'files.*' => ReceiptType::rules(),
            'source' => ['required', Rule::in(['camera', 'upload'])],
        ], ReceiptType::messages());

        $files = $request->file('files');

        DB::transaction(function () use ($request, $transaction, $store, $data, $files) {
            $line = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('update', $line);

            foreach ($files as $file) {
                $store->storeUpload($file, $request->user(), ReceiptSource::from($data['source']), $line);
            }

            $line->refreshStatus();
            $line->save();

            activity('lines')
                ->causedBy($request->user())
                ->performedOn($line)
                ->event('receipts_added')
                ->withProperties(['count' => count($files)])
                ->log("Added receipts to row {$line->row_number}");
        });

        return back()->with('success', count($files) === 1 ? 'Receipt added.' : count($files).' receipts added.');
    }
}
