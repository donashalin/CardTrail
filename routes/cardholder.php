<?php

use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\ReconciliationController;
use App\Http\Controllers\TransactionCommentController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

// Cardholder screens. Policies decide what each person may see; these routes only require sign-in.
Route::middleware('auth')->group(function () {
    Route::get('reconciliations/{reconciliation}', [ReconciliationController::class, 'show'])->name('reconciliations.show');

    Route::get('transactions/{transaction}', [TransactionController::class, 'show'])->name('transactions.show');
    Route::put('transactions/{transaction}', [TransactionController::class, 'update'])->name('transactions.update');
    Route::post('transactions/{transaction}/comments', [TransactionCommentController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('transactions.comments.store');

    Route::get('receipts/{receipt}', [ReceiptController::class, 'show'])->name('receipts.show');
    Route::delete('receipts/{receipt}', [ReceiptController::class, 'destroy'])->name('receipts.destroy');
});
