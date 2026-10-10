<?php

namespace App\Auth;

/** A sign-in the external identity provider has verified. */
final readonly class ExternalIdentity
{
    public function __construct(
        public string $email,
        public string $externalId,
    ) {}
}
