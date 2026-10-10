<?php

use App\Enums\InboundOutcome;
use App\Enums\ReceiptSource;
use App\Models\InboundMessage;
use App\Models\Receipt;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('receipts');
    $this->sam = User::factory()->create(['email' => 'sam@cardtrail.test']);
    $this->dir = sys_get_temp_dir().'/cardtrail-eml-'.uniqid();
    File::ensureDirectoryExists($this->dir);
});

afterEach(fn () => File::deleteDirectory($this->dir));

/** Write an invented email to the test folder. Each attachment is [filename, contents, mime type]. */
function eml(string $from, array $attachments = [], ?string $id = null): string
{
    $boundary = 'b'.uniqid();
    $id ??= uniqid().'@mail.example.test';
    $body = "--{$boundary}\r\nContent-Type: text/plain\r\n\r\nReceipt attached.\r\n";

    foreach ($attachments as [$name, $contents, $mime]) {
        $body .= "--{$boundary}\r\nContent-Type: {$mime}; name=\"{$name}\"\r\nContent-Disposition: attachment; filename=\"{$name}\"\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            .chunk_split(base64_encode($contents))."\r\n";
    }

    $raw = "From: {$from}\r\nTo: receipts@cardtrail.test\r\nSubject: Taxi receipt\r\nDate: Fri, 09 Oct 2026 10:15:00 +0100\r\n"
        ."Message-ID: <{$id}>\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n\r\n{$body}--{$boundary}--\r\n";

    $path = test()->dir.'/'.md5($id).'.eml';
    file_put_contents($path, $raw);

    return $path;
}

function pdfBytes(): string
{
    return "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
}

function jpegBytes(): string
{
    $image = imagecreatetruecolor(20, 20);
    ob_start();
    imagejpeg($image);

    return (string) ob_get_clean();
}

it('turns attachments from a cardholder into inbox receipts', function () {
    $path = eml('Sam Cardholder <SAM@cardtrail.test>', [['taxi.pdf', pdfBytes(), 'application/pdf'], ['photo.jpg', jpegBytes(), 'image/jpeg']], 'abc@mail.example.test');

    $this->artisan('receipts:ingest', ['path' => $path])->assertSuccessful();

    $message = InboundMessage::sole();
    expect($message)
        ->message_id->toBe('abc@mail.example.test')
        ->from_email->toBe('sam@cardtrail.test')
        ->subject->toBe('Taxi receipt')
        ->user_id->toBe($this->sam->id)
        ->outcome->toBe(InboundOutcome::Accepted)
        ->attachment_count->toBe(2)
        ->accepted_count->toBe(2)
        ->and($message->received_at->toIso8601String())->toBe('2026-10-09T09:15:00+00:00');

    expect(Receipt::query()->inInbox()->whereBelongsTo($this->sam)->where('source', ReceiptSource::Email)->where('inbound_message_id', $message->id)->count())->toBe(2);
});

it('records mail from unknown senders and leavers without making receipts', function () {
    $leaver = User::factory()->inactive()->create(['email' => 'gone@cardtrail.test']);
    eml('stranger@example.test', [['a.pdf', pdfBytes(), 'application/pdf']]);
    eml('gone@cardtrail.test', [['b.pdf', pdfBytes(), 'application/pdf']]);

    $this->artisan('receipts:ingest', ['path' => $this->dir])->assertSuccessful();

    expect(InboundMessage::pluck('outcome')->all())->toBe([InboundOutcome::UnknownSender, InboundOutcome::UnknownSender])
        ->and(InboundMessage::whereNotNull('user_id')->count())->toBe(0)
        ->and(Receipt::count())->toBe(0);
});

it('records mail with no attachments, or only files that aren’t receipts', function () {
    eml('sam@cardtrail.test', [], 'empty@mail.example.test');
    eml('sam@cardtrail.test', [['tool.exe', 'MZ', 'application/octet-stream'], ['fake.pdf', '<html></html>', 'application/pdf']], 'bad@mail.example.test');

    $this->artisan('receipts:ingest', ['path' => $this->dir])->assertSuccessful();

    expect(InboundMessage::firstWhere('message_id', 'empty@mail.example.test')->outcome)->toBe(InboundOutcome::NoAttachments)
        ->and(InboundMessage::firstWhere('message_id', 'bad@mail.example.test'))
        ->outcome->toBe(InboundOutcome::RejectedType)
        ->accepted_count->toBe(0)
        ->and(Receipt::count())->toBe(0);
});

it('processes each email only once', function () {
    $path = eml('sam@cardtrail.test', [['taxi.pdf', pdfBytes(), 'application/pdf']]);

    $this->artisan('receipts:ingest', ['path' => $path])->assertSuccessful();
    $this->artisan('receipts:ingest', ['path' => $path])->expectsOutputToContain('already processed: 1')->assertSuccessful();

    expect(InboundMessage::count())->toBe(1)->and(Receipt::count())->toBe(1);
});

it('reads the configured folder when no path is given', function () {
    config(['cardtrail.inbound.eml_path' => $this->dir]);
    eml('sam@cardtrail.test', [['taxi.pdf', pdfBytes(), 'application/pdf']]);

    $this->artisan('receipts:ingest')->assertSuccessful();

    expect(Receipt::count())->toBe(1);
});

it('explains what to do when there is nothing to read', function () {
    config(['cardtrail.inbound.eml_path' => null]);

    $this->artisan('receipts:ingest')->expectsOutputToContain('CARDTRAIL_INBOUND_EML_PATH')->assertFailed();
});

it('ships a demo email that the eml driver accepts', function () {
    User::factory()->create(['email' => 'jo@cardtrail.test']);
    $this->sam->update(['email' => 'sam@cardtrail.test']);

    $this->artisan('receipts:ingest', ['path' => resource_path('demo/demo-receipt.eml')])->assertSuccessful();

    expect(InboundMessage::sole()->outcome)->toBe(InboundOutcome::Accepted);
});

it('skips images embedded in the email body, such as signature logos, but keeps attached photos', function () {
    $pdf = chunk_split(base64_encode(pdfBytes()));
    $png = chunk_split(base64_encode((function () {
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($image);

        return ob_get_clean();
    })()));
    $jpg = chunk_split(base64_encode(jpegBytes()));
    $raw = "From: sam@cardtrail.test\r\nTo: receipts@cardtrail.test\r\nSubject: Taxi\r\nMessage-ID: <outlook@mail.example.test>\r\nMIME-Version: 1.0\r\n"
        ."Content-Type: multipart/mixed; boundary=\"outer\"\r\n\r\n"
        ."--outer\r\nContent-Type: multipart/related; boundary=\"rel\"\r\n\r\n"
        ."--rel\r\nContent-Type: text/html\r\n\r\n<p>Receipt attached</p><img src=\"cid:image001.png@01DB\">\r\n"
        ."--rel\r\nContent-Type: image/png; name=\"image001.png\"\r\nContent-Disposition: inline; filename=\"image001.png\"\r\nContent-ID: <image001.png@01DB>\r\nContent-Transfer-Encoding: base64\r\n\r\n{$png}\r\n"
        ."--rel--\r\n"
        ."--outer\r\nContent-Type: application/pdf; name=\"taxi.pdf\"\r\nContent-Disposition: attachment; filename=\"taxi.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\n{$pdf}\r\n"
        ."--outer\r\nContent-Type: image/jpeg; name=\"IMG_0042.jpg\"\r\nContent-Disposition: inline; filename=\"IMG_0042.jpg\"\r\nContent-Transfer-Encoding: base64\r\n\r\n{$jpg}\r\n"
        ."--outer--\r\n";
    file_put_contents($this->dir.'/outlook.eml', $raw);

    $this->artisan('receipts:ingest', ['path' => $this->dir])->assertSuccessful();

    expect(Receipt::pluck('original_name')->sort()->values()->all())->toBe(['IMG_0042.jpg', 'taxi.pdf'])
        ->and(InboundMessage::sole())->attachment_count->toBe(2)->accepted_count->toBe(2);
});
