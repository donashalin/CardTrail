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

    /*
    | Receipt files. The disk must be private. "address" is the email-in
    | address shown to cardholders (blank hides it until IT sets one up).
    */
    'receipts' => [
        'disk' => env('CARDTRAIL_RECEIPTS_DISK', 'receipts'),
        'address' => env('CARDTRAIL_RECEIPTS_ADDRESS'),
    ],

];
