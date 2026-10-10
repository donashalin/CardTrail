<?php

use App\Enums\TransactionStatus;
use App\Models\Receipt;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->sam = User::factory()->create();
});

it('needs a purpose plus a receipt or a no-receipt reason', function (array $attributes, bool $withReceipt, bool $done) {
    $line = lineFor($this->sam, ['amount' => 1250, ...$attributes]);

    if ($withReceipt) {
        Receipt::factory()->attachedTo($line)->create();
    }

    expect($line->meetsDoneRule())->toBe($done);
})->with([
    'purpose and receipt' => [['purpose' => 'Client lunch'], true, true],
    'purpose only' => [['purpose' => 'Client lunch'], false, false],
    'receipt only' => [[], true, false],
    'blank purpose with receipt' => [['purpose' => '   '], true, false],
    'no receipt with a reason' => [['purpose' => 'Parking', 'no_receipt' => true, 'no_receipt_reason' => 'Machine gave none'], false, true],
    'no receipt without a reason' => [['purpose' => 'Parking', 'no_receipt' => true, 'no_receipt_reason' => '  '], false, false],
    'reason but box not ticked' => [['purpose' => 'Parking', 'no_receipt_reason' => 'Lost it'], false, false],
]);

it('accepts a credit with just a purpose', function () {
    $line = lineFor($this->sam, ['amount' => -1250, 'purpose' => 'Refund of hotel deposit']);

    expect($line->meetsDoneRule())->toBeTrue();
});

it('moves between to do and done as the rule is met', function () {
    $line = lineFor($this->sam, ['purpose' => 'Client lunch']);
    $line->refreshStatus();
    expect($line->status)->toBe(TransactionStatus::Todo);

    Receipt::factory()->attachedTo($line)->create();
    $line->refreshStatus();
    expect($line->status)->toBe(TransactionStatus::Done);
});

it('clears a query only once the line meets the rule again', function () {
    $line = lineFor($this->sam, ['status' => TransactionStatus::Queried]);
    $line->refreshStatus();
    expect($line->status)->toBe(TransactionStatus::Queried);

    $line->fill(['purpose' => 'Train to depot', 'no_receipt' => true, 'no_receipt_reason' => 'Ticket machine broken']);
    $line->refreshStatus();
    expect($line->status)->toBe(TransactionStatus::Done);
});

it('never changes an approved line', function () {
    $line = lineFor($this->sam, ['status' => TransactionStatus::Approved]);
    $line->refreshStatus();

    expect($line->status)->toBe(TransactionStatus::Approved);
});

it('deletes the stored file along with the receipt', function () {
    Storage::fake('receipts');
    Storage::disk('receipts')->put('7/a.pdf', '%PDF-1.4');
    $receipt = Receipt::factory()->create(['path' => '7/a.pdf']);

    $receipt->delete();

    Storage::disk('receipts')->assertMissing('7/a.pdf');
});

it('returns a receipt to the inbox when its line is deleted', function () {
    $line = lineFor($this->sam);
    $receipt = Receipt::factory()->attachedTo($line)->create();

    $line->delete();

    expect($receipt->fresh()->transaction_id)->toBeNull()
        ->and(Receipt::query()->inInbox()->count())->toBe(1);
});

it('names a converted receipt after its stored type', function () {
    $receipt = Receipt::factory()->make(['original_name' => 'IMG_0042.HEIC', 'path' => '7/abc.jpg']);

    expect($receipt->downloadName())->toBe('IMG_0042.jpg');
});
