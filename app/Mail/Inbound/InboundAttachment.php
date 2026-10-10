<?php

namespace App\Mail\Inbound;

final readonly class InboundAttachment
{
    public function __construct(
        public string $filename,
        public string $contents,
    ) {}
}
