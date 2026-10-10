<?php

use App\Auth\Fake\FakeIdentityProvider;
use App\Mail\Inbound\EmlMailSource;

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

    /*
    | Email-in receipts. "eml" reads .eml files from a folder (development).
    | A mailbox driver is added later when IT grants access; set "schedule"
    | to true to poll every five minutes.
    */
    'inbound' => [
        'driver' => env('CARDTRAIL_INBOUND_DRIVER', 'eml'),
        'eml_path' => env('CARDTRAIL_INBOUND_EML_PATH'),
        'schedule' => (bool) env('CARDTRAIL_INBOUND_SCHEDULE', false),

        'drivers' => [
            'eml' => EmlMailSource::class,
        ],
    ],

    /*
    | Push services browsers subscribe through (Chrome/Edge/Android, Firefox,
    | Safari/iOS, Windows). Subscriptions pointing anywhere else are refused.
    */
    'push_hosts' => [
        'fcm.googleapis.com',
        '.push.services.mozilla.com',
        '.push.apple.com',
        '.notify.windows.com',
    ],

];
