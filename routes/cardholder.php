<?php

use App\Http\Controllers\ReconciliationController;
use Illuminate\Support\Facades\Route;

// Cardholder screens. Policies decide what each person may see; these routes only require sign-in.
Route::middleware('auth')->group(function () {
    Route::get('reconciliations/{reconciliation}', [ReconciliationController::class, 'show'])->name('reconciliations.show');
});
