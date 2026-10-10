<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class LoginEmailController extends Controller
{
    /**
     * Decide whether this email signs in with a password or the external identity provider.
     * Unknown and inactive emails get the password step so the response never reveals who has an account.
     */
    public function __invoke(Request $request): Response
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $email = $request->validate(['email' => ['required', 'string', 'email', 'max:255']])['email'];

        $request->session()->put('login.email', $email);

        $user = User::where('email', $email)->first();

        if ($user?->active && $user->use_microsoft) {
            return Inertia::location(route('identity.redirect'));
        }

        return redirect()->route('login.password');
    }
}
