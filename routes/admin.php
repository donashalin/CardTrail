<?php

use App\Http\Controllers\Admin\ClosePeriodController;
use App\Http\Controllers\Admin\ImportController;
use App\Http\Controllers\Admin\ImportMappingController;
use App\Http\Controllers\Admin\ImportPreviewController;
use App\Http\Controllers\Admin\LineApprovalController;
use App\Http\Controllers\Admin\LineQueryController;
use App\Http\Controllers\Admin\PeriodController;
use App\Http\Controllers\Admin\PeriodLinesController;
use App\Http\Controllers\Admin\PublishPeriodController;
use App\Http\Controllers\Admin\ReconciliationApprovalController;
use App\Http\Controllers\Admin\ReviewController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\UserInvitationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::get('users/create', [UserController::class, 'create'])->name('users.create');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
    Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::post('users/{user}/invite', [UserInvitationController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('users.invite');

    Route::get('periods', [PeriodController::class, 'index'])->name('periods.index');
    Route::get('periods/create', [PeriodController::class, 'create'])->name('periods.create');
    Route::post('periods', [PeriodController::class, 'store'])->name('periods.store');
    Route::get('periods/{period}', [PeriodController::class, 'show'])->name('periods.show');
    Route::get('periods/{period}/edit', [PeriodController::class, 'edit'])->name('periods.edit');
    Route::put('periods/{period}', [PeriodController::class, 'update'])->name('periods.update');
    Route::post('periods/{period}/publish', PublishPeriodController::class)->name('periods.publish');
    Route::post('periods/{period}/close', ClosePeriodController::class)->name('periods.close');

    Route::get('periods/{period}/imports/create', [ImportController::class, 'create'])->name('imports.create');
    Route::post('periods/{period}/imports', [ImportController::class, 'store'])->name('imports.store');
    Route::delete('imports/{import}', [ImportController::class, 'destroy'])->name('imports.destroy');
    Route::get('imports/{import}/mapping', [ImportMappingController::class, 'edit'])->name('imports.mapping.edit');
    Route::put('imports/{import}/mapping', [ImportMappingController::class, 'update'])->name('imports.mapping.update');
    Route::get('imports/{import}/preview', [ImportPreviewController::class, 'show'])->name('imports.preview');
    Route::post('imports/{import}/confirm', [ImportPreviewController::class, 'store'])->name('imports.confirm');

    Route::get('periods/{period}/lines', [PeriodLinesController::class, 'index'])->name('periods.lines');
    Route::put('periods/{period}/lines/assignment', [PeriodLinesController::class, 'update'])->name('periods.lines.assign');

    Route::get('reconciliations/{reconciliation}/review', [ReviewController::class, 'show'])->name('reconciliations.review');
    Route::post('reconciliations/{reconciliation}/approve', ReconciliationApprovalController::class)->name('reconciliations.approve');
    Route::post('lines/{transaction}/approve', LineApprovalController::class)->name('lines.approve');
    Route::post('lines/{transaction}/query', LineQueryController::class)->name('lines.query');
});
