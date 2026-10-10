<?php

use App\Auth\Fake\FakeIdentityProvider;

return [

    /*
    | External sign-in for users with "Use Microsoft 365" ticked.
    | "fake" works only in local and testing environments.
    */
    'identity' => [
        'driver' => env('CARDTRAIL_IDENTITY_DRIVER', 'fake'),

        'drivers' => [
            'fake' => FakeIdentityProvider::class,
        ],
    ],

];
