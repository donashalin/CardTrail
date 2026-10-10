# CardTrail Plan 3 — Cardholder Flow and Receipts Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cardholders open a published month, give every line a purpose and a receipt (photo, file, or from their receipt inbox) or a no-receipt reason, reply to queries, and submit. Their reconciliation then locks.

**Architecture:** `Transaction` owns the line-done rule and its status. Three policies (`TransactionPolicy`, `ReconciliationPolicy`, `ReceiptPolicy`) decide who may see and change what. `ReceiptStore` is the one way a receipt file reaches the private `receipts` disk, and Plan 5's email-in reuses it. A queued `ProcessReceiptImage` job downsizes photos. `ReconciliationSubmitter` submits under a row lock and fires `ReconciliationSubmitted`, which Plan 5 turns into admin notifications. Cardholder screens live in `routes/cardholder.php` and `resources/js/pages/{reconciliations,transactions,receipts}`.

**Tech Stack:** Laravel 12, Inertia 2, Vue 3 + TypeScript, Pest 3, GD + exif (installed locally; Imagick used when present), `@vueuse/core` (installed).

**Spec:** `docs/superpowers/specs/2026-10-10-cardtrail-design.md`. This plan covers §4.4–4.5, §5 Receipt and Comment, §5.1 line-done, edit-lock and submit rules, §6.3, §7 screens 1–4 and §9 policies.

**Builds on:** Plans 1–2 on `main`. Existing pieces this plan uses:
- Models and enums: `Period` (`status`, `due_date`), `Reconciliation` (`status`, `linesAdded()`), `Transaction` (`purpose`, `no_receipt`, `no_receipt_reason`, `status`, `amount` in pence), `PeriodStatus`, `ReconciliationStatus`, `TransactionStatus`.
- Services and parsers: `TransactionAssigner`, `AmountParser::parse()`.
- Factories: `Period::factory()->published()/closed()`, `Import::factory()->confirmed()`, `Reconciliation::factory()->submitted()/approved()`.
- Frontend: `FlashMessage.vue`, `lib/money.ts`, `AppSidebar.vue`.

## Global Constraints

- Everything in Plans 1–2's Global Constraints still applies: git author `donashalin <donashalin@gmail.com>`, never commit `.env`, invented data only, UK English, money in integer pence, `whereDate()` for date columns.
- Commit messages are plain. No Co-Authored-By, session or other AI attribution lines. The user is the sole author.
- Receipts: PDF, JPG, PNG, HEIC/HEIF, DOC, DOCX; max 20 MB each (20480 KB). Stored on private disk `receipts` at `{user_id}/{uuid}.{ext}`. Never on a public disk.
- Receipts are only ever served through `ReceiptController@show` after `ReceiptPolicy` allows it.
- No receipt is ever sent to any external service (no OCR, no AI).
- **Line done** = purpose filled AND (≥1 receipt OR (`no_receipt` AND reason filled)). Credits (negative amounts) need only a purpose.
- `queried` is set only by an admin (Plan 4). A cardholder edit clears it to `done` once the line meets the done rule again.
- Cardholders never see draft periods. Closed periods are read-only for everyone.
- Cardholder edits are blocked while their reconciliation is `submitted` or `approved`, except on `queried` lines. Approved lines are never editable.
- Domain events implement `ShouldDispatchAfterCommit`. This plan registers no listeners; Plan 5 does.
- Tests generate their own files at runtime with `UploadedFile::fake()` or temp files. No binary fixtures.

## Review Focus

1. **A cardholder guessing another person's IDs.** Changing the number in `/transactions/12` or `/receipts/7` must give 403, never someone else's line or invoice. Pinned in Task 2 (policies) and Tasks 4–6 (each controller).
2. **A file whose name lies about its contents.** An HTML page renamed `invoice.pdf` must be refused, because PDFs and images are shown inline in the browser. Pinned in Task 5.
3. **Editing after submitting.** Once submitted, the cardholder's autosave, uploads and receipt removals must all be refused, apart from lines the accountant queried. Pinned in Tasks 2, 4 and 5.
4. **Answering a query in words only.** When the accountant asks a question and the cardholder replies in the thread without changing anything, the line must stop being `queried`, so they can resubmit. Pinned in Task 4.
5. **Lines moved to someone else after receipts were attached.** The receipts belong to the person who uploaded them. They must go back to that person's inbox, not travel with the line to the new owner. Pinned in Task 7.

## Rulings made while planning

- **Amount in the inbox matcher.** When the cardholder types an amount, lines with exactly that amount come first. After that, lines are ordered by closeness of date and then by closeness of amount. Without an amount, lines are ordered by date only. The spec says "sorted by closeness of date…, then by amount if the user enters one". Putting exact amounts first is the more useful reading.
- **The no-receipt reason is kept when the box is unticked.** The rule ignores it unless the box is ticked, and nothing is lost if the cardholder ticks it again.
- **Comments on moved lines are deleted.** A query thread is between the accountant and the previous owner and shouldn't be shown to the new owner. The activity log keeps the record.
- **Imagick paths can't be tested on this machine** (Imagick isn't installed). GD and exif handle JPEG/PNG resizing and orientation. HEIC is stored unchanged with a logged warning unless Imagick with HEIC support is present, as §6.3 allows.

---

## File Structure

```
app/
  Enums/ReceiptSource.php                 # camera | upload | bulk | email
  Events/ReconciliationSubmitted.php
  Jobs/ProcessReceiptImage.php
  Models/Comment.php
  Models/Receipt.php
  Models/Transaction.php                  # + receipts, comments, done rule, status, editableByOwner
  Models/Reconciliation.php               # linesAdded() locks its row
  Models/User.php                         # + reconciliations(), receipts()
  Periods/TransactionAssigner.php         # receipts back to inbox, comments removed when lines move
  Policies/ReceiptPolicy.php
  Policies/ReconciliationPolicy.php
  Policies/TransactionPolicy.php
  Receipts/ImageOptimiser.php
  Receipts/ReceiptStore.php
  Receipts/ReceiptType.php                # allowed types, validation rules
  Reconciliations/CannotSubmit.php
  Reconciliations/ReconciliationSubmitter.php
  Reconciliations/ReconciliationSummary.php
  Http/Controllers/
    DashboardController.php
    ReceiptController.php                 # show (stream) + destroy
    ReceiptInboxController.php            # inbox + bulk upload
    ReceiptMatchController.php            # candidates, attach, detach
    ReconciliationController.php          # line list
    SubmitReconciliationController.php
    TransactionCommentController.php
    TransactionController.php             # detail + autosave
    TransactionReceiptController.php      # upload to a line
config/cardtrail.php                      # + receipts.disk, receipts.address
config/filesystems.php                    # + receipts disk
database/
  factories/{CommentFactory,ReceiptFactory}.php
  migrations/2026_10_12_000001_create_receipts_table.php
  migrations/2026_10_12_000002_create_comments_table.php
resources/js/
  components/{LineStatusBadge,ReconciliationStatusBadge,ProgressBar,ReceiptThumb,ReceiptPicker}.vue
  lib/receipts.ts
  types/cardtrail.ts
  pages/Dashboard.vue
  pages/reconciliations/Show.vue
  pages/transactions/Show.vue
  pages/receipts/{Inbox,Match}.vue
routes/cardholder.php
tests/Feature/Cardholder/{AccessTest,HomeTest,ReconciliationListTest,TransactionDetailTest,ReceiptUploadTest,InboxTest,SubmitTest}.php
tests/Feature/Models/LineRulesTest.php
```

---

### Task 1: Receipts, comments and the line-done rule

**Files:**
- Create: `app/Enums/ReceiptSource.php`, `app/Models/Receipt.php`, `app/Models/Comment.php`, both migrations, `database/factories/ReceiptFactory.php`, `database/factories/CommentFactory.php`
- Modify: `app/Models/Transaction.php`, `app/Models/User.php`, `config/filesystems.php`, `config/cardtrail.php`, `tests/Pest.php`
- Test: `tests/Feature/Models/LineRulesTest.php`

**Interfaces:**
- Produces:
  - `Receipt` with `user()`, `transaction()`, `scopeInInbox()`, `viewableInline(): bool`, `downloadName(): string`, `toListItem(): array{id,name,mime,size,is_image,is_pdf,url,created_at}`.
  - `Comment` with `transaction()` and `user()`.
  - `Transaction::receipts()`, `comments()`, `meetsDoneRule(): bool`, `refreshStatus(): void` (sets but does not save), `editableByOwner(): bool`, `ownerId(): ?int`.
  - `User::reconciliations()` and `receipts()`.
  - `ReceiptSource` enum.
  - Factory state `Receipt::factory()->attachedTo(Transaction)`.
  - Pest helper `lineFor(User $user, array $attributes = [], ?Period $period = null): Transaction`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Models/LineRulesTest.php`:

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Models/LineRulesTest.php`
Expected: FAIL. `lineFor()` and `Receipt` are not defined.

- [ ] **Step 3: Write the schema, models and factories**

`database/migrations/2026_10_12_000001_create_receipts_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Deleting a line (e.g. removing an import) puts its receipts back in the owner's inbox.
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('disk', 50);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->string('source', 20);
            $table->unsignedBigInteger('inbound_message_id')->nullable()->index();
            $table->timestamps();
            $table->index(['user_id', 'transaction_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
```

`database/migrations/2026_10_12_000002_create_comments_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
```

`app/Enums/ReceiptSource.php`:

```php
<?php

namespace App\Enums;

enum ReceiptSource: string
{
    case Camera = 'camera';
    case Upload = 'upload';
    case Bulk = 'bulk';
    case Email = 'email';
}
```

`app/Models/Receipt.php`:

```php
<?php

namespace App\Models;

use App\Enums\ReceiptSource;
use Database\Factories\ReceiptFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** An invoice or receipt file. With no transaction it sits in its owner's receipt inbox. */
class Receipt extends Model
{
    /** @use HasFactory<ReceiptFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'transaction_id', 'disk', 'path', 'original_name', 'mime', 'size', 'source', 'inbound_message_id'];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'source' => ReceiptSource::class,
        ];
    }

    protected static function booted(): void
    {
        static::deleted(fn (Receipt $receipt) => Storage::disk($receipt->disk)->delete($receipt->path));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function scopeInInbox(Builder $query): void
    {
        $query->whereNull('transaction_id');
    }

    /** Browsers can display these themselves; everything else is downloaded. */
    public function viewableInline(): bool
    {
        return in_array($this->mime, ['application/pdf', 'image/jpeg', 'image/png'], true);
    }

    /** The original name, with the extension of what is actually stored (a HEIC photo may now be a JPEG). */
    public function downloadName(): string
    {
        return pathinfo($this->original_name, PATHINFO_FILENAME).'.'.pathinfo($this->path, PATHINFO_EXTENSION);
    }

    /** @return array<string, mixed> */
    public function toListItem(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->original_name,
            'mime' => $this->mime,
            'size' => $this->size,
            'is_image' => in_array($this->mime, ['image/jpeg', 'image/png'], true),
            'is_pdf' => $this->mime === 'application/pdf',
            'url' => route('receipts.show', $this),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
```

`app/Models/Comment.php`:

```php
<?php

namespace App\Models;

use Database\Factories\CommentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A message in a line's query thread, between the accountant and the cardholder. */
class Comment extends Model
{
    /** @use HasFactory<CommentFactory> */
    use HasFactory;

    protected $fillable = ['transaction_id', 'user_id', 'body'];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

`app/Models/Transaction.php` (whole file, replacing the current one):

```php
<?php

namespace App\Models;

use App\Enums\PeriodStatus;
use App\Enums\ReconciliationStatus;
use App\Enums\TransactionStatus;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One row from an uploaded statement. Amount is in pence; negative is a credit. */
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    protected $fillable = [
        'period_id',
        'import_id',
        'reconciliation_id',
        'row_number',
        'transacted_on',
        'description',
        'amount',
        'card_ref',
        'extra',
        'purpose',
        'no_receipt',
        'no_receipt_reason',
        'status',
    ];

    protected $attributes = [
        'status' => 'todo',
        'no_receipt' => false,
    ];

    protected function casts(): array
    {
        return [
            'row_number' => 'integer',
            'transacted_on' => 'date:Y-m-d',
            'amount' => 'integer',
            'extra' => 'array',
            'no_receipt' => 'boolean',
            'status' => TransactionStatus::class,
            'approved_at' => 'datetime',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(Reconciliation::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class)->oldest('id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class)->oldest('id');
    }

    public function ownerId(): ?int
    {
        return $this->reconciliation?->user_id;
    }

    /** Purpose filled, and a receipt or a ticked no-receipt box with a reason. Credits need only a purpose. */
    public function meetsDoneRule(): bool
    {
        if (trim((string) $this->purpose) === '') {
            return false;
        }

        if ($this->amount < 0) {
            return true;
        }

        if ($this->no_receipt && trim((string) $this->no_receipt_reason) !== '') {
            return true;
        }

        return $this->receipts()->exists();
    }

    /** Re-derive the status after the cardholder changed something. Sets it; the caller saves. */
    public function refreshStatus(): void
    {
        $this->status = match ($this->status) {
            TransactionStatus::Approved => TransactionStatus::Approved,
            TransactionStatus::Queried => $this->meetsDoneRule() ? TransactionStatus::Done : TransactionStatus::Queried,
            default => $this->meetsDoneRule() ? TransactionStatus::Done : TransactionStatus::Todo,
        };
    }

    /** Whether the cardholder who owns this line may change it right now. */
    public function editableByOwner(): bool
    {
        $reconciliation = $this->reconciliation;

        if (! $reconciliation || $this->period->status !== PeriodStatus::Published) {
            return false;
        }

        if ($this->status === TransactionStatus::Approved) {
            return false;
        }

        return $reconciliation->status === ReconciliationStatus::InProgress || $this->status === TransactionStatus::Queried;
    }
}
```

In `app/Models/User.php`, add these two relations after `cardIdentifiers()`:

```php
    public function reconciliations(): HasMany
    {
        return $this->hasMany(Reconciliation::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }
```

`database/factories/ReceiptFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\ReceiptSource;
use App\Models\Receipt;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Receipt>
 */
class ReceiptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'transaction_id' => null,
            'disk' => 'receipts',
            'path' => fn () => 'demo/'.Str::uuid().'.pdf',
            'original_name' => 'receipt.pdf',
            'mime' => 'application/pdf',
            'size' => 2048,
            'source' => ReceiptSource::Upload,
        ];
    }

    /** On this line, owned by the line's cardholder. */
    public function attachedTo(Transaction $line): static
    {
        return $this->state(fn () => ['transaction_id' => $line->id, 'user_id' => $line->reconciliation->user_id]);
    }
}
```

`database/factories/CommentFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Comment;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Comment>
 */
class CommentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'transaction_id' => Transaction::factory(),
            'user_id' => User::factory(),
            'body' => fake()->sentence(),
        ];
    }
}
```

In `config/filesystems.php`, add this disk after the `local` disk:

```php
        'receipts' => [
            'driver' => 'local',
            'root' => storage_path('app/private/receipts'),
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],
```

In `config/cardtrail.php`, add after the `identity` block:

```php
    /*
    | Receipt files. The disk must be private. "address" is the email-in
    | address shown to cardholders (blank hides it until IT sets one up).
    */
    'receipts' => [
        'disk' => env('CARDTRAIL_RECEIPTS_DISK', 'receipts'),
        'address' => env('CARDTRAIL_RECEIPTS_ADDRESS'),
    ],
```

In `tests/Pest.php`, add `use App\Models\Period;`, `use App\Models\Reconciliation;`, `use App\Models\Transaction;` and `use App\Models\User;` to the imports, then append:

```php
/** A line on this cardholder's reconciliation, in a published period unless one is given. */
function lineFor(User $user, array $attributes = [], ?Period $period = null): Transaction
{
    $period ??= Period::factory()->published()->create();
    $import = $period->imports()->first() ?? Import::factory()->for($period)->confirmed()->create();
    $reconciliation = Reconciliation::firstOrCreate(['period_id' => $period->id, 'user_id' => $user->id]);

    return Transaction::factory()->for($import)->create(['reconciliation_id' => $reconciliation->id, ...$attributes]);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Models/LineRulesTest.php`
Expected: PASS (13 tests).

- [ ] **Step 5: Run the full suite and commit**

Run: `php artisan test`
Expected: all pass.

```bash
git add -A app database config tests
git commit -m "feat: receipts, comments and the line-done rule"
```

---

### Task 2: Who can see and change what

**Files:**
- Create: `app/Policies/TransactionPolicy.php`, `app/Policies/ReconciliationPolicy.php`, `app/Policies/ReceiptPolicy.php`
- Test: `tests/Feature/Cardholder/AccessTest.php`

**Interfaces:**
- Consumes: `Transaction::editableByOwner()`, `ownerId()` (Task 1).
- Produces abilities, auto-discovered by Laravel's `App\Policies\{Model}Policy` naming:
  - `view` and `update` on `Transaction`, plus `comment` (admin or owner; not in closed periods).
  - `view` and `submit` on `Reconciliation`.
  - `view` and `manage` on `Receipt`. `manage` covers delete, attach and detach: the owner only, when the receipt is in the inbox or on a line they can edit.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Cardholder/AccessTest.php`:

```php
<?php

use App\Enums\ReconciliationStatus;
use App\Enums\TransactionStatus;
use App\Models\Period;
use App\Models\Receipt;
use App\Models\User;

beforeEach(function () {
    $this->sam = User::factory()->create();
    $this->jo = User::factory()->create();
    $this->admin = User::factory()->admin()->create(['is_cardholder' => false]);
});

it('lets cardholders see only their own lines', function () {
    $line = lineFor($this->sam);

    expect($this->sam->can('view', $line))->toBeTrue()
        ->and($this->jo->can('view', $line))->toBeFalse()
        ->and($this->admin->can('view', $line))->toBeTrue()
        ->and($this->sam->can('view', $line->reconciliation))->toBeTrue()
        ->and($this->jo->can('view', $line->reconciliation))->toBeFalse();
});

it('hides lines from cardholders until the period is published', function () {
    $line = lineFor($this->sam, [], Period::factory()->create());

    expect($this->sam->can('view', $line))->toBeFalse()
        ->and($this->sam->can('view', $line->reconciliation))->toBeFalse()
        ->and($this->admin->can('view', $line))->toBeTrue();
});

it('lets only the owner edit while their reconciliation is in progress', function () {
    $line = lineFor($this->sam);

    expect($this->sam->can('update', $line))->toBeTrue()
        ->and($this->jo->can('update', $line))->toBeFalse()
        ->and($this->admin->can('update', $line))->toBeFalse();
});

it('locks lines once submitted, apart from queried ones', function () {
    $done = lineFor($this->sam, ['status' => TransactionStatus::Done]);
    $queried = lineFor($this->sam, ['status' => TransactionStatus::Queried], $done->period);
    $done->reconciliation->update(['status' => ReconciliationStatus::Submitted]);

    expect($this->sam->can('update', $done->fresh()))->toBeFalse()
        ->and($this->sam->can('update', $queried->fresh()))->toBeTrue();
});

it('never lets anyone edit approved lines or closed periods', function () {
    $approved = lineFor($this->sam, ['status' => TransactionStatus::Approved]);
    $closed = lineFor($this->sam, [], Period::factory()->closed()->create());

    expect($this->sam->can('update', $approved))->toBeFalse()
        ->and($this->sam->can('update', $closed))->toBeFalse()
        ->and($this->sam->can('view', $closed))->toBeTrue()
        ->and($this->sam->can('comment', $closed))->toBeFalse()
        ->and($this->admin->can('comment', $closed))->toBeFalse();
});

it('lets the owner and admins comment on an open line', function () {
    $line = lineFor($this->sam);

    expect($this->sam->can('comment', $line))->toBeTrue()
        ->and($this->admin->can('comment', $line))->toBeTrue()
        ->and($this->jo->can('comment', $line))->toBeFalse();
});

it('keeps receipts private to their owner and admins', function () {
    $receipt = Receipt::factory()->for($this->sam)->create();

    expect($this->sam->can('view', $receipt))->toBeTrue()
        ->and($this->jo->can('view', $receipt))->toBeFalse()
        ->and($this->admin->can('view', $receipt))->toBeTrue();
});

it('lets the owner manage inbox receipts and receipts on lines they can edit', function () {
    $inbox = Receipt::factory()->for($this->sam)->create();
    $line = lineFor($this->sam);
    $attached = Receipt::factory()->attachedTo($line)->create();

    expect($this->sam->can('manage', $inbox))->toBeTrue()
        ->and($this->sam->can('manage', $attached))->toBeTrue()
        ->and($this->jo->can('manage', $inbox))->toBeFalse()
        ->and($this->admin->can('manage', $inbox))->toBeFalse();

    $line->reconciliation->update(['status' => ReconciliationStatus::Submitted]);

    expect($this->sam->can('manage', $attached->fresh()))->toBeFalse();
});

it('only lets the owner submit a reconciliation that is in progress in a published period', function () {
    $line = lineFor($this->sam);
    $reconciliation = $line->reconciliation;

    expect($this->sam->can('submit', $reconciliation))->toBeTrue()
        ->and($this->jo->can('submit', $reconciliation))->toBeFalse()
        ->and($this->admin->can('submit', $reconciliation))->toBeFalse();

    $reconciliation->update(['status' => ReconciliationStatus::Submitted]);
    expect($this->sam->can('submit', $reconciliation->fresh()))->toBeFalse();

    $draft = lineFor($this->sam, [], Period::factory()->create());
    expect($this->sam->can('submit', $draft->reconciliation))->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Cardholder/AccessTest.php`
Expected: FAIL. With no policies registered, every ability is denied, so the first `toBeTrue()` fails.

- [ ] **Step 3: Write the policies**

`app/Policies/TransactionPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Enums\PeriodStatus;
use App\Models\Transaction;
use App\Models\User;

class TransactionPolicy
{
    public function view(User $user, Transaction $line): bool
    {
        return $user->is_admin || $this->ownsVisible($user, $line);
    }

    public function update(User $user, Transaction $line): bool
    {
        return $this->ownsVisible($user, $line) && $line->editableByOwner();
    }

    public function comment(User $user, Transaction $line): bool
    {
        if ($line->period->status === PeriodStatus::Closed) {
            return false;
        }

        return $user->is_admin || $this->ownsVisible($user, $line);
    }

    /** Cardholders only ever see their own lines, and only once the month is published. */
    private function ownsVisible(User $user, Transaction $line): bool
    {
        return $line->ownerId() === $user->id && $line->period->status !== PeriodStatus::Draft;
    }
}
```

`app/Policies/ReconciliationPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Enums\PeriodStatus;
use App\Enums\ReconciliationStatus;
use App\Models\Reconciliation;
use App\Models\User;

class ReconciliationPolicy
{
    public function view(User $user, Reconciliation $reconciliation): bool
    {
        return $user->is_admin
            || ($reconciliation->user_id === $user->id && $reconciliation->period->status !== PeriodStatus::Draft);
    }

    public function submit(User $user, Reconciliation $reconciliation): bool
    {
        return $reconciliation->user_id === $user->id
            && $reconciliation->period->status === PeriodStatus::Published
            && $reconciliation->status === ReconciliationStatus::InProgress;
    }
}
```

`app/Policies/ReceiptPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\Receipt;
use App\Models\User;

class ReceiptPolicy
{
    public function view(User $user, Receipt $receipt): bool
    {
        return $user->is_admin || $receipt->user_id === $user->id;
    }

    /** Delete, attach to a line, or move back to the inbox. */
    public function manage(User $user, Receipt $receipt): bool
    {
        if ($receipt->user_id !== $user->id) {
            return false;
        }

        return $receipt->transaction_id === null || $receipt->transaction->editableByOwner();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Cardholder/AccessTest.php`
Expected: PASS (9 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Policies tests/Feature/Cardholder/AccessTest.php
git commit -m "feat: policies for lines, reconciliations and receipts"
```

---
### Task 3: Cardholder home and line list

**Files:**
- Create: `app/Reconciliations/ReconciliationSummary.php`, `app/Http/Controllers/DashboardController.php`, `app/Http/Controllers/ReconciliationController.php`, `routes/cardholder.php`, `resources/js/types/cardtrail.ts`, `resources/js/components/LineStatusBadge.vue`, `resources/js/components/ReconciliationStatusBadge.vue`, `resources/js/components/ProgressBar.vue`, `resources/js/pages/reconciliations/Show.vue`
- Modify: `routes/web.php`, `resources/js/pages/Dashboard.vue`
- Test: `tests/Feature/Cardholder/HomeTest.php`, `tests/Feature/Cardholder/ReconciliationListTest.php`

**Interfaces:**
- Consumes: the `view` and `submit` abilities on `Reconciliation` (Task 2) and `User::reconciliations()`/`receipts()` (Task 1).
- Produces:
  - `ReconciliationSummary::withCounts(Builder): Builder` and `ReconciliationSummary::present(Reconciliation): array{id,status,submitted_at,lines,done,queried,overdue,period{id,label,due_date,status}}`.
  - Routes `dashboard` and `reconciliations.show`.
  - TS types `LineStatus`, `ReconciliationStatus`, `PeriodStatus`, `ReconciliationSummary`, `ReceiptItem`.
  - The `reconciliations/Show.vue` submit button posts to `reconciliations.submit`, which Task 7 adds.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Cardholder/HomeTest.php`:

```php
<?php

use App\Enums\TransactionStatus;
use App\Models\Period;
use App\Models\Receipt;
use App\Models\User;

it('shows a cardholder their published months with progress and past months', function () {
    $sam = User::factory()->create();
    $period = Period::factory()->published()->create(['label' => 'September 2026']);
    lineFor($sam, ['status' => TransactionStatus::Done], $period);
    lineFor($sam, [], $period);
    lineFor($sam, ['status' => TransactionStatus::Queried], $period);
    lineFor($sam, [], Period::factory()->create(['label' => 'Draft month']));
    lineFor($sam, ['status' => TransactionStatus::Approved], Period::factory()->closed()->create(['label' => 'June 2026']));
    lineFor(User::factory()->create(), [], $period);

    $this->actingAs($sam)->get('/dashboard')->assertInertia(fn ($page) => $page
        ->component('Dashboard')
        ->has('current', 1)
        ->where('current.0.period.label', 'September 2026')
        ->where('current.0.lines', 3)
        ->where('current.0.done', 1)
        ->where('current.0.queried', 1)
        ->where('current.0.overdue', false)
        ->has('past', 1)
        ->where('past.0.period.label', 'June 2026'));
});

it('flags a month as overdue once the due date has passed', function () {
    $sam = User::factory()->create();
    lineFor($sam, [], Period::factory()->published()->create(['due_date' => today()->subDay()]));

    $this->actingAs($sam)->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('current.0.overdue', true));
});

it('counts receipts waiting in the inbox', function () {
    $sam = User::factory()->create();
    Receipt::factory()->for($sam)->count(2)->create();
    Receipt::factory()->attachedTo(lineFor($sam))->create();

    $this->actingAs($sam)->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('inbox_count', 2));
});
```

`tests/Feature/Cardholder/ReconciliationListTest.php`:

```php
<?php

use App\Enums\TransactionStatus;
use App\Models\Period;
use App\Models\Receipt;
use App\Models\User;

beforeEach(function () {
    $this->sam = User::factory()->create(['name' => 'Sam']);
});

it('lists the cardholder’s lines in statement order with their receipts', function () {
    $first = lineFor($this->sam, ['row_number' => 5, 'description' => 'TRAIN', 'amount' => 4520]);
    lineFor($this->sam, ['row_number' => 3, 'description' => 'HOTEL'], $first->period);
    Receipt::factory()->attachedTo($first)->create();

    $this->actingAs($this->sam)->get(route('reconciliations.show', $first->reconciliation))
        ->assertInertia(fn ($page) => $page
            ->component('reconciliations/Show')
            ->where('reconciliation.mine', true)
            ->where('reconciliation.lines', 2)
            ->where('reconciliation.can_submit', false)
            ->has('lines', 2)
            ->where('lines.0.description', 'HOTEL')
            ->where('lines.1.description', 'TRAIN')
            ->where('lines.1.amount', 4520)
            ->where('lines.1.receipts', 1));
});

it('offers submit once every line is done', function () {
    $line = lineFor($this->sam, ['status' => TransactionStatus::Done]);

    $this->actingAs($this->sam)->get(route('reconciliations.show', $line->reconciliation))
        ->assertInertia(fn ($page) => $page->where('reconciliation.can_submit', true));
});

it('keeps other cardholders and draft months out', function () {
    $line = lineFor($this->sam);
    $draft = lineFor($this->sam, [], Period::factory()->create());

    $this->actingAs(User::factory()->create())->get(route('reconciliations.show', $line->reconciliation))->assertForbidden();
    $this->actingAs($this->sam)->get(route('reconciliations.show', $draft->reconciliation))->assertForbidden();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Cardholder/HomeTest.php tests/Feature/Cardholder/ReconciliationListTest.php`
Expected: FAIL. The props `current` and `inbox_count` are missing, and route `reconciliations.show` is not defined.

- [ ] **Step 3: Write the summary, controllers and routes**

`app/Reconciliations/ReconciliationSummary.php`:

```php
<?php

namespace App\Reconciliations;

use App\Enums\PeriodStatus;
use App\Enums\ReconciliationStatus;
use App\Enums\TransactionStatus;
use App\Models\Reconciliation;
use Illuminate\Database\Eloquent\Builder;

/** Progress figures for one cardholder's month, shared by the home screen and the line list. */
final class ReconciliationSummary
{
    /** Adds the period and the counts present() reads. */
    public static function withCounts(Builder $query): Builder
    {
        return $query->with('period')->withCount([
            'transactions as lines',
            'transactions as done' => fn ($query) => $query->whereIn('status', [TransactionStatus::Done, TransactionStatus::Approved]),
            'transactions as queried' => fn ($query) => $query->where('status', TransactionStatus::Queried),
        ]);
    }

    /** @return array<string, mixed> */
    public static function present(Reconciliation $reconciliation): array
    {
        $period = $reconciliation->period;

        return [
            'id' => $reconciliation->id,
            'status' => $reconciliation->status->value,
            'submitted_at' => $reconciliation->submitted_at?->toIso8601String(),
            'lines' => (int) $reconciliation->lines,
            'done' => (int) $reconciliation->done,
            'queried' => (int) $reconciliation->queried,
            'overdue' => $period->status === PeriodStatus::Published
                && $period->due_date->lt(today())
                && $reconciliation->status === ReconciliationStatus::InProgress,
            'period' => [
                'id' => $period->id,
                'label' => $period->label,
                'due_date' => $period->due_date->format('Y-m-d'),
                'status' => $period->status->value,
            ],
        ];
    }
}
```

`app/Http/Controllers/DashboardController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Enums\PeriodStatus;
use App\Models\Reconciliation;
use App\Reconciliations\ReconciliationSummary;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        $reconciliations = ReconciliationSummary::withCounts(Reconciliation::query()->whereBelongsTo($user))
            ->whereHas('period', fn ($query) => $query->where('status', '!=', PeriodStatus::Draft))
            ->get()
            ->sortByDesc(fn (Reconciliation $reconciliation) => $reconciliation->period->month->format('Y-m-d'))
            ->values();

        return Inertia::render('Dashboard', [
            'current' => $reconciliations
                ->filter(fn (Reconciliation $reconciliation) => $reconciliation->period->status === PeriodStatus::Published)
                ->map(ReconciliationSummary::present(...))
                ->values(),
            'past' => $reconciliations
                ->filter(fn (Reconciliation $reconciliation) => $reconciliation->period->status === PeriodStatus::Closed)
                ->take(12)
                ->map(ReconciliationSummary::present(...))
                ->values(),
            'inbox_count' => $user->receipts()->inInbox()->count(),
        ]);
    }
}
```

`app/Http/Controllers/ReconciliationController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Reconciliation;
use App\Models\Transaction;
use App\Reconciliations\ReconciliationSummary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ReconciliationController extends Controller
{
    public function show(Request $request, Reconciliation $reconciliation): Response
    {
        Gate::authorize('view', $reconciliation);

        $user = $request->user();
        $reconciliation = ReconciliationSummary::withCounts(Reconciliation::query()->whereKey($reconciliation->id))
            ->with('user:id,name')
            ->firstOrFail();

        $lines = $reconciliation->transactions()
            ->withCount('receipts')
            ->orderBy('import_id')
            ->orderBy('row_number')
            ->get();

        return Inertia::render('reconciliations/Show', [
            'reconciliation' => [
                ...ReconciliationSummary::present($reconciliation),
                'owner' => $reconciliation->user->name,
                'mine' => $reconciliation->user_id === $user->id,
                'can_submit' => $user->can('submit', $reconciliation)
                    && $reconciliation->lines > 0
                    && $reconciliation->done === $reconciliation->lines,
            ],
            'lines' => $lines->map(fn (Transaction $line) => [
                'id' => $line->id,
                'row_number' => $line->row_number,
                'date' => $line->transacted_on->format('Y-m-d'),
                'description' => $line->description,
                'amount' => $line->amount,
                'status' => $line->status->value,
                'purpose' => $line->purpose,
                'receipts' => $line->receipts_count,
                'no_receipt' => $line->no_receipt,
            ]),
        ]);
    }
}
```

`routes/cardholder.php`:

```php
<?php

use App\Http\Controllers\ReconciliationController;
use Illuminate\Support\Facades\Route;

// Cardholder screens. Policies decide what each person may see; these routes only require sign-in.
Route::middleware('auth')->group(function () {
    Route::get('reconciliations/{reconciliation}', [ReconciliationController::class, 'show'])->name('reconciliations.show');
});
```

`routes/web.php` (whole file, replacing the current one):

```php
<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::get('dashboard', DashboardController::class)->middleware(['auth', 'verified'])->name('dashboard');

require __DIR__.'/cardholder.php';
require __DIR__.'/admin.php';
require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Cardholder tests/Feature/DashboardTest.php tests/Feature/NavigationTest.php`
Expected: PASS.

- [ ] **Step 5: Write the screens**

`resources/js/types/cardtrail.ts`:

```ts
export type LineStatus = 'todo' | 'done' | 'queried' | 'approved';
export type ReconciliationStatus = 'in_progress' | 'submitted' | 'approved';
export type PeriodStatus = 'draft' | 'published' | 'closed';

export interface ReconciliationSummary {
    id: number;
    status: ReconciliationStatus;
    submitted_at: string | null;
    lines: number;
    done: number;
    queried: number;
    overdue: boolean;
    period: { id: number; label: string; due_date: string; status: PeriodStatus };
}

export interface ReceiptItem {
    id: number;
    name: string;
    mime: string;
    size: number;
    is_image: boolean;
    is_pdf: boolean;
    url: string;
    created_at: string | null;
}
```

`resources/js/components/LineStatusBadge.vue`:

```vue
<script setup lang="ts">
import type { LineStatus } from '@/types/cardtrail';

defineProps<{ status: LineStatus }>();

const styles: Record<LineStatus, string> = {
    todo: 'bg-neutral-200 text-neutral-800 dark:bg-neutral-800 dark:text-neutral-200',
    done: 'bg-green-100 text-green-900 dark:bg-green-950 dark:text-green-200',
    queried: 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200',
    approved: 'bg-blue-100 text-blue-900 dark:bg-blue-950 dark:text-blue-200',
};

const labels: Record<LineStatus, string> = { todo: 'To do', done: 'Done', queried: 'Queried', approved: 'Approved' };
</script>

<template>
    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium" :class="styles[status]">{{ labels[status] }}</span>
</template>
```

`resources/js/components/ReconciliationStatusBadge.vue`:

```vue
<script setup lang="ts">
import type { ReconciliationStatus } from '@/types/cardtrail';

defineProps<{ status: ReconciliationStatus }>();

const styles: Record<ReconciliationStatus, string> = {
    in_progress: 'bg-neutral-200 text-neutral-800 dark:bg-neutral-800 dark:text-neutral-200',
    submitted: 'bg-blue-100 text-blue-900 dark:bg-blue-950 dark:text-blue-200',
    approved: 'bg-green-100 text-green-900 dark:bg-green-950 dark:text-green-200',
};

const labels: Record<ReconciliationStatus, string> = { in_progress: 'In progress', submitted: 'Submitted', approved: 'Approved' };
</script>

<template>
    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium" :class="styles[status]">{{ labels[status] }}</span>
</template>
```

`resources/js/components/ProgressBar.vue`:

```vue
<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ value: number; max: number }>();

const percent = computed(() => (props.max > 0 ? Math.round((props.value / props.max) * 100) : 0));
</script>

<template>
    <div class="h-2 w-full overflow-hidden rounded-full bg-muted" role="progressbar" :aria-valuenow="value" aria-valuemin="0" :aria-valuemax="max">
        <div class="h-full rounded-full bg-primary transition-all" :style="{ width: `${percent}%` }" />
    </div>
</template>
```

`resources/js/pages/Dashboard.vue` (whole file, replacing the current one):

```vue
<script setup lang="ts">
import FlashMessage from '@/components/FlashMessage.vue';
import ProgressBar from '@/components/ProgressBar.vue';
import ReconciliationStatusBadge from '@/components/ReconciliationStatusBadge.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate } from '@/lib/money';
import { type BreadcrumbItem, type SharedData } from '@/types';
import type { ReconciliationSummary } from '@/types/cardtrail';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Inbox, MessageCircleQuestion } from 'lucide-vue-next';

defineProps<{
    current: ReconciliationSummary[];
    past: ReconciliationSummary[];
    inbox_count: number;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

const page = usePage<SharedData>();
const user = page.props.auth.user;
const firstName = user.name.split(' ')[0];
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-4 p-4">
            <FlashMessage />
            <h1 class="text-xl font-semibold">Hello, {{ firstName }}</h1>

            <template v-if="user.is_cardholder || current.length || past.length">
                <section v-for="item in current" :key="item.id" class="rounded-xl border p-4">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <h2 class="font-medium">{{ item.period.label }}</h2>
                            <p class="text-sm text-muted-foreground">
                                Due {{ formatDate(item.period.due_date) }}
                                <span v-if="item.overdue" class="font-medium text-red-600 dark:text-red-400"> · Overdue</span>
                            </p>
                        </div>
                        <ReconciliationStatusBadge :status="item.status" />
                    </div>
                    <ProgressBar class="mt-3" :value="item.done" :max="item.lines" />
                    <p class="mt-1 text-sm text-muted-foreground">{{ item.done }} of {{ item.lines }} lines done</p>
                    <p v-if="item.queried" class="mt-2 flex items-center gap-1 text-sm font-medium text-amber-700 dark:text-amber-300">
                        <MessageCircleQuestion class="size-4" />
                        {{ item.queried }} {{ item.queried === 1 ? 'question' : 'questions' }} from accounts
                    </p>
                    <Button class="mt-3 w-full sm:w-auto" as-child>
                        <Link :href="route('reconciliations.show', item.id)">{{ item.status === 'in_progress' ? 'Continue' : 'View' }}</Link>
                    </Button>
                </section>

                <section v-if="!current.length" class="rounded-xl border p-4">
                    <h2 class="font-medium">Your card reconciliations</h2>
                    <p class="mt-1 text-sm text-muted-foreground">Nothing to do right now. When accounts publish a month, your transactions will appear here.</p>
                </section>

                <Link
                    v-if="inbox_count"
                    :href="route('receipts.index')"
                    class="flex items-center gap-3 rounded-xl border p-4 text-sm hover:bg-accent"
                >
                    <Inbox class="size-5 shrink-0" />
                    <span>
                        You have {{ inbox_count }} {{ inbox_count === 1 ? 'receipt' : 'receipts' }} in your inbox waiting to be matched to a line.
                    </span>
                </Link>

                <section v-if="past.length" class="rounded-xl border">
                    <h2 class="border-b p-4 font-medium">Past months</h2>
                    <ul class="divide-y">
                        <li v-for="item in past" :key="item.id">
                            <Link :href="route('reconciliations.show', item.id)" class="flex items-center justify-between gap-2 p-4 text-sm hover:bg-accent">
                                <span>{{ item.period.label }}</span>
                                <ReconciliationStatusBadge :status="item.status" />
                            </Link>
                        </li>
                    </ul>
                </section>
            </template>

            <section v-if="user.is_admin" class="rounded-xl border p-4">
                <h2 class="font-medium">Admin</h2>
                <p class="mt-1 text-sm text-muted-foreground">Create a period for each statement month, upload the export, and track who has finished.</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <Button as-child>
                        <Link :href="route('admin.periods.index')">Periods</Link>
                    </Button>
                    <Button variant="outline" as-child>
                        <Link :href="route('admin.users.index')">Manage users</Link>
                    </Button>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
```

`resources/js/pages/reconciliations/Show.vue`:

```vue
<script setup lang="ts">
import FlashMessage from '@/components/FlashMessage.vue';
import LineStatusBadge from '@/components/LineStatusBadge.vue';
import ProgressBar from '@/components/ProgressBar.vue';
import ReconciliationStatusBadge from '@/components/ReconciliationStatusBadge.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatPence } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';
import type { LineStatus, ReconciliationSummary } from '@/types/cardtrail';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Line {
    id: number;
    row_number: number;
    date: string;
    description: string;
    amount: number;
    status: LineStatus;
    purpose: string | null;
    receipts: number;
    no_receipt: boolean;
}

type Tab = 'todo' | 'done' | 'queried';

const props = defineProps<{
    reconciliation: ReconciliationSummary & { owner: string; mine: boolean; can_submit: boolean };
    lines: Line[];
}>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Dashboard', href: '/dashboard' },
    { title: props.reconciliation.period.label, href: '#' },
]);

const groups = computed<Record<Tab, Line[]>>(() => ({
    todo: props.lines.filter((line) => line.status === 'todo'),
    queried: props.lines.filter((line) => line.status === 'queried'),
    done: props.lines.filter((line) => line.status === 'done' || line.status === 'approved'),
}));

const tab = ref<Tab>(groups.value.queried.length ? 'queried' : groups.value.todo.length ? 'todo' : 'done');
const tabs: { key: Tab; label: string }[] = [
    { key: 'todo', label: 'To do' },
    { key: 'done', label: 'Done' },
    { key: 'queried', label: 'Queried' },
];

const remaining = computed(() => groups.value.todo.length + groups.value.queried.length);
const canWork = computed(
    () => props.reconciliation.mine && props.reconciliation.period.status === 'published' && props.reconciliation.status === 'in_progress',
);

const submitting = ref(false);
const submit = () => {
    if (!confirm('Submit this month to accounts? You won’t be able to change lines afterwards unless accounts query them.')) {
        return;
    }

    router.post(route('reconciliations.submit', props.reconciliation.id), {}, {
        preserveScroll: true,
        onStart: () => (submitting.value = true),
        onFinish: () => (submitting.value = false),
    });
};

const hint = (line: Line) => {
    if (!line.purpose) {
        return 'Needs a purpose';
    }

    if (line.amount < 0) {
        return 'Credit';
    }

    if (line.receipts) {
        return line.receipts === 1 ? '1 receipt' : `${line.receipts} receipts`;
    }

    return line.no_receipt ? 'No receipt' : 'Needs a receipt';
};
</script>

<template>
    <Head :title="reconciliation.period.label" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-4 p-4">
            <FlashMessage />

            <div class="flex items-start justify-between gap-2">
                <div>
                    <h1 class="text-xl font-semibold">{{ reconciliation.period.label }}</h1>
                    <p class="text-sm text-muted-foreground">
                        <span v-if="!reconciliation.mine">{{ reconciliation.owner }} · </span>
                        Due {{ formatDate(reconciliation.period.due_date) }}
                        <span v-if="reconciliation.overdue" class="font-medium text-red-600 dark:text-red-400"> · Overdue</span>
                    </p>
                </div>
                <ReconciliationStatusBadge :status="reconciliation.status" />
            </div>

            <div>
                <ProgressBar :value="reconciliation.done" :max="reconciliation.lines" />
                <p class="mt-1 text-sm text-muted-foreground">{{ reconciliation.done }} of {{ reconciliation.lines }} lines done</p>
            </div>

            <p v-if="reconciliation.period.status === 'closed'" class="rounded-md border bg-muted px-4 py-3 text-sm">
                This month is closed. You can look, but nothing can be changed.
            </p>
            <p v-else-if="reconciliation.status === 'submitted'" class="rounded-md border bg-muted px-4 py-3 text-sm">
                Submitted {{ formatDate(reconciliation.submitted_at) }}. Accounts will review it and let you know if they have questions.
            </p>
            <p v-else-if="reconciliation.status === 'approved'" class="rounded-md border bg-muted px-4 py-3 text-sm">Approved by accounts. Nothing more to do.</p>

            <div class="flex gap-1 rounded-lg bg-muted p-1" role="tablist">
                <button
                    v-for="item in tabs"
                    :key="item.key"
                    type="button"
                    role="tab"
                    :aria-selected="tab === item.key"
                    class="flex-1 rounded-md px-3 py-1.5 text-sm font-medium"
                    :class="tab === item.key ? 'bg-background shadow-sm' : 'text-muted-foreground'"
                    @click="tab = item.key"
                >
                    {{ item.label }} ({{ groups[item.key].length }})
                </button>
            </div>

            <ul class="divide-y rounded-lg border">
                <li v-for="line in groups[tab]" :key="line.id">
                    <Link :href="route('transactions.show', line.id)" class="flex items-start gap-3 p-4 text-sm hover:bg-accent">
                        <div class="min-w-0 flex-1">
                            <div class="truncate font-medium">{{ line.description }}</div>
                            <div class="text-muted-foreground">Row {{ line.row_number }} · {{ formatDate(line.date) }} · {{ hint(line) }}</div>
                        </div>
                        <div class="shrink-0 text-right">
                            <div class="font-medium tabular-nums">{{ formatPence(line.amount) }}</div>
                            <LineStatusBadge :status="line.status" />
                        </div>
                    </Link>
                </li>
                <li v-if="!groups[tab].length" class="p-4 text-sm text-muted-foreground">Nothing here.</li>
            </ul>

            <div v-if="canWork" class="sticky bottom-0 -mx-4 border-t bg-background/95 p-4 backdrop-blur">
                <Button class="w-full" :disabled="!reconciliation.can_submit || submitting" @click="submit">
                    {{ reconciliation.can_submit ? 'Submit to accounts' : `${remaining} ${remaining === 1 ? 'line' : 'lines'} left to finish` }}
                </Button>
            </div>
        </div>
    </AppLayout>
</template>
```

- [ ] **Step 6: Build and commit**

Run: `npm run build`
Expected: build succeeds.

```bash
git add -A app routes resources/js tests
git commit -m "feat: cardholder home and line list"
```

---

### Task 4: Line detail, autosave and query replies

**Files:**
- Create: `app/Http/Controllers/TransactionController.php`, `app/Http/Controllers/TransactionCommentController.php`, `resources/js/lib/receipts.ts`, `resources/js/components/ReceiptThumb.vue`, `resources/js/components/ReceiptPicker.vue`, `resources/js/pages/transactions/Show.vue`
- Modify: `routes/cardholder.php`
- Test: `tests/Feature/Cardholder/TransactionDetailTest.php`

**Interfaces:**
- Consumes: the `view`, `update` and `comment` abilities on `Transaction`, plus `refreshStatus()`.
- Produces:
  - Routes `transactions.show` (GET), `transactions.update` (PUT; fields `purpose`, `no_receipt`, `no_receipt_reason`, optional `next`) and `transactions.comments.store` (POST `body`).
  - Vue components `ReceiptPicker` (emits `selected(files: File[], source: 'camera' | 'upload')`) and `ReceiptThumb` (prop `receipt`, slot `actions`).
  - `lib/receipts.ts` exports `ACCEPT`, `checkFiles()` and `formatBytes()`.
  - The page posts files to `transactions.receipts.store` and removes them through `receipts.destroy` and `receipts.match.destroy`. Tasks 5–6 add those routes.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Cardholder/TransactionDetailTest.php`:

```php
<?php

use App\Enums\ReconciliationStatus;
use App\Enums\TransactionStatus;
use App\Models\Comment;
use App\Models\Period;
use App\Models\Receipt;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->sam = User::factory()->create(['name' => 'Sam']);
    $this->admin = User::factory()->admin()->create(['name' => 'Alex', 'is_cardholder' => false]);
});

function saveLine($line, array $fields)
{
    return test()->actingAs(test()->sam)->put(route('transactions.update', $line), [
        'purpose' => null,
        'no_receipt' => false,
        'no_receipt_reason' => null,
        ...$fields,
    ]);
}

it('shows the line with its extra columns, receipts, thread and neighbours', function () {
    $first = lineFor($this->sam, ['row_number' => 2]);
    $line = lineFor($this->sam, ['row_number' => 3, 'description' => 'DEPOT CAFE', 'extra' => ['Reference' => 'AB12']], $first->period);
    $last = lineFor($this->sam, ['row_number' => 4], $first->period);
    lineFor($this->sam, ['row_number' => 9, 'purpose' => 'Fuel for van'], $first->period);
    Receipt::factory()->attachedTo($line)->create(['original_name' => 'cafe.pdf']);
    Comment::factory()->for($line)->for($this->admin)->create(['body' => 'Who attended?']);

    $this->actingAs($this->sam)->get(route('transactions.show', $line))
        ->assertInertia(fn ($page) => $page
            ->component('transactions/Show')
            ->where('line.description', 'DEPOT CAFE')
            ->where('line.extra.Reference', 'AB12')
            ->where('receipts.0.name', 'cafe.pdf')
            ->where('comments.0.body', 'Who attended?')
            ->where('comments.0.author', 'Alex')
            ->where('comments.0.mine', false)
            ->where('editable', true)
            ->where('suggestions', ['Fuel for van'])
            ->where('nav.previous', $first->id)
            ->where('nav.next', $last->id)
            ->where('nav.position', 2)
            ->where('nav.total', 4));
});

it('keeps other cardholders out of a line', function () {
    $line = lineFor($this->sam);
    $jo = User::factory()->create();

    $this->actingAs($jo)->get(route('transactions.show', $line))->assertForbidden();
    $this->actingAs($jo)->put(route('transactions.update', $line), ['purpose' => 'Mine now', 'no_receipt' => false])->assertForbidden();
    expect($line->fresh()->purpose)->toBeNull();
});

it('autosaves the purpose and keeps the line to do until there is a receipt or reason', function () {
    $line = lineFor($this->sam);

    saveLine($line, ['purpose' => 'Client lunch'])->assertRedirect();
    expect($line->fresh())->purpose->toBe('Client lunch')->status->toBe(TransactionStatus::Todo);

    saveLine($line, ['purpose' => 'Client lunch', 'no_receipt' => true, 'no_receipt_reason' => 'Card machine receipt lost'])->assertRedirect();
    expect($line->fresh()->status)->toBe(TransactionStatus::Done);

    expect(Activity::query()->where('event', 'line_updated')->count())->toBe(2);
});

it('does not log a save that changed nothing', function () {
    $line = lineFor($this->sam, ['purpose' => 'Client lunch']);

    saveLine($line, ['purpose' => 'Client lunch']);

    expect(Activity::query()->where('event', 'line_updated')->count())->toBe(0);
});

it('goes to the next line that still needs work on save and next', function () {
    $first = lineFor($this->sam, ['row_number' => 2]);
    lineFor($this->sam, ['row_number' => 3, 'status' => TransactionStatus::Done], $first->period);
    $open = lineFor($this->sam, ['row_number' => 4], $first->period);

    saveLine($first, ['purpose' => 'Parking', 'next' => true])
        ->assertRedirect(route('transactions.show', $open));
});

it('returns to the list when no other line needs work', function () {
    $line = lineFor($this->sam, ['amount' => -500]);

    saveLine($line, ['purpose' => 'Refund', 'next' => true])
        ->assertRedirect(route('reconciliations.show', $line->reconciliation_id))
        ->assertSessionHas('success');
});

it('refuses edits once submitted, except on queried lines', function () {
    $line = lineFor($this->sam, ['status' => TransactionStatus::Done, 'purpose' => 'Hotel']);
    $queried = lineFor($this->sam, ['status' => TransactionStatus::Queried, 'purpose' => 'Taxi'], $line->period);
    $line->reconciliation->update(['status' => ReconciliationStatus::Submitted]);

    saveLine($line, ['purpose' => 'Changed'])->assertForbidden();
    saveLine($queried, ['purpose' => 'Taxi from station to site', 'no_receipt' => true, 'no_receipt_reason' => 'Paid by card, no slip'])->assertRedirect();

    expect($line->fresh()->purpose)->toBe('Hotel')
        ->and($queried->fresh()->status)->toBe(TransactionStatus::Done);
});

it('lets the owner answer a query in words, which clears it', function () {
    $line = lineFor($this->sam, ['status' => TransactionStatus::Queried, 'purpose' => 'Team lunch', 'no_receipt' => true, 'no_receipt_reason' => 'Lost']);

    $this->actingAs($this->sam)->post(route('transactions.comments.store', $line), ['body' => 'Four of us from the depot.'])
        ->assertRedirect();

    expect($line->fresh()->status)->toBe(TransactionStatus::Done)
        ->and($line->comments()->first())->body->toBe('Four of us from the depot.')->user_id->toBe($this->sam->id);
});

it('keeps a query open when the reply leaves the line incomplete', function () {
    $line = lineFor($this->sam, ['status' => TransactionStatus::Queried]);

    $this->actingAs($this->sam)->post(route('transactions.comments.store', $line), ['body' => 'Will find the receipt.']);

    expect($line->fresh()->status)->toBe(TransactionStatus::Queried);
});

it('refuses comments from other cardholders, on closed months, and empty ones', function () {
    $line = lineFor($this->sam);
    $closed = lineFor($this->sam, [], Period::factory()->closed()->create());

    $this->actingAs(User::factory()->create())->post(route('transactions.comments.store', $line), ['body' => 'Hi'])->assertForbidden();
    $this->actingAs($this->sam)->post(route('transactions.comments.store', $closed), ['body' => 'Hi'])->assertForbidden();
    $this->actingAs($this->sam)->post(route('transactions.comments.store', $line), ['body' => ''])->assertSessionHasErrors('body');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Cardholder/TransactionDetailTest.php`
Expected: FAIL. Route `transactions.show` is not defined.

- [ ] **Step 3: Write the controllers and routes**

`app/Http/Controllers/TransactionController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Enums\TransactionStatus;
use App\Models\Comment;
use App\Models\Receipt;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    public function show(Request $request, Transaction $transaction): Response
    {
        Gate::authorize('view', $transaction);

        $user = $request->user();
        $transaction->load(['period', 'reconciliation.user:id,name', 'receipts', 'comments.user:id,name']);
        $editable = $user->can('update', $transaction);
        $siblings = $this->siblings($transaction);
        $position = $siblings->search($transaction->id);

        return Inertia::render('transactions/Show', [
            'line' => [
                'id' => $transaction->id,
                'row_number' => $transaction->row_number,
                'date' => $transaction->transacted_on->format('Y-m-d'),
                'description' => $transaction->description,
                'amount' => $transaction->amount,
                'card_ref' => $transaction->card_ref,
                'extra' => (object) ($transaction->extra ?? []),
                'purpose' => $transaction->purpose ?? '',
                'no_receipt' => $transaction->no_receipt,
                'no_receipt_reason' => $transaction->no_receipt_reason ?? '',
                'status' => $transaction->status->value,
            ],
            'receipts' => $transaction->receipts->map(fn (Receipt $receipt) => $receipt->toListItem()),
            'comments' => $transaction->comments->map(fn (Comment $comment) => [
                'id' => $comment->id,
                'body' => $comment->body,
                'author' => $comment->user->name,
                'mine' => $comment->user_id === $user->id,
                'created_at' => $comment->created_at->toIso8601String(),
            ]),
            'reconciliation' => $transaction->reconciliation ? [
                'id' => $transaction->reconciliation->id,
                'owner' => $transaction->reconciliation->user->name,
                'status' => $transaction->reconciliation->status->value,
            ] : null,
            'period' => ['label' => $transaction->period->label, 'status' => $transaction->period->status->value],
            'editable' => $editable,
            'can_comment' => $user->can('comment', $transaction),
            'suggestions' => $editable ? $this->suggestions($user) : [],
            'nav' => [
                'position' => $position === false ? null : $position + 1,
                'total' => $siblings->count(),
                'previous' => $position ? $siblings[$position - 1] : null,
                'next' => $position === false ? null : $siblings->get($position + 1),
            ],
        ]);
    }

    public function update(Request $request, Transaction $transaction): RedirectResponse
    {
        $data = $request->validate([
            'purpose' => ['nullable', 'string', 'max:1000'],
            'no_receipt' => ['required', 'boolean'],
            'no_receipt_reason' => ['nullable', 'string', 'max:500'],
            'next' => ['sometimes', 'boolean'],
        ]);

        $line = DB::transaction(function () use ($request, $transaction, $data) {
            $line = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('update', $line);

            // Blank strings arrive as null (ConvertEmptyStringsToNull). The reason is kept even when the box is unticked.
            $line->fill([
                'purpose' => $data['purpose'] ?? null,
                'no_receipt' => (bool) $data['no_receipt'],
                'no_receipt_reason' => $data['no_receipt_reason'] ?? null,
            ]);

            if ($line->isDirty()) {
                $changed = array_keys($line->getDirty());
                $line->refreshStatus();
                $line->save();

                activity('lines')
                    ->causedBy($request->user())
                    ->performedOn($line)
                    ->event('line_updated')
                    ->withProperties(['changed' => $changed, 'status' => $line->status->value])
                    ->log("Updated row {$line->row_number}");
            }

            return $line;
        });

        if (! $request->boolean('next')) {
            return back();
        }

        $next = $this->nextNeedingWork($line);

        if ($next) {
            return to_route('transactions.show', $next);
        }

        $allDone = ! Transaction::query()
            ->where('reconciliation_id', $line->reconciliation_id)
            ->whereIn('status', [TransactionStatus::Todo, TransactionStatus::Queried])
            ->exists();

        return to_route('reconciliations.show', $line->reconciliation_id)->with('success', $allDone
            ? 'Every line is done. Submit when you’re ready.'
            : 'That line still needs finishing before you can submit.');
    }

    /** @return Collection<int, int> ids of the lines on the same reconciliation, in statement order */
    private function siblings(Transaction $line): Collection
    {
        if (! $line->reconciliation_id) {
            return collect([$line->id]);
        }

        return Transaction::query()
            ->where('reconciliation_id', $line->reconciliation_id)
            ->orderBy('import_id')
            ->orderBy('row_number')
            ->pluck('id');
    }

    /** The next line after this one (wrapping round) that is still to do or queried. */
    private function nextNeedingWork(Transaction $line): ?int
    {
        $open = Transaction::query()
            ->where('reconciliation_id', $line->reconciliation_id)
            ->whereIn('status', [TransactionStatus::Todo, TransactionStatus::Queried])
            ->whereKeyNot($line->id)
            ->orderBy('import_id')
            ->orderBy('row_number')
            ->get(['id', 'import_id', 'row_number']);

        $after = $open->first(fn (Transaction $other) => [$other->import_id, $other->row_number] > [$line->import_id, $line->row_number]);

        return ($after ?? $open->first())?->id;
    }

    /** @return list<string> the cardholder's recent purposes, newest first */
    private function suggestions(User $user): array
    {
        return Transaction::query()
            ->whereHas('reconciliation', fn ($query) => $query->where('user_id', $user->id))
            ->whereNotNull('purpose')
            ->latest('updated_at')
            ->limit(50)
            ->pluck('purpose')
            ->unique(fn (string $purpose) => mb_strtolower($purpose))
            ->take(8)
            ->values()
            ->all();
    }
}
```

`app/Http/Controllers/TransactionCommentController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class TransactionCommentController extends Controller
{
    public function store(Request $request, Transaction $transaction): RedirectResponse
    {
        Gate::authorize('comment', $transaction);

        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        DB::transaction(function () use ($request, $transaction, $data) {
            $line = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            $line->comments()->create(['user_id' => $request->user()->id, 'body' => $data['body']]);

            // A cardholder answering a query in words has dealt with it, as long as the line is complete.
            if ($line->status === TransactionStatus::Queried && $request->user()->can('update', $line)) {
                $line->refreshStatus();
                $line->save();
            }

            activity('lines')
                ->causedBy($request->user())
                ->performedOn($line)
                ->event('commented')
                ->withProperties(['body' => $data['body']])
                ->log("Commented on row {$line->row_number}");
        });

        return back()->with('success', 'Comment added.');
    }
}
```

`routes/cardholder.php` (whole file, replacing the current one):

```php
<?php

use App\Http\Controllers\ReconciliationController;
use App\Http\Controllers\TransactionCommentController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

// Cardholder screens. Policies decide what each person may see; these routes only require sign-in.
Route::middleware('auth')->group(function () {
    Route::get('reconciliations/{reconciliation}', [ReconciliationController::class, 'show'])->name('reconciliations.show');

    Route::get('transactions/{transaction}', [TransactionController::class, 'show'])->name('transactions.show');
    Route::put('transactions/{transaction}', [TransactionController::class, 'update'])->name('transactions.update');
    Route::post('transactions/{transaction}/comments', [TransactionCommentController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('transactions.comments.store');
});
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Cardholder/TransactionDetailTest.php`
Expected: PASS (10 tests).

- [ ] **Step 5: Write the screen and receipt components**

`resources/js/lib/receipts.ts`:

```ts
export const EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'heic', 'heif', 'doc', 'docx'];

/** image/* lets iPhones offer the photo library as well as files. */
export const ACCEPT = [...EXTENSIONS.map((extension) => `.${extension}`), 'image/*'].join(',');

export const MAX_BYTES = 20 * 1024 * 1024;

/** Checks type and size before uploading. Returns a message for the first problem, or null. */
export function checkFiles(files: File[]): string | null {
    for (const file of files) {
        const extension = file.name.split('.').pop()?.toLowerCase() ?? '';

        if (!EXTENSIONS.includes(extension)) {
            return `${file.name} isn’t a PDF, photo or Word document.`;
        }

        if (file.size > MAX_BYTES) {
            return `${file.name} is larger than 20 MB.`;
        }
    }

    return null;
}

export function formatBytes(bytes: number): string {
    if (bytes < 1024 * 1024) {
        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}
```

`resources/js/components/ReceiptThumb.vue`:

```vue
<script setup lang="ts">
import { formatBytes } from '@/lib/receipts';
import type { ReceiptItem } from '@/types/cardtrail';
import { FileText } from 'lucide-vue-next';

defineProps<{ receipt: ReceiptItem }>();
</script>

<template>
    <div class="flex flex-col overflow-hidden rounded-lg border">
        <a :href="receipt.url" target="_blank" rel="noopener" class="block aspect-[3/4] bg-muted" :title="receipt.name">
            <img v-if="receipt.is_image" :src="receipt.url" :alt="receipt.name" loading="lazy" class="h-full w-full object-cover" />
            <div v-else class="flex h-full flex-col items-center justify-center gap-2 p-2 text-center">
                <FileText class="size-8 text-muted-foreground" />
                <span class="line-clamp-3 break-all text-xs">{{ receipt.name }}</span>
                <span class="text-xs text-muted-foreground">{{ formatBytes(receipt.size) }}</span>
            </div>
        </a>
        <div v-if="$slots.actions" class="flex divide-x border-t text-xs">
            <slot name="actions" />
        </div>
    </div>
</template>
```

`resources/js/components/ReceiptPicker.vue`:

```vue
<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { ACCEPT, checkFiles } from '@/lib/receipts';
import { Camera, Upload } from 'lucide-vue-next';
import { ref } from 'vue';

withDefaults(defineProps<{ processing?: boolean; multiple?: boolean }>(), { processing: false, multiple: true });

const emit = defineEmits<{ selected: [files: File[], source: 'camera' | 'upload'] }>();

const cameraInput = ref<HTMLInputElement>();
const fileInput = ref<HTMLInputElement>();
const problem = ref<string | null>(null);

const picked = (event: Event, source: 'camera' | 'upload') => {
    const input = event.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);
    input.value = '';

    if (!files.length) {
        return;
    }

    problem.value = checkFiles(files);

    if (!problem.value) {
        emit('selected', files, source);
    }
};
</script>

<template>
    <div class="flex flex-col gap-2">
        <div class="flex flex-wrap gap-2">
            <Button type="button" variant="outline" :disabled="processing" @click="cameraInput?.click()">
                <Camera class="size-4" />
                Take photo
            </Button>
            <Button type="button" variant="outline" :disabled="processing" @click="fileInput?.click()">
                <Upload class="size-4" />
                Choose {{ multiple ? 'files' : 'file' }}
            </Button>
        </div>
        <input ref="cameraInput" type="file" accept="image/*" capture="environment" class="hidden" @change="picked($event, 'camera')" />
        <input ref="fileInput" type="file" :accept="ACCEPT" :multiple="multiple" class="hidden" @change="picked($event, 'upload')" />
        <p v-if="problem" class="text-sm text-red-600 dark:text-red-400">{{ problem }}</p>
    </div>
</template>
```

`resources/js/pages/transactions/Show.vue`:

```vue
<script setup lang="ts">
import FlashMessage from '@/components/FlashMessage.vue';
import LineStatusBadge from '@/components/LineStatusBadge.vue';
import ReceiptPicker from '@/components/ReceiptPicker.vue';
import ReceiptThumb from '@/components/ReceiptThumb.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatPence } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';
import type { LineStatus, PeriodStatus, ReceiptItem, ReconciliationStatus } from '@/types/cardtrail';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';

interface Line {
    id: number;
    row_number: number;
    date: string;
    description: string;
    amount: number;
    card_ref: string | null;
    extra: Record<string, string>;
    purpose: string;
    no_receipt: boolean;
    no_receipt_reason: string;
    status: LineStatus;
}

interface CommentItem {
    id: number;
    body: string;
    author: string;
    mine: boolean;
    created_at: string;
}

const props = defineProps<{
    line: Line;
    receipts: ReceiptItem[];
    comments: CommentItem[];
    reconciliation: { id: number; owner: string; status: ReconciliationStatus } | null;
    period: { label: string; status: PeriodStatus };
    editable: boolean;
    can_comment: boolean;
    suggestions: string[];
    nav: { position: number | null; total: number; previous: number | null; next: number | null };
}>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Dashboard', href: '/dashboard' },
    ...(props.reconciliation ? [{ title: props.period.label, href: route('reconciliations.show', props.reconciliation.id) }] : []),
    { title: `Row ${props.line.row_number}`, href: '#' },
]);

const isCredit = computed(() => props.line.amount < 0);
const extraColumns = computed(() => Object.entries(props.line.extra ?? {}).filter(([, value]) => value !== ''));

const fields = reactive({
    purpose: props.line.purpose,
    no_receipt: props.line.no_receipt,
    no_receipt_reason: props.line.no_receipt_reason,
});
const saveState = ref<'idle' | 'pending' | 'saving' | 'saved' | 'error'>('idle');
const saveErrors = ref<Record<string, string>>({});
let timer: ReturnType<typeof setTimeout> | undefined;

// Inertia reuses this page when moving between lines, so start fresh when the line changes.
watch(
    () => props.line.id,
    () => {
        clearTimeout(timer);
        timer = undefined;
        Object.assign(fields, { purpose: props.line.purpose, no_receipt: props.line.no_receipt, no_receipt_reason: props.line.no_receipt_reason });
        saveState.value = 'idle';
        saveErrors.value = {};
    },
);

onBeforeUnmount(() => clearTimeout(timer));

const save = (next = false, after?: () => void) => {
    saveState.value = 'saving';
    router.put(
        route('transactions.update', props.line.id),
        { ...fields, next },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                saveState.value = 'saved';
                saveErrors.value = {};
            },
            onError: (errors) => {
                saveState.value = 'error';
                saveErrors.value = errors;
            },
            onFinish: () => after?.(),
        },
    );
};

const queueSave = () => {
    if (!props.editable) {
        return;
    }

    clearTimeout(timer);
    saveState.value = 'pending';
    timer = setTimeout(() => {
        timer = undefined;
        save();
    }, 800);
};

/** Save any typing that hasn't been sent yet, then carry on. Inertia cancels a visit when another starts. */
const flushThen = (then: () => void) => {
    if (timer) {
        clearTimeout(timer);
        timer = undefined;
        save(false, then);
    } else {
        then();
    }
};

const saveAndNext = () => {
    clearTimeout(timer);
    timer = undefined;
    save(true);
};

const useSuggestion = (purpose: string) => {
    fields.purpose = purpose;
    queueSave();
};

const toggleNoReceipt = (value: boolean) => {
    fields.no_receipt = value;
    queueSave();
};

const upload = useForm<{ files: File[]; source: 'camera' | 'upload' }>({ files: [], source: 'upload' });
const uploadError = computed(() => Object.values(upload.errors)[0] ?? null);

const uploadFiles = (files: File[], source: 'camera' | 'upload') => {
    flushThen(() => {
        upload.files = files;
        upload.source = source;
        upload.post(route('transactions.receipts.store', props.line.id), {
            forceFormData: true,
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => upload.reset(),
        });
    });
};

const moveToInbox = (receipt: ReceiptItem) => {
    flushThen(() => router.delete(route('receipts.match.destroy', receipt.id), { preserveScroll: true, preserveState: true }));
};

const deleteReceipt = (receipt: ReceiptItem) => {
    if (confirm(`Delete ${receipt.name}? This can’t be undone.`)) {
        flushThen(() => router.delete(route('receipts.destroy', receipt.id), { preserveScroll: true, preserveState: true }));
    }
};

const comment = useForm({ body: '' });
const sendComment = () => {
    flushThen(() =>
        comment.post(route('transactions.comments.store', props.line.id), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => comment.reset(),
        }),
    );
};

const lockedReason = computed(() => {
    if (props.period.status === 'closed') {
        return 'This month is closed, so this line can’t be changed.';
    }

    if (props.line.status === 'approved') {
        return 'Accounts have approved this line.';
    }

    if (props.reconciliation?.status === 'submitted') {
        return 'You’ve submitted this month. Accounts will query a line if they need anything changed.';
    }

    return null;
});

const saveLabel = computed(
    () => ({ idle: '', pending: 'Unsaved changes…', saving: 'Saving…', saved: 'Saved', error: 'Not saved – check the form' })[saveState.value],
);

const textareaClass =
    'w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:opacity-60';
</script>

<template>
    <Head :title="`Row ${line.row_number}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4">
            <FlashMessage />

            <div class="flex items-center justify-between gap-2 text-sm text-muted-foreground">
                <Link v-if="nav.previous" :href="route('transactions.show', nav.previous)" class="flex items-center gap-1 hover:text-foreground">
                    <ChevronLeft class="size-4" /> Previous
                </Link>
                <span v-else />
                <span v-if="nav.position">{{ nav.position }} of {{ nav.total }}</span>
                <Link v-if="nav.next" :href="route('transactions.show', nav.next)" class="flex items-center gap-1 hover:text-foreground">
                    Next <ChevronRight class="size-4" />
                </Link>
                <span v-else />
            </div>

            <section class="rounded-xl border p-4">
                <div class="flex items-start justify-between gap-3">
                    <h1 class="text-lg font-semibold break-words">{{ line.description }}</h1>
                    <LineStatusBadge :status="line.status" />
                </div>
                <p class="mt-2 text-2xl font-semibold tabular-nums">{{ formatPence(line.amount) }}</p>
                <p class="text-sm text-muted-foreground">
                    {{ formatDate(line.date) }} · Row {{ line.row_number }}<span v-if="line.card_ref"> · {{ line.card_ref }}</span>
                    <span v-if="isCredit"> · Credit</span>
                </p>
                <dl v-if="extraColumns.length" class="mt-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                    <template v-for="[label, value] in extraColumns" :key="label">
                        <dt class="text-muted-foreground">{{ label }}</dt>
                        <dd class="break-words">{{ value }}</dd>
                    </template>
                </dl>
            </section>

            <p v-if="!editable && lockedReason" class="rounded-md border bg-muted px-4 py-3 text-sm">{{ lockedReason }}</p>

            <section class="flex flex-col gap-2">
                <Label for="purpose">What was it for?</Label>
                <textarea
                    id="purpose"
                    v-model="fields.purpose"
                    rows="3"
                    maxlength="1000"
                    :disabled="!editable"
                    :class="textareaClass"
                    placeholder="e.g. Lunch with a supplier to discuss the depot contract"
                    @input="queueSave"
                />
                <p v-if="saveErrors.purpose" class="text-sm text-red-600 dark:text-red-400">{{ saveErrors.purpose }}</p>
                <div v-if="editable && suggestions.length" class="flex flex-wrap gap-2">
                    <button
                        v-for="suggestion in suggestions"
                        :key="suggestion"
                        type="button"
                        class="max-w-full truncate rounded-full border px-3 py-1 text-xs hover:bg-accent"
                        @click="useSuggestion(suggestion)"
                    >
                        {{ suggestion }}
                    </button>
                </div>
            </section>

            <section class="flex flex-col gap-3">
                <div>
                    <h2 class="font-medium">Receipts</h2>
                    <p v-if="isCredit" class="text-sm text-muted-foreground">Credits only need a purpose.</p>
                </div>

                <div v-if="receipts.length" class="grid grid-cols-3 gap-3 sm:grid-cols-4">
                    <ReceiptThumb v-for="receipt in receipts" :key="receipt.id" :receipt="receipt">
                        <template v-if="editable" #actions>
                            <button type="button" class="flex-1 px-2 py-1.5 hover:bg-accent" @click="moveToInbox(receipt)">To inbox</button>
                            <button type="button" class="flex-1 px-2 py-1.5 text-red-600 hover:bg-accent dark:text-red-400" @click="deleteReceipt(receipt)">
                                Delete
                            </button>
                        </template>
                    </ReceiptThumb>
                </div>

                <template v-if="editable">
                    <ReceiptPicker :processing="upload.processing" @selected="uploadFiles" />
                    <p v-if="upload.progress" class="text-sm text-muted-foreground">Uploading… {{ upload.progress.percentage }}%</p>
                    <p v-if="uploadError" class="text-sm text-red-600 dark:text-red-400">{{ uploadError }} Your purpose has been kept – try again.</p>

                    <label v-if="!isCredit" class="flex items-center gap-2 text-sm">
                        <Checkbox :checked="fields.no_receipt" @update:checked="toggleNoReceipt" />
                        I don’t have a receipt for this
                    </label>
                    <div v-if="fields.no_receipt && !isCredit" class="flex flex-col gap-2">
                        <Label for="no_receipt_reason">Why is there no receipt?</Label>
                        <textarea
                            id="no_receipt_reason"
                            v-model="fields.no_receipt_reason"
                            rows="2"
                            maxlength="500"
                            :class="textareaClass"
                            placeholder="e.g. Parking machine didn’t print one"
                            @input="queueSave"
                        />
                        <p v-if="saveErrors.no_receipt_reason" class="text-sm text-red-600 dark:text-red-400">{{ saveErrors.no_receipt_reason }}</p>
                    </div>
                </template>
                <p v-else-if="line.no_receipt" class="text-sm">No receipt: {{ line.no_receipt_reason || '—' }}</p>
                <p v-else-if="!receipts.length" class="text-sm text-muted-foreground">No receipts attached.</p>
            </section>

            <section v-if="comments.length || line.status === 'queried'" class="flex flex-col gap-3 rounded-xl border p-4">
                <h2 class="font-medium">Questions from accounts</h2>
                <ul class="flex flex-col gap-2">
                    <li
                        v-for="item in comments"
                        :key="item.id"
                        class="max-w-[85%] rounded-lg px-3 py-2 text-sm"
                        :class="item.mine ? 'self-end bg-primary text-primary-foreground' : 'self-start bg-muted'"
                    >
                        <div class="whitespace-pre-line break-words">{{ item.body }}</div>
                        <div class="mt-1 text-xs opacity-75">{{ item.author }} · {{ formatDate(item.created_at) }}</div>
                    </li>
                </ul>
                <form v-if="can_comment" class="flex flex-col gap-2" @submit.prevent="sendComment">
                    <textarea v-model="comment.body" rows="2" maxlength="2000" :class="textareaClass" placeholder="Reply to accounts" />
                    <p v-if="comment.errors.body" class="text-sm text-red-600 dark:text-red-400">{{ comment.errors.body }}</p>
                    <Button type="submit" variant="outline" class="self-end" :disabled="!comment.body.trim() || comment.processing">Send reply</Button>
                </form>
            </section>

            <div class="sticky bottom-0 -mx-4 flex items-center gap-3 border-t bg-background/95 p-4 backdrop-blur">
                <span class="flex-1 text-sm text-muted-foreground" aria-live="polite">{{ saveLabel }}</span>
                <Button v-if="editable" :disabled="saveState === 'saving'" @click="saveAndNext">Save &amp; next</Button>
                <Button v-else-if="nav.next" variant="outline" as-child>
                    <Link :href="route('transactions.show', nav.next)">Next line</Link>
                </Button>
            </div>
        </div>
    </AppLayout>
</template>
```

- [ ] **Step 6: Build and commit**

Run: `npm run build`
Expected: build succeeds.

```bash
git add -A app routes resources/js tests
git commit -m "feat: line detail with autosave, receipt picker and query replies"
```

---

### Task 5: Uploading, viewing and deleting receipts

**Files:**
- Create: `app/Receipts/ReceiptType.php`, `app/Receipts/ReceiptStore.php`, `app/Receipts/ImageOptimiser.php`, `app/Jobs/ProcessReceiptImage.php`, `app/Http/Controllers/TransactionReceiptController.php`, `app/Http/Controllers/ReceiptController.php`
- Modify: `routes/cardholder.php`
- Test: `tests/Feature/Cardholder/ReceiptUploadTest.php`

**Interfaces:**
- Consumes: the `update` ability on `Transaction`, the `view` and `manage` abilities on `Receipt`, and `Transaction::refreshStatus()`.
- Produces:
  - `ReceiptType::MIMES`, `MAX_KB`, `extension(string $filename): ?string`, `contentMatches(string $extension, string $detected): bool`, `storedMime(string $extension, string $detected): string`, `rules(): array` and `messages(): array`.
  - `ReceiptStore::storeUpload(UploadedFile, User, ReceiptSource, ?Transaction = null): Receipt`.
  - `ReceiptStore::storeContents(string $contents, string $originalName, User, ReceiptSource, ?int $inboundMessageId = null): ?Receipt`, which returns null when the type is not accepted. Plan 5 uses it.
  - `ImageOptimiser::optimise(Receipt)` and the `ProcessReceiptImage` job.
  - Routes `transactions.receipts.store` (POST `files[]`, `source` camera|upload), `receipts.show` and `receipts.destroy`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Cardholder/ReceiptUploadTest.php`:

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Cardholder/ReceiptUploadTest.php`
Expected: FAIL. Route `transactions.receipts.store` is not defined.

- [ ] **Step 3: Write the receipt services, job and controllers**

`app/Receipts/ReceiptType.php`:

```php
<?php

namespace App\Receipts;

use Closure;
use Illuminate\Http\UploadedFile;

/** Which files count as receipts, and how to check them. */
final class ReceiptType
{
    public const MIMES = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'heic' => 'image/heic',
        'heif' => 'image/heif',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    public const MAX_KB = 20480;

    private const INLINE = ['application/pdf', 'image/jpeg', 'image/png'];

    /** The lower-cased extension when it is an accepted type, otherwise null. */
    public static function extension(string $filename): ?string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return array_key_exists($extension, self::MIMES) ? $extension : null;
    }

    /**
     * Browsers display PDFs and images themselves, so those bytes must really be a PDF or an image.
     * Other types are always downloaded, never displayed, so their contents aren't checked.
     */
    public static function contentMatches(string $extension, string $detected): bool
    {
        return match (self::MIMES[$extension]) {
            'application/pdf' => $detected === 'application/pdf',
            'image/jpeg', 'image/png' => in_array($detected, ['image/jpeg', 'image/png'], true),
            default => true,
        };
    }

    /** What we record and serve the file as. A ".jpg" that is really a PNG is served as a PNG. */
    public static function storedMime(string $extension, string $detected): string
    {
        $expected = self::MIMES[$extension];

        return in_array($expected, self::INLINE, true) && in_array($detected, self::INLINE, true) ? $detected : $expected;
    }

    /** Validation rules for one uploaded receipt. */
    public static function rules(): array
    {
        return [
            'file',
            'max:'.self::MAX_KB,
            'extensions:'.implode(',', array_keys(self::MIMES)),
            function (string $attribute, mixed $file, Closure $fail) {
                $extension = $file instanceof UploadedFile ? self::extension($file->getClientOriginalName()) : null;

                if ($extension !== null && ! self::contentMatches($extension, (string) $file->getMimeType())) {
                    $fail('That file doesn’t look like a real '.strtoupper($extension).'.');
                }
            },
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'files.required' => 'Choose at least one file.',
            'files.max' => 'Upload up to :max files at a time.',
            'files.*.max' => 'Each file must be 20 MB or smaller.',
            'files.*.extensions' => 'Receipts must be PDF, JPG, PNG, HEIC, DOC or DOCX files.',
        ];
    }
}
```

`app/Receipts/ReceiptStore.php`:

```php
<?php

namespace App\Receipts;

use App\Enums\ReceiptSource;
use App\Jobs\ProcessReceiptImage;
use App\Models\Receipt;
use App\Models\Transaction;
use App\Models\User;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** The one way receipt files reach storage: uploads now, email attachments in Plan 5. */
final class ReceiptStore
{
    public function storeUpload(UploadedFile $file, User $owner, ReceiptSource $source, ?Transaction $line = null): Receipt
    {
        $name = $file->getClientOriginalName();
        $extension = ReceiptType::extension($name) ?? throw new InvalidArgumentException("{$name} is not an accepted receipt type.");
        $path = $this->newPath($owner, $extension);

        Storage::disk($this->disk())->putFileAs(dirname($path), $file, basename($path));

        return $this->record($owner, $source, $line, $path, $name, ReceiptType::storedMime($extension, (string) $file->getMimeType()), (int) $file->getSize());
    }

    /** For receipts that arrive as bytes. Returns null when the file isn't an accepted, genuine receipt type. */
    public function storeContents(string $contents, string $originalName, User $owner, ReceiptSource $source, ?int $inboundMessageId = null): ?Receipt
    {
        $extension = ReceiptType::extension($originalName);
        $detected = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($contents);

        if ($extension === null || strlen($contents) > ReceiptType::MAX_KB * 1024 || ! ReceiptType::contentMatches($extension, $detected)) {
            return null;
        }

        $path = $this->newPath($owner, $extension);
        Storage::disk($this->disk())->put($path, $contents);

        return $this->record($owner, $source, null, $path, $originalName, ReceiptType::storedMime($extension, $detected), strlen($contents), $inboundMessageId);
    }

    private function record(User $owner, ReceiptSource $source, ?Transaction $line, string $path, string $name, string $mime, int $size, ?int $inboundMessageId = null): Receipt
    {
        $receipt = Receipt::create([
            'user_id' => $owner->id,
            'transaction_id' => $line?->id,
            'disk' => $this->disk(),
            'path' => $path,
            'original_name' => Str::limit($name, 250, ''),
            'mime' => $mime,
            'size' => $size,
            'source' => $source,
            'inbound_message_id' => $inboundMessageId,
        ]);

        if (str_starts_with($mime, 'image/')) {
            ProcessReceiptImage::dispatch($receipt)->afterCommit();
        }

        return $receipt;
    }

    private function newPath(User $owner, string $extension): string
    {
        return $owner->id.'/'.Str::uuid().'.'.$extension;
    }

    private function disk(): string
    {
        return config('cardtrail.receipts.disk');
    }
}
```

`app/Receipts/ImageOptimiser.php`:

```php
<?php

namespace App\Receipts;

use App\Models\Receipt;
use GdImage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Imagick;

/** Turns HEIC photos into JPEGs and shrinks big photos, so receipts load quickly on phones. */
final class ImageOptimiser
{
    public const MAX_EDGE = 2000;

    public function optimise(Receipt $receipt): void
    {
        $heic = in_array($receipt->mime, ['image/heic', 'image/heif'], true);

        if (! $heic && ! in_array($receipt->mime, ['image/jpeg', 'image/png'], true)) {
            return;
        }

        if ($heic && ! (class_exists(Imagick::class) && Imagick::queryFormats('HEIC') !== [])) {
            Log::warning('Receipt kept as HEIC because Imagick with HEIC support is not installed.', ['receipt_id' => $receipt->id]);

            return;
        }

        $disk = Storage::disk($receipt->disk);
        $original = $disk->get($receipt->path);

        if ($original === null) {
            return;
        }

        $result = class_exists(Imagick::class) ? $this->withImagick($original, $heic) : $this->withGd($original, $receipt->mime);

        if ($result === null) {
            return;
        }

        [$bytes, $mime, $extension] = $result;
        $path = preg_replace('/\.[^.\/]+$/', '.'.$extension, $receipt->path);

        $disk->put($path, $bytes);

        if ($path !== $receipt->path) {
            $disk->delete($receipt->path);
        }

        $receipt->update(['path' => $path, 'mime' => $mime, 'size' => strlen($bytes)]);
    }

    /** @return array{0: string, 1: string, 2: string}|null */
    private function withImagick(string $bytes, bool $heic): ?array
    {
        $image = new Imagick;
        $image->readImageBlob($bytes);
        $large = max($image->getImageWidth(), $image->getImageHeight()) > self::MAX_EDGE;

        if (! $heic && ! $large) {
            return null;
        }

        if (method_exists($image, 'autoOrient')) {
            $image->autoOrient();
        }

        if ($large) {
            $image->thumbnailImage(self::MAX_EDGE, self::MAX_EDGE, true);
        }

        if ($heic) {
            $image->setImageFormat('jpeg');
        }

        $image->setImageCompressionQuality(85);
        $png = strtolower($image->getImageFormat()) === 'png';

        return [$image->getImagesBlob(), $png ? 'image/png' : 'image/jpeg', $png ? 'png' : 'jpg'];
    }

    /** @return array{0: string, 1: string, 2: string}|null */
    private function withGd(string $bytes, string $mime): ?array
    {
        $size = @getimagesizefromstring($bytes);

        if (! $size || max($size[0], $size[1]) <= self::MAX_EDGE) {
            return null;
        }

        $image = @imagecreatefromstring($bytes);

        if (! $image) {
            return null;
        }

        if ($mime === 'image/jpeg') {
            $image = $this->orient($image, $bytes);
        }

        $scale = self::MAX_EDGE / max(imagesx($image), imagesy($image));
        $resized = imagescale($image, max(1, (int) round(imagesx($image) * $scale)), max(1, (int) round(imagesy($image) * $scale)));

        ob_start();
        if ($mime === 'image/png') {
            imagesavealpha($resized, true);
            imagepng($resized, null, 6);
        } else {
            imagejpeg($resized, null, 85);
        }

        return [(string) ob_get_clean(), $mime, $mime === 'image/png' ? 'png' : 'jpg'];
    }

    /** GD drops EXIF data, so turn the pixels the way the camera intended before re-saving. */
    private function orient(GdImage $image, string $bytes): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));

        return match ((int) ($exif['Orientation'] ?? 1)) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };
    }
}
```

`app/Jobs/ProcessReceiptImage.php`:

```php
<?php

namespace App\Jobs;

use App\Models\Receipt;
use App\Receipts\ImageOptimiser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessReceiptImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** The receipt may be deleted before the job runs. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public Receipt $receipt) {}

    public function handle(ImageOptimiser $optimiser): void
    {
        $optimiser->optimise($this->receipt);
    }
}
```

`app/Http/Controllers/TransactionReceiptController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Enums\ReceiptSource;
use App\Models\Transaction;
use App\Receipts\ReceiptStore;
use App\Receipts\ReceiptType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TransactionReceiptController extends Controller
{
    public function store(Request $request, Transaction $transaction, ReceiptStore $store): RedirectResponse
    {
        Gate::authorize('update', $transaction);

        $data = $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:10'],
            'files.*' => ReceiptType::rules(),
            'source' => ['required', Rule::in(['camera', 'upload'])],
        ], ReceiptType::messages());

        $files = $request->file('files');

        DB::transaction(function () use ($request, $transaction, $store, $data, $files) {
            $line = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('update', $line);

            foreach ($files as $file) {
                $store->storeUpload($file, $request->user(), ReceiptSource::from($data['source']), $line);
            }

            $line->refreshStatus();
            $line->save();

            activity('lines')
                ->causedBy($request->user())
                ->performedOn($line)
                ->event('receipts_added')
                ->withProperties(['count' => count($files)])
                ->log("Added receipts to row {$line->row_number}");
        });

        return back()->with('success', count($files) === 1 ? 'Receipt added.' : count($files).' receipts added.');
    }
}
```

`app/Http/Controllers/ReceiptController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Receipt;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReceiptController extends Controller
{
    /** Receipts are never publicly addressable; every view passes through the policy. */
    public function show(Receipt $receipt): StreamedResponse
    {
        Gate::authorize('view', $receipt);

        return Storage::disk($receipt->disk)->response(
            $receipt->path,
            $receipt->downloadName(),
            [
                'Content-Type' => $receipt->mime,
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, max-age=3600',
            ],
            $receipt->viewableInline() ? 'inline' : 'attachment',
        );
    }

    public function destroy(Request $request, Receipt $receipt): RedirectResponse
    {
        Gate::authorize('manage', $receipt);

        DB::transaction(function () use ($request, $receipt) {
            $line = $receipt->transaction_id
                ? Transaction::query()->whereKey($receipt->transaction_id)->lockForUpdate()->first()
                : null;

            $receipt->delete();

            if ($line) {
                $line->refreshStatus();
                $line->save();
            }

            activity('receipts')
                ->causedBy($request->user())
                ->event('receipt_deleted')
                ->withProperties(['name' => $receipt->original_name, 'transaction_id' => $line?->id])
                ->log("Deleted receipt {$receipt->original_name}");
        });

        return back()->with('success', 'Receipt deleted.');
    }
}
```

`routes/cardholder.php` (whole file, replacing the current one):

```php
<?php

use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\ReconciliationController;
use App\Http\Controllers\TransactionCommentController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TransactionReceiptController;
use Illuminate\Support\Facades\Route;

// Cardholder screens. Policies decide what each person may see; these routes only require sign-in.
Route::middleware('auth')->group(function () {
    Route::get('reconciliations/{reconciliation}', [ReconciliationController::class, 'show'])->name('reconciliations.show');

    Route::get('transactions/{transaction}', [TransactionController::class, 'show'])->name('transactions.show');
    Route::put('transactions/{transaction}', [TransactionController::class, 'update'])->name('transactions.update');
    Route::post('transactions/{transaction}/comments', [TransactionCommentController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('transactions.comments.store');
    Route::post('transactions/{transaction}/receipts', [TransactionReceiptController::class, 'store'])
        ->middleware('throttle:60,1')
        ->name('transactions.receipts.store');

    Route::get('receipts/{receipt}', [ReceiptController::class, 'show'])->name('receipts.show');
    Route::delete('receipts/{receipt}', [ReceiptController::class, 'destroy'])->name('receipts.destroy');
});
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Cardholder/ReceiptUploadTest.php`
Expected: PASS (11 tests; the HEIC test is skipped only where Imagick can read HEIC).

- [ ] **Step 5: Commit**

```bash
git add -A app routes tests
git commit -m "feat: upload, view and delete receipts; shrink large photos"
```

---

### Task 6: Receipt inbox and matching

**Files:**
- Create: `app/Http/Controllers/ReceiptInboxController.php`, `app/Http/Controllers/ReceiptMatchController.php`, `resources/js/pages/receipts/Inbox.vue`, `resources/js/pages/receipts/Match.vue`
- Modify: `routes/cardholder.php`, `resources/js/components/AppSidebar.vue`
- Test: `tests/Feature/Cardholder/InboxTest.php`

**Interfaces:**
- Consumes: `ReceiptStore::storeUpload()`, `ReceiptType::rules()`/`messages()` (Task 5), `AmountParser::parse(mixed): ?int` (Plan 2), and the policies.
- Produces:
  - Routes `receipts.index`.
  - `receipts.store` (POST `files[]`, `source` camera|upload; uploads are recorded as `bulk`).
  - `receipts.match` (GET, optional `amount`).
  - `receipts.match.store` (PUT `transaction_id`).
  - `receipts.match.destroy` (DELETE: back to the inbox).

- [ ] **Step 1: Write the failing test**

`tests/Feature/Cardholder/InboxTest.php`:

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Cardholder/InboxTest.php`
Expected: FAIL. Route `receipts.store` is not defined.

- [ ] **Step 3: Write the controllers and routes**

`app/Http/Controllers/ReceiptInboxController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Enums\ReceiptSource;
use App\Models\Receipt;
use App\Receipts\ReceiptStore;
use App\Receipts\ReceiptType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ReceiptInboxController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('receipts/Inbox', [
            'receipts' => $request->user()->receipts()->inInbox()->latest('id')->get()->map(fn (Receipt $receipt) => $receipt->toListItem()),
            'address' => config('cardtrail.receipts.address'),
        ]);
    }

    public function store(Request $request, ReceiptStore $store): RedirectResponse
    {
        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:20'],
            'files.*' => ReceiptType::rules(),
            'source' => ['nullable', Rule::in(['camera', 'upload'])],
        ], ReceiptType::messages());

        $files = $request->file('files');
        $source = $request->input('source') === 'camera' ? ReceiptSource::Camera : ReceiptSource::Bulk;

        DB::transaction(function () use ($files, $request, $source, $store) {
            foreach ($files as $file) {
                $store->storeUpload($file, $request->user(), $source);
            }
        });

        return back()->with('success', 'Added '.count($files).' '.Str::plural('receipt', count($files)).' to your inbox.');
    }
}
```

`app/Http/Controllers/ReceiptMatchController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Enums\PeriodStatus;
use App\Enums\TransactionStatus;
use App\Imports\Parsing\AmountParser;
use App\Models\Receipt;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ReceiptMatchController extends Controller
{
    /** Lines this receipt could belong to. Nothing is ever attached automatically. */
    public function show(Request $request, Receipt $receipt): Response|RedirectResponse
    {
        Gate::authorize('manage', $receipt);

        if ($receipt->transaction_id) {
            return to_route('transactions.show', $receipt->transaction_id);
        }

        $typed = (string) $request->query('amount', '');
        $amount = $typed === '' ? null : AmountParser::parse($typed);
        $amount = $amount === null ? null : abs($amount);
        $anchor = $receipt->created_at->toImmutable()->startOfDay();

        $exact = fn (Transaction $line) => $amount !== null && abs($line->amount) === $amount;
        $daysAway = fn (Transaction $line) => abs($line->transacted_on->diffInDays($anchor));
        $amountGap = fn (Transaction $line) => $amount === null ? 0 : abs(abs($line->amount) - $amount);

        $candidates = Transaction::query()
            ->whereHas('reconciliation', fn ($query) => $query->where('user_id', $request->user()->id))
            ->whereHas('period', fn ($query) => $query->where('status', PeriodStatus::Published))
            ->where('status', '!=', TransactionStatus::Approved)
            ->with(['period', 'reconciliation'])
            ->withCount('receipts')
            ->get()
            ->filter(fn (Transaction $line) => $line->editableByOwner())
            ->sortBy([
                fn (Transaction $a, Transaction $b) => $exact($b) <=> $exact($a),
                fn (Transaction $a, Transaction $b) => $daysAway($a) <=> $daysAway($b),
                fn (Transaction $a, Transaction $b) => $amountGap($a) <=> $amountGap($b),
            ])
            ->take(50)
            ->values();

        return Inertia::render('receipts/Match', [
            'receipt' => $receipt->toListItem(),
            'amount' => $typed,
            'candidates' => $candidates->map(fn (Transaction $line) => [
                'id' => $line->id,
                'row_number' => $line->row_number,
                'date' => $line->transacted_on->format('Y-m-d'),
                'description' => $line->description,
                'amount' => $line->amount,
                'status' => $line->status->value,
                'receipts' => $line->receipts_count,
                'period' => $line->period->label,
            ]),
        ]);
    }

    public function store(Request $request, Receipt $receipt): RedirectResponse
    {
        Gate::authorize('manage', $receipt);

        $data = $request->validate(['transaction_id' => ['required', 'integer']]);

        $line = DB::transaction(function () use ($request, $receipt, $data) {
            $line = Transaction::query()->whereKey($data['transaction_id'])->lockForUpdate()->firstOrFail();
            Gate::authorize('update', $line);

            $receipt = Receipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();

            if ($receipt->transaction_id !== null) {
                return null;
            }

            $receipt->update(['transaction_id' => $line->id]);
            $line->refreshStatus();
            $line->save();

            activity('lines')
                ->causedBy($request->user())
                ->performedOn($line)
                ->event('receipt_matched')
                ->withProperties(['receipt_id' => $receipt->id])
                ->log("Matched {$receipt->original_name} to row {$line->row_number}");

            return $line;
        });

        if (! $line) {
            return back()->with('error', 'That receipt is already on a line.');
        }

        return to_route('transactions.show', $line)->with('success', "Receipt attached to row {$line->row_number}.");
    }

    public function destroy(Request $request, Receipt $receipt): RedirectResponse
    {
        Gate::authorize('manage', $receipt);

        if (! $receipt->transaction_id) {
            return back();
        }

        DB::transaction(function () use ($request, $receipt) {
            $line = Transaction::query()->whereKey($receipt->transaction_id)->lockForUpdate()->firstOrFail();
            $receipt->update(['transaction_id' => null]);
            $line->refreshStatus();
            $line->save();

            activity('lines')
                ->causedBy($request->user())
                ->performedOn($line)
                ->event('receipt_unmatched')
                ->withProperties(['receipt_id' => $receipt->id])
                ->log("Moved {$receipt->original_name} from row {$line->row_number} to the inbox");
        });

        return back()->with('success', 'Receipt moved to your inbox.');
    }
}
```

`routes/cardholder.php` (whole file, replacing the current one):

```php
<?php

use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\ReceiptInboxController;
use App\Http\Controllers\ReceiptMatchController;
use App\Http\Controllers\ReconciliationController;
use App\Http\Controllers\TransactionCommentController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TransactionReceiptController;
use Illuminate\Support\Facades\Route;

// Cardholder screens. Policies decide what each person may see; these routes only require sign-in.
Route::middleware('auth')->group(function () {
    Route::get('reconciliations/{reconciliation}', [ReconciliationController::class, 'show'])->name('reconciliations.show');

    Route::get('transactions/{transaction}', [TransactionController::class, 'show'])->name('transactions.show');
    Route::put('transactions/{transaction}', [TransactionController::class, 'update'])->name('transactions.update');
    Route::post('transactions/{transaction}/comments', [TransactionCommentController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('transactions.comments.store');
    Route::post('transactions/{transaction}/receipts', [TransactionReceiptController::class, 'store'])
        ->middleware('throttle:60,1')
        ->name('transactions.receipts.store');

    Route::get('receipts', [ReceiptInboxController::class, 'index'])->name('receipts.index');
    Route::post('receipts', [ReceiptInboxController::class, 'store'])->middleware('throttle:30,1')->name('receipts.store');
    Route::get('receipts/{receipt}', [ReceiptController::class, 'show'])->name('receipts.show');
    Route::delete('receipts/{receipt}', [ReceiptController::class, 'destroy'])->name('receipts.destroy');
    Route::get('receipts/{receipt}/match', [ReceiptMatchController::class, 'show'])->name('receipts.match');
    Route::put('receipts/{receipt}/match', [ReceiptMatchController::class, 'store'])->name('receipts.match.store');
    Route::delete('receipts/{receipt}/match', [ReceiptMatchController::class, 'destroy'])->name('receipts.match.destroy');
});
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Cardholder/InboxTest.php`
Expected: PASS (6 tests).

- [ ] **Step 5: Write the screens and the menu entry**

`resources/js/pages/receipts/Inbox.vue`:

```vue
<script setup lang="ts">
import FlashMessage from '@/components/FlashMessage.vue';
import ReceiptPicker from '@/components/ReceiptPicker.vue';
import ReceiptThumb from '@/components/ReceiptThumb.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import type { ReceiptItem } from '@/types/cardtrail';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Mail } from 'lucide-vue-next';
import { computed } from 'vue';

defineProps<{ receipts: ReceiptItem[]; address: string | null }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Receipt inbox', href: '/receipts' },
];

const upload = useForm<{ files: File[]; source: 'camera' | 'upload' }>({ files: [], source: 'upload' });
const uploadError = computed(() => Object.values(upload.errors)[0] ?? null);

const uploadFiles = (files: File[], source: 'camera' | 'upload') => {
    upload.files = files;
    upload.source = source;
    upload.post(route('receipts.store'), { forceFormData: true, preserveScroll: true, onSuccess: () => upload.reset() });
};

const remove = (receipt: ReceiptItem) => {
    if (confirm(`Delete ${receipt.name}? This can’t be undone.`)) {
        router.delete(route('receipts.destroy', receipt.id), { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Receipt inbox" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-4 p-4">
            <FlashMessage />

            <div>
                <h1 class="text-xl font-semibold">Receipt inbox</h1>
                <p class="text-sm text-muted-foreground">Upload receipts as you get them, then match each one to a line when the month is published.</p>
            </div>

            <div v-if="address" class="flex items-start gap-3 rounded-xl border p-4 text-sm">
                <Mail class="mt-0.5 size-5 shrink-0" />
                <p>
                    You can also email receipts to <strong class="break-all">{{ address }}</strong> from your work email address. Attachments land here.
                </p>
            </div>

            <ReceiptPicker :processing="upload.processing" @selected="uploadFiles" />
            <p v-if="upload.progress" class="text-sm text-muted-foreground">Uploading… {{ upload.progress.percentage }}%</p>
            <p v-if="uploadError" class="text-sm text-red-600 dark:text-red-400">{{ uploadError }}</p>

            <div v-if="receipts.length" class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                <ReceiptThumb v-for="receipt in receipts" :key="receipt.id" :receipt="receipt">
                    <template #actions>
                        <Link :href="route('receipts.match', receipt.id)" class="flex-1 px-2 py-1.5 text-center font-medium hover:bg-accent">Match</Link>
                        <button type="button" class="flex-1 px-2 py-1.5 text-red-600 hover:bg-accent dark:text-red-400" @click="remove(receipt)">Delete</button>
                    </template>
                </ReceiptThumb>
            </div>
            <p v-else class="rounded-xl border p-4 text-sm text-muted-foreground">Your inbox is empty.</p>
        </div>
    </AppLayout>
</template>
```

`resources/js/pages/receipts/Match.vue`:

```vue
<script setup lang="ts">
import FlashMessage from '@/components/FlashMessage.vue';
import LineStatusBadge from '@/components/LineStatusBadge.vue';
import ReceiptThumb from '@/components/ReceiptThumb.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatPence } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';
import type { LineStatus, ReceiptItem } from '@/types/cardtrail';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

interface Candidate {
    id: number;
    row_number: number;
    date: string;
    description: string;
    amount: number;
    status: LineStatus;
    receipts: number;
    period: string;
}

const props = defineProps<{ receipt: ReceiptItem; amount: string; candidates: Candidate[] }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Receipt inbox', href: '/receipts' },
    { title: 'Match', href: '#' },
];

const amount = ref(props.amount);
const attaching = ref<number | null>(null);

const sort = () => router.get(route('receipts.match', props.receipt.id), amount.value ? { amount: amount.value } : {}, { preserveState: true });

const attach = (line: Candidate) => {
    router.put(route('receipts.match.store', props.receipt.id), { transaction_id: line.id }, {
        onStart: () => (attaching.value = line.id),
        onFinish: () => (attaching.value = null),
    });
};
</script>

<template>
    <Head title="Match receipt" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-4 p-4 md:flex-row md:items-start">
            <div class="md:w-56 md:shrink-0">
                <ReceiptThumb :receipt="receipt" />
            </div>

            <div class="flex min-w-0 flex-1 flex-col gap-4">
                <FlashMessage />
                <div>
                    <h1 class="text-xl font-semibold">Which line is this receipt for?</h1>
                    <p class="text-sm text-muted-foreground">Lines closest to the date you added the receipt come first.</p>
                </div>

                <form class="flex gap-2" @submit.prevent="sort">
                    <Input v-model="amount" inputmode="decimal" placeholder="Amount on the receipt, e.g. 12.50" aria-label="Amount on the receipt" />
                    <Button type="submit" variant="outline">Sort</Button>
                </form>

                <ul class="divide-y rounded-lg border">
                    <li v-for="line in candidates" :key="line.id" class="flex items-center gap-3 p-4 text-sm">
                        <div class="min-w-0 flex-1">
                            <div class="truncate font-medium">{{ line.description }}</div>
                            <div class="text-muted-foreground">
                                {{ line.period }} · Row {{ line.row_number }} · {{ formatDate(line.date) }}
                                <span v-if="line.receipts"> · {{ line.receipts }} attached</span>
                            </div>
                        </div>
                        <div class="shrink-0 text-right">
                            <div class="font-medium tabular-nums">{{ formatPence(line.amount) }}</div>
                            <LineStatusBadge :status="line.status" />
                        </div>
                        <Button size="sm" :disabled="attaching !== null" @click="attach(line)">Attach</Button>
                    </li>
                    <li v-if="!candidates.length" class="p-4 text-sm text-muted-foreground">There are no open lines to attach this to yet.</li>
                </ul>
            </div>
        </div>
    </AppLayout>
</template>
```

`resources/js/components/AppSidebar.vue` (whole file, replacing the current one):

```vue
<script setup lang="ts">
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { CalendarRange, Inbox, LayoutGrid, Users } from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from './AppLogo.vue';

const page = usePage<SharedData>();

const mainNavItems = computed<NavItem[]>(() => [
    { title: 'Dashboard', href: '/dashboard', icon: LayoutGrid },
    ...(page.props.auth.user.is_cardholder ? [{ title: 'Receipt inbox', href: '/receipts', icon: Inbox }] : []),
    ...(page.props.auth.user.is_admin
        ? [
              { title: 'Periods', href: '/admin/periods', icon: CalendarRange },
              { title: 'Manage users', href: '/admin/users', icon: Users },
          ]
        : []),
]);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="route('dashboard')">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
```

- [ ] **Step 6: Build and commit**

Run: `npm run build`
Expected: build succeeds.

```bash
git add -A app routes resources/js tests
git commit -m "feat: receipt inbox with bulk upload and line matching"
```

---

### Task 7: Submitting, row locks and moving receipts with lines

**Files:**
- Create: `app/Events/ReconciliationSubmitted.php`, `app/Reconciliations/CannotSubmit.php`, `app/Reconciliations/ReconciliationSubmitter.php`, `app/Http/Controllers/SubmitReconciliationController.php`
- Modify: `app/Models/Reconciliation.php` (`linesAdded()` locks its row), `app/Periods/TransactionAssigner.php`, `routes/cardholder.php`
- Test: `tests/Feature/Cardholder/SubmitTest.php`, `tests/Feature/Cardholder/MovedLinesTest.php`

**Interfaces:**
- Consumes: the `submit` ability (Task 2).
- Produces:
  - `ReconciliationSubmitter::submit(Reconciliation, User): Reconciliation`, which throws `CannotSubmit`.
  - Event `ReconciliationSubmitted(Reconciliation $reconciliation, bool $resubmitted)`, which Plan 5 notifies admins about.
  - Route `reconciliations.submit`.
  - `submitted_at` keeps the first submission time until the next submit, so `resubmitted` is true when it is already set.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Cardholder/SubmitTest.php`:

```php
<?php

use App\Enums\ReconciliationStatus;
use App\Enums\TransactionStatus;
use App\Events\ReconciliationSubmitted;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Event::fake([ReconciliationSubmitted::class]);
    $this->sam = User::factory()->create();
});

it('submits a month once every line is done', function () {
    $line = lineFor($this->sam, ['status' => TransactionStatus::Done]);
    lineFor($this->sam, ['status' => TransactionStatus::Approved], $line->period);
    $reconciliation = $line->reconciliation;

    $this->actingAs($this->sam)->post(route('reconciliations.submit', $reconciliation))
        ->assertRedirect(route('reconciliations.show', $reconciliation))
        ->assertSessionHas('success');

    expect($reconciliation->fresh())
        ->status->toBe(ReconciliationStatus::Submitted)
        ->submitted_at->not->toBeNull();
    Event::assertDispatched(ReconciliationSubmitted::class, fn ($event) => $event->reconciliation->is($reconciliation) && ! $event->resubmitted);
    expect(Activity::query()->where('event', 'submitted')->exists())->toBeTrue();
});

it('refuses to submit while a line is still to do or queried', function (TransactionStatus $status) {
    $line = lineFor($this->sam, ['status' => TransactionStatus::Done]);
    lineFor($this->sam, ['status' => $status], $line->period);

    $this->actingAs($this->sam)->post(route('reconciliations.submit', $line->reconciliation))
        ->assertSessionHas('error');

    expect($line->reconciliation->fresh()->status)->toBe(ReconciliationStatus::InProgress);
    Event::assertNotDispatched(ReconciliationSubmitted::class);
})->with([TransactionStatus::Todo, TransactionStatus::Queried]);

it('marks a second submission as a resubmission', function () {
    $line = lineFor($this->sam, ['status' => TransactionStatus::Done]);
    $line->reconciliation->update(['submitted_at' => now()->subDay()]);

    $this->actingAs($this->sam)->post(route('reconciliations.submit', $line->reconciliation));

    Event::assertDispatched(ReconciliationSubmitted::class, fn ($event) => $event->resubmitted);
    expect(Activity::query()->where('event', 'resubmitted')->exists())->toBeTrue();
});

it('explains rather than errors when a month is already submitted', function () {
    $line = lineFor($this->sam, ['status' => TransactionStatus::Done]);
    $line->reconciliation->update(['status' => ReconciliationStatus::Submitted]);

    $this->actingAs($this->sam)->post(route('reconciliations.submit', $line->reconciliation))
        ->assertSessionHas('error');
});

it('only lets the owner submit', function () {
    $line = lineFor($this->sam, ['status' => TransactionStatus::Done]);

    $this->actingAs(User::factory()->create())->post(route('reconciliations.submit', $line->reconciliation))->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->post(route('reconciliations.submit', $line->reconciliation))->assertForbidden();

    expect($line->reconciliation->fresh()->status)->toBe(ReconciliationStatus::InProgress);
});
```

`tests/Feature/Cardholder/MovedLinesTest.php`:

```php
<?php

use App\Enums\TransactionStatus;
use App\Models\Comment;
use App\Models\Receipt;
use App\Models\User;
use App\Periods\TransactionAssigner;

it('sends receipts back to the previous owner’s inbox and drops the thread when a line moves', function () {
    $sam = User::factory()->create();
    $jo = User::factory()->create();
    $admin = User::factory()->admin()->create();
    $line = lineFor($sam, ['purpose' => 'Hotel', 'status' => TransactionStatus::Done]);
    $receipt = Receipt::factory()->attachedTo($line)->create();
    Comment::factory()->for($line)->for($admin)->create();

    app(TransactionAssigner::class)->assign($line->period, [$line->id], $jo, $admin);

    expect($receipt->fresh())
        ->transaction_id->toBeNull()
        ->user_id->toBe($sam->id)
        ->and($line->comments()->count())->toBe(0)
        ->and($line->fresh()->status)->toBe(TransactionStatus::Todo);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Cardholder/SubmitTest.php tests/Feature/Cardholder/MovedLinesTest.php`
Expected: FAIL. Class `ReconciliationSubmitted` and route `reconciliations.submit` are not defined, and the moved line keeps its receipt.

- [ ] **Step 3: Write the submitter, event and controller; fix the assigner and the lock**

`app/Events/ReconciliationSubmitted.php`:

```php
<?php

namespace App\Events;

use App\Models\Reconciliation;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A cardholder submitted (or resubmitted after a query) their month. Plan 5 tells the admins. */
class ReconciliationSubmitted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public Reconciliation $reconciliation,
        public bool $resubmitted,
    ) {}
}
```

`app/Reconciliations/CannotSubmit.php`:

```php
<?php

namespace App\Reconciliations;

use RuntimeException;

final class CannotSubmit extends RuntimeException {}
```

`app/Reconciliations/ReconciliationSubmitter.php`:

```php
<?php

namespace App\Reconciliations;

use App\Enums\ReconciliationStatus;
use App\Enums\TransactionStatus;
use App\Events\ReconciliationSubmitted;
use App\Models\Reconciliation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ReconciliationSubmitter
{
    /** @throws CannotSubmit */
    public function submit(Reconciliation $reconciliation, User $user): Reconciliation
    {
        return DB::transaction(function () use ($reconciliation, $user) {
            // Locked so lines arriving at the same moment (late import, reassignment) can't slip in unseen.
            $reconciliation = Reconciliation::query()->whereKey($reconciliation->id)->lockForUpdate()->firstOrFail();

            if (! $user->can('submit', $reconciliation)) {
                throw new CannotSubmit('This month can’t be submitted right now.');
            }

            if (! $reconciliation->transactions()->exists()) {
                throw new CannotSubmit('There are no lines to submit.');
            }

            $open = $reconciliation->transactions()->whereIn('status', [TransactionStatus::Todo, TransactionStatus::Queried])->count();

            if ($open > 0) {
                throw new CannotSubmit("{$open} ".Str::plural('line', $open).' still need finishing before you can submit.');
            }

            $resubmitted = $reconciliation->submitted_at !== null;
            $reconciliation->update(['status' => ReconciliationStatus::Submitted, 'submitted_at' => now()]);

            ReconciliationSubmitted::dispatch($reconciliation, $resubmitted);

            activity('reconciliations')
                ->causedBy($user)
                ->performedOn($reconciliation)
                ->event($resubmitted ? 'resubmitted' : 'submitted')
                ->log(($resubmitted ? 'Resubmitted ' : 'Submitted ').$reconciliation->period->label);

            return $reconciliation;
        });
    }
}
```

`app/Http/Controllers/SubmitReconciliationController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Reconciliation;
use App\Reconciliations\CannotSubmit;
use App\Reconciliations\ReconciliationSubmitter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SubmitReconciliationController extends Controller
{
    public function __invoke(Request $request, Reconciliation $reconciliation, ReconciliationSubmitter $submitter): RedirectResponse
    {
        // Only the owner may submit; whether it can be submitted right now is explained, not refused with a 403.
        abort_unless($reconciliation->user_id === $request->user()->id, 403);

        try {
            $submitter->submit($reconciliation, $request->user());
        } catch (CannotSubmit $e) {
            return back()->with('error', $e->getMessage());
        }

        return to_route('reconciliations.show', $reconciliation)
            ->with('success', "{$reconciliation->period->label} is submitted. Accounts will be in touch if they have questions.");
    }
}
```

In `routes/cardholder.php`, add the import `use App\Http\Controllers\SubmitReconciliationController;` and add this line after the `reconciliations.show` route:

```php
    Route::post('reconciliations/{reconciliation}/submit', SubmitReconciliationController::class)->name('reconciliations.submit');
```

In `app/Models/Reconciliation.php`, replace `linesAdded()` with:

```php
    /** New lines arrived: anything already submitted or approved needs looking at again. Call inside a transaction. */
    public function linesAdded(int $count): void
    {
        // Locked so a submit happening at the same moment can't slip past the reopen.
        $current = self::query()->whereKey($this->id)->lockForUpdate()->firstOrFail();

        if (in_array($current->status, [ReconciliationStatus::Submitted, ReconciliationStatus::Approved], true)) {
            $current->update([
                'status' => ReconciliationStatus::InProgress,
                'approved_at' => null,
                'approved_by' => null,
            ]);
        }

        $this->setRawAttributes($current->getAttributes(), true);

        if ($this->period->status === PeriodStatus::Published) {
            ReconciliationLinesAdded::dispatch($this, $count);
        }
    }
```

In `app/Periods/TransactionAssigner.php`, add `use App\Models\Comment;` and `use App\Models\Receipt;`, then insert this before the `foreach ($moving as $line)` loop:

```php
            // Receipts belong to whoever uploaded them, and a query thread is about their work: neither travels with the line.
            Receipt::query()->whereIn('transaction_id', $moving->pluck('id'))->update(['transaction_id' => null]);
            Comment::query()->whereIn('transaction_id', $moving->pluck('id'))->delete();
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Cardholder tests/Feature/Admin/PeriodLinesTest.php tests/Feature/Imports`
Expected: PASS.

- [ ] **Step 5: Run the full suite, build and commit**

Run: `php artisan test && npm run build`
Expected: all pass; build succeeds.

```bash
git add -A app routes tests
git commit -m "feat: submit a month; receipts return to the inbox when lines move"
```
