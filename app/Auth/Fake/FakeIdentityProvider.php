<?php

namespace App\Auth\Fake;

use App\Auth\ExternalIdentity;
use App\Auth\IdentityProvider;
use App\Auth\IdentityProviderException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Local/testing stand-in for Microsoft 365: a form that lets you pick who to "be". */
class FakeIdentityProvider implements IdentityProvider
{
    public const SESSION_KEY = 'fake_identity';

    public function redirect(): Response
    {
        return redirect()->route('identity.fake');
    }

    public function user(Request $request): ExternalIdentity
    {
        $data = $request->session()->pull(self::SESSION_KEY);

        if (! is_array($data) || empty($data['email']) || empty($data['external_id'])) {
            throw new IdentityProviderException('No fake identity in the session.');
        }

        return new ExternalIdentity(Str::lower(trim($data['email'])), $data['external_id']);
    }
}
