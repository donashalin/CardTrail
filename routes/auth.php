<?php

use App\Http\Controllers\Auth\AcceptInvitationController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\FakeIdentityController;
use App\Http\Controllers\Auth\IdentityController;
use App\Http\Controllers\Auth\LoginEmailController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::post('login/email', LoginEmailController::class)
        ->middleware('throttle:20,1')
        ->name('login.email');

    Route::get('login/password', [AuthenticatedSessionController::class, 'createPassword'])
        ->name('login.password');

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('password.store');

    Route::get('auth/identity/redirect', [IdentityController::class, 'redirect'])
        ->name('identity.redirect');

    Route::get('auth/identity/callback', [IdentityController::class, 'callback'])
        ->middleware('throttle:20,1')
        ->name('identity.callback');

    Route::get('invite/{token}', [AcceptInvitationController::class, 'show'])->name('invite.show');
    Route::post('invite', [AcceptInvitationController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('invite.store');

    if (app()->environment('local', 'testing')) {
        Route::get('auth/fake-identity', [FakeIdentityController::class, 'show'])->name('identity.fake');
        Route::post('auth/fake-identity', [FakeIdentityController::class, 'store'])->name('identity.fake.store');
    }
});

Route::middleware('auth')->group(function () {
    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
