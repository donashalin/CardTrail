<?php

use App\Enums\ImportStatus;
use App\Imports\Parsing\HeaderSignature;
use App\Models\CardIdentifier;
use App\Models\ColumnMapping;
use App\Models\Import;
use App\Models\Period;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->admin = User::factory()->admin()->create(['is_cardholder' => false]);
    $this->period = Period::factory()->create();
    $this->sam = User::factory()->create(['name' => 'Sam Cardholder']);
    CardIdentifier::factory()->for($this->sam)->create(['last4' => '1001']);
});

function demoCsv(): string
{
    return statementCsv([
        ['03/09/2026', 'TRAINLINE', 'S CARDHOLDER', 'XXXX-XXXXXX-01001', '45.20'],
        ['04/09/2026', 'MYSTERY', 'NOBODY', '9999', '5.00'],
    ]);
}

function uploadCsv(string $csv, string $name = 'statement.csv')
{
    return test()->actingAs(test()->admin)->post(route('admin.imports.store', test()->period), [
        'file' => UploadedFile::fake()->createWithContent($name, $csv),
    ]);
}

it('is only for admins', function () {
    $this->actingAs($this->sam)->get(route('admin.imports.create', $this->period))->assertForbidden();
});

it('shows the upload page', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.imports.create', $this->period))
        ->assertInertia(fn ($page) => $page->component('admin/imports/Create')->where('period.id', $this->period->id));
});

it('stores an upload privately and goes to column mapping the first time', function () {
    $response = uploadCsv(demoCsv());

    $import = Import::sole();
    $response->assertRedirect(route('admin.imports.mapping.edit', $import));
    expect($import->status)->toBe(ImportStatus::Draft)
        ->and($import->original_filename)->toBe('statement.csv')
        ->and($import->uploaded_by)->toBe($this->admin->id)
        ->and($import->header_row)->toBe(1)
        ->and($import->stored_path)->toStartWith("imports/{$this->period->id}/");
    Storage::disk('local')->assertExists($import->stored_path);
});

it('goes straight to the preview when the layout matches a saved mapping', function () {
    ColumnMapping::create([
        'name' => 'Amex export',
        'header_signature' => HeaderSignature::for(['Date', 'Description', 'Card Member', 'Account #', 'Amount']),
        'header_row' => 1,
        'mapping' => ['date' => 0, 'description' => 1, 'cardholder' => 2, 'card' => 3, 'amount' => 4, 'purpose' => null],
    ]);

    $response = uploadCsv(demoCsv());

    $import = Import::sole();
    $response->assertRedirect(route('admin.imports.preview', $import))->assertSessionHas('success');
    expect($import->column_mapping['amount'])->toBe(4);
});

it('rejects files it cannot read and removes them', function () {
    uploadCsv('', 'statement.csv')->assertSessionHasErrors('file');

    expect(Import::count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('rejects the wrong file type', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.imports.store', $this->period), ['file' => UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf')])
        ->assertSessionHasErrors('file');
});

it('does not accept uploads into a closed period', function () {
    $this->period->update(['status' => 'closed']);

    uploadCsv(demoCsv())->assertForbidden();
});

it('shows the mapping step with guessed columns and sample rows', function () {
    uploadCsv(demoCsv());
    $import = Import::sole();

    $this->actingAs($this->admin)
        ->get(route('admin.imports.mapping.edit', $import))
        ->assertInertia(fn ($page) => $page
            ->component('admin/imports/Mapping')
            ->where('upload.id', $import->id)
            ->where('header_row', 1)
            ->where('header_labels', ['Date', 'Description', 'Card Member', 'Account #', 'Amount'])
            ->where('mapping.date', 0)
            ->where('mapping.card', 3)
            ->where('mapping.cardholder', 2)
            ->has('samples', 2)
            ->where('samples.0.cells.1', 'TRAINLINE'));
});

it('lets the admin pick a different header row', function () {
    $this->actingAs($this->admin)->post(route('admin.imports.store', $this->period), [
        'file' => UploadedFile::fake()->createWithContent('statement.csv', "Corporate card statement\n".demoCsv()),
    ]);
    $import = Import::sole();

    $this->actingAs($this->admin)
        ->get(route('admin.imports.mapping.edit', ['import' => $import, 'header_row' => 2]))
        ->assertInertia(fn ($page) => $page->where('header_row', 2)->where('header_labels.0', 'Date'));
});

it('saves the mapping, remembers it, and goes to the preview', function () {
    uploadCsv(demoCsv());
    $import = Import::sole();

    $this->actingAs($this->admin)
        ->put(route('admin.imports.mapping.update', $import), [
            'header_row' => 1,
            'mapping' => ['date' => 0, 'description' => 1, 'amount' => 4, 'card' => 3, 'cardholder' => 2, 'purpose' => null],
            'save_as' => 'Amex export',
        ])
        ->assertRedirect(route('admin.imports.preview', $import));

    expect($import->fresh()->column_mapping)->toBe(['date' => 0, 'description' => 1, 'amount' => 4, 'card' => 3, 'cardholder' => 2, 'purpose' => null])
        ->and(ColumnMapping::sole()->name)->toBe('Amex export');
});

it('validates the mapping', function (array $mapping, string $errorKey) {
    uploadCsv(demoCsv());

    $this->actingAs($this->admin)
        ->put(route('admin.imports.mapping.update', Import::sole()), ['header_row' => 1, 'mapping' => $mapping])
        ->assertSessionHasErrors($errorKey);
})->with([
    'missing amount' => [['date' => 0, 'description' => 1, 'amount' => null], 'mapping.amount'],
    'column out of range' => [['date' => 0, 'description' => 1, 'amount' => 9], 'mapping.amount'],
    'same column twice' => [['date' => 0, 'description' => 0, 'amount' => 4], 'mapping'],
]);

it('shows the preview with matches, problems and a duplicate-file warning', function () {
    $earlier = Import::factory()->for($this->period)->confirmed()->create(['original_filename' => 'first.csv']);
    uploadCsv(demoCsv());
    $import = Import::latest('id')->first();
    $earlier->update(['file_hash' => $import->file_hash]);
    $import->update(['column_mapping' => ['date' => 0, 'description' => 1, 'cardholder' => 2, 'card' => 3, 'amount' => 4, 'purpose' => null]]);

    $this->actingAs($this->admin)
        ->get(route('admin.imports.preview', $import))
        ->assertInertia(fn ($page) => $page
            ->component('admin/imports/Preview')
            ->where('upload.id', $import->id)
            ->has('rows', 2)
            ->where('rows.0.user_name', 'Sam Cardholder')
            ->where('rows.1.user_id', null)
            ->where('summary.unassigned', 1)
            ->where('duplicate_file.original_filename', 'first.csv'));
});

it('sends an import without a mapping back to the mapping step', function () {
    uploadCsv(demoCsv());

    $this->actingAs($this->admin)
        ->get(route('admin.imports.preview', Import::sole()))
        ->assertRedirect(route('admin.imports.mapping.edit', Import::sole()));
});

it('confirms the import and returns to the period', function () {
    uploadCsv(demoCsv());
    $import = Import::sole();
    $import->update(['column_mapping' => ['date' => 0, 'description' => 1, 'cardholder' => 2, 'card' => 3, 'amount' => 4, 'purpose' => null]]);

    $this->actingAs($this->admin)
        ->post(route('admin.imports.confirm', $import), ['skip' => [], 'include_duplicates' => [], 'fixes' => []])
        ->assertRedirect(route('admin.periods.show', $this->period))
        ->assertSessionHas('success', 'Imported 2 lines (1 unassigned).');

    expect(Transaction::count())->toBe(2);
});

it('explains which rows still need attention', function () {
    uploadCsv(statementCsv([['bad date', 'SHOP', 'S CARDHOLDER', '1001', '1.00']]));
    $import = Import::sole();
    $import->update(['column_mapping' => ['date' => 0, 'description' => 1, 'cardholder' => 2, 'card' => 3, 'amount' => 4, 'purpose' => null]]);

    $this->actingAs($this->admin)
        ->post(route('admin.imports.confirm', $import), [])
        ->assertSessionHasErrors(['rows' => 'Rows 2 still have problems. Fix or skip them before confirming.']);

    $this->actingAs($this->admin)
        ->post(route('admin.imports.confirm', $import), ['fixes' => [2 => ['date' => '03/09/2026']]])
        ->assertSessionHasNoErrors();
    expect(Transaction::count())->toBe(1);
});

it('does not show the wizard for a confirmed import', function () {
    $import = Import::factory()->for($this->period)->confirmed()->create();

    $this->actingAs($this->admin)->get(route('admin.imports.mapping.edit', $import))->assertNotFound();
    $this->actingAs($this->admin)->get(route('admin.imports.preview', $import))->assertNotFound();
});

it('removes an import', function () {
    uploadCsv(demoCsv());

    $this->actingAs($this->admin)
        ->delete(route('admin.imports.destroy', Import::sole()))
        ->assertRedirect(route('admin.periods.show', $this->period))
        ->assertSessionHas('success');

    expect(Import::count())->toBe(0);
});

it('explains why a confirmed import in a published period cannot be removed', function () {
    $import = Import::factory()->for(Period::factory()->published())->confirmed()->create();

    $this->actingAs($this->admin)
        ->delete(route('admin.imports.destroy', $import))
        ->assertSessionHas('error');

    expect(Import::count())->toBe(1);
});
