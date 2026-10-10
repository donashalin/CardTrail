<?php

namespace App\Mail\Inbound;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\Header\DateHeader;
use ZBateson\MailMimeParser\Message;

/** Development driver: reads one .eml file or every .eml file in a folder. Files are left where they are. */
final class EmlMailSource implements InboundMailSource
{
    public function __construct(private string $path) {}

    public function fetch(): iterable
    {
        if (is_dir($this->path)) {
            $files = glob(rtrim($this->path, '/').'/*.eml') ?: [];
            sort($files);
        } elseif (is_file($this->path)) {
            $files = [$this->path];
        } else {
            throw new InvalidArgumentException("There’s no .eml file or folder at {$this->path}.");
        }

        foreach ($files as $file) {
            yield self::parse((string) file_get_contents($file), $file);
        }
    }

    public function markProcessed(InboundEmail $email): void
    {
        // Nothing to do: message ids stop a file being processed twice.
    }

    public static function parse(string $raw, mixed $reference = null): InboundEmail
    {
        $message = Message::from($raw, true);

        $from = $message->getHeader('From');
        $email = $from instanceof AddressHeader ? $from->getEmail() : null;
        $date = $message->getHeader('Date');
        $sent = $date instanceof DateHeader ? $date->getDateTimeImmutable() : null;
        $id = trim((string) $message->getHeaderValue('Message-ID'), "<> \t");

        // Images the body shows inline (signature logos and the like) are referenced as cid: in the HTML. Photos
        // attached "inline" by phones aren't referenced, so they're kept.
        preg_match_all('/cid:([^"\'\s>)]+)/i', (string) $message->getHtmlContent(), $matches);
        $embedded = array_map('strtolower', $matches[1]);

        $attachments = [];
        foreach ($message->getAllAttachmentParts() as $part) {
            $contentId = strtolower(trim((string) $part->getHeaderValue('Content-ID'), "<> \t"));

            if ($contentId !== '' && in_array($contentId, $embedded, true)) {
                continue;
            }

            $contents = $part->getBinaryContentStream()?->getContents();

            if ($contents !== null && $contents !== '') {
                $attachments[] = new InboundAttachment($part->getFilename() ?: 'attachment', $contents);
            }
        }

        return new InboundEmail(
            messageId: Str::limit($id !== '' ? $id : 'sha256:'.hash('sha256', $raw), 250, ''),
            from: $email ? Str::lower(trim($email)) : null,
            subject: ($subject = $message->getSubject()) !== null ? Str::limit($subject, 250, '') : null,
            receivedAt: $sent ? CarbonImmutable::instance($sent)->utc() : null,
            attachments: $attachments,
            reference: $reference,
        );
    }
}
