<?php

namespace App\Mail\Inbound;

/**
 * Where emailed receipts come from. v1 has the `eml` driver; a Microsoft Graph mailbox driver
 * can be added later once IT grants access (spec §6.4) without touching the rest of the app.
 */
interface InboundMailSource
{
    /** @return iterable<InboundEmail> */
    public function fetch(): iterable;

    /** Called after an email has been recorded, so the source can move or flag it. */
    public function markProcessed(InboundEmail $email): void;
}
