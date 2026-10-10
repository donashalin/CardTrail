<?php

use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\ReceiptInboxController;
use App\Http\Controllers\ReceiptMatchController;
use App\Http\Controllers\ReconciliationController;
use App\Http\Controllers\SubmitReconciliationController;
use App\Http\Controllers\TransactionCommentController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TransactionReceiptController;
use Illuminate\Support\Facades\Route;

// Cardholder screens. Policies decide what each person may see; these routes only require sign-in.
Route::middleware('auth')->group(function () {
    Route::get('reconciliations/{reconciliation}', [ReconciliationController::class, 'show'])->name('reconciliations.show');
    Route::post('reconciliations/{reconciliation}/submit', SubmitReconciliationController::class)->name('reconciliations.submit');

    Route::get('transactions/{transaction}', [TransactionController::class, 'show'])->name('transactions.show');
    Route::put('transactions/{transaction}', [TransactionController::class, 'update'])->name('transactions.update');
    Route::post('transactions/{transaction}/comments', [TransactionCommentController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('transactions.comments.store');
    Route::post('transactions/{transaction}/receipts', [TransactionReceiptController::class, 'store'])
        ->middleware('throttle:60,1')
        ->name('transactions.receipts.store');

    Route::get('receipts', [ReceiptInboxController::class, 'index'])->name('receipts.index');
    Route::post('receipts', [ReceiptInboxController::class, 'store'])->middleware('throttle:30,1')->name('receipts.store');
    Route::get('receipts/{receipt}', [ReceiptController::class, 'show'])->name('receipts.show');
    Route::delete('receipts/{receipt}', [ReceiptController::class, 'destroy'])->name('receipts.destroy');
    Route::get('receipts/{receipt}/match', [ReceiptMatchController::class, 'show'])->name('receipts.match');
    Route::put('receipts/{receipt}/match', [ReceiptMatchController::class, 'store'])->name('receipts.match.store');
    Route::delete('receipts/{receipt}/match', [ReceiptMatchController::class, 'destroy'])->name('receipts.match.destroy');
});
