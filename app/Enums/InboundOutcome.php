<?php

namespace App\Enums;

enum InboundOutcome: string
{
    case Accepted = 'accepted';
    case UnknownSender = 'unknown_sender';
    case NoAttachments = 'no_attachments';
    case RejectedType = 'rejected_type';
}
