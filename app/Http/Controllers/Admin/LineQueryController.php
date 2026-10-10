<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Reviews\CannotReview;
use App\Reviews\LineReviewer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LineQueryController extends Controller
{
    public function __invoke(Request $request, Transaction $transaction, LineReviewer $reviewer): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']], ['body.required' => 'Say what you need from the cardholder.']);

        try {
            $reviewer->query($transaction, $request->user(), $data['body']);
        } catch (CannotReview $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Queried row {$transaction->row_number}. The month is back with the cardholder.");
    }
}
