<?php

namespace App\Mail\Inbound;

use Carbon\CarbonImmutable;

/** An email as any mail source sees it. `reference` is the source's own handle (a file path, a mailbox id). */
final readonly class InboundEmail
{
    /** @param  list<InboundAttachment>  $attachments */
    public function __construct(
        public string $messageId,
        public ?string $from,
        public ?string $subject,
        public ?CarbonImmutable $receivedAt,
        public array $attachments,
        public mixed $reference = null,
    ) {}
}
