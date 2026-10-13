<?php

use App\Enums\ReceiptSource;
use App\Enums\ReconciliationStatus;
use App\Enums\TransactionStatus;
use App\Jobs\ProcessReceiptImage;
use App\Models\Receipt;
use App\Models\User;
use App\Receipts\ImageOptimiser;
use App\Receipts\ReceiptFiler;
use App\Receipts\ReceiptFolders;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
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
        ->and($receipt->path)->toBe(ReceiptFolders::for($this->sam, $line).'/Hotel Invoice.pdf')
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

it('refuses a .heic file that is really something else', function () {
    $path = tempnam(sys_get_temp_dir(), 'receipt');
    file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg"><image href="text:/etc/passwd"/></svg>');

    uploadTo(lineFor($this->sam), [new UploadedFile($path, 'receipt.heic', null, null, true)])->assertSessionHasErrors('files.0');

    expect(Receipt::count())->toBe(0);
});

it('accepts a genuine HEIC file', function () {
    $path = tempnam(sys_get_temp_dir(), 'receipt');
    file_put_contents($path, "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic".str_repeat("\x00", 64));

    uploadTo(lineFor($this->sam), [new UploadedFile($path, 'IMG_0001.heic', null, null, true)])->assertSessionHasNoErrors();

    expect(Receipt::sole()->mime)->toBe('image/heic');
});

it('keeps the original photo when saving the smaller copy fails', function () {
    Queue::fake();
    uploadTo(lineFor($this->sam), [UploadedFile::fake()->image('wide.jpg', 3000, 1500)]);
    $receipt = Receipt::sole();
    $original = Storage::disk('receipts')->get($receipt->path);
    Receipt::updating(fn () => throw new RuntimeException('Database went away'));

    expect(fn () => (new ProcessReceiptImage($receipt))->handle(app(ImageOptimiser::class), app(ReceiptFiler::class)))->toThrow(RuntimeException::class);

    expect(Storage::disk('receipts')->get($receipt->fresh()->path))->toBe($original)
        ->and(Storage::disk('receipts')->allFiles())->toHaveCount(1);
});

it('explains when a file is bigger than the server accepts', function () {
    $path = tempnam(sys_get_temp_dir(), 'receipt');
    file_put_contents($path, '%PDF-1.4');

    uploadTo(lineFor($this->sam), [new UploadedFile($path, 'big.pdf', 'application/pdf', UPLOAD_ERR_INI_SIZE, true)])
        ->assertSessionHasErrors(['files.0' => 'A file was bigger than the server accepts, or didn’t finish uploading. Try a smaller file, or ask IT to raise the upload limit.']);
});

it('explains when an upload is too big for the server altogether', function () {
    $this->actingAs($this->sam)
        ->from(route('transactions.show', $line = lineFor($this->sam)))
        ->call('POST', route('transactions.receipts.store', $line), [], [], [], ['CONTENT_LENGTH' => PHP_INT_MAX])
        ->assertRedirect(route('transactions.show', $line))
        ->assertSessionHas('error', 'Those files are too big to upload together. Try fewer at a time.');
});

it('refuses deleting a receipt once the month is submitted', function () {
    $line = lineFor($this->sam, ['purpose' => 'Hotel', 'status' => TransactionStatus::Done]);
    $receipt = Receipt::factory()->attachedTo($line)->create();
    $line->reconciliation->update(['status' => ReconciliationStatus::Submitted]);

    $this->actingAs($this->sam)->delete(route('receipts.destroy', $receipt))->assertForbidden();

    expect($receipt->fresh())->not->toBeNull();
});

it('serves a receipt whose name has backslashes in it', function () {
    $receipt = Receipt::factory()->for($this->sam)->create(['original_name' => 'C:\Scans\fuel.pdf']);
    Storage::disk('receipts')->put($receipt->path, '%PDF-1.4');

    $this->actingAs($this->sam)->get(route('receipts.show', $receipt))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'inline; filename="C:-Scans-fuel.pdf"');
});
