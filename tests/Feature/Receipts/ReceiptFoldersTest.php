<?php

use App\Models\Import;
use App\Models\Period;
use App\Models\Receipt;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('receipts');
    $this->sam = User::factory()->create(['name' => 'Sam Cardholder']);
    $this->period = Period::factory()->published()->create(['month' => '2026-09-01', 'label' => 'September 2026']);
});

function pdf(string $name = 'Fuel receipt.pdf'): UploadedFile
{
    return UploadedFile::fake()->create($name, 50, 'application/pdf');
}

function receiptFiles(): array
{
    return collect(Storage::disk('receipts')->allFiles())->sort()->values()->all();
}

it('files a receipt uploaded on a line under person, period and row', function () {
    $line = lineFor($this->sam, ['row_number' => 9], $this->period);

    $this->actingAs($this->sam)->post(route('transactions.receipts.store', $line), ['files' => [pdf()], 'source' => 'upload']);

    expect(Receipt::sole()->path)->toBe('sam-cardholder/2026-09/Row9/Fuel receipt.pdf')
        ->and(receiptFiles())->toBe(['sam-cardholder/2026-09/Row9/Fuel receipt.pdf']);
});

it('keeps both files when two receipts on a line share a name', function () {
    $line = lineFor($this->sam, ['row_number' => 9], $this->period);

    $this->actingAs($this->sam)->post(route('transactions.receipts.store', $line), ['files' => [pdf(), pdf()], 'source' => 'upload']);

    expect(receiptFiles())->toBe(['sam-cardholder/2026-09/Row9/Fuel receipt (2).pdf', 'sam-cardholder/2026-09/Row9/Fuel receipt.pdf']);
});

it('files inbox receipts in the inbox folder and moves them when matched and unmatched', function () {
    $line = lineFor($this->sam, ['row_number' => 12, 'amount' => 4500], $this->period);

    $this->actingAs($this->sam)->post(route('receipts.store'), ['files' => [pdf('Hotel.pdf')]]);
    $receipt = Receipt::sole();
    expect($receipt->path)->toBe('sam-cardholder/Inbox/Hotel.pdf');

    $this->actingAs($this->sam)->put(route('receipts.match.store', $receipt), ['transaction_id' => $line->id]);
    expect($receipt->fresh()->path)->toBe('sam-cardholder/2026-09/Row12/Hotel.pdf')
        ->and(receiptFiles())->toBe(['sam-cardholder/2026-09/Row12/Hotel.pdf']);

    $this->actingAs($this->sam)->delete(route('receipts.match.destroy', $receipt));
    expect($receipt->fresh()->path)->toBe('sam-cardholder/Inbox/Hotel.pdf')
        ->and(receiptFiles())->toBe(['sam-cardholder/Inbox/Hotel.pdf']);
});

it('names rows from a second statement the way the export does', function () {
    Import::factory()->for($this->period)->confirmed()->create();
    $second = Import::factory()->for($this->period)->confirmed()->create();
    $line = lineFor($this->sam, ['row_number' => 4, 'import_id' => $second->id], $this->period);

    $this->actingAs($this->sam)->post(route('transactions.receipts.store', $line), ['files' => [pdf()], 'source' => 'upload']);

    expect(Receipt::sole()->path)->toBe('sam-cardholder/2026-09/Import2-Row4/Fuel receipt.pdf');
});

it('sends receipts back to the uploader’s inbox folder when their line is reassigned', function () {
    $admin = User::factory()->admin()->create();
    $jo = User::factory()->create(['name' => 'Jo Manager']);
    $line = lineFor($this->sam, ['row_number' => 9], $this->period);
    $this->actingAs($this->sam)->post(route('transactions.receipts.store', $line), ['files' => [pdf()], 'source' => 'upload']);

    $this->actingAs($admin)->put(route('admin.periods.lines.assign', $this->period), ['transaction_ids' => [$line->id], 'user_id' => $jo->id]);

    expect(Receipt::sole()->path)->toBe('sam-cardholder/Inbox/Fuel receipt.pdf')
        ->and(receiptFiles())->toBe(['sam-cardholder/Inbox/Fuel receipt.pdf']);
});

it('moves someone’s receipts to a new folder when their name changes', function () {
    $line = lineFor($this->sam, ['row_number' => 9], $this->period);
    $this->actingAs($this->sam)->post(route('transactions.receipts.store', $line), ['files' => [pdf()], 'source' => 'upload']);
    $this->actingAs($this->sam)->post(route('receipts.store'), ['files' => [pdf('Taxi.pdf')]]);

    $this->sam->update(['name' => 'Sam Smith']);

    expect(receiptFiles())->toBe(['sam-smith/2026-09/Row9/Fuel receipt.pdf', 'sam-smith/Inbox/Taxi.pdf']);
});

it('moves a period’s receipts when its month is corrected', function () {
    $line = lineFor($this->sam, ['row_number' => 9], $this->period);
    $this->actingAs($this->sam)->post(route('transactions.receipts.store', $line), ['files' => [pdf()], 'source' => 'upload']);

    $this->period->update(['month' => '2026-08-01']);

    expect(receiptFiles())->toBe(['sam-cardholder/2026-08/Row9/Fuel receipt.pdf']);
});

it('makes a safe file name from an awkward original name', function () {
    $line = lineFor($this->sam, ['row_number' => 9], $this->period);

    $this->actingAs($this->sam)->post(route('transactions.receipts.store', $line), ['files' => [pdf('.. fuel: "Shell" ?.pdf')], 'source' => 'upload']);

    expect(Receipt::sole()->path)->toBe('sam-cardholder/2026-09/Row9/fuel Shell.pdf');
});

it('uses a stand-in folder for a name with no letters it can use', function () {
    $nameless = User::factory()->create(['name' => '—']);
    $line = lineFor($nameless, ['row_number' => 3], $this->period);

    $this->actingAs($nameless)->post(route('transactions.receipts.store', $line), ['files' => [pdf()], 'source' => 'upload']);

    expect(Receipt::sole()->path)->toBe("user-{$nameless->id}/2026-09/Row3/Fuel receipt.pdf");
});

it('files a resized photo under its original name', function () {
    $line = lineFor($this->sam, ['row_number' => 9], $this->period);
    $photo = UploadedFile::fake()->image('IMG_0420.jpg', 3000, 2000);

    $this->actingAs($this->sam)->post(route('transactions.receipts.store', $line), ['files' => [$photo], 'source' => 'camera']);

    $receipt = Receipt::sole();
    expect($receipt->path)->toBe('sam-cardholder/2026-09/Row9/IMG_0420.jpg')
        ->and(receiptFiles())->toBe(['sam-cardholder/2026-09/Row9/IMG_0420.jpg'])
        ->and(getimagesizefromstring(Storage::disk('receipts')->get($receipt->path))[0])->toBe(2000);
});
