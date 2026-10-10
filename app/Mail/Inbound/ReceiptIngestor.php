<?php

namespace App\Mail\Inbound;

use App\Enums\InboundOutcome;
use App\Enums\ReceiptSource;
use App\Models\InboundMessage;
use App\Models\User;
use App\Receipts\ReceiptStore;
use Illuminate\Database\UniqueConstraintViolationException;

/** Records an inbound email and turns its accepted attachments into the sender's inbox receipts. */
final class ReceiptIngestor
{
    public function __construct(private ReceiptStore $store) {}

    /** @return InboundMessage|null null when this message was processed before */
    public function ingest(InboundEmail $email, string $driver): ?InboundMessage
    {
        if (InboundMessage::query()->where('message_id', $email->messageId)->exists()) {
            return null;
        }

        try {
            return $this->store->transaction(fn () => $this->record($email, $driver));
        } catch (UniqueConstraintViolationException) {
            return null; // Another run recorded it a moment ago.
        }
    }

    private function record(InboundEmail $email, string $driver): InboundMessage
    {
        $sender = $email->from === null ? null : User::query()
            ->active()
            ->where('is_cardholder', true)
            ->where('email', $email->from)
            ->first();

        $message = InboundMessage::create([
            'source_driver' => $driver,
            'message_id' => $email->messageId,
            'from_email' => $email->from,
            'subject' => $email->subject,
            'received_at' => $email->receivedAt,
            'user_id' => $sender?->id,
            'outcome' => InboundOutcome::NoAttachments,
            'attachment_count' => count($email->attachments),
        ]);

        if (! $sender) {
            $message->update(['outcome' => InboundOutcome::UnknownSender]);

            return $message;
        }

        if ($email->attachments === []) {
            return $message;
        }

        $accepted = 0;
        foreach ($email->attachments as $attachment) {
            if ($this->store->storeContents($attachment->contents, $attachment->filename, $sender, ReceiptSource::Email, $message->id)) {
                $accepted++;
            }
        }

        $message->update([
            'outcome' => $accepted > 0 ? InboundOutcome::Accepted : InboundOutcome::RejectedType,
            'accepted_count' => $accepted,
        ]);

        return $message;
    }
}
