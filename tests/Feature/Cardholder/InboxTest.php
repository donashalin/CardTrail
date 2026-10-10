<?php

use App\Enums\ReceiptSource;
use App\Enums\ReconciliationStatus;
use App\Enums\TransactionStatus;
use App\Models\Period;
use App\Models\Receipt;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('receipts');
    $this->sam = User::factory()->create();
});

it('puts bulk uploads in the inbox', function () {
    $this->actingAs($this->sam)->post(route('receipts.store'), [
        'files' => [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'), UploadedFile::fake()->image('b.jpg')],
        'source' => 'upload',
    ])->assertRedirect()->assertSessionHas('success', 'Added 2 receipts to your inbox.');

    expect(Receipt::query()->inInbox()->whereBelongsTo($this->sam)->where('source', ReceiptSource::Bulk)->count())->toBe(2);
});

it('lists only the cardholder’s unmatched receipts and the email-in address', function () {
    config(['cardtrail.receipts.address' => 'receipts@cardtrail.test']);
    Receipt::factory()->for($this->sam)->create(['original_name' => 'mine.pdf']);
    Receipt::factory()->attachedTo(lineFor($this->sam))->create();
    Receipt::factory()->create(['original_name' => 'theirs.pdf']);

    $this->actingAs($this->sam)->get(route('receipts.index'))
        ->assertInertia(fn ($page) => $page
            ->component('receipts/Inbox')
            ->has('receipts', 1)
            ->where('receipts.0.name', 'mine.pdf')
            ->where('address', 'receipts@cardtrail.test'));
});

it('suggests open lines closest in date to the receipt, exact amounts first when given', function () {
    $receipt = Receipt::factory()->for($this->sam)->create(['created_at' => '2026-10-10 09:00:00']);
    $period = Period::factory()->published()->create();
    lineFor($this->sam, ['transacted_on' => '2026-09-01', 'amount' => 1250, 'description' => 'FAR'], $period);
    lineFor($this->sam, ['transacted_on' => '2026-10-09', 'amount' => 999, 'description' => 'NEAR'], $period);
    lineFor($this->sam, ['transacted_on' => '2026-10-01', 'amount' => 4000, 'description' => 'MIDDLE'], $period);
    lineFor($this->sam, ['transacted_on' => '2026-10-10', 'status' => TransactionStatus::Approved, 'description' => 'APPROVED'], $period);
    $submitted = lineFor($this->sam, ['transacted_on' => '2026-10-10', 'status' => TransactionStatus::Done, 'description' => 'SUBMITTED']);
    $submitted->reconciliation->update(['status' => ReconciliationStatus::Submitted]);
    lineFor(User::factory()->create(), ['transacted_on' => '2026-10-10', 'description' => 'SOMEONE ELSE']);

    $this->actingAs($this->sam)->get(route('receipts.match', $receipt))
        ->assertInertia(fn ($page) => $page
            ->component('receipts/Match')
            ->has('candidates', 3)
            ->where('candidates.0.description', 'NEAR')
            ->where('candidates.1.description', 'MIDDLE')
            ->where('candidates.2.description', 'FAR'));

    $this->actingAs($this->sam)->get(route('receipts.match', [$receipt, 'amount' => '£12.50']))
        ->assertInertia(fn ($page) => $page
            ->where('amount', '£12.50')
            ->where('candidates.0.description', 'FAR')
            ->where('candidates.1.description', 'NEAR'));
});

it('attaches an inbox receipt to a line', function () {
    $line = lineFor($this->sam, ['purpose' => 'Fuel']);
    $receipt = Receipt::factory()->for($this->sam)->create();

    $this->actingAs($this->sam)->put(route('receipts.match.store', $receipt), ['transaction_id' => $line->id])
        ->assertRedirect(route('transactions.show', $line));

    expect($receipt->fresh()->transaction_id)->toBe($line->id)
        ->and($line->fresh()->status)->toBe(TransactionStatus::Done);
});

it('refuses to attach to another person’s or a locked line, or to move another person’s receipt', function () {
    $receipt = Receipt::factory()->for($this->sam)->create();
    $locked = lineFor($this->sam, ['status' => TransactionStatus::Done]);
    $locked->reconciliation->update(['status' => ReconciliationStatus::Submitted]);

    $this->actingAs($this->sam)->put(route('receipts.match.store', $receipt), ['transaction_id' => lineFor(User::factory()->create())->id])->assertForbidden();
    $this->actingAs($this->sam)->put(route('receipts.match.store', $receipt), ['transaction_id' => $locked->id])->assertForbidden();
    $this->actingAs($this->sam)->put(route('receipts.match.store', Receipt::factory()->create()), ['transaction_id' => lineFor($this->sam)->id])->assertForbidden();

    expect($receipt->fresh()->transaction_id)->toBeNull();
});

it('moves a receipt from a line back to the inbox', function () {
    $line = lineFor($this->sam, ['purpose' => 'Fuel', 'status' => TransactionStatus::Done]);
    $receipt = Receipt::factory()->attachedTo($line)->create();

    $this->actingAs($this->sam)->delete(route('receipts.match.destroy', $receipt))->assertRedirect();

    expect($receipt->fresh()->transaction_id)->toBeNull()
        ->and($line->fresh()->status)->toBe(TransactionStatus::Todo);
});

it('ignores an amount that is not a single value', function () {
    $receipt = Receipt::factory()->for($this->sam)->create();

    $this->actingAs($this->sam)->get(route('receipts.match', $receipt).'?amount[]=12')->assertOk();
});

it('refuses moving a receipt to the inbox once the month is submitted', function () {
    $line = lineFor($this->sam, ['purpose' => 'Fuel', 'status' => TransactionStatus::Done]);
    $receipt = Receipt::factory()->attachedTo($line)->create();
    $line->reconciliation->update(['status' => ReconciliationStatus::Submitted]);

    $this->actingAs($this->sam)->delete(route('receipts.match.destroy', $receipt))->assertForbidden();

    expect($receipt->fresh()->transaction_id)->toBe($line->id);
});

it('stores none of the files when an upload fails partway', function () {
    $created = 0;
    Receipt::creating(function () use (&$created) {
        if (++$created === 2) {
            throw new RuntimeException('Database went away');
        }
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($this->sam)->post(route('receipts.store'), [
        'files' => [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'), UploadedFile::fake()->create('b.pdf', 10, 'application/pdf')],
    ]))->toThrow(RuntimeException::class);

    expect(Storage::disk('receipts')->allFiles())->toBe([])
        ->and(Receipt::count())->toBe(0);
});
