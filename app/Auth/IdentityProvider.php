<?php

namespace App\Auth;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The only way CardTrail talks to an external sign-in service.
 * Drivers are listed in config/cardtrail.php and chosen with CARDTRAIL_IDENTITY_DRIVER.
 */
interface IdentityProvider
{
    /** Start the external sign-in (usually a redirect to the provider). */
    public function redirect(): Response;

    /**
     * Read the verified identity from the provider's callback request.
     *
     * @throws IdentityProviderException when the sign-in can't be verified
     */
    public function user(Request $request): ExternalIdentity;
}
