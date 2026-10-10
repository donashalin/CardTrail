<?php

use App\Enums\ReceiptSource;
use App\Enums\ReconciliationStatus;
use App\Enums\TransactionStatus;
use App\Models\Receipt;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('receipts');
    $this->sam = User::factory()->create();
});

function uploadTo($line, array $files, string $source = 'upload')
{
    return test()->actingAs(test()->sam)->post(route('transactions.receipts.store', $line), ['files' => $files, 'source' => $source]);
}

function fakePdf(string $name = 'invoice.pdf'): UploadedFile
{
    return UploadedFile::fake()->create($name, 120, 'application/pdf');
}

it('stores an uploaded receipt privately and completes the line', function () {
    $line = lineFor($this->sam, ['purpose' => 'Hotel for site visit']);

    uploadTo($line, [fakePdf('Hotel Invoice.pdf')])->assertRedirect()->assertSessionHas('success');

    $receipt = Receipt::sole();
    expect($receipt)
        ->user_id->toBe($this->sam->id)
        ->transaction_id->toBe($line->id)
        ->original_name->toBe('Hotel Invoice.pdf')
        ->mime->toBe('application/pdf')
        ->source->toBe(ReceiptSource::Upload)
        ->and($receipt->path)->toStartWith($this->sam->id.'/')->toEndWith('.pdf')
        ->and($line->fresh()->status)->toBe(TransactionStatus::Done);
    Storage::disk('receipts')->assertExists($receipt->path);
});

it('records photos taken with the camera and accepts several files at once', function () {
    $line = lineFor($this->sam);

    uploadTo($line, [UploadedFile::fake()->image('photo.jpg', 800, 600), UploadedFile::fake()->image('back.png', 400, 300)], 'camera');

    expect(Receipt::count())->toBe(2)
        ->and(Receipt::query()->where('source', ReceiptSource::Camera)->count())->toBe(2);
});

it('refuses files that are not receipts or are too big', function () {
    $line = lineFor($this->sam);

    uploadTo($line, [UploadedFile::fake()->create('notes.txt', 1, 'text/plain')])->assertSessionHasErrors('files.0');
    uploadTo($line, [UploadedFile::fake()->create('huge.pdf', 20481, 'application/pdf')])->assertSessionHasErrors('files.0');

    expect(Receipt::count())->toBe(0);
});

it('refuses a file whose contents do not match its name', function () {
    $path = tempnam(sys_get_temp_dir(), 'receipt');
    file_put_contents($path, '<html><script>alert(1)</script></html>');

    uploadTo(lineFor($this->sam), [new UploadedFile($path, 'invoice.pdf', null, null, true)])->assertSessionHasErrors('files.0');

    expect(Receipt::count())->toBe(0);
});

it('refuses uploads to someone else’s line or a submitted month', function () {
    uploadTo(lineFor(User::factory()->create()), [fakePdf()])->assertForbidden();

    $line = lineFor($this->sam);
    $line->reconciliation->update(['status' => ReconciliationStatus::Submitted]);
    uploadTo($line, [fakePdf()])->assertForbidden();

    expect(Receipt::count())->toBe(0);
});

it('shows a receipt only to its owner and admins', function () {
    uploadTo(lineFor($this->sam), [fakePdf('cafe.pdf')]);
    $receipt = Receipt::sole();

    $response = $this->actingAs($this->sam)->get(route('receipts.show', $receipt))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Content-Disposition'))->toStartWith('inline');

    $this->actingAs(User::factory()->create())->get(route('receipts.show', $receipt))->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->get(route('receipts.show', $receipt))->assertOk();
});

it('downloads Word documents rather than opening them', function () {
    uploadTo(lineFor($this->sam), [UploadedFile::fake()->create('notes.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')]);

    $response = $this->actingAs($this->sam)->get(route('receipts.show', Receipt::sole()))->assertOk();

    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment');
});

it('deletes a receipt and reopens the line', function () {
    $line = lineFor($this->sam, ['purpose' => 'Hotel']);
    uploadTo($line, [fakePdf()]);
    $receipt = Receipt::sole();

    $this->actingAs($this->sam)->delete(route('receipts.destroy', $receipt))->assertRedirect();

    expect(Receipt::count())->toBe(0)->and($line->fresh()->status)->toBe(TransactionStatus::Todo);
    Storage::disk('receipts')->assertMissing($receipt->path);
});

it('refuses deleting someone else’s receipt', function () {
    $receipt = Receipt::factory()->for(User::factory()->create())->create();

    $this->actingAs($this->sam)->delete(route('receipts.destroy', $receipt))->assertForbidden();

    expect($receipt->fresh())->not->toBeNull();
});

it('shrinks large photos to 2000 pixels on the long edge', function () {
    uploadTo(lineFor($this->sam), [UploadedFile::fake()->image('wide.jpg', 3000, 1500)]);

    $receipt = Receipt::sole();
    $stored = Storage::disk('receipts')->get($receipt->path);

    expect(array_slice(getimagesizefromstring($stored), 0, 2))->toBe([2000, 1000])
        ->and($receipt->size)->toBe(strlen($stored));
});

it('keeps a HEIC photo as it is when the server cannot convert it', function () {
    Log::spy();

    uploadTo(lineFor($this->sam), [UploadedFile::fake()->create('IMG_0042.HEIC', 50, 'image/heic')]);

    expect(Receipt::sole())->mime->toBe('image/heic')->path->toEndWith('.heic');
    Log::shouldHaveReceived('warning')->once();
})->skip(fn () => class_exists(Imagick::class) && Imagick::queryFormats('HEIC') !== [], 'Imagick can convert HEIC here');
