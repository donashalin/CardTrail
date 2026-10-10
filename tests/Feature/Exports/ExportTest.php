<?php

use App\Enums\ExportStatus;
use App\Enums\ImportStatus;
use App\Events\ExportFailed;
use App\Events\ExportReady;
use App\Exports\ExportBuilder;
use App\Jobs\BuildExport;
use App\Models\Export;
use App\Models\Period;
use App\Models\Receipt;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('receipts');
    Event::fake([ExportReady::class, ExportFailed::class]);
    $this->admin = User::factory()->admin()->create(['is_cardholder' => false]);
    $this->sam = User::factory()->create(['name' => 'Sam Cardholder']);
    $this->jo = User::factory()->create(['name' => 'Jo Manager']);
    $this->period = Period::factory()->published()->create(['label' => 'September 2026']);
    $this->import = importFromCsv(statementCsv([
        ['03/10/2026', 'DEPOT CAFE', 'S CARDHOLDER', '1001', '12.50'],
        ['04/10/2026', 'TRAIN', 'S CARDHOLDER', '1001', '45.20'],
        ['05/10/2026', 'HOTEL', 'J MANAGER', '1002', '120.00'],
    ]), ['period_id' => $this->period->id, 'status' => ImportStatus::Confirmed, 'original_filename' => 'amex-september.csv']);
});

function receiptOn(Transaction $line, string $extension, string $contents): Receipt
{
    $path = "{$line->reconciliation->user_id}/".uniqid().".{$extension}";
    Storage::disk('receipts')->put($path, $contents);

    return Receipt::factory()->attachedTo($line)->create(['path' => $path, 'original_name' => "original.{$extension}"]);
}

/** @return array<string, string> entry name => contents */
function zipEntries(string $bytes): array
{
    $path = tempnam(sys_get_temp_dir(), 'zip');
    file_put_contents($path, $bytes);
    $zip = new ZipArchive;
    $zip->open($path);
    $entries = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entries[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
    }

    $zip->close();
    unlink($path);
    ksort($entries);

    return $entries;
}

function downloadExport(Export $export): string
{
    return test()->actingAs(test()->admin)->get(route('admin.exports.show', $export))->assertOk()->streamedContent();
}

it('exports the whole period: filled spreadsheet at the root and a receipts folder per person', function () {
    $cafe = lineFor($this->sam, ['row_number' => 2, 'purpose' => 'Supplier meeting'], $this->period);
    $train = lineFor($this->sam, ['row_number' => 3, 'purpose' => 'Site visit', 'no_receipt' => true, 'no_receipt_reason' => 'Ticket machine'], $this->period);
    $hotel = lineFor($this->jo, ['row_number' => 4, 'purpose' => 'Conference hotel'], $this->period);
    receiptOn($cafe, 'pdf', 'cafe-pdf');
    receiptOn($cafe, 'jpg', 'cafe-photo');
    receiptOn($hotel, 'pdf', 'hotel-pdf');

    $this->actingAs($this->admin)->post(route('admin.exports.store', $this->period))->assertRedirect()->assertSessionHas('success');

    $export = Export::sole();
    expect($export)->status->toBe(ExportStatus::Ready)->filename->toBe('September 2026.zip');
    Event::assertDispatched(ExportReady::class);
    expect(Activity::query()->where('event', 'exported')->exists())->toBeTrue();

    $entries = zipEntries(downloadExport($export));
    expect(array_keys($entries))->toBe(['Jo Manager/Row4.pdf', 'Sam Cardholder/Row2-2.jpg', 'Sam Cardholder/Row2.pdf', 'amex-september.csv'])
        ->and($entries['Sam Cardholder/Row2.pdf'])->toBe('cafe-pdf')
        ->and($entries['amex-september.csv'])
        ->toContain('DEPOT CAFE,S CARDHOLDER,1001,12.50,Supplier meeting')
        ->toContain('TRAIN,S CARDHOLDER,1001,45.20,Site visit (No receipt: Ticket machine)')
        ->toContain('HOTEL,J MANAGER,1002,120.00,Conference hotel');
});

it('exports one manager into their own folder with only their purposes filled in', function () {
    $cafe = lineFor($this->sam, ['row_number' => 2, 'purpose' => 'Supplier meeting'], $this->period);
    lineFor($this->jo, ['row_number' => 4, 'purpose' => 'Conference hotel'], $this->period);
    receiptOn($cafe, 'pdf', 'cafe-pdf');

    $this->actingAs($this->admin)->post(route('admin.exports.store', $this->period), ['user_id' => $this->sam->id]);

    $export = Export::sole();
    $entries = zipEntries(downloadExport($export));

    expect($export->filename)->toBe('September 2026 - Sam Cardholder.zip')
        ->and(array_keys($entries))->toBe(['September 2026 - Sam Cardholder/Row2.pdf', 'September 2026 - Sam Cardholder/amex-september.csv'])
        ->and($entries['September 2026 - Sam Cardholder/amex-september.csv'])
        ->toContain('Supplier meeting')
        ->not->toContain('Conference hotel');
});

it('prefixes receipts and same-named spreadsheets from a second import', function () {
    $second = importFromCsv(statementCsv([['06/10/2026', 'FUEL', 'S CARDHOLDER', '1001', '60.00']]), [
        'period_id' => $this->period->id, 'status' => ImportStatus::Confirmed, 'original_filename' => 'amex-september.csv',
    ]);
    $first = lineFor($this->sam, ['row_number' => 2, 'purpose' => 'Supplier meeting'], $this->period);
    $fuel = Transaction::factory()->for($second)->create(['row_number' => 2, 'purpose' => 'Van fuel', 'reconciliation_id' => $first->reconciliation_id]);
    receiptOn($first, 'pdf', 'first');
    receiptOn($fuel, 'pdf', 'fuel');

    $this->actingAs($this->admin)->post(route('admin.exports.store', $this->period));

    expect(array_keys(zipEntries(downloadExport(Export::sole()))))->toBe([
        'Import2 - amex-september.csv',
        'Sam Cardholder/Import2-Row2.pdf',
        'Sam Cardholder/Row2.pdf',
        'amex-september.csv',
    ]);
});

it('fails with a clear message rather than leaving a receipt out', function () {
    $cafe = lineFor($this->sam, ['row_number' => 2, 'purpose' => 'Supplier meeting'], $this->period);
    Receipt::factory()->attachedTo($cafe)->create(['path' => 'gone/missing.pdf']);
    $export = Export::factory()->for($this->period)->create(['requested_by' => $this->admin->id]);

    expect(fn () => app(Dispatcher::class)->dispatch(new BuildExport($export)))->toThrow(RuntimeException::class);

    expect($export->fresh())
        ->status->toBe(ExportStatus::Failed)
        ->error->toContain('row 2');
    Event::assertDispatched(ExportFailed::class);
});

it('only exports published or closed periods', function () {
    $draft = Period::factory()->create();

    $this->actingAs($this->admin)->post(route('admin.exports.store', $draft))->assertSessionHas('error');
    expect(Export::count())->toBe(0);
});

it('keeps downloads to admins and to finished exports', function () {
    $ready = Export::factory()->for($this->period)->create(['requested_by' => $this->admin->id, 'status' => ExportStatus::Ready, 'path' => 'exports/1/a.zip', 'filename' => 'September 2026.zip']);
    Storage::disk('local')->put('exports/1/a.zip', 'zip');
    $pending = Export::factory()->for($this->period)->create(['requested_by' => $this->admin->id]);

    $this->actingAs($this->sam)->get(route('admin.exports.show', $ready))->assertForbidden();
    $this->actingAs($this->admin)->get(route('admin.exports.show', $pending))->assertNotFound();
    $this->actingAs($this->admin)->get(route('admin.exports.show', $ready))->assertDownload('September 2026.zip');
});

it('lists recent exports on the period dashboard', function () {
    Export::factory()->for($this->period)->create(['requested_by' => $this->admin->id, 'user_id' => $this->sam->id, 'status' => ExportStatus::Ready, 'path' => 'exports/1/a.zip', 'filename' => 'x.zip']);

    $this->actingAs($this->admin)->get(route('admin.periods.show', $this->period))
        ->assertInertia(fn ($page) => $page
            ->where('exports.0.scope', 'Sam Cardholder')
            ->where('exports.0.status', 'ready')
            ->where('exports.0.download_url', route('admin.exports.show', Export::sole())));
});

it('deletes exports older than a week', function () {
    $old = Export::factory()->for($this->period)->create(['requested_by' => $this->admin->id, 'status' => ExportStatus::Ready, 'path' => 'exports/9/old.zip', 'created_at' => now()->subDays(8)]);
    $recent = Export::factory()->for($this->period)->create(['requested_by' => $this->admin->id]);
    Storage::disk('local')->put('exports/9/old.zip', 'zip');

    $this->artisan('exports:prune')->assertSuccessful();

    expect(Export::pluck('id')->all())->toBe([$recent->id]);
    Storage::disk('local')->assertMissing('exports/9/old.zip');
});

it('leaves no partial ZIP behind when a build fails', function () {
    $cafe = lineFor($this->sam, ['row_number' => 2, 'purpose' => 'Supplier meeting'], $this->period);
    receiptOn($cafe, 'pdf', 'cafe-pdf');
    $gone = lineFor($this->sam, ['row_number' => 3, 'purpose' => 'Site visit'], $this->period);
    Receipt::factory()->attachedTo($gone)->create(['path' => 'gone/missing.pdf']);
    $export = Export::factory()->for($this->period)->create(['requested_by' => $this->admin->id]);
    $before = glob(sys_get_temp_dir().'/export*') ?: [];

    expect(fn () => app(ExportBuilder::class)->build($export))->toThrow(RuntimeException::class, 'Row3');

    expect(glob(sys_get_temp_dir().'/export*') ?: [])->toBe($before);
});

it('gives the queue longer than an export may take before retrying it', function () {
    $export = Export::factory()->make();

    expect(config('queue.connections.database.retry_after'))->toBeGreaterThan((new BuildExport($export))->timeout);
});

it('explains when the uploaded statement is missing from storage', function () {
    lineFor($this->sam, ['row_number' => 2, 'purpose' => 'Supplier meeting'], $this->period);
    Storage::disk('local')->delete($this->import->stored_path);
    $export = Export::factory()->for($this->period)->create(['requested_by' => $this->admin->id]);

    expect(fn () => app(Dispatcher::class)->dispatch(new BuildExport($export)))->toThrow(RuntimeException::class);

    expect($export->fresh()->error)->toBe('The uploaded statement amex-september.csv is missing from storage, so its purposes can’t be filled in. Restore it from a backup, then export again.');
});

it('keeps unexpected error details out of the failure message', function () {
    $export = Export::factory()->for($this->period)->create(['requested_by' => $this->admin->id]);

    (new BuildExport($export))->failed(new RuntimeException('SQLSTATE[HY000]: connection refused at 10.0.0.1'));

    expect($export->fresh()->error)->toBe('Something went wrong while building the export. Try again, and if it keeps failing ask IT to check the logs.');
});

it('refreshes the export list without working out the rest of the dashboard', function () {
    lineFor($this->sam, [], $this->period);
    Export::factory()->for($this->period)->create(['requested_by' => $this->admin->id]);
    $version = $this->actingAs($this->admin)->get(route('admin.periods.show', $this->period))->viewData('page')['version'];
    DB::enableQueryLog();

    $this->get(route('admin.periods.show', $this->period), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) $version,
        'X-Inertia-Partial-Component' => 'admin/periods/Show',
        'X-Inertia-Partial-Data' => 'exports',
    ])->assertOk()->assertJsonCount(1, 'props.exports');

    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_contains($sql, 'reconciliations') || str_contains($sql, '"transactions"')))->toBeEmpty();
});
