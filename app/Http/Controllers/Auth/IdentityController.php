<?php

namespace App\Http\Controllers\Auth;

use App\Auth\IdentityProvider;
use App\Auth\IdentityProviderException;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class IdentityController extends Controller
{
    public function redirect(IdentityProvider $provider): Response
    {
        return $provider->redirect();
    }

    public function callback(Request $request, IdentityProvider $provider): RedirectResponse
    {
        try {
            $identity = $provider->user($request);
        } catch (IdentityProviderException $e) {
            report($e);

            return $this->failed();
        }

        $user = User::where('external_id', $identity->externalId)->first()
            ?? User::where('email', $identity->email)->first();

        $externalIdClashes = $user?->external_id !== null && $user->external_id !== $identity->externalId;

        if (! $user || ! $user->active || ! $user->use_microsoft || $externalIdClashes) {
            return $this->failed();
        }

        if ($user->external_id === null) {
            $user->forceFill(['external_id' => $identity->externalId])->save();
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget('login.email');

        activity('auth')
            ->causedBy($user)
            ->performedOn($user)
            ->event('login')
            ->log('Signed in with Microsoft 365');

        return redirect()->intended(route('dashboard', absolute: false));
    }

    private function failed(): RedirectResponse
    {
        return redirect()->route('login')
            ->with('error', "We couldn't sign you in with Microsoft 365. Ask an admin to check your account.");
    }
}
