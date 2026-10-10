<?php

namespace App\Http\Controllers;

use App\Enums\ReceiptSource;
use App\Models\Receipt;
use App\Receipts\ReceiptStore;
use App\Receipts\ReceiptType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ReceiptInboxController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('receipts/Inbox', [
            'receipts' => $request->user()->receipts()->inInbox()->latest('id')->get()->map(fn (Receipt $receipt) => $receipt->toListItem()),
            'address' => config('cardtrail.receipts.address'),
        ]);
    }

    public function store(Request $request, ReceiptStore $store): RedirectResponse
    {
        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:20'],
            'files.*' => ReceiptType::rules(),
            'source' => ['nullable', Rule::in(['camera', 'upload'])],
        ], ReceiptType::messages());

        $files = $request->file('files');
        $source = $request->input('source') === 'camera' ? ReceiptSource::Camera : ReceiptSource::Bulk;

        $store->transaction(function () use ($files, $request, $source, $store) {
            foreach ($files as $file) {
                $store->storeUpload($file, $request->user(), $source);
            }
        });

        return back()->with('success', 'Added '.count($files).' '.Str::plural('receipt', count($files)).' to your inbox.');
    }
}
