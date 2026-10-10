<?php

namespace App\Http\Controllers\Auth;

use App\Auth\Fake\FakeIdentityProvider;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/** Only routed in local and testing. Plays the part of the Microsoft sign-in screen. */
class FakeIdentityController extends Controller
{
    public function show(Request $request): Response
    {
        return Inertia::render('auth/FakeIdentity', [
            'email' => $request->session()->get('login.email'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'external_id' => ['nullable', 'string', 'max:255'],
        ]);

        $email = Str::lower(trim($data['email']));

        $request->session()->put(FakeIdentityProvider::SESSION_KEY, [
            'email' => $email,
            'external_id' => $data['external_id'] ?? 'fake-'.sha1($email),
        ]);

        return redirect()->route('identity.callback');
    }
}
