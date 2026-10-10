# CardTrail Plan 2 — Periods and Imports Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Admins create monthly periods, upload card-statement exports (CSV/XLSX/XLS), map columns once (remembered for next month), preview every row with problems and duplicates flagged, confirm the import so lines are auto-assigned to cardholders, sort out unassigned lines, and publish the period.

**Architecture:** Pure parsing classes (`app/Imports/Parsing`) turn spreadsheet cells into typed rows with no framework dependency. `ImportSheet` reads the stored file once per request via PhpSpreadsheet. `ImportPreviewer` builds a preview (parsed rows, cardholder matches, duplicates) and `ImportCommitter` turns a preview into transactions and reconciliations inside one DB transaction. `TransactionAssigner` moves lines between people. Domain events (`ReconciliationLinesAdded`, `PeriodPublished`) are dispatched after commit for Plan 5's notifications; this plan adds no listeners.

**Tech Stack:** Laravel 12, Inertia 2, Vue 3 + TypeScript, Pest 3, `phpoffice/phpspreadsheet` ^5, `spatie/laravel-activitylog` (installed in Plan 1).

**Spec:** `docs/superpowers/specs/2026-10-10-cardtrail-design.md` (this plan covers §4.1–4.3, §5 Period/Import/ColumnMapping/Reconciliation/Transaction, §5.1 reassignment and late-import rules, §6.2, §7 screens 5–7, §8 import errors).

**Builds on:** Plan 1 (`docs/superpowers/plans/2026-10-10-cardtrail-plan-1-foundation.md`), all committed on `main`. Relevant existing code: `User` (`is_admin`, `is_cardholder`, `active`, `cardIdentifiers()`), `CardIdentifier` (`last4`, `cardholder_name`), `admin` route middleware, `routes/admin.php`, `FlashMessage.vue`, `AppSidebar.vue`, `UserFactory` states `admin()`, `microsoft()`, `inactive()`.

## Global Constraints

- Everything in Plan 1's Global Constraints still applies (git author `donashalin <donashalin@gmail.com>`, never commit `.env`, invented data only, UK English, emails lower-cased).
- Commit messages are plain: no Co-Authored-By, session or other AI attribution lines. The user is the sole author.
- **Money is an integer number of pence.** Negative = credit/refund. Never store floats.
- **Only the last 4 digits of a card number are ever stored.** Any run of 13–19 digits (spaces/dashes allowed) in a description or extra column is masked to `**** 1234` before saving.
- **Row numbers are the real spreadsheet row numbers** (blank rows still count), so "Row29" means row 29 in the file the accountant uploaded.
- Uploaded statements are stored on the `local` disk (`storage/app/private`) under `imports/{period_id}/`. Never on a public disk.
- Compare `date` columns with `whereDate()`, never `where()` — SQLite tests store dates with a time part.
- UK day-first dates: `03/10/2026` is 3 October 2026.
- Tests build their own CSV/XLSX files at runtime. No binary fixtures, no real statements. The demo CSV in Task 10 is invented.
- An import passed to Inertia is named `upload` (`import` is a reserved word in Vue template expressions).
- Domain events implement `ShouldDispatchAfterCommit`; this plan registers no listeners (Plan 5 does).

## Review Focus

1. **Ambiguous and odd date formats** — `03/10/2026` must be 3 October (not 10 March), two-digit years must land in the 2000s, and Excel serial numbers (e.g. `46298`) must become real dates — pinned in Task 2.
2. **The same transactions arriving twice** — re-uploading a file, or a second export that overlaps the first, must flag the repeats as duplicates (skipped unless the admin includes them), never silently double the lines — pinned in Tasks 6 and 7.
3. **A card identifier shared by two people, or belonging to someone inactive** — those rows must land in Unassigned, never on the wrong person — pinned in Task 3.
4. **A late import into a published period where a manager already submitted** — their reconciliation must reopen and a `ReconciliationLinesAdded` event must fire, so they get told — pinned in Task 7.
5. **Full card numbers hiding in columns nobody mapped** — must be masked before they reach the database — pinned in Task 3 (parser) and Task 7 (stored row).

---

## File Structure

```
app/
  Enums/
    ImportStatus.php            # draft | confirmed
    PeriodStatus.php            # draft | published | closed
    ReconciliationStatus.php    # in_progress | submitted | approved
    TransactionStatus.php       # todo | done | queried | approved
  Events/
    PeriodPublished.php
    ReconciliationLinesAdded.php
  Imports/
    CardholderMatcher.php       # card identifier → user id (or null when ambiguous/unknown)
    ColumnMap.php               # which column holds which field; guesses from header names
    CommitResult.php
    DuplicateKey.php
    ImportCommitter.php         # preview → transactions + reconciliations
    ImportNotReady.php
    ImportPreview.php
    ImportPreviewer.php
    ImportRemover.php
    ImportSheet.php             # cached rows/headers for one import's stored file
    ParsedRow.php
    PreviewRow.php
    RowParser.php
    SpreadsheetReader.php
    UnreadableSpreadsheet.php
    Parsing/
      AmountParser.php
      CardNumberMasker.php
      DateParser.php
      HeaderSignature.php
  Periods/
    CannotReassign.php
    PeriodDashboard.php
    TransactionAssigner.php
  Http/
    Controllers/Admin/
      ImportController.php          # upload + remove
      ImportMappingController.php   # column mapping step
      ImportPreviewController.php   # preview + confirm
      PeriodController.php          # periods CRUD + dashboard
      PeriodLinesController.php     # list + reassign lines
      PublishPeriodController.php
    Requests/Admin/
      PeriodRequest.php
  Models/
    ColumnMapping.php
    Import.php
    Period.php
    Reconciliation.php
    Transaction.php
database/
  factories/{Period,Import,Reconciliation,Transaction}Factory.php
  migrations/2026_10_11_00000{1..5}_create_*_table.php
resources/
  demo/demo-statement.csv
  js/
    components/PeriodStatusBadge.vue
    lib/money.ts
    pages/admin/periods/{Index,Form,Show,Lines}.vue
    pages/admin/imports/{Create,Mapping,Preview}.vue
tests/
  Unit/Imports/{AmountParser,CardNumberMasker,ColumnMap,DateParser,HeaderSignature,RowParser,CardholderMatcher,SpreadsheetReader}Test.php
  Feature/Models/PeriodDomainTest.php
  Feature/Imports/{CardholderMatcherQuery,ImportPreviewer,ImportCommitter,ImportRemover}Test.php
  Feature/Admin/{Periods,ImportWizard,PeriodLines,PublishPeriod}Test.php
```

---

### Task 1: Period, import, reconciliation and transaction schema

**Files:**
- Create: `app/Enums/PeriodStatus.php`, `app/Enums/ImportStatus.php`, `app/Enums/ReconciliationStatus.php`, `app/Enums/TransactionStatus.php`
- Create: `database/migrations/2026_10_11_000001_create_periods_table.php` … `000005_create_transactions_table.php`
- Create: `app/Models/Period.php`, `app/Models/ColumnMapping.php`, `app/Models/Import.php`, `app/Models/Reconciliation.php`, `app/Models/Transaction.php`
- Create: `database/factories/PeriodFactory.php`, `ImportFactory.php`, `ReconciliationFactory.php`, `TransactionFactory.php`
- Test: `tests/Feature/Models/PeriodDomainTest.php`

**Interfaces:**
- Consumes: `User` (Plan 1).
- Produces:
  - Enums `App\Enums\PeriodStatus` (`Draft='draft'`, `Published='published'`, `Closed='closed'`), `ImportStatus` (`Draft`, `Confirmed`), `ReconciliationStatus` (`InProgress='in_progress'`, `Submitted`, `Approved`), `TransactionStatus` (`Todo`, `Done`, `Queried`, `Approved`).
  - `Period`: fillable `label, month, due_date, reminder_offsets, status, published_at`; casts `month`/`due_date` → `date:Y-m-d`, `status` → `PeriodStatus`, `reminder_offsets` → array (default `[3, 0]`), `published_at` → datetime; relations `imports()`, `reconciliations()`, `transactions()`, `unassignedTransactions()`.
  - `ColumnMapping`: fillable `name, header_signature, header_row, mapping`; `mapping` → array.
  - `Import`: fillable `original_filename, stored_path, file_hash, header_row, column_mapping, row_count, status, uploaded_by`; casts `column_mapping` → array, `status` → `ImportStatus` (default draft); relations `period()`, `transactions()`, `uploader()`.
  - `Reconciliation`: fillable `period_id, user_id, status, submitted_at, approved_at, approved_by`; `status` → `ReconciliationStatus` (default in_progress); relations `period()`, `user()`, `transactions()`.
  - `Transaction`: fillable `period_id, import_id, reconciliation_id, row_number, transacted_on, description, amount, card_ref, extra, purpose, no_receipt, no_receipt_reason, status`; casts `transacted_on` → `date:Y-m-d`, `amount` → integer, `extra` → array, `no_receipt` → boolean, `status` → `TransactionStatus` (default todo); relations `period()`, `import()`, `reconciliation()`.
  - Factories: `PeriodFactory` (+ `published()`, `closed()`), `ImportFactory` (+ `confirmed()`; default mapping `date=0, description=1, cardholder=2, card=3, amount=4`), `ReconciliationFactory` (+ `submitted()`, `approved()`), `TransactionFactory`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Models/PeriodDomainTest.php`:
```php
<?php

use App\Enums\ImportStatus;
use App\Enums\PeriodStatus;
use App\Enums\ReconciliationStatus;
use App\Enums\TransactionStatus;
use App\Models\Import;
use App\Models\Period;
use App\Models\Reconciliation;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\QueryException;

it('defaults a new period to draft with reminders 3 days before and on the day', function () {
    $period = Period::factory()->create();

    expect($period->status)->toBe(PeriodStatus::Draft)
        ->and($period->reminder_offsets)->toBe([3, 0])
        ->and($period->fresh()->status)->toBe(PeriodStatus::Draft)
        ->and($period->fresh()->reminder_offsets)->toBe([3, 0]);
});

it('allows only one period per month', function () {
    Period::factory()->create(['month' => '2026-09-01']);

    expect(fn () => Period::factory()->create(['month' => '2026-09-01']))
        ->toThrow(QueryException::class);
});

it('serialises period dates without a time', function () {
    $period = Period::factory()->create(['month' => '2026-09-01', 'due_date' => '2026-10-15']);

    expect($period->fresh()->toArray())->toMatchArray(['month' => '2026-09-01', 'due_date' => '2026-10-15']);
});

it('links periods, imports, reconciliations and transactions', function () {
    $period = Period::factory()->create();
    $import = Import::factory()->for($period)->confirmed()->create();
    $reconciliation = Reconciliation::factory()->for($period)->create();
    $assigned = Transaction::factory()->for($import)->create(['reconciliation_id' => $reconciliation->id]);
    $unassigned = Transaction::factory()->for($import)->create();

    expect($period->imports->pluck('id')->all())->toBe([$import->id])
        ->and($period->reconciliations->pluck('id')->all())->toBe([$reconciliation->id])
        ->and($period->transactions)->toHaveCount(2)
        ->and($period->unassignedTransactions->pluck('id')->all())->toBe([$unassigned->id])
        ->and($reconciliation->transactions->pluck('id')->all())->toBe([$assigned->id])
        ->and($assigned->period_id)->toBe($period->id)
        ->and($import->transactions)->toHaveCount(2);
});

it('defaults imports to draft and reconciliations to in progress', function () {
    expect(Import::factory()->create()->fresh()->status)->toBe(ImportStatus::Draft)
        ->and(Reconciliation::factory()->create()->fresh()->status)->toBe(ReconciliationStatus::InProgress);
});

it('allows one reconciliation per person per period', function () {
    $period = Period::factory()->create();
    $user = User::factory()->create();
    Reconciliation::factory()->for($period)->for($user)->create();

    expect(fn () => Reconciliation::factory()->for($period)->for($user)->create())
        ->toThrow(QueryException::class);
});

it('stores amounts as integer pence and keeps extra columns', function () {
    $transaction = Transaction::factory()->create([
        'amount' => -1250,
        'extra' => ['Category' => 'Travel'],
        'transacted_on' => '2026-09-03',
    ]);

    $fresh = $transaction->fresh();
    expect($fresh->amount)->toBe(-1250)
        ->and($fresh->extra)->toBe(['Category' => 'Travel'])
        ->and($fresh->toArray()['transacted_on'])->toBe('2026-09-03')
        ->and($fresh->status)->toBe(TransactionStatus::Todo)
        ->and($fresh->no_receipt)->toBeFalse();
});
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `php artisan test tests/Feature/Models/PeriodDomainTest.php`
Expected: FAIL — `Class "App\Models\Period" not found`.

- [ ] **Step 3: Create the enums**

`app/Enums/PeriodStatus.php`:
```php
<?php

namespace App\Enums;

enum PeriodStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Closed = 'closed';
}
```

`app/Enums/ImportStatus.php`:
```php
<?php

namespace App\Enums;

enum ImportStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
}
```

`app/Enums/ReconciliationStatus.php`:
```php
<?php

namespace App\Enums;

enum ReconciliationStatus: string
{
    case InProgress = 'in_progress';
    case Submitted = 'submitted';
    case Approved = 'approved';
}
```

`app/Enums/TransactionStatus.php`:
```php
<?php

namespace App\Enums;

enum TransactionStatus: string
{
    case Todo = 'todo';
    case Done = 'done';
    case Queried = 'queried';
    case Approved = 'approved';
}
```

- [ ] **Step 4: Create the migrations**

`database/migrations/2026_10_11_000001_create_periods_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('periods', function (Blueprint $table) {
            $table->id();
            $table->string('label', 100);
            $table->date('month')->unique();
            $table->date('due_date');
            $table->string('status', 20)->default('draft')->index();
            $table->json('reminder_offsets');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('periods');
    }
};
```

`database/migrations/2026_10_11_000002_create_column_mappings_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('column_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->char('header_signature', 40)->unique();
            $table->unsignedInteger('header_row')->default(1);
            $table->json('mapping');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('column_mappings');
    }
};
```

`database/migrations/2026_10_11_000003_create_imports_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained()->cascadeOnDelete();
            $table->string('original_filename');
            $table->string('stored_path');
            $table->char('file_hash', 64)->index();
            $table->unsignedInteger('header_row')->default(1);
            $table->json('column_mapping')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->string('status', 20)->default('draft');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imports');
    }
};
```

`database/migrations/2026_10_11_000004_create_reconciliations_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('in_progress');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['period_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliations');
    }
};
```

`database/migrations/2026_10_11_000005_create_transactions_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('import_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reconciliation_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('row_number');
            $table->date('transacted_on');
            $table->string('description', 500);
            $table->bigInteger('amount');
            $table->string('card_ref', 100)->nullable();
            $table->json('extra')->nullable();
            $table->text('purpose')->nullable();
            $table->boolean('no_receipt')->default(false);
            $table->text('no_receipt_reason')->nullable();
            $table->string('status', 20)->default('todo');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['import_id', 'row_number']);
            $table->index(['period_id', 'reconciliation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
```

- [ ] **Step 5: Create the models**

`app/Models/Period.php`:
```php
<?php

namespace App\Models;

use App\Enums\PeriodStatus;
use Database\Factories\PeriodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Period extends Model
{
    /** @use HasFactory<PeriodFactory> */
    use HasFactory;

    protected $fillable = ['label', 'month', 'due_date', 'reminder_offsets', 'status', 'published_at'];

    /** In-memory defaults mirroring the DB, so new models behave the same before a re-read. */
    protected $attributes = [
        'status' => 'draft',
        'reminder_offsets' => '[3,0]',
    ];

    protected function casts(): array
    {
        return [
            'month' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'status' => PeriodStatus::class,
            'reminder_offsets' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function imports(): HasMany
    {
        return $this->hasMany(Import::class);
    }

    public function reconciliations(): HasMany
    {
        return $this->hasMany(Reconciliation::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function unassignedTransactions(): HasMany
    {
        return $this->transactions()->whereNull('reconciliation_id');
    }
}
```

`app/Models/ColumnMapping.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A remembered column layout, found again by the signature of a file's header row. */
class ColumnMapping extends Model
{
    protected $fillable = ['name', 'header_signature', 'header_row', 'mapping'];

    protected function casts(): array
    {
        return [
            'header_row' => 'integer',
            'mapping' => 'array',
        ];
    }
}
```

`app/Models/Import.php`:
```php
<?php

namespace App\Models;

use App\Enums\ImportStatus;
use Database\Factories\ImportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Import extends Model
{
    /** @use HasFactory<ImportFactory> */
    use HasFactory;

    protected $fillable = [
        'original_filename',
        'stored_path',
        'file_hash',
        'header_row',
        'column_mapping',
        'row_count',
        'status',
        'uploaded_by',
    ];

    protected $attributes = [
        'status' => 'draft',
        'header_row' => 1,
        'row_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'header_row' => 'integer',
            'row_count' => 'integer',
            'column_mapping' => 'array',
            'status' => ImportStatus::class,
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
```

`app/Models/Reconciliation.php`:
```php
<?php

namespace App\Models;

use App\Enums\ReconciliationStatus;
use Database\Factories\ReconciliationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One cardholder's lines for one period: what they submit and the accountant approves. */
class Reconciliation extends Model
{
    /** @use HasFactory<ReconciliationFactory> */
    use HasFactory;

    protected $fillable = ['period_id', 'user_id', 'status', 'submitted_at', 'approved_at', 'approved_by'];

    protected $attributes = [
        'status' => 'in_progress',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReconciliationStatus::class,
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
```

`app/Models/Transaction.php`:
```php
<?php

namespace App\Models;

use App\Enums\TransactionStatus;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
```

- [ ] **Step 6: Create the factories**

`database/factories/PeriodFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Enums\PeriodStatus;
use App\Models\Period;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Period>
 */
class PeriodFactory extends Factory
{
    /** Each new period goes one month further back, starting a year ago, so months never clash with dates tests choose. */
    private static int $monthsBack = 12;

    public function definition(): array
    {
        $month = CarbonImmutable::now()->startOfMonth()->subMonths(++self::$monthsBack);

        return [
            'label' => $month->format('F Y'),
            'month' => $month->format('Y-m-d'),
            'due_date' => $month->addMonth()->day(15)->format('Y-m-d'),
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['status' => PeriodStatus::Published, 'published_at' => now()]);
    }

    public function closed(): static
    {
        return $this->state(fn () => ['status' => PeriodStatus::Closed, 'published_at' => now()]);
    }
}
```

`database/factories/ImportFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Enums\ImportStatus;
use App\Models\Import;
use App\Models\Period;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Import>
 */
class ImportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'period_id' => Period::factory(),
            'original_filename' => 'statement.csv',
            'stored_path' => fn () => 'imports/'.Str::uuid().'.csv',
            'file_hash' => fn () => hash('sha256', Str::random(40)),
            'header_row' => 1,
            'column_mapping' => ['date' => 0, 'description' => 1, 'cardholder' => 2, 'card' => 3, 'amount' => 4, 'purpose' => null],
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn () => ['status' => ImportStatus::Confirmed]);
    }
}
```

`database/factories/ReconciliationFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Enums\ReconciliationStatus;
use App\Models\Period;
use App\Models\Reconciliation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reconciliation>
 */
class ReconciliationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'period_id' => Period::factory(),
            'user_id' => User::factory(),
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn () => ['status' => ReconciliationStatus::Submitted, 'submitted_at' => now()]);
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => ReconciliationStatus::Approved, 'submitted_at' => now(), 'approved_at' => now()]);
    }
}
```

`database/factories/TransactionFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\Import;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    private static int $row = 1;

    public function definition(): array
    {
        return [
            // import_id must come first: period_id is read from it.
            'import_id' => Import::factory()->confirmed(),
            'period_id' => fn (array $attributes) => Import::find($attributes['import_id'])->period_id,
            'reconciliation_id' => null,
            'row_number' => fn () => ++self::$row,
            'transacted_on' => fake()->dateTimeBetween('-60 days')->format('Y-m-d'),
            'description' => strtoupper(fake()->company()),
            'amount' => fake()->numberBetween(100, 50000),
            'card_ref' => '1001',
        ];
    }
}
```

- [ ] **Step 7: Run the tests to see them pass**

Run: `php artisan migrate && php artisan test tests/Feature/Models/PeriodDomainTest.php`
Expected: migrations run on MySQL; 7 tests PASS.

- [ ] **Step 8: Run the full suite and commit**

Run: `php artisan test`
Expected: all PASS.

```bash
git add app/Enums app/Models database/migrations database/factories tests/Feature/Models/PeriodDomainTest.php
git commit -m "feat: periods, imports, reconciliations and transactions schema"
```

---

### Task 2: Date, amount, header and card-number parsers

**Files:**
- Create: `app/Imports/Parsing/DateParser.php`, `AmountParser.php`, `HeaderSignature.php`, `CardNumberMasker.php`
- Test: `tests/Unit/Imports/DateParserTest.php`, `AmountParserTest.php`, `HeaderSignatureTest.php`, `CardNumberMaskerTest.php`

**Interfaces:**
- Consumes: nothing (pure PHP + Carbon + `Illuminate\Support\Str`).
- Produces:
  - `DateParser::parse(mixed $value): ?CarbonImmutable` (start of day; null when unreadable).
  - `AmountParser::parse(mixed $value): ?int` (pence; negative for credits; null when unreadable).
  - `HeaderSignature::normalise(array $headers): list<string>` and `HeaderSignature::for(array $headers): string` (40-char sha1).
  - `CardNumberMasker::mask(string $text): string` and `CardNumberMasker::last4(mixed $value): ?string`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Imports/DateParserTest.php`:
```php
<?php

use App\Imports\Parsing\DateParser;

it('reads UK and ISO date formats', function (mixed $input) {
    expect(DateParser::parse($input)?->format('Y-m-d'))->toBe('2026-10-03');
})->with([
    'day first' => '03/10/2026',
    'no leading zeros, two-digit year' => '3/10/26',
    'dashes' => '03-10-2026',
    'dots' => '03.10.2026',
    'ISO' => '2026-10-03',
    'ISO with time' => '2026-10-03 00:00:00',
    'ISO with T time' => '2026-10-03T14:30:00',
    'short month name' => '3 Oct 2026',
    'long month name' => '03 October 2026',
    'Excel-style text' => '3-Oct-26',
    'Excel serial number' => 46298,
    'Excel serial with time' => 46298.5,
    'Excel serial as text' => '46298',
    'padded' => '  03/10/2026  ',
]);

it('reads DateTime objects', function () {
    expect(DateParser::parse(new DateTimeImmutable('2026-10-03 15:00'))->format('Y-m-d H:i'))->toBe('2026-10-03 00:00');
});

it('treats two-digit years as 2000s', function () {
    expect(DateParser::parse('01/01/99')->format('Y-m-d'))->toBe('2099-01-01');
});

it('returns null for anything that is not a real date', function (mixed $input) {
    expect(DateParser::parse($input))->toBeNull();
})->with([
    'impossible day' => '31/02/2026',
    'month 13' => '13/13/2026',
    'words' => 'yesterday',
    'totals row' => 'Total',
    'empty' => '',
    'null' => null,
    'tiny number' => 123,
    'bad month name' => '3 Foo 2026',
]);
```

`tests/Unit/Imports/AmountParserTest.php`:
```php
<?php

use App\Imports\Parsing\AmountParser;

it('reads amounts into pence', function (mixed $input, int $expected) {
    expect(AmountParser::parse($input))->toBe($expected);
})->with([
    ['12.50', 1250],
    ['£1,234.56', 123456],
    ['12.5', 1250],
    ['0.1', 10],
    ['7', 700],
    ['-12.00', -1200],
    ['(12.00)', -1200],
    ['12.00 CR', -1200],
    ['12.00cr', -1200],
    ['12.00 DR', 1200],
    ['-£3.50', -350],
    ['£-3.50', -350],
    ['3.50-', -350],
    ['+3.50', 350],
    ['GBP 9.99', 999],
    [12.34, 1234],
    [0.29, 29],
    [-5.1, -510],
    [7, 700],
]);

it('returns null for anything that is not an amount', function (mixed $input) {
    expect(AmountParser::parse($input))->toBeNull();
})->with(['12.345', 'abc', '', '1.2.3', 'CR', null, true]);
```

`tests/Unit/Imports/HeaderSignatureTest.php`:
```php
<?php

use App\Imports\Parsing\HeaderSignature;

it('ignores case, spacing and trailing blank columns', function () {
    expect(HeaderSignature::for([' Date ', 'Card  Member', 'AMOUNT', null, '']))
        ->toBe(HeaderSignature::for(['date', 'card member', 'amount']));
});

it('changes when columns are reordered or renamed', function () {
    $original = HeaderSignature::for(['Date', 'Amount']);

    expect(HeaderSignature::for(['Amount', 'Date']))->not->toBe($original)
        ->and(HeaderSignature::for(['Date', 'Value']))->not->toBe($original);
});

it('produces a 40-character signature', function () {
    expect(HeaderSignature::for(['Date']))->toHaveLength(40);
});
```

`tests/Unit/Imports/CardNumberMaskerTest.php`:
```php
<?php

use App\Imports\Parsing\CardNumberMasker;

it('masks anything that looks like a full card number', function (string $input, string $expected) {
    expect(CardNumberMasker::mask($input))->toBe($expected);
})->with([
    ['Ref 378282246310005 paid', 'Ref **** 0005 paid'],
    ['3782 822463 10005', '**** 0005'],
    ['4111-1111-1111-1111', '**** 1111'],
]);

it('leaves shorter numbers alone', function (string $input) {
    expect(CardNumberMasker::mask($input))->toBe($input);
})->with(['07700 900123', '1001', 'INV-20261003', 'Order 123456789012']);

it('extracts the last four digits of a card reference', function (mixed $input, ?string $expected) {
    expect(CardNumberMasker::last4($input))->toBe($expected);
})->with([
    ['XXXX-XXXXXX-01001', '1001'],
    ['1001', '1001'],
    [1001, '1001'],
    ['0012', '0012'],
    ['123', null],
    ['', null],
    [null, null],
]);
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `php artisan test tests/Unit/Imports`
Expected: FAIL — `Class "App\Imports\Parsing\DateParser" not found` (and the same for the others).

- [ ] **Step 3: Implement the date parser**

`app/Imports/Parsing/DateParser.php`:
```php
<?php

namespace App\Imports\Parsing;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/** Reads the date formats card statements use. Ambiguous dates are UK day-first. */
final class DateParser
{
    /** Excel serials between these cover 1954–2119; smaller numbers are not treated as dates. */
    private const MIN_SERIAL = 20000;

    private const MAX_SERIAL = 80000;

    private const MONTHS = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];

    public static function parse(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->startOfDay();
        }

        if (is_int($value) || is_float($value)) {
            return self::fromSerial((float) $value);
        }

        if (! is_string($value)) {
            return null;
        }

        $text = trim($value);

        if (is_numeric($text)) {
            return self::fromSerial((float) $text);
        }

        // Drop any time part: "2026-10-03 00:00:00", "2026-10-03T14:30:00".
        $text = preg_replace('/[ T]\d{1,2}:\d{2}(:\d{2})?.*$/', '', $text);

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $text, $m)) {
            return self::make((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2}|\d{4})$/', $text, $m)) {
            return self::make(self::year($m[3]), (int) $m[2], (int) $m[1]);
        }

        if (preg_match('/^(\d{1,2})[\s\-]+([A-Za-z]{3,9})[\s\-,]+(\d{2}|\d{4})$/', $text, $m)) {
            $month = self::MONTHS[strtolower(substr($m[2], 0, 3))] ?? null;

            return $month ? self::make(self::year($m[3]), $month, (int) $m[1]) : null;
        }

        return null;
    }

    private static function fromSerial(float $serial): ?CarbonImmutable
    {
        if ($serial < self::MIN_SERIAL || $serial >= self::MAX_SERIAL) {
            return null;
        }

        // Excel counts days from 30 December 1899 (this absorbs its 1900 leap-year bug for modern dates).
        return CarbonImmutable::create(1899, 12, 30)->addDays((int) floor($serial));
    }

    private static function year(string $digits): int
    {
        return strlen($digits) === 2 ? 2000 + (int) $digits : (int) $digits;
    }

    private static function make(int $year, int $month, int $day): ?CarbonImmutable
    {
        return checkdate($month, $day, $year) ? CarbonImmutable::create($year, $month, $day) : null;
    }
}
```

- [ ] **Step 4: Implement the amount parser**

`app/Imports/Parsing/AmountParser.php`:
```php
<?php

namespace App\Imports\Parsing;

/** Turns statement amounts into integer pence. Credits come back negative. */
final class AmountParser
{
    public static function parse(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value * 100;
        }

        if (is_float($value)) {
            return (int) round($value * 100);
        }

        if (! is_string($value)) {
            return null;
        }

        $text = strtoupper(trim($value));
        $negative = false;

        if (str_ends_with($text, 'CR')) {
            $negative = true;
            $text = trim(substr($text, 0, -2));
        } elseif (str_ends_with($text, 'DR')) {
            $text = trim(substr($text, 0, -2));
        }

        if (preg_match('/^\((.*)\)$/', $text, $m)) {
            $negative = true;
            $text = $m[1];
        }

        $text = str_replace(['£', 'GBP', ',', ' '], '', $text);

        if (str_starts_with($text, '-')) {
            $negative = true;
            $text = substr($text, 1);
        } elseif (str_ends_with($text, '-')) {
            $negative = true;
            $text = substr($text, 0, -1);
        } elseif (str_starts_with($text, '+')) {
            $text = substr($text, 1);
        }

        // String arithmetic, never floats, so 0.29 stays exactly 29p.
        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $text, $m)) {
            return null;
        }

        $pence = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');

        return $negative ? -$pence : $pence;
    }
}
```

- [ ] **Step 5: Implement the header signature and card-number masker**

`app/Imports/Parsing/HeaderSignature.php`:
```php
<?php

namespace App\Imports\Parsing;

use Illuminate\Support\Str;

/** A fingerprint of a header row, used to recognise a statement layout seen before. */
final class HeaderSignature
{
    /** @return list<string> */
    public static function normalise(array $headers): array
    {
        $normalised = array_map(fn ($header) => Str::lower(Str::squish((string) $header)), array_values($headers));

        while ($normalised !== [] && end($normalised) === '') {
            array_pop($normalised);
        }

        return $normalised;
    }

    public static function for(array $headers): string
    {
        return sha1(implode("\x1F", self::normalise($headers)));
    }
}
```

`app/Imports/Parsing/CardNumberMasker.php`:
```php
<?php

namespace App\Imports\Parsing;

/** Keeps full card numbers out of the database: only the last four digits ever survive. */
final class CardNumberMasker
{
    /** 13–19 digits, optionally split by spaces or dashes, not touching other digits. */
    private const CARD_NUMBER = '/(?<!\d)(?:\d[ \-]?){12,18}\d(?!\d)/';

    public static function mask(string $text): string
    {
        return preg_replace_callback(
            self::CARD_NUMBER,
            fn (array $m) => '**** '.substr(preg_replace('/\D/', '', $m[0]), -4),
            $text,
        );
    }

    public static function last4(mixed $value): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', (string) $value);

        return strlen($digits) >= 4 ? substr($digits, -4) : null;
    }
}
```

- [ ] **Step 6: Run the tests to see them pass**

Run: `php artisan test tests/Unit/Imports`
Expected: all PASS.

- [ ] **Step 7: Run the full suite and commit**

Run: `php artisan test` — all PASS.

```bash
git add app/Imports tests/Unit/Imports
git commit -m "feat: statement date, amount, header and card-number parsers"
```

---

### Task 3: Row parsing, column guessing and cardholder matching

**Files:**
- Create: `app/Imports/ColumnMap.php`, `app/Imports/ParsedRow.php`, `app/Imports/DuplicateKey.php`, `app/Imports/RowParser.php`, `app/Imports/CardholderMatcher.php`
- Test: `tests/Unit/Imports/ColumnMapTest.php`, `tests/Unit/Imports/RowParserTest.php`, `tests/Unit/Imports/CardholderMatcherTest.php`, `tests/Feature/Imports/CardholderMatcherQueryTest.php`

**Interfaces:**
- Consumes: Task 2 parsers; `CardIdentifier`, `User` (Plan 1).
- Produces:
  - `final readonly class ColumnMap` with public `int $date, $description, $amount` and `?int $card, $cardholder, $purpose`; `const FIELDS`, `const REQUIRED`; `static fromArray(array): self`; `toArray(): array<string, ?int>`; `mappedColumns(): list<int>`; `static guess(array $headers): array<string, ?int>`.
  - `final readonly class ParsedRow` (`rowNumber`, `?CarbonImmutable date`, `rawDate`, `description`, `?int amount`, `rawAmount`, `?string last4`, `?string cardholderName`, `array extra`, `array errors`) with `isValid(): bool`, `cardRef(): ?string`, `duplicateKey(): ?string`.
  - `DuplicateKey::make(CarbonInterface $date, int $amount, string $description, ?string $cardRef): string`.
  - `new RowParser(ColumnMap $map, array $headers)`; `parse(int $rowNumber, array $cells, array $overrides = []): ParsedRow` (overrides keys `date`, `amount`, `description`); `static text(mixed $value): string`.
  - `new CardholderMatcher(iterable $identifiers)` (each `['user_id' => int, 'last4' => ?string, 'cardholder_name' => ?string]`); `static forActiveCardholders(): self`; `match(?string $last4, ?string $name): ?int`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Imports/ColumnMapTest.php`:
```php
<?php

use App\Imports\ColumnMap;

it('round-trips through an array', function () {
    $map = ColumnMap::fromArray(['date' => 0, 'description' => 1, 'amount' => '4', 'card' => 3, 'cardholder' => null]);

    expect($map->amount)->toBe(4)
        ->and($map->purpose)->toBeNull()
        ->and($map->toArray())->toBe(['date' => 0, 'description' => 1, 'amount' => 4, 'card' => 3, 'cardholder' => null, 'purpose' => null])
        ->and($map->mappedColumns())->toBe([0, 1, 4, 3]);
});

it('guesses columns from common header names', function () {
    $guess = ColumnMap::guess(['Date', 'Description', 'Card Member', 'Account #', 'Amount', 'What was it for?']);

    expect($guess)->toBe(['date' => 0, 'description' => 1, 'amount' => 4, 'card' => 3, 'cardholder' => 2, 'purpose' => 5]);
});

it('leaves fields it cannot recognise empty', function () {
    expect(ColumnMap::guess(['Foo', 'Bar']))->toBe([
        'date' => null, 'description' => null, 'amount' => null, 'card' => null, 'cardholder' => null, 'purpose' => null,
    ]);
});
```

`tests/Unit/Imports/RowParserTest.php`:
```php
<?php

use App\Imports\ColumnMap;
use App\Imports\RowParser;

function demoParser(): RowParser
{
    return new RowParser(
        ColumnMap::fromArray(['date' => 0, 'description' => 1, 'cardholder' => 2, 'card' => 3, 'amount' => 4]),
        ['Date', 'Description', 'Card Member', 'Account #', 'Amount', 'Category', '', 'Reference'],
    );
}

it('parses a valid statement row', function () {
    $row = demoParser()->parse(7, ['03/10/2026', ' TRAINLINE ', 'S CARDHOLDER', 'XXXX-XXXXXX-01001', '45.20', 'Travel']);

    expect($row->isValid())->toBeTrue()
        ->and($row->rowNumber)->toBe(7)
        ->and($row->date->format('Y-m-d'))->toBe('2026-10-03')
        ->and($row->description)->toBe('TRAINLINE')
        ->and($row->amount)->toBe(4520)
        ->and($row->last4)->toBe('1001')
        ->and($row->cardholderName)->toBe('S CARDHOLDER')
        ->and($row->cardRef())->toBe('1001')
        ->and($row->extra)->toBe(['Category' => 'Travel']);
});

it('reports every problem on a bad row', function () {
    $row = demoParser()->parse(9, ['31/02/2026', '', 'S CARDHOLDER', '1001', 'twelve']);

    expect($row->isValid())->toBeFalse()
        ->and(array_keys($row->errors))->toBe(['date', 'amount', 'description'])
        ->and($row->rawDate)->toBe('31/02/2026')
        ->and($row->rawAmount)->toBe('twelve')
        ->and($row->duplicateKey())->toBeNull();
});

it('applies the admin\'s fixes over the file\'s values', function () {
    $row = demoParser()->parse(9, ['31/02/2026', 'SHOP', 'S CARDHOLDER', '1001', 'twelve'], ['date' => '28/02/2026', 'amount' => '12.00']);

    expect($row->isValid())->toBeTrue()
        ->and($row->date->format('Y-m-d'))->toBe('2026-02-28')
        ->and($row->amount)->toBe(1200);
});

it('masks full card numbers in the description and unmapped columns', function () {
    $row = demoParser()->parse(3, ['03/10/2026', 'REFUND 378282246310005', 'S CARDHOLDER', '1001', '-5.00', 'Misc', 'x', '4111 1111 1111 1111']);

    expect($row->description)->toBe('REFUND **** 0005')
        ->and($row->extra['Reference'])->toBe('**** 1111');
});

it('labels unnamed extra columns by position and skips empty cells', function () {
    $row = demoParser()->parse(3, ['03/10/2026', 'SHOP', 'S CARDHOLDER', '1001', '1.00', '', 'note']);

    expect($row->extra)->toBe(['Column 7' => 'note']);
});

it('uses the cardholder name as the card reference when there is no card number', function () {
    $parser = new RowParser(ColumnMap::fromArray(['date' => 0, 'description' => 1, 'amount' => 2, 'cardholder' => 3]), ['Date', 'Desc', 'Amount', 'Name']);

    $row = $parser->parse(2, ['03/10/2026', 'SHOP', '1.00', 'J MANAGER']);

    expect($row->last4)->toBeNull()->and($row->cardRef())->toBe('J MANAGER');
});

it('builds the same duplicate key regardless of case and spacing', function () {
    $a = demoParser()->parse(2, ['03/10/2026', 'Trainline  Ltd', 'X', '1001', '45.20']);
    $b = demoParser()->parse(3, ['2026-10-03', 'TRAINLINE LTD', 'X', '1001', '£45.20']);

    expect($a->duplicateKey())->toBe($b->duplicateKey());
});

it('turns spreadsheet cell values into text', function (mixed $input, string $expected) {
    expect(RowParser::text($input))->toBe($expected);
})->with([
    [null, ''],
    [12.0, '12'],
    [12.5, '12.5'],
    ['  hi ', 'hi'],
    [true, 'TRUE'],
]);
```

`tests/Unit/Imports/CardholderMatcherTest.php`:
```php
<?php

use App\Imports\CardholderMatcher;

function matcher(): CardholderMatcher
{
    return new CardholderMatcher([
        ['user_id' => 1, 'last4' => '1001', 'cardholder_name' => 'S CARDHOLDER'],
        ['user_id' => 2, 'last4' => '1002', 'cardholder_name' => 'J Manager'],
        ['user_id' => 3, 'last4' => '7777', 'cardholder_name' => null],
        ['user_id' => 4, 'last4' => '7777', 'cardholder_name' => 'SHARED NAME'],
        ['user_id' => 5, 'last4' => null, 'cardholder_name' => 'shared name'],
    ]);
}

it('matches on a unique last four digits', function () {
    expect(matcher()->match('1001', null))->toBe(1);
});

it('leaves a last four shared by two people unassigned rather than guessing', function () {
    expect(matcher()->match('7777', 'J MANAGER'))->toBeNull();
});

it('falls back to the cardholder name when the last four is unknown', function () {
    expect(matcher()->match('9999', '  j   manager '))->toBe(2)
        ->and(matcher()->match(null, 'J MANAGER'))->toBe(2);
});

it('leaves an ambiguous cardholder name unassigned', function () {
    expect(matcher()->match(null, 'Shared Name'))->toBeNull();
});

it('returns null when nothing matches', function () {
    expect(matcher()->match('0000', 'NOBODY'))->toBeNull()
        ->and(matcher()->match(null, null))->toBeNull();
});
```

`tests/Feature/Imports/CardholderMatcherQueryTest.php`:
```php
<?php

use App\Imports\CardholderMatcher;
use App\Models\CardIdentifier;
use App\Models\User;

it('only matches active cardholders', function () {
    $active = User::factory()->create();
    CardIdentifier::factory()->for($active)->create(['last4' => '1001']);

    $inactive = User::factory()->inactive()->create();
    CardIdentifier::factory()->for($inactive)->create(['last4' => '2002']);

    $adminOnly = User::factory()->admin()->create(['is_cardholder' => false]);
    CardIdentifier::factory()->for($adminOnly)->create(['last4' => '3003']);

    $matcher = CardholderMatcher::forActiveCardholders();

    expect($matcher->match('1001', null))->toBe($active->id)
        ->and($matcher->match('2002', null))->toBeNull()
        ->and($matcher->match('3003', null))->toBeNull();
});
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `php artisan test tests/Unit/Imports tests/Feature/Imports`
Expected: FAIL — `Class "App\Imports\ColumnMap" not found` and similar.

- [ ] **Step 3: Implement `ColumnMap`**

`app/Imports/ColumnMap.php`:
```php
<?php

namespace App\Imports;

use Illuminate\Support\Str;

/** Which spreadsheet column (0-based) holds each field. */
final readonly class ColumnMap
{
    public const FIELDS = ['date', 'description', 'amount', 'card', 'cardholder', 'purpose'];

    public const REQUIRED = ['date', 'description', 'amount'];

    /** Header words that suggest each field, checked in this order. */
    private const HINTS = [
        'date' => ['date'],
        'description' => ['description', 'merchant', 'details', 'narrative'],
        'amount' => ['amount', 'value'],
        'card' => ['account', 'card number', 'card no', 'card #'],
        'cardholder' => ['card member', 'cardholder', 'card holder', 'name'],
        'purpose' => ['purpose', 'what was it for', 'reason'],
    ];

    public function __construct(
        public int $date,
        public int $description,
        public int $amount,
        public ?int $card = null,
        public ?int $cardholder = null,
        public ?int $purpose = null,
    ) {}

    public static function fromArray(array $mapping): self
    {
        $optional = fn (string $key) => isset($mapping[$key]) && $mapping[$key] !== '' ? (int) $mapping[$key] : null;

        return new self(
            (int) $mapping['date'],
            (int) $mapping['description'],
            (int) $mapping['amount'],
            $optional('card'),
            $optional('cardholder'),
            $optional('purpose'),
        );
    }

    /** @return array<string, ?int> */
    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'description' => $this->description,
            'amount' => $this->amount,
            'card' => $this->card,
            'cardholder' => $this->cardholder,
            'purpose' => $this->purpose,
        ];
    }

    /** @return list<int> */
    public function mappedColumns(): array
    {
        return array_values(array_filter(
            [$this->date, $this->description, $this->amount, $this->card, $this->cardholder, $this->purpose],
            fn (?int $column) => $column !== null,
        ));
    }

    /**
     * Suggest a mapping from header labels. Each column is used at most once.
     *
     * @return array<string, ?int>
     */
    public static function guess(array $headers): array
    {
        $labels = array_map(fn ($header) => Str::lower(Str::squish((string) $header)), $headers);
        $guess = array_fill_keys(self::FIELDS, null);
        $used = [];

        foreach (self::HINTS as $field => $hints) {
            foreach ($labels as $index => $label) {
                if (in_array($index, $used, true) || $label === '') {
                    continue;
                }

                if (Str::contains($label, $hints)) {
                    $guess[$field] = $index;
                    $used[] = $index;
                    break;
                }
            }
        }

        return $guess;
    }
}
```

- [ ] **Step 4: Implement `DuplicateKey` and `ParsedRow`**

`app/Imports/DuplicateKey.php`:
```php
<?php

namespace App\Imports;

use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/** Two lines with the same date, amount, description and card are treated as the same transaction. */
final class DuplicateKey
{
    public static function make(CarbonInterface $date, int $amount, string $description, ?string $cardRef): string
    {
        return implode('|', [
            $date->format('Y-m-d'),
            $amount,
            Str::lower(Str::squish($description)),
            Str::lower(Str::squish($cardRef ?? '')),
        ]);
    }
}
```

`app/Imports/ParsedRow.php`:
```php
<?php

namespace App\Imports;

use Carbon\CarbonImmutable;

final readonly class ParsedRow
{
    /**
     * @param  array<string, string>  $extra  unmapped columns, label => text (card numbers already masked)
     * @param  array<string, string>  $errors  field => message
     */
    public function __construct(
        public int $rowNumber,
        public ?CarbonImmutable $date,
        public string $rawDate,
        public string $description,
        public ?int $amount,
        public string $rawAmount,
        public ?string $last4,
        public ?string $cardholderName,
        public array $extra,
        public array $errors,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    public function cardRef(): ?string
    {
        return $this->last4 ?? $this->cardholderName;
    }

    public function duplicateKey(): ?string
    {
        return $this->isValid()
            ? DuplicateKey::make($this->date, $this->amount, $this->description, $this->cardRef())
            : null;
    }
}
```

- [ ] **Step 5: Implement `RowParser`**

`app/Imports/RowParser.php`:
```php
<?php

namespace App\Imports;

use App\Imports\Parsing\AmountParser;
use App\Imports\Parsing\CardNumberMasker;
use App\Imports\Parsing\DateParser;
use DateTimeInterface;
use Illuminate\Support\Str;

final class RowParser
{
    /** @param  array<int, string>  $headers  header labels by column index */
    public function __construct(
        private ColumnMap $map,
        private array $headers,
    ) {}

    /**
     * @param  list<mixed>  $cells
     * @param  array{date?: ?string, amount?: ?string, description?: ?string}  $overrides  the admin's corrections
     */
    public function parse(int $rowNumber, array $cells, array $overrides = []): ParsedRow
    {
        $rawDate = $overrides['date'] ?? $cells[$this->map->date] ?? null;
        $rawAmount = $overrides['amount'] ?? $cells[$this->map->amount] ?? null;
        $description = Str::limit(
            CardNumberMasker::mask(self::text($overrides['description'] ?? $cells[$this->map->description] ?? null)),
            500,
            '',
        );

        $date = DateParser::parse($rawDate);
        $amount = AmountParser::parse($rawAmount);

        $errors = [];
        if ($date === null) {
            $errors['date'] = "Couldn't read this date.";
        }
        if ($amount === null) {
            $errors['amount'] = "Couldn't read this amount.";
        }
        if ($description === '') {
            $errors['description'] = 'The description is missing.';
        }

        $last4 = $this->map->card !== null ? CardNumberMasker::last4($cells[$this->map->card] ?? null) : null;
        $name = $this->map->cardholder !== null ? self::text($cells[$this->map->cardholder] ?? null) : '';

        return new ParsedRow(
            rowNumber: $rowNumber,
            date: $date,
            rawDate: self::text($rawDate),
            description: $description,
            amount: $amount,
            rawAmount: self::text($rawAmount),
            last4: $last4,
            cardholderName: $name !== '' ? $name : null,
            extra: $this->extra($cells),
            errors: $errors,
        );
    }

    /** Any cell value as trimmed display text. Whole-number floats lose their ".0". */
    public static function text(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_bool($value) => $value ? 'TRUE' : 'FALSE',
            is_float($value) && floor($value) === $value => (string) (int) $value,
            default => trim((string) $value),
        };
    }

    /** @return array<string, string> */
    private function extra(array $cells): array
    {
        $mapped = $this->map->mappedColumns();
        $extra = [];

        foreach ($cells as $index => $value) {
            $text = self::text($value);

            if ($text === '' || in_array($index, $mapped, true)) {
                continue;
            }

            $label = trim((string) ($this->headers[$index] ?? ''));
            $extra[$label !== '' ? $label : 'Column '.($index + 1)] = CardNumberMasker::mask($text);
        }

        return $extra;
    }
}
```

- [ ] **Step 6: Implement `CardholderMatcher`**

`app/Imports/CardholderMatcher.php`:
```php
<?php

namespace App\Imports;

use App\Models\CardIdentifier;
use Illuminate\Support\Str;

/**
 * Finds which cardholder a statement row belongs to.
 * Last four digits win; the name is only used when the digits match nobody.
 * Anything ambiguous returns null so the line goes to Unassigned instead of the wrong person.
 */
final class CardholderMatcher
{
    /** @var array<string, list<int>> */
    private array $byLast4 = [];

    /** @var array<string, list<int>> */
    private array $byName = [];

    /** @param  iterable<array{user_id: int, last4: ?string, cardholder_name: ?string}>  $identifiers */
    public function __construct(iterable $identifiers)
    {
        foreach ($identifiers as $identifier) {
            if ($identifier['last4'] !== null && $identifier['last4'] !== '') {
                $this->byLast4[$identifier['last4']][] = (int) $identifier['user_id'];
            }

            $name = self::normaliseName($identifier['cardholder_name']);
            if ($name !== '') {
                $this->byName[$name][] = (int) $identifier['user_id'];
            }
        }
    }

    public static function forActiveCardholders(): self
    {
        return new self(
            CardIdentifier::query()
                ->join('users', 'users.id', '=', 'card_identifiers.user_id')
                ->where('users.active', true)
                ->where('users.is_cardholder', true)
                ->get(['card_identifiers.user_id', 'card_identifiers.last4', 'card_identifiers.cardholder_name'])
                ->map(fn (CardIdentifier $identifier) => [
                    'user_id' => $identifier->user_id,
                    'last4' => $identifier->last4,
                    'cardholder_name' => $identifier->cardholder_name,
                ]),
        );
    }

    public function match(?string $last4, ?string $name): ?int
    {
        if ($last4 !== null && isset($this->byLast4[$last4])) {
            return self::single($this->byLast4[$last4]);
        }

        $name = self::normaliseName($name);

        return $name !== '' && isset($this->byName[$name]) ? self::single($this->byName[$name]) : null;
    }

    private static function single(array $userIds): ?int
    {
        $unique = array_values(array_unique($userIds));

        return count($unique) === 1 ? $unique[0] : null;
    }

    private static function normaliseName(?string $name): string
    {
        return Str::lower(Str::squish($name ?? ''));
    }
}
```

- [ ] **Step 7: Run the tests to see them pass**

Run: `php artisan test tests/Unit/Imports tests/Feature/Imports`
Expected: all PASS.

- [ ] **Step 8: Run the full suite and commit**

Run: `php artisan test` — all PASS.

```bash
git add app/Imports tests/Unit/Imports tests/Feature/Imports
git commit -m "feat: statement row parsing, column guessing and cardholder matching"
```

---

### Task 4: Spreadsheet reading

**Files:**
- Create: `app/Imports/SpreadsheetReader.php`, `app/Imports/UnreadableSpreadsheet.php`, `app/Imports/ImportSheet.php`
- Modify: `app/Providers/AppServiceProvider.php` (scoped `ImportSheet`)
- Modify: `composer.json` (via `composer require`)
- Test: `tests/Unit/Imports/SpreadsheetReaderTest.php`

**Interfaces:**
- Consumes: `RowParser::text()` (Task 3), `Import` (Task 1).
- Produces:
  - `SpreadsheetReader::read(string $absolutePath, string $originalName): array<int, list<mixed>>` — rows keyed by real spreadsheet row number; blank rows omitted (numbering kept); first worksheet only; throws `UnreadableSpreadsheet` (message is safe to show users). `SpreadsheetReader::MAX_ROWS = 5000`.
  - `ImportSheet` (scoped singleton): `rows(Import): array<int, list<mixed>>`, `headers(Import, int $headerRow): array<int, string>`, `dataRows(Import, int $headerRow): array<int, list<mixed>>`.

- [ ] **Step 1: Install PhpSpreadsheet**

```bash
composer require phpoffice/phpspreadsheet:^5.0
```
Expected: installs 5.x without conflicts.

- [ ] **Step 2: Write the failing tests**

`tests/Unit/Imports/SpreadsheetReaderTest.php`:
```php
<?php

use App\Imports\SpreadsheetReader;
use App\Imports\UnreadableSpreadsheet;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function tempFile(string $contents, string $extension): string
{
    $path = tempnam(sys_get_temp_dir(), 'ct').'.'.$extension;
    file_put_contents($path, $contents);

    return $path;
}

function tempXlsx(callable $build): string
{
    $book = new Spreadsheet;
    $build($book);
    $path = tempnam(sys_get_temp_dir(), 'ct').'.xlsx';
    (new Xlsx($book))->save($path);

    return $path;
}

it('reads CSV rows keyed by their real row numbers, skipping blank lines', function () {
    $path = tempFile("Date,Description,Amount\n03/10/2026,SHOP,12.50\n\n04/10/2026,CAFE,3.20\n", 'csv');

    $rows = (new SpreadsheetReader)->read($path, 'statement.csv');

    expect(array_keys($rows))->toBe([1, 2, 4])
        ->and($rows[1])->toBe(['Date', 'Description', 'Amount'])
        ->and($rows[4][1])->toBe('CAFE');
});

it('keeps CSV values as text so leading zeros survive', function () {
    $path = tempFile("Card,Amount\n0012,-12.50\n", 'csv');

    $rows = (new SpreadsheetReader)->read($path, 'statement.csv');

    expect($rows[2])->toBe(['0012', '-12.50']);
});

it('strips a UTF-8 byte order mark from the first header', function () {
    $path = tempFile("\xEF\xBB\xBFDate,Amount\n03/10/2026,1.00\n", 'csv');

    expect((new SpreadsheetReader)->read($path, 'statement.csv')[1][0])->toBe('Date');
});

it('reads Windows-encoded CSVs with pound signs', function () {
    $path = tempFile(mb_convert_encoding("Amount\n£12.50\n", 'Windows-1252', 'UTF-8'), 'csv');

    expect((new SpreadsheetReader)->read($path, 'statement.csv')[2][0])->toBe('£12.50');
});

it('reads raw values from the first sheet of an XLSX file', function () {
    $path = tempXlsx(function (Spreadsheet $book) {
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([['Date', 'Description', 'Amount'], [46298, 'SHOP', 12.5]]);
        $book->createSheet()->setCellValue('A1', 'ignored second sheet');
    });

    $rows = (new SpreadsheetReader)->read($path, 'statement.xlsx');

    expect($rows)->toHaveCount(2)
        ->and($rows[2][0])->toEqual(46298)
        ->and($rows[2][2])->toEqual(12.5);
});

it('rejects unsupported file types', function () {
    $path = tempFile('hello', 'pdf');

    expect(fn () => (new SpreadsheetReader)->read($path, 'statement.pdf'))
        ->toThrow(UnreadableSpreadsheet::class, 'CSV or Excel');
});

it('rejects a corrupt spreadsheet', function () {
    $path = tempFile('not really a zip file', 'xlsx');

    expect(fn () => (new SpreadsheetReader)->read($path, 'statement.xlsx'))
        ->toThrow(UnreadableSpreadsheet::class);
});

it('rejects files with too many rows', function () {
    $path = tempFile("Date,Amount\n".str_repeat("03/10/2026,1.00\n", SpreadsheetReader::MAX_ROWS + 1), 'csv');

    expect(fn () => (new SpreadsheetReader)->read($path, 'statement.csv'))
        ->toThrow(UnreadableSpreadsheet::class, 'rows');
});
```

- [ ] **Step 3: Run the tests to see them fail**

Run: `php artisan test tests/Unit/Imports/SpreadsheetReaderTest.php`
Expected: FAIL — `Class "App\Imports\SpreadsheetReader" not found`.

- [ ] **Step 4: Implement the reader and its exception**

`app/Imports/UnreadableSpreadsheet.php`:
```php
<?php

namespace App\Imports;

use RuntimeException;

/** The uploaded file can't be used. The message is safe to show the admin. */
class UnreadableSpreadsheet extends RuntimeException {}
```

`app/Imports/SpreadsheetReader.php`:
```php
<?php

namespace App\Imports;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use Throwable;

final class SpreadsheetReader
{
    public const MAX_ROWS = 5000;

    /**
     * Read the first worksheet. Rows are keyed by their real row number; blank rows are left out.
     *
     * @return array<int, list<mixed>>
     *
     * @throws UnreadableSpreadsheet
     */
    public function read(string $absolutePath, string $originalName): array
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        $reader = match ($extension) {
            'csv', 'txt' => $this->csvReader(),
            'xlsx' => new Xlsx,
            'xls' => new Xls,
            default => throw new UnreadableSpreadsheet('Upload a CSV or Excel file (.csv, .xlsx or .xls).'),
        };

        $reader->setReadDataOnly(true);

        $book = $this->load($reader, $absolutePath, $extension);
        $sheet = $book->getSheet(0);

        if ($sheet->getHighestDataRow() > self::MAX_ROWS + 1) {
            $book->disconnectWorksheets();
            throw new UnreadableSpreadsheet('This file has more than '.self::MAX_ROWS.' rows. Split it into smaller files.');
        }

        $rows = [];
        foreach ($sheet->toArray(null, false, false, true) as $rowNumber => $cells) {
            $values = array_values($cells);

            if (collect($values)->every(fn ($value) => $value === null || (is_string($value) && trim($value) === ''))) {
                continue;
            }

            $rows[(int) $rowNumber] = $values;
        }

        $book->disconnectWorksheets();

        return $rows;
    }

    private function csvReader(): Csv
    {
        $reader = new Csv;
        $reader->setInputEncoding(Csv::GUESS_ENCODING);
        $reader->setFallbackEncoding('CP1252');

        return $reader;
    }

    private function load(IReader $reader, string $path, string $extension)
    {
        // CSV cells stay as text (so "0012" keeps its zeros); our own parsers read numbers and dates.
        $previousBinder = Cell::getValueBinder();
        if ($reader instanceof Csv) {
            Cell::setValueBinder(new StringValueBinder);
        }

        try {
            return $reader->load($path);
        } catch (Throwable $e) {
            throw new UnreadableSpreadsheet("We couldn't read this file. Check it opens in Excel and try again.", previous: $e);
        } finally {
            Cell::setValueBinder($previousBinder);
        }
    }
}
```
(If a PhpSpreadsheet 5 API used here has moved — e.g. `Cell::getValueBinder()` or `Csv::GUESS_ENCODING` — use the 5.x equivalent with the same behaviour and record it as a ruling. The tests pin the behaviour.)

- [ ] **Step 5: Implement `ImportSheet` and bind it per request**

`app/Imports/ImportSheet.php`:
```php
<?php

namespace App\Imports;

use App\Models\Import;
use Illuminate\Support\Facades\Storage;

/** Reads an import's stored file once per request and hands out its rows and headers. */
final class ImportSheet
{
    /** @var array<int, array<int, list<mixed>>> */
    private array $cache = [];

    public function __construct(private SpreadsheetReader $reader) {}

    /** @return array<int, list<mixed>> */
    public function rows(Import $import): array
    {
        return $this->cache[$import->id] ??= $this->reader->read(
            Storage::disk('local')->path($import->stored_path),
            $import->original_filename,
        );
    }

    /** @return array<int, string> header text by column index */
    public function headers(Import $import, int $headerRow): array
    {
        return array_map(RowParser::text(...), $this->rows($import)[$headerRow] ?? []);
    }

    /** @return array<int, list<mixed>> rows below the header row */
    public function dataRows(Import $import, int $headerRow): array
    {
        return array_filter($this->rows($import), fn (int $rowNumber) => $rowNumber > $headerRow, ARRAY_FILTER_USE_KEY);
    }
}
```

In `app/Providers/AppServiceProvider.php`, add `use App\Imports\ImportSheet;` and, in `register()` after the identity-provider binding:
```php
        $this->app->scoped(ImportSheet::class);
```

- [ ] **Step 6: Run the tests to see them pass**

Run: `php artisan test tests/Unit/Imports/SpreadsheetReaderTest.php`
Expected: 8 tests PASS. If the blank-line test shows the CSV reader drops blank lines from the numbering (keys `[1, 2, 3]`), stop: row numbering is a spec requirement — fix the reader (e.g. read CSV line numbers yourself with `fgetcsv`) rather than the test, and record a ruling.

- [ ] **Step 7: Run the full suite and commit**

Run: `php artisan test` — all PASS.

```bash
git add composer.json composer.lock app/Imports app/Providers/AppServiceProvider.php tests/Unit/Imports/SpreadsheetReaderTest.php
git commit -m "feat: read CSV and Excel statements with real row numbers"
```

---

### Task 5: Periods admin and dashboard

**Files:**
- Create: `app/Http/Requests/Admin/PeriodRequest.php`, `app/Http/Controllers/Admin/PeriodController.php`, `app/Periods/PeriodDashboard.php`
- Create: `resources/js/lib/money.ts`, `resources/js/components/PeriodStatusBadge.vue`, `resources/js/pages/admin/periods/Index.vue`, `Form.vue`, `Show.vue`
- Modify: `routes/admin.php`, `resources/js/components/AppSidebar.vue`, `resources/js/pages/Dashboard.vue`
- Test: `tests/Feature/Admin/PeriodsTest.php`

**Interfaces:**
- Consumes: models and enums (Task 1); `admin` middleware, `FlashMessage.vue` (Plan 1).
- Produces:
  - Routes: `admin.periods.index` (GET /admin/periods), `admin.periods.create`, `admin.periods.store` (POST), `admin.periods.show` (GET /admin/periods/{period}), `admin.periods.edit`, `admin.periods.update` (PUT).
  - `PeriodRequest::periodAttributes(): array` (label defaulted from month, month as `Y-m-01`, due_date, reminder_offsets sorted high→low ints).
  - `PeriodDashboard::for(Period $period): array` with keys `period`, `reconciliations` (each `id, user{id,name,active}, status, lines, done, queried, overdue`), `imports` (each `id, original_filename, status, row_count, created_at`), `unassigned_count`, `totals{lines, amount}`.
  - TS helpers `formatPence(pence: number | null): string`, `formatDate(iso: string | null): string` in `@/lib/money`.
  - `PeriodStatusBadge.vue` (prop `status`).
  - `admin/periods/Show.vue` with a `<div class="flex flex-wrap gap-2" data-actions>` header-actions container (later tasks add buttons there), an imports `<section data-imports>` and a `<section data-unassigned>` callout.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Admin/PeriodsTest.php`:
```php
<?php

use App\Enums\PeriodStatus;
use App\Enums\TransactionStatus;
use App\Models\Import;
use App\Models\Period;
use App\Models\Reconciliation;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['is_cardholder' => false]);
});

function periodPayload(array $overrides = []): array
{
    return array_merge([
        'label' => '',
        'month' => '2026-09',
        'due_date' => '2026-10-15',
        'reminder_offsets' => '3, 0',
    ], $overrides);
}

it('is only for admins', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.periods.index'))->assertForbidden();
});

it('lists periods newest first', function () {
    Period::factory()->create(['month' => '2026-08-01', 'label' => 'August 2026']);
    Period::factory()->create(['month' => '2026-09-01', 'label' => 'September 2026']);

    $this->actingAs($this->admin)
        ->get(route('admin.periods.index'))
        ->assertInertia(fn ($page) => $page
            ->component('admin/periods/Index')
            ->where('periods.0.label', 'September 2026')
            ->where('periods.1.label', 'August 2026'));
});

it('suggests last month and the 15th as defaults for a new period', function () {
    $this->travelTo('2026-10-10');

    $this->actingAs($this->admin)
        ->get(route('admin.periods.create'))
        ->assertInertia(fn ($page) => $page
            ->component('admin/periods/Form')
            ->where('defaults.month', '2026-09')
            ->where('defaults.due_date', '2026-10-15')
            ->where('defaults.reminder_offsets', '3, 0'));
});

it('creates a period, naming it after the month when no label is given', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.periods.store'), periodPayload())
        ->assertSessionHasNoErrors();

    $period = Period::sole();
    expect($period->label)->toBe('September 2026')
        ->and($period->month->format('Y-m-d'))->toBe('2026-09-01')
        ->and($period->due_date->format('Y-m-d'))->toBe('2026-10-15')
        ->and($period->reminder_offsets)->toBe([3, 0])
        ->and($period->status)->toBe(PeriodStatus::Draft);
    $this->assertDatabaseCount('periods', 1);
});

it('redirects to the new period\'s dashboard', function () {
    $response = $this->actingAs($this->admin)->post(route('admin.periods.store'), periodPayload());

    $response->assertRedirect(route('admin.periods.show', Period::sole()));
});

it('refuses a second period for the same month', function () {
    Period::factory()->create(['month' => '2026-09-01']);

    $this->actingAs($this->admin)
        ->post(route('admin.periods.store'), periodPayload())
        ->assertSessionHasErrors('month');
});

it('validates reminder days', function (string $offsets) {
    $this->actingAs($this->admin)
        ->post(route('admin.periods.store'), periodPayload(['reminder_offsets' => $offsets]))
        ->assertSessionHasErrors();
})->with(['abc', '3, 3', '-1', '61', '1,2,3,4,5,6']);

it('accepts no reminders at all', function () {
    $this->actingAs($this->admin)->post(route('admin.periods.store'), periodPayload(['reminder_offsets' => '']));

    expect(Period::sole()->reminder_offsets)->toBe([]);
});

it('updates a draft period', function () {
    $period = Period::factory()->create(['month' => '2026-09-01']);

    $this->actingAs($this->admin)
        ->put(route('admin.periods.update', $period), periodPayload(['label' => 'Sept', 'month' => '2026-08', 'due_date' => '2026-10-20']))
        ->assertRedirect(route('admin.periods.show', $period));

    $period->refresh();
    expect($period->label)->toBe('Sept')
        ->and($period->month->format('Y-m'))->toBe('2026-08')
        ->and($period->due_date->format('Y-m-d'))->toBe('2026-10-20');
});

it('locks the month once a period is published', function () {
    $period = Period::factory()->published()->create(['month' => '2026-09-01']);

    $this->actingAs($this->admin)
        ->put(route('admin.periods.update', $period), periodPayload(['month' => '2026-08']))
        ->assertSessionHasErrors('month');

    expect($period->fresh()->month->format('Y-m'))->toBe('2026-09');
});

it('does not allow editing a closed period', function () {
    $period = Period::factory()->closed()->create(['month' => '2026-09-01']);

    $this->actingAs($this->admin)->get(route('admin.periods.edit', $period))->assertForbidden();
    $this->actingAs($this->admin)->put(route('admin.periods.update', $period), periodPayload())->assertForbidden();
});

it('shows a dashboard with per-person progress, unassigned lines and totals', function () {
    $this->travelTo('2026-10-20');
    $period = Period::factory()->published()->create(['month' => '2026-09-01', 'due_date' => '2026-10-15']);
    $import = Import::factory()->for($period)->confirmed()->create(['original_filename' => 'sept.csv', 'row_count' => 4]);

    $sam = User::factory()->create(['name' => 'Sam']);
    $jo = User::factory()->inactive()->create(['name' => 'Jo']);
    $samRec = Reconciliation::factory()->for($period)->for($sam)->create();
    $joRec = Reconciliation::factory()->for($period)->for($jo)->submitted()->create();

    Transaction::factory()->for($import)->create(['reconciliation_id' => $samRec->id, 'amount' => 1000, 'status' => TransactionStatus::Done]);
    Transaction::factory()->for($import)->create(['reconciliation_id' => $samRec->id, 'amount' => 500, 'status' => TransactionStatus::Queried]);
    Transaction::factory()->for($import)->create(['reconciliation_id' => $joRec->id, 'amount' => 250]);
    Transaction::factory()->for($import)->create(['amount' => -100]);

    $this->actingAs($this->admin)
        ->get(route('admin.periods.show', $period))
        ->assertInertia(fn ($page) => $page
            ->component('admin/periods/Show')
            ->where('period.due_date', '2026-10-15')
            ->where('reconciliations.0.user.name', 'Jo')
            ->where('reconciliations.0.user.active', false)
            ->where('reconciliations.0.overdue', false)
            ->where('reconciliations.1.user.name', 'Sam')
            ->where('reconciliations.1.lines', 2)
            ->where('reconciliations.1.done', 1)
            ->where('reconciliations.1.queried', 1)
            ->where('reconciliations.1.overdue', true)
            ->where('imports.0.original_filename', 'sept.csv')
            ->where('unassigned_count', 1)
            ->where('totals.lines', 4)
            ->where('totals.amount', 1650));
});
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `php artisan test tests/Feature/Admin/PeriodsTest.php`
Expected: FAIL — `Route [admin.periods.index] not defined`.

- [ ] **Step 3: Create the form request**

`app/Http/Requests/Admin/PeriodRequest.php`:
```php
<?php

namespace App\Http\Requests\Admin;

use App\Enums\PeriodStatus;
use App\Models\Period;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class PeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    /** The form sends reminder days as "3, 0"; validate them as a list. */
    protected function prepareForValidation(): void
    {
        $offsets = $this->input('reminder_offsets');

        if (is_string($offsets)) {
            $offsets = collect(explode(',', $offsets))
                ->map(fn ($value) => trim($value))
                ->filter(fn ($value) => $value !== '')
                ->values()
                ->all();
        }

        $this->merge(['reminder_offsets' => $offsets ?? []]);
    }

    public function rules(): array
    {
        /** @var Period|null $period */
        $period = $this->route('period');

        return [
            'label' => ['nullable', 'string', 'max:100'],
            'month' => ['required', 'date_format:Y-m', function (string $attribute, mixed $value, Closure $fail) use ($period) {
                if ($period && $period->status !== PeriodStatus::Draft && $value !== $period->month->format('Y-m')) {
                    $fail("The month can't change once the period is published.");

                    return;
                }

                $taken = Period::query()
                    ->whereDate('month', $value.'-01')
                    ->when($period, fn ($query) => $query->whereKeyNot($period->id))
                    ->exists();

                if ($taken) {
                    $fail('There is already a period for this month.');
                }
            }],
            'due_date' => ['required', 'date_format:Y-m-d'],
            'reminder_offsets' => ['array', 'max:5'],
            'reminder_offsets.*' => ['integer', 'min:0', 'max:60', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return [
            'reminder_offsets.max' => 'Use at most 5 reminder days.',
            'reminder_offsets.*.integer' => 'Reminder days must be whole numbers, e.g. "3, 0".',
            'reminder_offsets.*.min' => 'Reminder days can’t be negative.',
            'reminder_offsets.*.max' => 'Reminders can be at most 60 days before the due date.',
            'reminder_offsets.*.distinct' => 'Each reminder day can only appear once.',
        ];
    }

    /** @return array<string, mixed> */
    public function periodAttributes(): array
    {
        $month = CarbonImmutable::createFromFormat('!Y-m', $this->validated('month'));

        return [
            'label' => $this->validated('label') ?: $month->format('F Y'),
            'month' => $month->format('Y-m-d'),
            'due_date' => $this->validated('due_date'),
            'reminder_offsets' => collect($this->validated('reminder_offsets', []))
                ->map(fn ($days) => (int) $days)
                ->sortDesc()
                ->values()
                ->all(),
        ];
    }
}
```

- [ ] **Step 4: Create the dashboard query**

`app/Periods/PeriodDashboard.php`:
```php
<?php

namespace App\Periods;

use App\Enums\PeriodStatus;
use App\Enums\ReconciliationStatus;
use App\Enums\TransactionStatus;
use App\Models\Import;
use App\Models\Period;
use App\Models\Reconciliation;

/** Everything the period dashboard shows, in one place. */
final class PeriodDashboard
{
    /** @return array<string, mixed> */
    public static function for(Period $period): array
    {
        $pastDue = $period->status === PeriodStatus::Published && $period->due_date->lt(today());

        $reconciliations = $period->reconciliations()
            ->with('user:id,name,active')
            ->withCount([
                'transactions as lines',
                'transactions as done' => fn ($query) => $query->whereIn('status', [TransactionStatus::Done, TransactionStatus::Approved]),
                'transactions as queried' => fn ($query) => $query->where('status', TransactionStatus::Queried),
            ])
            ->get()
            ->sortBy(fn (Reconciliation $reconciliation) => mb_strtolower($reconciliation->user->name))
            ->values();

        return [
            'period' => [
                'id' => $period->id,
                'label' => $period->label,
                'month' => $period->month->format('Y-m-d'),
                'due_date' => $period->due_date->format('Y-m-d'),
                'status' => $period->status->value,
                'published_at' => $period->published_at?->toIso8601String(),
            ],
            'reconciliations' => $reconciliations->map(fn (Reconciliation $reconciliation) => [
                'id' => $reconciliation->id,
                'user' => $reconciliation->user->only(['id', 'name', 'active']),
                'status' => $reconciliation->status->value,
                'lines' => $reconciliation->lines,
                'done' => $reconciliation->done,
                'queried' => $reconciliation->queried,
                'overdue' => $pastDue && $reconciliation->status === ReconciliationStatus::InProgress,
            ])->all(),
            'imports' => $period->imports()->latest('id')->get()->map(fn (Import $import) => [
                'id' => $import->id,
                'original_filename' => $import->original_filename,
                'status' => $import->status->value,
                'row_count' => $import->row_count,
                'created_at' => $import->created_at->toIso8601String(),
            ])->all(),
            'unassigned_count' => $period->unassignedTransactions()->count(),
            'totals' => [
                'lines' => $period->transactions()->count(),
                'amount' => (int) $period->transactions()->sum('amount'),
            ],
        ];
    }
}
```

- [ ] **Step 5: Create the controller and routes**

`app/Http/Controllers/Admin/PeriodController.php`:
```php
<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PeriodStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PeriodRequest;
use App\Models\Period;
use App\Periods\PeriodDashboard;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PeriodController extends Controller
{
    public function index(): Response
    {
        $periods = Period::query()
            ->withCount('transactions')
            ->orderByDesc('month')
            ->get()
            ->map(fn (Period $period) => [
                'id' => $period->id,
                'label' => $period->label,
                'due_date' => $period->due_date->format('Y-m-d'),
                'status' => $period->status->value,
                'transactions_count' => $period->transactions_count,
            ]);

        return Inertia::render('admin/periods/Index', ['periods' => $periods]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/periods/Form', [
            'period' => null,
            'defaults' => [
                // Reconciliation is usually for last month, due mid-month.
                'month' => today()->subMonthNoOverflow()->format('Y-m'),
                'due_date' => today()->day(15)->format('Y-m-d'),
                'reminder_offsets' => '3, 0',
            ],
        ]);
    }

    public function store(PeriodRequest $request): RedirectResponse
    {
        $period = Period::create($request->periodAttributes());

        return to_route('admin.periods.show', $period)->with('success', "{$period->label} has been created. Upload the statement next.");
    }

    public function show(Period $period): Response
    {
        return Inertia::render('admin/periods/Show', PeriodDashboard::for($period));
    }

    public function edit(Period $period): Response
    {
        abort_if($period->status === PeriodStatus::Closed, 403);

        return Inertia::render('admin/periods/Form', [
            'period' => [
                'id' => $period->id,
                'label' => $period->label,
                'month' => $period->month->format('Y-m'),
                'due_date' => $period->due_date->format('Y-m-d'),
                'reminder_offsets' => implode(', ', $period->reminder_offsets),
                'status' => $period->status->value,
            ],
            'defaults' => null,
        ]);
    }

    public function update(PeriodRequest $request, Period $period): RedirectResponse
    {
        abort_if($period->status === PeriodStatus::Closed, 403);

        $period->update($request->periodAttributes());

        return to_route('admin.periods.show', $period)->with('success', "{$period->label} has been updated.");
    }
}
```

In `routes/admin.php`, add `use App\Http\Controllers\Admin\PeriodController;` and inside the group:
```php
    Route::get('periods', [PeriodController::class, 'index'])->name('periods.index');
    Route::get('periods/create', [PeriodController::class, 'create'])->name('periods.create');
    Route::post('periods', [PeriodController::class, 'store'])->name('periods.store');
    Route::get('periods/{period}', [PeriodController::class, 'show'])->name('periods.show');
    Route::get('periods/{period}/edit', [PeriodController::class, 'edit'])->name('periods.edit');
    Route::put('periods/{period}', [PeriodController::class, 'update'])->name('periods.update');
```
(The closed-period update test passes validation first only if the request is valid; `abort_if` in `update()` runs after validation, and the payload in that test is valid, so it returns 403.)

- [ ] **Step 6: Run the backend tests to see them pass**

Run: `php artisan test tests/Feature/Admin/PeriodsTest.php --filter='admins|refuses|validates|accepts no|updates a draft|locks|closed|creates a period|redirects'`
Expected: PASS. The tests that render pages ("lists…", "suggests…", "shows a dashboard…") need the Vue pages from Steps 7–9 (Inertia checks page files exist) and run in Step 10.

- [ ] **Step 7: Add the money/date helpers and status badge**

`resources/js/lib/money.ts`:
```ts
const gbp = new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'GBP' });

/** 123456 → "£1,234.56"; negative amounts (credits) → "-£12.00". */
export function formatPence(pence: number | null): string {
    if (pence === null) {
        return '—';
    }

    const formatted = gbp.format(Math.abs(pence) / 100);

    return pence < 0 ? `-${formatted}` : formatted;
}

/** "2026-10-03" → "3 Oct 2026", without timezone surprises. */
export function formatDate(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    const [year, month, day] = iso.slice(0, 10).split('-').map(Number);

    return new Date(Date.UTC(year, month - 1, day)).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        timeZone: 'UTC',
    });
}
```

`resources/js/components/PeriodStatusBadge.vue`:
```vue
<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ status: 'draft' | 'published' | 'closed' }>();

const styles = {
    draft: 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200',
    published: 'bg-blue-100 text-blue-900 dark:bg-blue-950 dark:text-blue-200',
    closed: 'bg-neutral-200 text-neutral-800 dark:bg-neutral-800 dark:text-neutral-200',
};

const labels = { draft: 'Draft', published: 'Published', closed: 'Closed' };

const classes = computed(() => styles[props.status]);
</script>

<template>
    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium" :class="classes">{{ labels[status] }}</span>
</template>
```

- [ ] **Step 8: Create the periods list and form pages**

`resources/js/pages/admin/periods/Index.vue`:
```vue
<script setup lang="ts">
import FlashMessage from '@/components/FlashMessage.vue';
import PeriodStatusBadge from '@/components/PeriodStatusBadge.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';

interface PeriodRow {
    id: number;
    label: string;
    due_date: string;
    status: 'draft' | 'published' | 'closed';
    transactions_count: number;
}

defineProps<{ periods: PeriodRow[] }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Periods', href: '/admin/periods' }];
</script>

<template>
    <Head title="Periods" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-4 p-4">
            <FlashMessage />

            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold">Periods</h1>
                <Button as-child>
                    <Link :href="route('admin.periods.create')">New period</Link>
                </Button>
            </div>

            <ul class="divide-y rounded-lg border">
                <li v-for="period in periods" :key="period.id">
                    <Link :href="route('admin.periods.show', period.id)" class="flex items-center justify-between gap-4 p-4 hover:bg-muted/50">
                        <div>
                            <div class="font-medium">{{ period.label }}</div>
                            <div class="text-sm text-muted-foreground">
                                Due {{ formatDate(period.due_date) }} · {{ period.transactions_count }} lines
                            </div>
                        </div>
                        <PeriodStatusBadge :status="period.status" />
                    </Link>
                </li>
                <li v-if="!periods.length" class="p-4 text-sm text-muted-foreground">No periods yet. Create one to upload a statement.</li>
            </ul>
        </div>
    </AppLayout>
</template>
```

`resources/js/pages/admin/periods/Form.vue`:
```vue
<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

interface EditablePeriod {
    id: number;
    label: string;
    month: string;
    due_date: string;
    reminder_offsets: string;
    status: 'draft' | 'published' | 'closed';
}

const props = defineProps<{
    period: EditablePeriod | null;
    defaults: { month: string; due_date: string; reminder_offsets: string } | null;
}>();

const monthLocked = computed(() => props.period !== null && props.period.status !== 'draft');

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Periods', href: '/admin/periods' },
    { title: props.period ? props.period.label : 'New period', href: '#' },
];

const form = useForm({
    label: props.period?.label ?? '',
    month: props.period?.month ?? props.defaults?.month ?? '',
    due_date: props.period?.due_date ?? props.defaults?.due_date ?? '',
    reminder_offsets: props.period?.reminder_offsets ?? props.defaults?.reminder_offsets ?? '',
});

const errors = computed(() => form.errors as Record<string, string | undefined>);
const reminderError = computed(() =>
    Object.entries(errors.value).find(([key]) => key.startsWith('reminder_offsets'))?.[1],
);

const submit = () => {
    if (props.period) {
        form.put(route('admin.periods.update', props.period.id));
    } else {
        form.post(route('admin.periods.store'));
    }
};
</script>

<template>
    <Head :title="period ? `Edit ${period.label}` : 'New period'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <form @submit.prevent="submit" class="flex max-w-xl flex-col gap-6 p-4">
            <div class="grid gap-2">
                <Label for="month">Statement month</Label>
                <Input id="month" type="month" v-model="form.month" required :disabled="monthLocked" />
                <p v-if="monthLocked" class="text-xs text-muted-foreground">The month can't change once the period is published.</p>
                <InputError :message="form.errors.month" />
            </div>

            <div class="grid gap-2">
                <Label for="label">Name (optional)</Label>
                <Input id="label" v-model="form.label" placeholder="Leave blank to use the month, e.g. September 2026" />
                <InputError :message="form.errors.label" />
            </div>

            <div class="grid gap-2">
                <Label for="due_date">Due date</Label>
                <Input id="due_date" type="date" v-model="form.due_date" required />
                <InputError :message="form.errors.due_date" />
            </div>

            <div class="grid gap-2">
                <Label for="reminder_offsets">Reminder days</Label>
                <Input id="reminder_offsets" v-model="form.reminder_offsets" placeholder="3, 0" />
                <p class="text-xs text-muted-foreground">
                    Days before the due date to remind anyone who hasn't finished. 0 means on the day. Leave blank for no reminders.
                </p>
                <InputError :message="reminderError" />
            </div>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing">{{ period ? 'Save changes' : 'Create period' }}</Button>
                <Button variant="ghost" as-child>
                    <Link :href="period ? route('admin.periods.show', period.id) : route('admin.periods.index')">Cancel</Link>
                </Button>
            </div>
        </form>
    </AppLayout>
</template>
```

- [ ] **Step 9: Create the dashboard page**

`resources/js/pages/admin/periods/Show.vue`:
```vue
<script setup lang="ts">
import FlashMessage from '@/components/FlashMessage.vue';
import PeriodStatusBadge from '@/components/PeriodStatusBadge.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatPence } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';

interface DashboardPeriod {
    id: number;
    label: string;
    month: string;
    due_date: string;
    status: 'draft' | 'published' | 'closed';
    published_at: string | null;
}

interface DashboardReconciliation {
    id: number;
    user: { id: number; name: string; active: boolean };
    status: 'in_progress' | 'submitted' | 'approved';
    lines: number;
    done: number;
    queried: number;
    overdue: boolean;
}

interface DashboardImport {
    id: number;
    original_filename: string;
    status: 'draft' | 'confirmed';
    row_count: number;
    created_at: string;
}

const props = defineProps<{
    period: DashboardPeriod;
    reconciliations: DashboardReconciliation[];
    imports: DashboardImport[];
    unassigned_count: number;
    totals: { lines: number; amount: number };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Periods', href: '/admin/periods' },
    { title: props.period.label, href: `/admin/periods/${props.period.id}` },
];

const statusLabel = (row: DashboardReconciliation) => {
    if (row.status === 'submitted') return 'Submitted';
    if (row.status === 'approved') return 'Approved';
    return row.done === 0 && row.queried === 0 ? 'Not started' : 'In progress';
};
</script>

<template>
    <Head :title="period.label" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-4">
            <FlashMessage />

            <header class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-xl font-semibold">{{ period.label }}</h1>
                        <PeriodStatusBadge :status="period.status" />
                    </div>
                    <p class="text-sm text-muted-foreground">
                        Due {{ formatDate(period.due_date) }} · {{ totals.lines }} lines · {{ formatPence(totals.amount) }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2" data-actions>
                    <Button v-if="period.status !== 'closed'" variant="outline" as-child>
                        <Link :href="route('admin.periods.edit', period.id)">Edit</Link>
                    </Button>
                </div>
            </header>

            <section v-if="unassigned_count > 0" data-unassigned class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
                {{ unassigned_count }} {{ unassigned_count === 1 ? 'line is' : 'lines are' }} not matched to anyone yet.
            </section>

            <section class="flex flex-col gap-2">
                <h2 class="font-medium">Cardholders</h2>

                <ul class="divide-y rounded-lg border">
                    <li v-for="row in reconciliations" :key="row.id" class="flex flex-col gap-1 p-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <div class="font-medium">
                                {{ row.user.name }}
                                <span v-if="!row.user.active" class="ml-1 text-xs font-normal text-red-600">Inactive: reassign their lines</span>
                            </div>
                            <div class="text-sm text-muted-foreground">
                                {{ row.done }} of {{ row.lines }} done<span v-if="row.queried"> · {{ row.queried }} queried</span>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 text-sm">
                            <span v-if="row.overdue" class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800 dark:bg-red-950 dark:text-red-200">Overdue</span>
                            <span>{{ statusLabel(row) }}</span>
                        </div>
                    </li>
                    <li v-if="!reconciliations.length" class="p-4 text-sm text-muted-foreground">
                        No lines assigned yet. Upload the statement to get started.
                    </li>
                </ul>
            </section>

            <section data-imports class="flex flex-col gap-2">
                <h2 class="font-medium">Uploaded files</h2>

                <ul class="divide-y rounded-lg border">
                    <li v-for="upload in imports" :key="upload.id" class="flex items-center justify-between gap-3 p-4 text-sm">
                        <div>
                            <div class="font-medium">{{ upload.original_filename }}</div>
                            <div class="text-muted-foreground">
                                {{ upload.status === 'confirmed' ? `${upload.row_count} lines imported` : 'Not finished' }} · {{ formatDate(upload.created_at) }}
                            </div>
                        </div>
                    </li>
                    <li v-if="!imports.length" class="p-4 text-sm text-muted-foreground">No files uploaded yet.</li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
```

- [ ] **Step 10: Add Periods to the navigation and admin dashboard card**

In `resources/js/components/AppSidebar.vue`:
- Change the icon import to `import { CalendarRange, LayoutGrid, Users } from 'lucide-vue-next';`
- Replace the admin entry line with:
```ts
    ...(page.props.auth.user.is_admin
        ? [
              { title: 'Periods', href: '/admin/periods', icon: CalendarRange },
              { title: 'Manage users', href: '/admin/users', icon: Users },
          ]
        : []),
```

In `resources/js/pages/Dashboard.vue`, replace the admin card's single button with:
```vue
                <div class="mt-3 flex flex-wrap gap-2">
                    <Button as-child>
                        <Link :href="route('admin.periods.index')">Periods</Link>
                    </Button>
                    <Button variant="outline" as-child>
                        <Link :href="route('admin.users.index')">Manage users</Link>
                    </Button>
                </div>
```
and change that card's paragraph to: `Create a period for each statement month, upload the export, and track who has finished.`

- [ ] **Step 11: Run all tests and the frontend checks**

Run: `php artisan test && npm run build && npx vue-tsc --noEmit 2>&1 | grep -E 'error TS' | grep -v TS2688`
Expected: all PASS; build succeeds; the final grep prints nothing.

- [ ] **Step 12: Commit**

```bash
git add -A
git commit -m "feat: periods admin with dashboard"
```

---

### Task 6: Import preview

**Files:**
- Create: `app/Imports/PreviewRow.php`, `app/Imports/ImportPreview.php`, `app/Imports/ImportPreviewer.php`
- Modify: `tests/Pest.php` (helpers)
- Test: `tests/Feature/Imports/ImportPreviewerTest.php`

**Interfaces:**
- Consumes: `ImportSheet` (Task 4), `ColumnMap`, `RowParser`, `CardholderMatcher`, `DuplicateKey`, `ParsedRow` (Task 3), models (Task 1).
- Produces:
  - `final readonly class PreviewRow(ParsedRow $parsed, ?int $userId, bool $duplicate)` with `toArray(array $userNames): array` (keys `row_number, date, raw_date, description, amount, raw_amount, card_ref, user_id, user_name, duplicate, errors, extra`).
  - `final readonly class ImportPreview(list<PreviewRow> $rows)` with `errorRowNumbers(): list<int>` and `summary(array $userNames): array` (keys `total, errors, duplicates, unassigned, by_user` — `by_user` a list of `{user_id, name, count}` sorted by name).
  - `ImportPreviewer::preview(Import $import, array $fixes = []): ImportPreview` — `$fixes` keyed by row number, each `['date' => ?, 'amount' => ?, 'description' => ?]`. Rows that duplicate an earlier row in the file, or a line already in the period from another import, are `duplicate = true`.
  - Pest helpers (global, in `tests/Pest.php`): `statementCsv(array $rows, string $header = 'Date,Description,Card Member,Account #,Amount'): string` and `importFromCsv(string $csv, array $attributes = []): Import` (creates a **draft** import with the factory's default mapping and writes the CSV to its `stored_path` on the `local` disk — call `Storage::fake('local')` first).

- [ ] **Step 1: Add the test helpers**

Append to `tests/Pest.php`:
```php
/** Build a statement CSV. Each row is a list of cells; cells containing commas must be quoted by the caller. */
function statementCsv(array $rows, string $header = 'Date,Description,Card Member,Account #,Amount'): string
{
    return $header."\n".implode("\n", array_map(fn (array $row) => implode(',', $row), $rows))."\n";
}

/** A draft import whose stored file is this CSV. Call Storage::fake('local') first. */
function importFromCsv(string $csv, array $attributes = []): App\Models\Import
{
    $import = App\Models\Import::factory()->create(['original_filename' => 'statement.csv', ...$attributes]);
    Illuminate\Support\Facades\Storage::disk('local')->put($import->stored_path, $csv);

    return $import;
}
```

- [ ] **Step 2: Write the failing tests**

`tests/Feature/Imports/ImportPreviewerTest.php`:
```php
<?php

use App\Imports\ImportPreviewer;
use App\Models\CardIdentifier;
use App\Models\Import;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    $this->sam = User::factory()->create(['name' => 'Sam Cardholder']);
    CardIdentifier::factory()->for($this->sam)->create(['last4' => '1001']);
    $this->jo = User::factory()->create(['name' => 'Jo Manager']);
    CardIdentifier::factory()->for($this->jo)->create(['last4' => '1002']);
});

function previewOf(Import $import, array $fixes = [])
{
    return app(ImportPreviewer::class)->preview($import, $fixes);
}

it('parses rows and assigns them to cardholders', function () {
    $import = importFromCsv(statementCsv([
        ['03/09/2026', 'TRAINLINE', 'S CARDHOLDER', 'XXXX-XXXXXX-01001', '45.20'],
        ['04/09/2026', 'PREMIER INN', 'J MANAGER', 'XXXX-XXXXXX-01002', '89.00'],
    ]));

    $preview = previewOf($import);

    expect($preview->rows)->toHaveCount(2)
        ->and($preview->rows[0]->parsed->rowNumber)->toBe(2)
        ->and($preview->rows[0]->userId)->toBe($this->sam->id)
        ->and($preview->rows[1]->userId)->toBe($this->jo->id)
        ->and($preview->errorRowNumbers())->toBe([]);
});

it('flags rows it cannot read and leaves them unassigned', function () {
    $import = importFromCsv(statementCsv([
        ['31/02/2026', 'SHOP', 'S CARDHOLDER', '1001', '1.00'],
    ]));

    $row = previewOf($import)->rows[0];

    expect($row->parsed->isValid())->toBeFalse()
        ->and($row->userId)->toBeNull()
        ->and(previewOf($import)->errorRowNumbers())->toBe([2]);
});

it('applies fixes to rows', function () {
    $import = importFromCsv(statementCsv([
        ['31/02/2026', 'SHOP', 'S CARDHOLDER', '1001', '1.00'],
    ]));

    $row = previewOf($import, [2 => ['date' => '28/02/2026']])->rows[0];

    expect($row->parsed->isValid())->toBeTrue()->and($row->userId)->toBe($this->sam->id);
});

it('flags a repeated row within the same file', function () {
    $import = importFromCsv(statementCsv([
        ['03/09/2026', 'TRAINLINE', 'S CARDHOLDER', '1001', '45.20'],
        ['03/09/2026', 'Trainline', 'S CARDHOLDER', '1001', '45.20'],
    ]));

    $rows = previewOf($import)->rows;

    expect($rows[0]->duplicate)->toBeFalse()->and($rows[1]->duplicate)->toBeTrue();
});

it('flags rows already imported into this period from another file', function () {
    $import = importFromCsv(statementCsv([
        ['03/09/2026', 'TRAINLINE', 'S CARDHOLDER', '1001', '45.20'],
        ['05/09/2026', 'NEW SHOP', 'S CARDHOLDER', '1001', '10.00'],
    ]));
    $earlier = Import::factory()->for($import->period)->confirmed()->create();
    Transaction::factory()->for($earlier)->create([
        'transacted_on' => '2026-09-03', 'amount' => 4520, 'description' => 'TRAINLINE', 'card_ref' => '1001',
    ]);

    $rows = previewOf($import)->rows;

    expect($rows[0]->duplicate)->toBeTrue()->and($rows[1]->duplicate)->toBeFalse();
});

it('ignores lines from other periods when checking duplicates', function () {
    $import = importFromCsv(statementCsv([
        ['03/09/2026', 'TRAINLINE', 'S CARDHOLDER', '1001', '45.20'],
    ]));
    Transaction::factory()->create([
        'transacted_on' => '2026-09-03', 'amount' => 4520, 'description' => 'TRAINLINE', 'card_ref' => '1001',
    ]);

    expect(previewOf($import)->rows[0]->duplicate)->toBeFalse();
});

it('respects a header row further down the file', function () {
    $import = importFromCsv(
        "Corporate card statement\n".statementCsv([['03/09/2026', 'SHOP', 'S CARDHOLDER', '1001', '1.00']]),
        ['header_row' => 2],
    );

    $rows = previewOf($import)->rows;

    expect($rows)->toHaveCount(1)->and($rows[0]->parsed->rowNumber)->toBe(3);
});

it('summarises the preview', function () {
    $import = importFromCsv(statementCsv([
        ['03/09/2026', 'A', 'S CARDHOLDER', '1001', '1.00'],
        ['03/09/2026', 'B', 'J MANAGER', '1002', '2.00'],
        ['03/09/2026', 'C', 'J MANAGER', '1002', '3.00'],
        ['03/09/2026', 'D', 'NOBODY', '9999', '4.00'],
        ['bad', 'E', 'S CARDHOLDER', '1001', '5.00'],
        ['03/09/2026', 'A', 'S CARDHOLDER', '1001', '1.00'],
    ]));
    $names = [$this->sam->id => 'Sam Cardholder', $this->jo->id => 'Jo Manager'];

    expect(previewOf($import)->summary($names))->toBe([
        'total' => 6,
        'errors' => 1,
        'duplicates' => 1,
        'unassigned' => 1,
        'by_user' => [
            ['user_id' => $this->jo->id, 'name' => 'Jo Manager', 'count' => 2],
            ['user_id' => $this->sam->id, 'name' => 'Sam Cardholder', 'count' => 1],
        ],
    ]);
});

it('converts a row for the preview screen', function () {
    $import = importFromCsv(statementCsv([
        ['03/09/2026', 'TRAINLINE', 'S CARDHOLDER', 'XXXX-XXXXXX-01001', '45.20'],
    ]));

    expect(previewOf($import)->rows[0]->toArray([$this->sam->id => 'Sam Cardholder']))->toBe([
        'row_number' => 2,
        'date' => '2026-09-03',
        'raw_date' => '03/09/2026',
        'description' => 'TRAINLINE',
        'amount' => 4520,
        'raw_amount' => '45.20',
        'card_ref' => '1001',
        'user_id' => $this->sam->id,
        'user_name' => 'Sam Cardholder',
        'duplicate' => false,
        'errors' => [],
        'extra' => [],
    ]);
});
```

- [ ] **Step 3: Run the tests to see them fail**

Run: `php artisan test tests/Feature/Imports/ImportPreviewerTest.php`
Expected: FAIL — `Class "App\Imports\ImportPreviewer" not found`.

- [ ] **Step 4: Implement the preview value objects**

`app/Imports/PreviewRow.php`:
```php
<?php

namespace App\Imports;

final readonly class PreviewRow
{
    public function __construct(
        public ParsedRow $parsed,
        public ?int $userId,
        public bool $duplicate,
    ) {}

    /** @param  array<int, string>  $userNames */
    public function toArray(array $userNames): array
    {
        return [
            'row_number' => $this->parsed->rowNumber,
            'date' => $this->parsed->date?->format('Y-m-d'),
            'raw_date' => $this->parsed->rawDate,
            'description' => $this->parsed->description,
            'amount' => $this->parsed->amount,
            'raw_amount' => $this->parsed->rawAmount,
            'card_ref' => $this->parsed->cardRef(),
            'user_id' => $this->userId,
            'user_name' => $this->userId !== null ? ($userNames[$this->userId] ?? null) : null,
            'duplicate' => $this->duplicate,
            'errors' => $this->parsed->errors,
            'extra' => $this->parsed->extra,
        ];
    }
}
```

`app/Imports/ImportPreview.php`:
```php
<?php

namespace App\Imports;

final readonly class ImportPreview
{
    /** @param  list<PreviewRow>  $rows */
    public function __construct(public array $rows) {}

    /** @return list<int> */
    public function errorRowNumbers(): array
    {
        return array_values(array_map(
            fn (PreviewRow $row) => $row->parsed->rowNumber,
            array_filter($this->rows, fn (PreviewRow $row) => ! $row->parsed->isValid()),
        ));
    }

    /** @param  array<int, string>  $userNames */
    public function summary(array $userNames): array
    {
        $importable = array_filter($this->rows, fn (PreviewRow $row) => $row->parsed->isValid() && ! $row->duplicate);

        $byUser = [];
        foreach ($importable as $row) {
            if ($row->userId !== null) {
                $byUser[$row->userId] = ($byUser[$row->userId] ?? 0) + 1;
            }
        }

        $byUser = collect($byUser)
            ->map(fn (int $count, int $userId) => ['user_id' => $userId, 'name' => $userNames[$userId] ?? 'Unknown', 'count' => $count])
            ->sortBy(fn (array $entry) => mb_strtolower($entry['name']))
            ->values()
            ->all();

        return [
            'total' => count($this->rows),
            'errors' => count($this->errorRowNumbers()),
            'duplicates' => count(array_filter($this->rows, fn (PreviewRow $row) => $row->duplicate)),
            'unassigned' => count(array_filter($importable, fn (PreviewRow $row) => $row->userId === null)),
            'by_user' => $byUser,
        ];
    }
}
```

- [ ] **Step 5: Implement the previewer**

`app/Imports/ImportPreviewer.php`:
```php
<?php

namespace App\Imports;

use App\Models\Import;
use App\Models\Transaction;
use LogicException;

/** Shows exactly what confirming an import would do, row by row. */
final class ImportPreviewer
{
    public function __construct(private ImportSheet $sheet) {}

    /** @param  array<int, array{date?: ?string, amount?: ?string, description?: ?string}>  $fixes  keyed by row number */
    public function preview(Import $import, array $fixes = []): ImportPreview
    {
        if ($import->column_mapping === null) {
            throw new LogicException('The import has no column mapping yet.');
        }

        $parser = new RowParser(
            ColumnMap::fromArray($import->column_mapping),
            $this->sheet->headers($import, $import->header_row),
        );
        $matcher = CardholderMatcher::forActiveCardholders();
        $seen = $this->keysAlreadyInPeriod($import);

        $rows = [];
        foreach ($this->sheet->dataRows($import, $import->header_row) as $rowNumber => $cells) {
            $parsed = $parser->parse($rowNumber, $cells, $fixes[$rowNumber] ?? []);
            $key = $parsed->duplicateKey();
            $duplicate = $key !== null && isset($seen[$key]);

            if ($key !== null) {
                $seen[$key] = true;
            }

            $rows[] = new PreviewRow(
                $parsed,
                $parsed->isValid() ? $matcher->match($parsed->last4, $parsed->cardholderName) : null,
                $duplicate,
            );
        }

        return new ImportPreview($rows);
    }

    /** @return array<string, true> */
    private function keysAlreadyInPeriod(Import $import): array
    {
        return Transaction::query()
            ->where('period_id', $import->period_id)
            ->where('import_id', '!=', $import->id)
            ->get(['transacted_on', 'amount', 'description', 'card_ref'])
            ->mapWithKeys(fn (Transaction $transaction) => [
                DuplicateKey::make($transaction->transacted_on, $transaction->amount, $transaction->description, $transaction->card_ref) => true,
            ])
            ->all();
    }
}
```

- [ ] **Step 6: Run the tests to see them pass**

Run: `php artisan test tests/Feature/Imports/ImportPreviewerTest.php`
Expected: 9 tests PASS.

- [ ] **Step 7: Run the full suite and commit**

Run: `php artisan test` — all PASS.

```bash
git add app/Imports tests/Pest.php tests/Feature/Imports/ImportPreviewerTest.php
git commit -m "feat: preview an import with matches, problems and duplicates"
```

---

### Task 7: Confirming and removing imports

**Files:**
- Create: `app/Imports/ImportCommitter.php`, `app/Imports/ImportNotReady.php`, `app/Imports/CommitResult.php`, `app/Imports/ImportRemover.php`, `app/Events/ReconciliationLinesAdded.php`
- Modify: `app/Models/Reconciliation.php` (`linesAdded()`)
- Test: `tests/Feature/Imports/ImportCommitterTest.php`, `tests/Feature/Imports/ImportRemoverTest.php`

**Interfaces:**
- Consumes: `ImportPreviewer` (Task 6), models/enums (Task 1), activity log (Plan 1).
- Produces:
  - `ImportCommitter::commit(Import $import, User $admin, array $skip = [], array $includeDuplicates = [], array $fixes = []): CommitResult` — throws `ImportNotReady` (message safe to show) if the import isn't a draft, the period is closed, or unskipped rows still have errors.
  - `final readonly class CommitResult(int $created, int $unassigned, int $skippedDuplicates)` with `message(): string`.
  - `ImportNotReady extends RuntimeException` with `static unresolvedRows(array $rowNumbers): self`.
  - `ImportRemover::remove(Import $import, User $admin): void` — throws `ImportNotReady` when a confirmed import's period is no longer draft.
  - `Reconciliation::linesAdded(int $count): void` — reopens a submitted/approved reconciliation and, if the period is published, dispatches `ReconciliationLinesAdded`.
  - Event `App\Events\ReconciliationLinesAdded(Reconciliation $reconciliation, int $count)` implementing `ShouldDispatchAfterCommit`.
  - Activity log name `periods`, events `import_confirmed`, `import_removed`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Imports/ImportCommitterTest.php`:
```php
<?php

use App\Enums\ImportStatus;
use App\Enums\ReconciliationStatus;
use App\Events\ReconciliationLinesAdded;
use App\Imports\ImportCommitter;
use App\Imports\ImportNotReady;
use App\Models\CardIdentifier;
use App\Models\Import;
use App\Models\Period;
use App\Models\Reconciliation;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('local');

    $this->admin = User::factory()->admin()->create(['is_cardholder' => false]);
    $this->sam = User::factory()->create(['name' => 'Sam Cardholder']);
    CardIdentifier::factory()->for($this->sam)->create(['last4' => '1001']);
});

function commitImport(Import $import, ...$args)
{
    return app(ImportCommitter::class)->commit($import, test()->admin, ...$args);
}

it('creates transactions and a reconciliation per cardholder', function () {
    $import = importFromCsv(statementCsv([
        ['03/09/2026', 'TRAINLINE', 'S CARDHOLDER', 'XXXX-XXXXXX-01001', '45.20'],
        ['04/09/2026', 'MYSTERY', 'NOBODY', '9999', '-5.00'],
    ]));

    $result = commitImport($import);

    expect($result->created)->toBe(2)
        ->and($result->unassigned)->toBe(1)
        ->and($import->fresh()->status)->toBe(ImportStatus::Confirmed)
        ->and($import->fresh()->row_count)->toBe(2);

    $reconciliation = Reconciliation::sole();
    expect($reconciliation->user_id)->toBe($this->sam->id)
        ->and($reconciliation->transactions)->toHaveCount(1);

    $line = $reconciliation->transactions->first();
    expect($line->row_number)->toBe(2)
        ->and($line->transacted_on->format('Y-m-d'))->toBe('2026-09-03')
        ->and($line->amount)->toBe(4520)
        ->and($line->card_ref)->toBe('1001')
        ->and(Transaction::whereNull('reconciliation_id')->sole()->amount)->toBe(-500);
});

it('never stores a full card number', function () {
    $import = importFromCsv(
        statementCsv([['03/09/2026', 'REFUND 378282246310005', 'S CARDHOLDER', '1001', '1.00', '4111 1111 1111 1111']], 'Date,Description,Card Member,Account #,Amount,Reference'),
    );

    commitImport($import);

    $line = Transaction::sole();
    expect($line->description)->toBe('REFUND **** 0005')
        ->and($line->extra)->toBe(['Reference' => '**** 1111']);
});

it('refuses to confirm while rows still have errors', function () {
    $import = importFromCsv(statementCsv([
        ['31/02/2026', 'SHOP', 'S CARDHOLDER', '1001', '1.00'],
        ['03/09/2026', 'SHOP', 'S CARDHOLDER', '1001', 'abc'],
    ]));

    expect(fn () => commitImport($import))->toThrow(ImportNotReady::class, 'Rows 2, 3');
    expect(Transaction::count())->toBe(0)->and($import->fresh()->status)->toBe(ImportStatus::Draft);
});

it('confirms once bad rows are fixed or skipped', function () {
    $import = importFromCsv(statementCsv([
        ['31/02/2026', 'SHOP', 'S CARDHOLDER', '1001', '1.00'],
        ['03/09/2026', 'SHOP', 'S CARDHOLDER', '1001', 'abc'],
    ]));

    $result = commitImport($import, [3], [], [2 => ['date' => '28/02/2026']]);

    expect($result->created)->toBe(1)
        ->and(Transaction::sole()->transacted_on->format('Y-m-d'))->toBe('2026-02-28');
});

it('skips duplicates unless the admin includes them', function () {
    $csv = statementCsv([
        ['03/09/2026', 'TRAINLINE', 'S CARDHOLDER', '1001', '45.20'],
        ['03/09/2026', 'TRAINLINE', 'S CARDHOLDER', '1001', '45.20'],
    ]);

    $first = commitImport(importFromCsv($csv));
    expect($first->created)->toBe(1)->and($first->skippedDuplicates)->toBe(1);

    Transaction::query()->delete();
    Import::query()->delete();

    $second = commitImport(importFromCsv($csv), [], [3]);
    expect($second->created)->toBe(2);
});

it('does not double lines when the same file is uploaded twice', function () {
    $csv = statementCsv([['03/09/2026', 'TRAINLINE', 'S CARDHOLDER', '1001', '45.20']]);
    $first = importFromCsv($csv);
    commitImport($first);

    $again = commitImport(importFromCsv($csv, ['period_id' => $first->period_id]));

    expect($again->created)->toBe(0)
        ->and($again->skippedDuplicates)->toBe(1)
        ->and(Transaction::count())->toBe(1);
});

it('refuses to confirm twice or into a closed period', function () {
    $import = importFromCsv(statementCsv([['03/09/2026', 'SHOP', 'S CARDHOLDER', '1001', '1.00']]));
    commitImport($import);

    expect(fn () => commitImport($import->fresh()))->toThrow(ImportNotReady::class, 'already');

    $closed = importFromCsv(
        statementCsv([['03/09/2026', 'SHOP', 'S CARDHOLDER', '1001', '1.00']]),
        ['period_id' => Period::factory()->closed()->create()->id],
    );
    expect(fn () => commitImport($closed))->toThrow(ImportNotReady::class, 'closed');
});

it('reopens a submitted reconciliation and announces late lines in a published period', function () {
    Event::fake([ReconciliationLinesAdded::class]);
    $period = Period::factory()->published()->create();
    $reconciliation = Reconciliation::factory()->for($period)->for($this->sam)->submitted()->create();

    commitImport(importFromCsv(statementCsv([['03/09/2026', 'LATE CHARGE', 'S CARDHOLDER', '1001', '9.99']]), ['period_id' => $period->id]));

    expect($reconciliation->fresh()->status)->toBe(ReconciliationStatus::InProgress);
    Event::assertDispatched(ReconciliationLinesAdded::class, fn ($event) => $event->reconciliation->is($reconciliation) && $event->count === 1);
});

it('reopens an approved reconciliation and clears the approval', function () {
    $period = Period::factory()->published()->create();
    $reconciliation = Reconciliation::factory()->for($period)->for($this->sam)->approved()->create(['approved_by' => $this->admin->id]);

    commitImport(importFromCsv(statementCsv([['03/09/2026', 'LATE CHARGE', 'S CARDHOLDER', '1001', '9.99']]), ['period_id' => $period->id]));

    $fresh = $reconciliation->fresh();
    expect($fresh->status)->toBe(ReconciliationStatus::InProgress)
        ->and($fresh->approved_at)->toBeNull()
        ->and($fresh->approved_by)->toBeNull();
});

it('announces nothing while the period is still a draft', function () {
    Event::fake([ReconciliationLinesAdded::class]);

    commitImport(importFromCsv(statementCsv([['03/09/2026', 'SHOP', 'S CARDHOLDER', '1001', '1.00']])));

    Event::assertNotDispatched(ReconciliationLinesAdded::class);
});

it('logs the import', function () {
    $import = importFromCsv(statementCsv([['03/09/2026', 'SHOP', 'S CARDHOLDER', '1001', '1.00']]));

    commitImport($import);

    $activity = Activity::where('log_name', 'periods')->where('event', 'import_confirmed')->sole();
    expect($activity->causer_id)->toBe($this->admin->id)
        ->and($activity->subject_id)->toBe($import->period_id);
});

it('describes the result', function () {
    expect((new App\Imports\CommitResult(12, 2, 1))->message())->toBe('Imported 12 lines (2 unassigned, 1 duplicate skipped).')
        ->and((new App\Imports\CommitResult(1, 0, 0))->message())->toBe('Imported 1 line.');
});
```

`tests/Feature/Imports/ImportRemoverTest.php`:
```php
<?php

use App\Imports\ImportNotReady;
use App\Imports\ImportRemover;
use App\Models\Import;
use App\Models\Period;
use App\Models\Reconciliation;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->admin = User::factory()->admin()->create();
});

it('removes an unfinished import and its file', function () {
    $import = importFromCsv(statementCsv([['03/09/2026', 'SHOP', 'X', '1001', '1.00']]));

    app(ImportRemover::class)->remove($import, $this->admin);

    expect(Import::count())->toBe(0);
    Storage::disk('local')->assertMissing($import->stored_path);
});

it('removes a confirmed import from a draft period with its lines and empty reconciliations', function () {
    $period = Period::factory()->create();
    $import = Import::factory()->for($period)->confirmed()->create();
    $other = Import::factory()->for($period)->confirmed()->create();
    $emptied = Reconciliation::factory()->for($period)->create();
    $kept = Reconciliation::factory()->for($period)->create();
    Transaction::factory()->for($import)->create(['reconciliation_id' => $emptied->id]);
    Transaction::factory()->for($import)->create(['reconciliation_id' => $kept->id]);
    Transaction::factory()->for($other)->create(['reconciliation_id' => $kept->id]);

    app(ImportRemover::class)->remove($import, $this->admin);

    expect(Transaction::count())->toBe(1)
        ->and(Reconciliation::pluck('id')->all())->toBe([$kept->id]);
});

it('refuses to remove a confirmed import once the period is published', function () {
    $import = Import::factory()->for(Period::factory()->published())->confirmed()->create();

    expect(fn () => app(ImportRemover::class)->remove($import, $this->admin))->toThrow(ImportNotReady::class);
    expect(Import::count())->toBe(1);
});
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `php artisan test tests/Feature/Imports/ImportCommitterTest.php tests/Feature/Imports/ImportRemoverTest.php`
Expected: FAIL — `Class "App\Imports\ImportCommitter" not found`.

- [ ] **Step 3: Add the event and `Reconciliation::linesAdded()`**

`app/Events/ReconciliationLinesAdded.php`:
```php
<?php

namespace App\Events;

use App\Models\Reconciliation;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** New lines landed on a cardholder's reconciliation in a published period. Plan 5 notifies them. */
class ReconciliationLinesAdded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public Reconciliation $reconciliation,
        public int $count,
    ) {}
}
```

In `app/Models/Reconciliation.php`, add imports `use App\Enums\PeriodStatus;` and `use App\Events\ReconciliationLinesAdded;`, then add:
```php
    /** New lines arrived: anything already submitted or approved needs looking at again. */
    public function linesAdded(int $count): void
    {
        if (in_array($this->status, [ReconciliationStatus::Submitted, ReconciliationStatus::Approved], true)) {
            $this->update([
                'status' => ReconciliationStatus::InProgress,
                'approved_at' => null,
                'approved_by' => null,
            ]);
        }

        if ($this->period->status === PeriodStatus::Published) {
            ReconciliationLinesAdded::dispatch($this, $count);
        }
    }
```

- [ ] **Step 4: Implement the result and exception**

`app/Imports/CommitResult.php`:
```php
<?php

namespace App\Imports;

use Illuminate\Support\Str;

final readonly class CommitResult
{
    public function __construct(
        public int $created,
        public int $unassigned,
        public int $skippedDuplicates,
    ) {}

    public function message(): string
    {
        $notes = array_filter([
            $this->unassigned > 0 ? "{$this->unassigned} unassigned" : null,
            $this->skippedDuplicates > 0 ? $this->skippedDuplicates.' '.Str::plural('duplicate', $this->skippedDuplicates).' skipped' : null,
        ]);

        return "Imported {$this->created} ".Str::plural('line', $this->created)
            .($notes ? ' ('.implode(', ', $notes).')' : '')
            .'.';
    }
}
```

`app/Imports/ImportNotReady.php`:
```php
<?php

namespace App\Imports;

use RuntimeException;

/** The import can't be confirmed or removed yet. The message is safe to show the admin. */
class ImportNotReady extends RuntimeException
{
    /** @param  list<int>  $rowNumbers */
    public static function unresolvedRows(array $rowNumbers): self
    {
        return new self('Rows '.implode(', ', $rowNumbers).' still have problems. Fix or skip them before confirming.');
    }
}
```

- [ ] **Step 5: Implement the committer**

`app/Imports/ImportCommitter.php`:
```php
<?php

namespace App\Imports;

use App\Enums\ImportStatus;
use App\Enums\PeriodStatus;
use App\Models\Import;
use App\Models\Reconciliation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Turns a previewed import into lines on people's reconciliations, all or nothing. */
final class ImportCommitter
{
    public function __construct(private ImportPreviewer $previewer) {}

    /**
     * @param  list<int>  $skip  row numbers to leave out
     * @param  list<int>  $includeDuplicates  duplicate rows to import anyway
     * @param  array<int, array{date?: ?string, amount?: ?string, description?: ?string}>  $fixes
     *
     * @throws ImportNotReady
     */
    public function commit(Import $import, User $admin, array $skip = [], array $includeDuplicates = [], array $fixes = []): CommitResult
    {
        return DB::transaction(function () use ($import, $admin, $skip, $includeDuplicates, $fixes) {
            $import = Import::query()->whereKey($import->id)->lockForUpdate()->firstOrFail();
            $period = $import->period;

            if ($import->status !== ImportStatus::Draft) {
                throw new ImportNotReady('This file has already been imported.');
            }

            if ($period->status === PeriodStatus::Closed) {
                throw new ImportNotReady('This period is closed.');
            }

            $preview = $this->previewer->preview($import, $fixes);
            $skip = array_flip($skip);
            $includeDuplicates = array_flip($includeDuplicates);

            $unresolved = array_values(array_filter($preview->errorRowNumbers(), fn (int $row) => ! isset($skip[$row])));
            if ($unresolved !== []) {
                throw ImportNotReady::unresolvedRows($unresolved);
            }

            /** @var array<int, Reconciliation> $reconciliations */
            $reconciliations = [];
            $added = [];
            $created = $unassigned = $skippedDuplicates = 0;

            foreach ($preview->rows as $row) {
                $rowNumber = $row->parsed->rowNumber;

                if (isset($skip[$rowNumber])) {
                    continue;
                }

                if ($row->duplicate && ! isset($includeDuplicates[$rowNumber])) {
                    $skippedDuplicates++;

                    continue;
                }

                $reconciliationId = null;
                if ($row->userId !== null) {
                    $reconciliations[$row->userId] ??= Reconciliation::firstOrCreate(['period_id' => $period->id, 'user_id' => $row->userId]);
                    $reconciliationId = $reconciliations[$row->userId]->id;
                    $added[$row->userId] = ($added[$row->userId] ?? 0) + 1;
                } else {
                    $unassigned++;
                }

                $import->transactions()->create([
                    'period_id' => $period->id,
                    'reconciliation_id' => $reconciliationId,
                    'row_number' => $rowNumber,
                    'transacted_on' => $row->parsed->date,
                    'description' => $row->parsed->description,
                    'amount' => $row->parsed->amount,
                    'card_ref' => $row->parsed->cardRef(),
                    'extra' => $row->parsed->extra ?: null,
                ]);
                $created++;
            }

            $import->update(['status' => ImportStatus::Confirmed, 'row_count' => $created]);

            foreach ($reconciliations as $userId => $reconciliation) {
                $reconciliation->linesAdded($added[$userId]);
            }

            $result = new CommitResult($created, $unassigned, $skippedDuplicates);

            activity('periods')
                ->causedBy($admin)
                ->performedOn($period)
                ->event('import_confirmed')
                ->withProperties(['import_id' => $import->id, 'file' => $import->original_filename, 'created' => $created, 'unassigned' => $unassigned, 'duplicates_skipped' => $skippedDuplicates])
                ->log($result->message());

            return $result;
        });
    }
}
```

- [ ] **Step 6: Implement the remover**

`app/Imports/ImportRemover.php`:
```php
<?php

namespace App\Imports;

use App\Enums\ImportStatus;
use App\Enums\PeriodStatus;
use App\Models\Import;
use App\Models\Reconciliation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class ImportRemover
{
    /** @throws ImportNotReady */
    public function remove(Import $import, User $admin): void
    {
        $period = $import->period;

        if ($import->status === ImportStatus::Confirmed && $period->status !== PeriodStatus::Draft) {
            throw new ImportNotReady('Cardholders may already be working on these lines. Files can only be removed before the period is published.');
        }

        DB::transaction(function () use ($import, $period, $admin) {
            $reconciliationIds = $import->transactions()->whereNotNull('reconciliation_id')->distinct()->pluck('reconciliation_id');

            $import->transactions()->delete();
            Reconciliation::query()->whereIn('id', $reconciliationIds)->whereDoesntHave('transactions')->delete();
            $import->delete();

            if ($import->status === ImportStatus::Confirmed) {
                activity('periods')
                    ->causedBy($admin)
                    ->performedOn($period)
                    ->event('import_removed')
                    ->withProperties(['file' => $import->original_filename])
                    ->log("Removed {$import->original_filename}");
            }
        });

        Storage::disk('local')->delete($import->stored_path);
    }
}
```

- [ ] **Step 7: Run the tests to see them pass**

Run: `php artisan test tests/Feature/Imports`
Expected: all PASS.

- [ ] **Step 8: Run the full suite and commit**

Run: `php artisan test` — all PASS.

```bash
git add app/Imports app/Events app/Models/Reconciliation.php tests/Feature/Imports
git commit -m "feat: confirm imports into reconciliations and remove imports"
```

---

### Task 8: Import wizard screens

**Files:**
- Create: `app/Http/Controllers/Admin/ImportController.php`, `ImportMappingController.php`, `ImportPreviewController.php`
- Create: `resources/js/pages/admin/imports/Create.vue`, `Mapping.vue`, `Preview.vue`
- Modify: `app/Models/Import.php` (`duplicateOf()`), `app/Models/ColumnMapping.php` (`matchFirstRows()`)
- Modify: `routes/admin.php`, `resources/js/pages/admin/periods/Show.vue`
- Test: `tests/Feature/Admin/ImportWizardTest.php`

**Interfaces:**
- Consumes: `ImportSheet`, `SpreadsheetReader`/`UnreadableSpreadsheet` (Task 4), `ColumnMap`, `HeaderSignature`, `RowParser::text` (Tasks 2–3), `ImportPreviewer` (Task 6), `ImportCommitter`, `ImportRemover`, `ImportNotReady` (Task 7).
- Produces:
  - Routes: `admin.imports.create` (GET /admin/periods/{period}/imports/create), `admin.imports.store` (POST /admin/periods/{period}/imports), `admin.imports.mapping.edit` (GET /admin/imports/{import}/mapping), `admin.imports.mapping.update` (PUT), `admin.imports.preview` (GET /admin/imports/{import}/preview), `admin.imports.confirm` (POST /admin/imports/{import}/confirm), `admin.imports.destroy` (DELETE /admin/imports/{import}).
  - `Import::duplicateOf(): ?Import` — an earlier confirmed import in the same period with the same file hash.
  - `ColumnMapping::matchFirstRows(array $rows, int $limit = 10): ?array` — `[ColumnMapping, headerRowNumber]` for the first of the file's first rows whose header signature was saved.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Admin/ImportWizardTest.php`:
```php
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
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `php artisan test tests/Feature/Admin/ImportWizardTest.php`
Expected: FAIL — `Route [admin.imports.create] not defined`.

- [ ] **Step 3: Add the model helpers**

In `app/Models/Import.php`, add:
```php
    /** An earlier confirmed upload of the very same file into this period, if any. */
    public function duplicateOf(): ?self
    {
        return self::query()
            ->where('period_id', $this->period_id)
            ->where('file_hash', $this->file_hash)
            ->whereKeyNot($this->id)
            ->where('status', ImportStatus::Confirmed)
            ->oldest('id')
            ->first();
    }
```

In `app/Models/ColumnMapping.php`, add `use App\Imports\Parsing\HeaderSignature;` and `use App\Imports\RowParser;`, then:
```php
    /**
     * Look for a saved layout among a file's first rows.
     *
     * @param  array<int, list<mixed>>  $rows  rows keyed by row number
     * @return array{0: self, 1: int}|null  the saved mapping and the row its headers are on
     */
    public static function matchFirstRows(array $rows, int $limit = 10): ?array
    {
        foreach (array_slice($rows, 0, $limit, true) as $rowNumber => $cells) {
            $saved = self::query()
                ->where('header_signature', HeaderSignature::for(array_map(RowParser::text(...), $cells)))
                ->first();

            if ($saved) {
                return [$saved, $rowNumber];
            }
        }

        return null;
    }
```

- [ ] **Step 4: Create the upload and remove controller**

`app/Http/Controllers/Admin/ImportController.php`:
```php
<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PeriodStatus;
use App\Http\Controllers\Controller;
use App\Imports\ImportNotReady;
use App\Imports\ImportRemover;
use App\Imports\ImportSheet;
use App\Imports\UnreadableSpreadsheet;
use App\Models\ColumnMapping;
use App\Models\Import;
use App\Models\Period;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ImportController extends Controller
{
    public function create(Period $period): Response
    {
        abort_if($period->status === PeriodStatus::Closed, 403);

        return Inertia::render('admin/imports/Create', [
            'period' => $period->only(['id', 'label']),
        ]);
    }

    public function store(Request $request, Period $period, ImportSheet $sheet): RedirectResponse
    {
        abort_if($period->status === PeriodStatus::Closed, 403);

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240'],
        ], [
            'file.mimes' => 'Upload a CSV or Excel file (.csv, .xlsx or .xls).',
            'file.max' => 'The file must be smaller than 10 MB.',
        ]);

        $file = $request->file('file');
        $import = $period->imports()->create([
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $file->store("imports/{$period->id}", 'local'),
            'file_hash' => hash_file('sha256', $file->getRealPath()),
            'uploaded_by' => $request->user()->id,
        ]);

        try {
            $rows = $sheet->rows($import);
        } catch (UnreadableSpreadsheet $e) {
            return $this->discard($import, $e->getMessage());
        }

        if ($rows === []) {
            return $this->discard($import, 'This file has no rows.');
        }

        if ($match = ColumnMapping::matchFirstRows($rows)) {
            [$saved, $headerRow] = $match;
            $import->update(['header_row' => $headerRow, 'column_mapping' => $saved->mapping]);

            return to_route('admin.imports.preview', $import)
                ->with('success', "Recognised the “{$saved->name}” layout. Check the rows below.");
        }

        $import->update(['header_row' => array_key_first($rows)]);

        return to_route('admin.imports.mapping.edit', $import);
    }

    public function destroy(Request $request, Import $import, ImportRemover $remover): RedirectResponse
    {
        try {
            $remover->remove($import, $request->user());
        } catch (ImportNotReady $e) {
            return back()->with('error', $e->getMessage());
        }

        return to_route('admin.periods.show', $import->period_id)->with('success', "Removed {$import->original_filename}.");
    }

    private function discard(Import $import, string $message): RedirectResponse
    {
        Storage::disk('local')->delete($import->stored_path);
        $import->delete();

        return back()->withErrors(['file' => $message]);
    }
}
```

- [ ] **Step 5: Create the mapping controller**

`app/Http/Controllers/Admin/ImportMappingController.php`:
```php
<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ImportStatus;
use App\Http\Controllers\Controller;
use App\Imports\ColumnMap;
use App\Imports\ImportSheet;
use App\Imports\Parsing\HeaderSignature;
use App\Imports\RowParser;
use App\Models\ColumnMapping;
use App\Models\Import;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ImportMappingController extends Controller
{
    public function edit(Request $request, Import $import, ImportSheet $sheet): Response
    {
        abort_unless($import->status === ImportStatus::Draft, 404);

        $rows = $sheet->rows($import);
        $headerRow = (int) $request->query('header_row', $import->header_row);
        if (! isset($rows[$headerRow])) {
            $headerRow = array_key_first($rows);
        }

        $headers = $sheet->headers($import, $headerRow);
        $labels = array_map(fn (string $text, int $index) => $text !== '' ? $text : 'Column '.($index + 1), $headers, array_keys($headers));
        $asText = fn (array $rows) => array_map(
            fn (array $cells, int $rowNumber) => ['row_number' => $rowNumber, 'cells' => array_map(RowParser::text(...), $cells)],
            $rows,
            array_keys($rows),
        );

        $mapping = $import->column_mapping !== null && $headerRow === $import->header_row
            ? $import->column_mapping
            : ColumnMap::guess($headers);

        $duplicate = $import->duplicateOf();

        return Inertia::render('admin/imports/Mapping', [
            'upload' => [
                'id' => $import->id,
                'original_filename' => $import->original_filename,
                'period' => $import->period->only(['id', 'label']),
            ],
            'header_row' => $headerRow,
            'first_rows' => $asText(array_slice($rows, 0, 10, true)),
            'header_labels' => $labels,
            'samples' => $asText(array_slice($sheet->dataRows($import, $headerRow), 0, 5, true)),
            'mapping' => array_merge(array_fill_keys(ColumnMap::FIELDS, null), $mapping),
            'duplicate_file' => $duplicate ? [
                'original_filename' => $duplicate->original_filename,
                'created_at' => $duplicate->created_at->toIso8601String(),
            ] : null,
        ]);
    }

    public function update(Request $request, Import $import, ImportSheet $sheet): RedirectResponse
    {
        abort_unless($import->status === ImportStatus::Draft, 404);

        $rows = $sheet->rows($import);
        $data = $request->validate([
            'header_row' => ['required', 'integer', Rule::in(array_keys($rows))],
            'save_as' => ['nullable', 'string', 'max:100'],
            'mapping' => ['required', 'array'],
        ]);

        $headers = $sheet->headers($import, (int) $data['header_row']);
        $columns = array_keys($headers);

        $mapping = $request->validate([
            'mapping.date' => ['required', 'integer', Rule::in($columns)],
            'mapping.description' => ['required', 'integer', Rule::in($columns)],
            'mapping.amount' => ['required', 'integer', Rule::in($columns)],
            'mapping.card' => ['nullable', 'integer', Rule::in($columns)],
            'mapping.cardholder' => ['nullable', 'integer', Rule::in($columns)],
            'mapping.purpose' => ['nullable', 'integer', Rule::in($columns)],
        ], [
            'mapping.*.required' => 'Choose which column holds this.',
            'mapping.*.in' => 'Choose a column from this file.',
        ])['mapping'];

        $used = array_filter(Arr::only($mapping, ColumnMap::FIELDS), fn ($column) => $column !== null);
        if (count($used) !== count(array_unique($used))) {
            throw ValidationException::withMessages(['mapping' => 'Each column can only be used for one field.']);
        }

        $map = ColumnMap::fromArray($mapping)->toArray();
        $import->update(['header_row' => (int) $data['header_row'], 'column_mapping' => $map]);

        if (filled($data['save_as'] ?? null)) {
            ColumnMapping::updateOrCreate(
                ['header_signature' => HeaderSignature::for($headers)],
                ['name' => $data['save_as'], 'header_row' => (int) $data['header_row'], 'mapping' => $map],
            );
        }

        return to_route('admin.imports.preview', $import);
    }
}
```

- [ ] **Step 6: Create the preview controller and routes**

`app/Http/Controllers/Admin/ImportPreviewController.php`:
```php
<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ImportStatus;
use App\Http\Controllers\Controller;
use App\Imports\ImportCommitter;
use App\Imports\ImportNotReady;
use App\Imports\ImportPreviewer;
use App\Imports\PreviewRow;
use App\Models\Import;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ImportPreviewController extends Controller
{
    public function show(Import $import, ImportPreviewer $previewer): Response|RedirectResponse
    {
        abort_unless($import->status === ImportStatus::Draft, 404);

        if ($import->column_mapping === null) {
            return to_route('admin.imports.mapping.edit', $import);
        }

        $preview = $previewer->preview($import);
        $names = User::query()
            ->whereIn('id', array_filter(array_map(fn (PreviewRow $row) => $row->userId, $preview->rows)))
            ->pluck('name', 'id')
            ->all();
        $duplicate = $import->duplicateOf();

        return Inertia::render('admin/imports/Preview', [
            'upload' => [
                'id' => $import->id,
                'original_filename' => $import->original_filename,
                'period' => [...$import->period->only(['id', 'label']), 'status' => $import->period->status->value],
            ],
            'rows' => array_map(fn (PreviewRow $row) => $row->toArray($names), $preview->rows),
            'summary' => $preview->summary($names),
            'duplicate_file' => $duplicate ? [
                'original_filename' => $duplicate->original_filename,
                'created_at' => $duplicate->created_at->toIso8601String(),
            ] : null,
        ]);
    }

    public function store(Request $request, Import $import, ImportCommitter $committer): RedirectResponse
    {
        $data = $request->validate([
            'skip' => ['array'],
            'skip.*' => ['integer'],
            'include_duplicates' => ['array'],
            'include_duplicates.*' => ['integer'],
            'fixes' => ['array'],
            'fixes.*.date' => ['nullable', 'string', 'max:50'],
            'fixes.*.amount' => ['nullable', 'string', 'max:50'],
            'fixes.*.description' => ['nullable', 'string', 'max:500'],
        ]);

        $fixes = collect($data['fixes'] ?? [])
            ->mapWithKeys(fn (array $fix, $rowNumber) => [(int) $rowNumber => array_filter($fix, fn ($value) => $value !== null)])
            ->all();

        try {
            $result = $committer->commit(
                $import,
                $request->user(),
                array_map('intval', $data['skip'] ?? []),
                array_map('intval', $data['include_duplicates'] ?? []),
                $fixes,
            );
        } catch (ImportNotReady $e) {
            throw ValidationException::withMessages(['rows' => $e->getMessage()]);
        }

        return to_route('admin.periods.show', $import->period_id)->with('success', $result->message());
    }
}
```

In `routes/admin.php`, add the three controller imports and inside the group:
```php
    Route::get('periods/{period}/imports/create', [ImportController::class, 'create'])->name('imports.create');
    Route::post('periods/{period}/imports', [ImportController::class, 'store'])->name('imports.store');
    Route::delete('imports/{import}', [ImportController::class, 'destroy'])->name('imports.destroy');
    Route::get('imports/{import}/mapping', [ImportMappingController::class, 'edit'])->name('imports.mapping.edit');
    Route::put('imports/{import}/mapping', [ImportMappingController::class, 'update'])->name('imports.mapping.update');
    Route::get('imports/{import}/preview', [ImportPreviewController::class, 'show'])->name('imports.preview');
    Route::post('imports/{import}/confirm', [ImportPreviewController::class, 'store'])->name('imports.confirm');
```

- [ ] **Step 7: Run the backend tests**

Run: `php artisan test tests/Feature/Admin/ImportWizardTest.php --filter='admins|stores|straight|rejects|closed|saves the mapping|validates|sends an import|confirms|explains|confirmed import|removes'`
Expected: PASS. The page-rendering tests ("shows the upload page", "shows the mapping step…", "lets the admin pick…", "shows the preview…") need Steps 8–10.

- [ ] **Step 8: Create the upload page**

`resources/js/pages/admin/imports/Create.vue`:
```vue
<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps<{ period: { id: number; label: string } }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Periods', href: '/admin/periods' },
    { title: props.period.label, href: `/admin/periods/${props.period.id}` },
    { title: 'Upload statement', href: '#' },
];

const form = useForm<{ file: File | null }>({ file: null });

const pick = (event: Event) => {
    form.file = (event.target as HTMLInputElement).files?.[0] ?? null;
};

const submit = () => {
    form.post(route('admin.imports.store', props.period.id), { forceFormData: true });
};
</script>

<template>
    <Head title="Upload statement" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <form @submit.prevent="submit" class="flex max-w-xl flex-col gap-6 p-4">
            <div>
                <h1 class="text-xl font-semibold">Upload the statement for {{ period.label }}</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Use the card company's export (CSV or Excel). The first time, you'll match up the columns; after that CardTrail remembers the layout.
                </p>
            </div>

            <div class="grid gap-2">
                <Label for="file">Statement file</Label>
                <input
                    id="file"
                    type="file"
                    accept=".csv,.xlsx,.xls"
                    required
                    class="block w-full text-sm file:mr-4 file:rounded-md file:border-0 file:bg-primary file:px-4 file:py-2 file:text-sm file:font-medium file:text-primary-foreground"
                    @change="pick"
                />
                <InputError :message="form.errors.file" />
            </div>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing || !form.file">Upload</Button>
                <Button variant="ghost" as-child>
                    <Link :href="route('admin.periods.show', period.id)">Cancel</Link>
                </Button>
            </div>
        </form>
    </AppLayout>
</template>
```

- [ ] **Step 9: Create the mapping page**

`resources/js/pages/admin/imports/Mapping.vue`:
```vue
<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

type Field = 'date' | 'description' | 'amount' | 'card' | 'cardholder' | 'purpose';

interface SheetRow {
    row_number: number;
    cells: string[];
}

const props = defineProps<{
    upload: { id: number; original_filename: string; period: { id: number; label: string } };
    header_row: number;
    first_rows: SheetRow[];
    header_labels: string[];
    samples: SheetRow[];
    mapping: Record<Field, number | null>;
    duplicate_file: { original_filename: string; created_at: string } | null;
}>();

const fields: { key: Field; label: string; help: string; required: boolean }[] = [
    { key: 'date', label: 'Transaction date', help: 'When the purchase was made.', required: true },
    { key: 'description', label: 'Description', help: 'The merchant or transaction details.', required: true },
    { key: 'amount', label: 'Amount', help: 'Charges positive; credits negative or marked CR.', required: true },
    { key: 'card', label: 'Card number or last 4', help: 'Matches rows to cardholders. Only the last 4 digits are kept.', required: false },
    { key: 'cardholder', label: 'Cardholder name', help: 'Used for matching when there is no card number.', required: false },
    { key: 'purpose', label: '“What was it for” column', help: 'Where purposes go in the exported spreadsheet. Leave empty to add a new column.', required: false },
];

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Periods', href: '/admin/periods' },
    { title: props.upload.period.label, href: `/admin/periods/${props.upload.period.id}` },
    { title: 'Match columns', href: '#' },
];

const form = useForm({
    header_row: props.header_row,
    mapping: { ...props.mapping },
    remember: true,
    save_as: 'Card statement',
});

const errors = computed(() => form.errors as Record<string, string | undefined>);

const mappedField = (column: number) => fields.find((field) => form.mapping[field.key] === column);

const setField = (key: Field, event: Event) => {
    const value = (event.target as HTMLSelectElement).value;
    form.mapping[key] = value === '' ? null : Number(value);
};

const changeHeaderRow = (event: Event) => {
    router.get(route('admin.imports.mapping.edit', props.upload.id), { header_row: (event.target as HTMLSelectElement).value });
};

const submit = () => {
    form.transform((data) => ({
        header_row: data.header_row,
        mapping: data.mapping,
        save_as: data.remember ? data.save_as : null,
    })).put(route('admin.imports.mapping.update', props.upload.id));
};

const selectClass = 'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring';
</script>

<template>
    <Head title="Match columns" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <form @submit.prevent="submit" class="flex flex-col gap-6 p-4">
            <div>
                <h1 class="text-xl font-semibold">Match the columns in {{ upload.original_filename }}</h1>
                <p class="mt-1 text-sm text-muted-foreground">Tell CardTrail which column holds what. You only need to do this once per layout.</p>
            </div>

            <div v-if="duplicate_file" class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
                This file looks identical to {{ duplicate_file.original_filename }}, imported on {{ formatDate(duplicate_file.created_at) }}. Repeated rows will be flagged on the next step.
            </div>

            <div class="grid max-w-md gap-2">
                <Label for="header_row">Which row has the column headings?</Label>
                <select id="header_row" :class="selectClass" :value="header_row" @change="changeHeaderRow">
                    <option v-for="row in first_rows" :key="row.row_number" :value="row.row_number">
                        Row {{ row.row_number }}: {{ row.cells.filter(Boolean).slice(0, 4).join(', ') }}
                    </option>
                </select>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div v-for="field in fields" :key="field.key" class="grid gap-1">
                    <Label :for="`field-${field.key}`">
                        {{ field.label }}<span v-if="field.required" class="text-red-600"> *</span>
                    </Label>
                    <select :id="`field-${field.key}`" :class="selectClass" :value="form.mapping[field.key] ?? ''" @change="setField(field.key, $event)">
                        <option value="">{{ field.required ? 'Choose a column…' : '— Not in this file —' }}</option>
                        <option v-for="(label, index) in header_labels" :key="index" :value="index">{{ label }}</option>
                    </select>
                    <p class="text-xs text-muted-foreground">{{ field.help }}</p>
                    <InputError :message="errors[`mapping.${field.key}`]" />
                </div>
            </div>
            <InputError :message="errors.mapping" />

            <section class="flex flex-col gap-2">
                <h2 class="text-sm font-medium">First rows of the file</h2>
                <div class="overflow-x-auto rounded-lg border">
                    <table class="w-full text-left text-xs">
                        <thead class="border-b bg-muted/50">
                            <tr>
                                <th class="px-3 py-2 font-medium">Row</th>
                                <th v-for="(label, index) in header_labels" :key="index" class="whitespace-nowrap px-3 py-2 font-medium">
                                    {{ label }}
                                    <div v-if="mappedField(index)" class="font-normal text-primary">→ {{ mappedField(index)?.label }}</div>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in samples" :key="row.row_number" class="border-b last:border-0">
                                <td class="px-3 py-2 text-muted-foreground">{{ row.row_number }}</td>
                                <td v-for="(label, index) in header_labels" :key="index" class="whitespace-nowrap px-3 py-2">{{ row.cells[index] }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="grid max-w-md gap-2">
                <Label for="remember" class="flex items-center gap-3">
                    <Checkbox id="remember" v-model:checked="form.remember" />
                    <span>Remember this layout for future uploads</span>
                </Label>
                <Input v-if="form.remember" v-model="form.save_as" placeholder="Layout name, e.g. Amex export" />
                <InputError :message="errors.save_as" />
            </div>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing">Continue to preview</Button>
                <Button variant="ghost" as-child>
                    <Link :href="route('admin.periods.show', upload.period.id)">Cancel</Link>
                </Button>
            </div>
        </form>
    </AppLayout>
</template>
```

- [ ] **Step 10: Create the preview page**

`resources/js/pages/admin/imports/Preview.vue`:
```vue
<script setup lang="ts">
import FlashMessage from '@/components/FlashMessage.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatPence } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

type FixField = 'date' | 'amount' | 'description';

interface PreviewRow {
    row_number: number;
    date: string | null;
    raw_date: string;
    description: string;
    amount: number | null;
    raw_amount: string;
    card_ref: string | null;
    user_id: number | null;
    user_name: string | null;
    duplicate: boolean;
    errors: Partial<Record<FixField, string>>;
    extra: Record<string, string>;
}

const props = defineProps<{
    upload: { id: number; original_filename: string; period: { id: number; label: string; status: string } };
    rows: PreviewRow[];
    summary: {
        total: number;
        errors: number;
        duplicates: number;
        unassigned: number;
        by_user: { user_id: number; name: string; count: number }[];
    };
    duplicate_file: { original_filename: string; created_at: string } | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Periods', href: '/admin/periods' },
    { title: props.upload.period.label, href: `/admin/periods/${props.upload.period.id}` },
    { title: 'Check and confirm', href: '#' },
];

type Filter = 'all' | 'problems' | 'duplicates' | 'unassigned';
const filter = ref<Filter>(props.summary.errors > 0 ? 'problems' : 'all');

const skip = reactive(new Set<number>());
const include = reactive(new Set<number>());
const fixes = reactive<Record<number, Partial<Record<FixField, string>>>>({});

const hasErrors = (row: PreviewRow) => Object.keys(row.errors).length > 0;

/** A problem row is resolved when it is skipped, or every field it complained about has a correction typed in. */
const resolved = (row: PreviewRow) =>
    skip.has(row.row_number) ||
    (Object.keys(row.errors) as FixField[]).every((field) => (fixes[row.row_number]?.[field] ?? '').trim() !== '');

const unresolved = computed(() => props.rows.filter((row) => hasErrors(row) && !resolved(row)).length);

const visibleRows = computed(() =>
    props.rows.filter((row) => {
        if (filter.value === 'problems') return hasErrors(row);
        if (filter.value === 'duplicates') return row.duplicate;
        if (filter.value === 'unassigned') return !hasErrors(row) && !row.duplicate && row.user_id === null;
        return true;
    }),
);

const fixValue = (row: PreviewRow, field: FixField) => fixes[row.row_number]?.[field] ?? '';

const setFix = (row: PreviewRow, field: FixField, value: string | number) => {
    fixes[row.row_number] = { ...(fixes[row.row_number] ?? {}), [field]: String(value) };
};

const toggle = (set: Set<number>, rowNumber: number, on: boolean) => (on ? set.add(rowNumber) : set.delete(rowNumber));

const form = useForm({});
const formErrors = computed(() => form.errors as Record<string, string | undefined>);

const confirm = () => {
    form.transform(() => ({
        skip: [...skip],
        include_duplicates: [...include],
        fixes,
    })).post(route('admin.imports.confirm', props.upload.id), { preserveScroll: true });
};

const tabs: { key: Filter; label: string; count: number }[] = [
    { key: 'all', label: 'All rows', count: props.summary.total },
    { key: 'problems', label: 'Problems', count: props.summary.errors },
    { key: 'duplicates', label: 'Duplicates', count: props.summary.duplicates },
    { key: 'unassigned', label: 'Unassigned', count: props.summary.unassigned },
];
</script>

<template>
    <Head title="Check and confirm" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-4">
            <FlashMessage />

            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 class="text-xl font-semibold">Check {{ upload.original_filename }}</h1>
                    <p class="text-sm text-muted-foreground">Nothing is saved until you confirm.</p>
                </div>
                <Button variant="outline" as-child>
                    <Link :href="route('admin.imports.mapping.edit', upload.id)">Change column matching</Link>
                </Button>
            </div>

            <div v-if="duplicate_file" class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
                This file looks identical to {{ duplicate_file.original_filename }}, imported on {{ formatDate(duplicate_file.created_at) }}.
            </div>

            <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-lg border p-3">
                    <div class="text-2xl font-semibold">{{ summary.total }}</div>
                    <div class="text-xs text-muted-foreground">rows in the file</div>
                </div>
                <div class="rounded-lg border p-3">
                    <div class="text-2xl font-semibold" :class="{ 'text-red-600': summary.errors }">{{ summary.errors }}</div>
                    <div class="text-xs text-muted-foreground">rows with problems</div>
                </div>
                <div class="rounded-lg border p-3">
                    <div class="text-2xl font-semibold">{{ summary.duplicates }}</div>
                    <div class="text-xs text-muted-foreground">possible duplicates (skipped unless ticked)</div>
                </div>
                <div class="rounded-lg border p-3">
                    <div class="text-2xl font-semibold">{{ summary.unassigned }}</div>
                    <div class="text-xs text-muted-foreground">not matched to anyone</div>
                </div>
            </section>

            <p v-if="summary.by_user.length" class="text-sm text-muted-foreground">
                Matched: <span v-for="(entry, i) in summary.by_user" :key="entry.user_id">{{ entry.name }} ({{ entry.count }})<span v-if="i < summary.by_user.length - 1">, </span></span>
            </p>

            <div class="flex flex-wrap gap-2">
                <Button v-for="tab in tabs" :key="tab.key" size="sm" :variant="filter === tab.key ? 'default' : 'outline'" @click="filter = tab.key">
                    {{ tab.label }} ({{ tab.count }})
                </Button>
            </div>

            <ul class="divide-y rounded-lg border">
                <li v-for="row in visibleRows" :key="row.row_number" class="flex flex-col gap-2 p-4 text-sm" :class="{ 'opacity-50': skip.has(row.row_number) }">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="font-medium">
                                <span class="text-muted-foreground">Row {{ row.row_number }} ·</span> {{ row.description || row.raw_date || '(blank)' }}
                            </div>
                            <div class="text-muted-foreground">
                                {{ row.date ? formatDate(row.date) : row.raw_date }} · {{ row.amount !== null ? formatPence(row.amount) : row.raw_amount }}
                                <span v-if="row.card_ref"> · {{ row.card_ref }}</span>
                            </div>
                        </div>
                        <div class="text-right">
                            <span v-if="hasErrors(row)" class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800 dark:bg-red-950 dark:text-red-200">Problem</span>
                            <span v-else-if="row.duplicate" class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-900 dark:bg-amber-950 dark:text-amber-200">Duplicate</span>
                            <span v-else-if="row.user_name">{{ row.user_name }}</span>
                            <span v-else class="text-muted-foreground">Unassigned</span>
                        </div>
                    </div>

                    <div v-if="hasErrors(row)" class="grid gap-2 sm:grid-cols-3">
                        <div v-for="(message, field) in row.errors" :key="field" class="grid gap-1">
                            <label class="text-xs text-red-700 dark:text-red-300" :for="`fix-${row.row_number}-${field}`">{{ message }}</label>
                            <Input
                                :id="`fix-${row.row_number}-${field}`"
                                :model-value="fixValue(row, field as FixField)"
                                :placeholder="field === 'date' ? 'e.g. 03/09/2026' : field === 'amount' ? 'e.g. 12.50' : 'Description'"
                                :disabled="skip.has(row.row_number)"
                                @update:model-value="setFix(row, field as FixField, $event)"
                            />
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-4">
                        <label v-if="hasErrors(row)" class="flex items-center gap-2 text-xs">
                            <Checkbox :checked="skip.has(row.row_number)" @update:checked="toggle(skip, row.row_number, $event)" />
                            Skip this row
                        </label>
                        <label v-if="row.duplicate && !hasErrors(row)" class="flex items-center gap-2 text-xs">
                            <Checkbox :checked="include.has(row.row_number)" @update:checked="toggle(include, row.row_number, $event)" />
                            Import anyway (it's a genuine repeat)
                        </label>
                    </div>

                    <dl v-if="Object.keys(row.extra).length" class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                        <div v-for="(value, label) in row.extra" :key="label">
                            <dt class="inline">{{ label }}:</dt>
                            <dd class="inline"> {{ value }}</dd>
                        </div>
                    </dl>
                </li>
                <li v-if="!visibleRows.length" class="p-4 text-sm text-muted-foreground">Nothing to show here.</li>
            </ul>

            <div class="sticky bottom-0 flex flex-col gap-2 border-t bg-background py-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="text-sm">
                    <InputError :message="formErrors.rows" />
                    <span v-if="!formErrors.rows && unresolved > 0" class="text-red-600">
                        {{ unresolved }} {{ unresolved === 1 ? 'row needs' : 'rows need' }} a correction or skipping.
                    </span>
                </div>
                <Button :disabled="form.processing || unresolved > 0" @click="confirm">Confirm import</Button>
            </div>
        </div>
    </AppLayout>
</template>
```

- [ ] **Step 11: Link the wizard from the period dashboard**

In `resources/js/pages/admin/periods/Show.vue`:
- Change the `@inertiajs/vue3` import to `import { Head, Link, router } from '@inertiajs/vue3';`
- Add to the script:
```ts
const removeImport = (upload: DashboardImport) => {
    if (window.confirm(`Remove ${upload.original_filename}? Any lines it created will be deleted.`)) {
        router.delete(route('admin.imports.destroy', upload.id), { preserveScroll: true });
    }
};
```
- Inside `<div class="flex flex-wrap gap-2" data-actions>`, before the Edit button, add:
```vue
                    <Button v-if="period.status !== 'closed'" as-child>
                        <Link :href="route('admin.imports.create', period.id)">Upload statement</Link>
                    </Button>
```
- In the uploaded-files list item, after the closing `</div>` of the filename block, add:
```vue
                        <div class="flex shrink-0 gap-2">
                            <Button v-if="upload.status === 'draft'" size="sm" variant="outline" as-child>
                                <Link :href="route('admin.imports.preview', upload.id)">Continue</Link>
                            </Button>
                            <Button v-if="upload.status === 'draft' || period.status === 'draft'" size="sm" variant="ghost" @click="removeImport(upload)">
                                Remove
                            </Button>
                        </div>
```

- [ ] **Step 12: Run all tests and the frontend checks**

Run: `php artisan test && npm run build && npx vue-tsc --noEmit 2>&1 | grep -E 'error TS' | grep -v TS2688`
Expected: all PASS; build succeeds; the final grep prints nothing.

- [ ] **Step 13: Commit**

```bash
git add -A
git commit -m "feat: statement upload, column matching and import preview screens"
```

---

### Task 9: Unassigned lines and reassignment

**Files:**
- Create: `app/Periods/TransactionAssigner.php`, `app/Periods/CannotReassign.php`, `app/Http/Controllers/Admin/PeriodLinesController.php`, `resources/js/pages/admin/periods/Lines.vue`
- Modify: `routes/admin.php`, `resources/js/pages/admin/periods/Show.vue`
- Test: `tests/Feature/Admin/PeriodLinesTest.php`

**Interfaces:**
- Consumes: models/enums (Task 1), `Reconciliation::linesAdded()` (Task 7), `PeriodDashboard` data (Task 5).
- Produces:
  - `TransactionAssigner::assign(Period $period, array $transactionIds, ?User $to, User $admin): int` (number moved). Throws `CannotReassign` (message safe to show) for a closed period, an approved line, a line from another period, or a target who isn't an active cardholder. Moving a line clears its purpose, no-receipt flag/reason and resets it to `todo`. (Plan 3 must also move/detach receipts here.)
  - Routes `admin.periods.lines` (GET /admin/periods/{period}/lines?owner=unassigned|all|{userId}), `admin.periods.lines.assign` (PUT /admin/periods/{period}/lines/assignment).
  - Activity log `periods` event `lines_reassigned`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Admin/PeriodLinesTest.php`:
```php
<?php

use App\Enums\ReconciliationStatus;
use App\Enums\TransactionStatus;
use App\Events\ReconciliationLinesAdded;
use App\Models\Import;
use App\Models\Period;
use App\Models\Reconciliation;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['is_cardholder' => false]);
    $this->period = Period::factory()->create();
    $this->import = Import::factory()->for($this->period)->confirmed()->create();
    $this->sam = User::factory()->create(['name' => 'Sam']);
    $this->jo = User::factory()->create(['name' => 'Jo']);
});

function line(array $attributes = []): Transaction
{
    return Transaction::factory()->for(test()->import)->create($attributes);
}

function assignLines(array $ids, ?int $userId)
{
    return test()->actingAs(test()->admin)->put(route('admin.periods.lines.assign', test()->period), [
        'transaction_ids' => $ids,
        'user_id' => $userId,
    ]);
}

it('lists unassigned lines by default with cardholders to choose from', function () {
    $loose = line(['description' => 'LOOSE']);
    $samRec = Reconciliation::factory()->for($this->period)->for($this->sam)->create();
    line(['reconciliation_id' => $samRec->id]);
    User::factory()->inactive()->create(['name' => 'Gone']);

    $this->actingAs($this->admin)
        ->get(route('admin.periods.lines', $this->period))
        ->assertInertia(fn ($page) => $page
            ->component('admin/periods/Lines')
            ->where('owner', 'unassigned')
            ->has('lines', 1)
            ->where('lines.0.id', $loose->id)
            ->where('unassigned_count', 1)
            ->where('cardholders', fn ($list) => collect($list)->pluck('name')->all() === ['Jo', 'Sam']));
});

it('filters lines by owner', function () {
    $samRec = Reconciliation::factory()->for($this->period)->for($this->sam)->create();
    $samLine = line(['reconciliation_id' => $samRec->id]);
    line();

    $this->actingAs($this->admin)
        ->get(route('admin.periods.lines', [$this->period, 'owner' => $this->sam->id]))
        ->assertInertia(fn ($page) => $page->has('lines', 1)->where('lines.0.id', $samLine->id)->where('lines.0.owner.name', 'Sam'));

    $this->actingAs($this->admin)
        ->get(route('admin.periods.lines', [$this->period, 'owner' => 'all']))
        ->assertInertia(fn ($page) => $page->has('lines', 2));
});

it('assigns unassigned lines to a cardholder, creating their reconciliation', function () {
    $a = line();
    $b = line();

    assignLines([$a->id, $b->id], $this->sam->id)
        ->assertRedirect()
        ->assertSessionHas('success', 'Moved 2 lines to Sam.');

    $reconciliation = Reconciliation::where('user_id', $this->sam->id)->sole();
    expect($a->fresh()->reconciliation_id)->toBe($reconciliation->id)
        ->and($b->fresh()->reconciliation_id)->toBe($reconciliation->id);
});

it('moves a line between people, resets it, and removes the emptied reconciliation', function () {
    $samRec = Reconciliation::factory()->for($this->period)->for($this->sam)->create();
    $moved = line(['reconciliation_id' => $samRec->id, 'purpose' => 'Hotel', 'status' => TransactionStatus::Done, 'no_receipt' => true, 'no_receipt_reason' => 'Lost']);

    assignLines([$moved->id], $this->jo->id);

    $moved->refresh();
    expect($moved->reconciliation->user_id)->toBe($this->jo->id)
        ->and($moved->purpose)->toBeNull()
        ->and($moved->no_receipt)->toBeFalse()
        ->and($moved->no_receipt_reason)->toBeNull()
        ->and($moved->status)->toBe(TransactionStatus::Todo)
        ->and(Reconciliation::find($samRec->id))->toBeNull();
});

it('can move lines back to unassigned', function () {
    $samRec = Reconciliation::factory()->for($this->period)->for($this->sam)->create();
    $moved = line(['reconciliation_id' => $samRec->id]);

    assignLines([$moved->id], null)->assertSessionHas('success', 'Moved 1 line to Unassigned.');

    expect($moved->fresh()->reconciliation_id)->toBeNull();
});

it('reopens the receiving reconciliation and announces it in a published period', function () {
    Event::fake([ReconciliationLinesAdded::class]);
    $this->period->update(['status' => 'published']);
    $joRec = Reconciliation::factory()->for($this->period)->for($this->jo)->submitted()->create();

    assignLines([line()->id], $this->jo->id);

    expect($joRec->fresh()->status)->toBe(ReconciliationStatus::InProgress);
    Event::assertDispatched(ReconciliationLinesAdded::class, fn ($event) => $event->reconciliation->is($joRec) && $event->count === 1);
});

it('refuses to move approved lines', function () {
    $samRec = Reconciliation::factory()->for($this->period)->for($this->sam)->create();
    $approved = line(['reconciliation_id' => $samRec->id, 'status' => TransactionStatus::Approved]);

    assignLines([$approved->id], $this->jo->id)->assertSessionHas('error');

    expect($approved->fresh()->reconciliation_id)->toBe($samRec->id);
});

it('refuses targets who are inactive or not cardholders', function (string $who) {
    $target = match ($who) {
        'inactive' => User::factory()->inactive()->create(),
        'admin only' => User::factory()->admin()->create(['is_cardholder' => false]),
    };

    assignLines([line()->id], $target->id)->assertSessionHas('error');
})->with(['inactive', 'admin only']);

it('refuses lines from another period', function () {
    $other = Transaction::factory()->create();

    assignLines([$other->id], $this->sam->id)->assertSessionHas('error');

    expect($other->fresh()->reconciliation_id)->toBeNull();
});

it('refuses changes to a closed period', function () {
    $loose = line();
    $this->period->update(['status' => 'closed']);

    assignLines([$loose->id], $this->sam->id)->assertSessionHas('error');
});

it('logs reassignments', function () {
    assignLines([line()->id], $this->sam->id);

    expect(Activity::where('log_name', 'periods')->where('event', 'lines_reassigned')->sole()->causer_id)->toBe($this->admin->id);
});
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `php artisan test tests/Feature/Admin/PeriodLinesTest.php`
Expected: FAIL — `Route [admin.periods.lines] not defined`.

- [ ] **Step 3: Implement the assigner**

`app/Periods/CannotReassign.php`:
```php
<?php

namespace App\Periods;

use RuntimeException;

/** The message is safe to show the admin. */
class CannotReassign extends RuntimeException {}
```

`app/Periods/TransactionAssigner.php`:
```php
<?php

namespace App\Periods;

use App\Enums\PeriodStatus;
use App\Enums\TransactionStatus;
use App\Models\Period;
use App\Models\Reconciliation;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Moves lines to a cardholder (or back to Unassigned). Work done on a moved line belongs to its old owner, so it starts fresh. */
final class TransactionAssigner
{
    /**
     * @param  list<int>  $transactionIds
     *
     * @throws CannotReassign
     */
    public function assign(Period $period, array $transactionIds, ?User $to, User $admin): int
    {
        if ($period->status === PeriodStatus::Closed) {
            throw new CannotReassign('This period is closed.');
        }

        if ($to && (! $to->active || ! $to->is_cardholder)) {
            throw new CannotReassign("{$to->name} isn't an active cardholder.");
        }

        return DB::transaction(function () use ($period, $transactionIds, $to, $admin) {
            $lines = Transaction::query()->whereIn('id', $transactionIds)->lockForUpdate()->get();

            if ($lines->count() !== count(array_unique($transactionIds)) || $lines->contains(fn (Transaction $line) => $line->period_id !== $period->id)) {
                throw new CannotReassign('Some of those lines are not in this period.');
            }

            if ($lines->contains(fn (Transaction $line) => $line->status === TransactionStatus::Approved)) {
                throw new CannotReassign('Approved lines can’t be moved.');
            }

            $target = $to ? Reconciliation::firstOrCreate(['period_id' => $period->id, 'user_id' => $to->id]) : null;
            $moving = $lines->reject(fn (Transaction $line) => $line->reconciliation_id === $target?->id);
            $sources = $moving->pluck('reconciliation_id')->filter()->unique();

            foreach ($moving as $line) {
                $line->update([
                    'reconciliation_id' => $target?->id,
                    'purpose' => null,
                    'no_receipt' => false,
                    'no_receipt_reason' => null,
                    'status' => TransactionStatus::Todo,
                ]);
            }

            Reconciliation::query()->whereIn('id', $sources)->whereDoesntHave('transactions')->delete();

            if ($target && $moving->isNotEmpty()) {
                $target->linesAdded($moving->count());
            }

            activity('periods')
                ->causedBy($admin)
                ->performedOn($period)
                ->event('lines_reassigned')
                ->withProperties(['transaction_ids' => $moving->pluck('id')->all(), 'to_user_id' => $to?->id])
                ->log('Moved '.$moving->count().' lines to '.($to?->name ?? 'Unassigned'));

            return $moving->count();
        });
    }
}
```

- [ ] **Step 4: Create the controller and routes**

`app/Http/Controllers/Admin/PeriodLinesController.php`:
```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Period;
use App\Models\Reconciliation;
use App\Models\Transaction;
use App\Models\User;
use App\Periods\CannotReassign;
use App\Periods\TransactionAssigner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PeriodLinesController extends Controller
{
    public function index(Request $request, Period $period): Response
    {
        $unassignedCount = $period->unassignedTransactions()->count();
        $owner = (string) $request->query('owner', $unassignedCount > 0 ? 'unassigned' : 'all');

        $lines = $period->transactions()
            ->with('reconciliation.user:id,name')
            ->when($owner === 'unassigned', fn ($query) => $query->whereNull('reconciliation_id'))
            ->when(ctype_digit($owner), fn ($query) => $query->whereHas('reconciliation', fn ($query) => $query->where('user_id', (int) $owner)))
            ->orderBy('import_id')
            ->orderBy('row_number')
            ->get()
            ->map(fn (Transaction $line) => [
                'id' => $line->id,
                'row_number' => $line->row_number,
                'date' => $line->transacted_on->format('Y-m-d'),
                'description' => $line->description,
                'amount' => $line->amount,
                'card_ref' => $line->card_ref,
                'status' => $line->status->value,
                'owner' => $line->reconciliation?->user?->only(['id', 'name']),
            ]);

        return Inertia::render('admin/periods/Lines', [
            'period' => [...$period->only(['id', 'label']), 'status' => $period->status->value],
            'owner' => $owner,
            'lines' => $lines,
            'unassigned_count' => $unassignedCount,
            'owners' => $period->reconciliations()->with('user:id,name')->withCount('transactions')->get()
                ->map(fn (Reconciliation $reconciliation) => ['id' => $reconciliation->user->id, 'name' => $reconciliation->user->name, 'lines' => $reconciliation->transactions_count])
                ->sortBy(fn (array $entry) => mb_strtolower($entry['name']))
                ->values(),
            'cardholders' => User::query()->where('active', true)->where('is_cardholder', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, Period $period, TransactionAssigner $assigner): RedirectResponse
    {
        $data = $request->validate([
            'transaction_ids' => ['required', 'array', 'min:1'],
            'transaction_ids.*' => ['integer'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $to = isset($data['user_id']) ? User::find($data['user_id']) : null;

        try {
            $moved = $assigner->assign($period, array_map('intval', $data['transaction_ids']), $to, $request->user());
        } catch (CannotReassign $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Moved {$moved} ".Str::plural('line', $moved).' to '.($to?->name ?? 'Unassigned').'.');
    }
}
```

In `routes/admin.php`, add `use App\Http\Controllers\Admin\PeriodLinesController;` and inside the group:
```php
    Route::get('periods/{period}/lines', [PeriodLinesController::class, 'index'])->name('periods.lines');
    Route::put('periods/{period}/lines/assignment', [PeriodLinesController::class, 'update'])->name('periods.lines.assign');
```

- [ ] **Step 5: Run the backend tests**

Run: `php artisan test tests/Feature/Admin/PeriodLinesTest.php --filter='assigns|moves|back to|reopens|refuses|logs'`
Expected: PASS. The two listing tests need the page from Step 6.

- [ ] **Step 6: Create the lines page**

`resources/js/pages/admin/periods/Lines.vue`:
```vue
<script setup lang="ts">
import FlashMessage from '@/components/FlashMessage.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatPence } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

interface Line {
    id: number;
    row_number: number;
    date: string;
    description: string;
    amount: number;
    card_ref: string | null;
    status: 'todo' | 'done' | 'queried' | 'approved';
    owner: { id: number; name: string } | null;
}

const props = defineProps<{
    period: { id: number; label: string; status: 'draft' | 'published' | 'closed' };
    owner: string;
    lines: Line[];
    unassigned_count: number;
    owners: { id: number; name: string; lines: number }[];
    cardholders: { id: number; name: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Periods', href: '/admin/periods' },
    { title: props.period.label, href: `/admin/periods/${props.period.id}` },
    { title: 'Lines', href: '#' },
];

const selected = reactive(new Set<number>());
const target = ref<string>('');
const movable = computed(() => props.lines.filter((line) => line.status !== 'approved'));
const allSelected = computed(() => movable.value.length > 0 && movable.value.every((line) => selected.has(line.id)));

const toggleAll = (on: boolean) => movable.value.forEach((line) => (on ? selected.add(line.id) : selected.delete(line.id)));
const toggle = (id: number, on: boolean) => (on ? selected.add(id) : selected.delete(id));

const changeOwner = (event: Event) => {
    router.get(route('admin.periods.lines', props.period.id), { owner: (event.target as HTMLSelectElement).value });
};

const form = useForm({});

const move = () => {
    form.transform(() => ({
        transaction_ids: [...selected],
        user_id: target.value === 'unassigned' ? null : Number(target.value),
    })).put(route('admin.periods.lines.assign', props.period.id), {
        preserveScroll: true,
        onSuccess: () => selected.clear(),
    });
};

const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <Head :title="`Lines · ${period.label}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-4 p-4">
            <FlashMessage />

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <h1 class="text-xl font-semibold">Lines in {{ period.label }}</h1>
                <select :class="selectClass" :value="owner" @change="changeOwner" aria-label="Show lines for">
                    <option value="unassigned">Unassigned ({{ unassigned_count }})</option>
                    <option value="all">Everyone</option>
                    <option v-for="person in owners" :key="person.id" :value="String(person.id)">{{ person.name }} ({{ person.lines }})</option>
                </select>
            </div>

            <div v-if="period.status !== 'closed'" class="flex flex-col gap-2 rounded-lg border p-3 sm:flex-row sm:items-center">
                <label class="flex items-center gap-2 text-sm">
                    <Checkbox :checked="allSelected" @update:checked="toggleAll" />
                    Select all
                </label>
                <span class="text-sm text-muted-foreground sm:ml-auto">{{ selected.size }} selected · move to</span>
                <select v-model="target" :class="selectClass" aria-label="Move selected lines to">
                    <option value="" disabled>Choose…</option>
                    <option v-for="person in cardholders" :key="person.id" :value="String(person.id)">{{ person.name }}</option>
                    <option value="unassigned">Unassigned</option>
                </select>
                <Button :disabled="!selected.size || !target || form.processing" @click="move">Move</Button>
            </div>
            <p class="text-xs text-muted-foreground">Moving a line clears any purpose the previous person entered. Approved lines can't be moved.</p>

            <ul class="divide-y rounded-lg border">
                <li v-for="line in lines" :key="line.id" class="flex items-start gap-3 p-4 text-sm">
                    <Checkbox
                        v-if="period.status !== 'closed'"
                        :checked="selected.has(line.id)"
                        :disabled="line.status === 'approved'"
                        @update:checked="toggle(line.id, $event)"
                        :aria-label="`Select row ${line.row_number}`"
                    />
                    <div class="min-w-0 flex-1">
                        <div class="font-medium">{{ line.description }}</div>
                        <div class="text-muted-foreground">
                            Row {{ line.row_number }} · {{ formatDate(line.date) }} · {{ formatPence(line.amount) }}
                            <span v-if="line.card_ref"> · {{ line.card_ref }}</span>
                        </div>
                    </div>
                    <div class="shrink-0 text-right">
                        <div>{{ line.owner?.name ?? 'Unassigned' }}</div>
                        <div v-if="line.status === 'approved'" class="text-xs text-muted-foreground">Approved</div>
                    </div>
                </li>
                <li v-if="!lines.length" class="p-4 text-sm text-muted-foreground">No lines here.</li>
            </ul>
        </div>
    </AppLayout>
</template>
```

- [ ] **Step 7: Link the lines page from the dashboard**

In `resources/js/pages/admin/periods/Show.vue`:
- Inside the `data-unassigned` section, after the sentence, add:
```vue
                <Link :href="route('admin.periods.lines', period.id)" class="ml-1 font-medium underline underline-offset-4">Assign them</Link>
```
- Inside `data-actions`, after the Upload statement button, add:
```vue
                    <Button v-if="totals.lines > 0" variant="outline" as-child>
                        <Link :href="route('admin.periods.lines', period.id)">Lines</Link>
                    </Button>
```

- [ ] **Step 8: Run all tests and the frontend checks**

Run: `php artisan test && npm run build && npx vue-tsc --noEmit 2>&1 | grep -E 'error TS' | grep -v TS2688`
Expected: all PASS; build succeeds; the final grep prints nothing.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "feat: assign and reassign period lines"
```

---

### Task 10: Publishing a period and demo statement

**Files:**
- Create: `app/Events/PeriodPublished.php`, `app/Http/Controllers/Admin/PublishPeriodController.php`, `resources/demo/demo-statement.csv`
- Modify: `routes/admin.php`, `resources/js/pages/admin/periods/Show.vue`
- Test: `tests/Feature/Admin/PublishPeriodTest.php`, `tests/Feature/DemoStatementTest.php`

**Interfaces:**
- Consumes: everything above.
- Produces:
  - Route `admin.periods.publish` (POST /admin/periods/{period}/publish).
  - Event `App\Events\PeriodPublished(Period $period)` implementing `ShouldDispatchAfterCommit`.
  - Activity log `periods` event `published`.
  - `resources/demo/demo-statement.csv` — invented statement for manual testing that matches the demo users' last-4 digits (`1001`–`1003`) and includes one unmatched card, one bad date, one exact duplicate, one credit and one full test card number to be masked.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Admin/PublishPeriodTest.php`:
```php
<?php

use App\Enums\PeriodStatus;
use App\Events\PeriodPublished;
use App\Models\Import;
use App\Models\Period;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['is_cardholder' => false]);
});

it('publishes a draft period that has an imported statement', function () {
    Event::fake([PeriodPublished::class]);
    $this->travelTo('2026-10-10 09:00');
    $period = Period::factory()->create();
    Import::factory()->for($period)->confirmed()->create();

    $this->actingAs($this->admin)
        ->post(route('admin.periods.publish', $period))
        ->assertRedirect(route('admin.periods.show', $period))
        ->assertSessionHas('success');

    $period->refresh();
    expect($period->status)->toBe(PeriodStatus::Published)
        ->and($period->published_at->toDateTimeString())->toBe('2026-10-10 09:00:00');
    Event::assertDispatched(PeriodPublished::class, fn ($event) => $event->period->is($period));
    expect(Activity::where('log_name', 'periods')->where('event', 'published')->sole()->causer_id)->toBe($this->admin->id);
});

it('needs at least one imported statement', function () {
    Event::fake([PeriodPublished::class]);
    $period = Period::factory()->create();
    Import::factory()->for($period)->create();

    $this->actingAs($this->admin)
        ->post(route('admin.periods.publish', $period))
        ->assertSessionHas('error');

    expect($period->fresh()->status)->toBe(PeriodStatus::Draft);
    Event::assertNotDispatched(PeriodPublished::class);
});

it('only publishes once', function () {
    Event::fake([PeriodPublished::class]);
    $period = Period::factory()->published()->create();

    $this->actingAs($this->admin)
        ->post(route('admin.periods.publish', $period))
        ->assertSessionHas('error');

    Event::assertNotDispatched(PeriodPublished::class);
});

it('is only for admins', function () {
    $period = Period::factory()->create();

    $this->actingAs(User::factory()->create())->post(route('admin.periods.publish', $period))->assertForbidden();
});
```

`tests/Feature/DemoStatementTest.php`:
```php
<?php

use App\Imports\ImportPreviewer;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Storage;

it('ships a demo statement that exercises matching, problems, duplicates and masking', function () {
    Storage::fake('local');
    $this->seed(DemoSeeder::class);

    $import = importFromCsv(file_get_contents(resource_path('demo/demo-statement.csv')), [
        'column_mapping' => ['date' => 0, 'description' => 1, 'cardholder' => 2, 'card' => 3, 'amount' => 4, 'purpose' => null],
    ]);

    $summary = app(ImportPreviewer::class)->preview($import)->summary([]);
    $rows = collect(app(ImportPreviewer::class)->preview($import)->rows);

    expect($summary['errors'])->toBe(1)
        ->and($summary['duplicates'])->toBe(1)
        ->and($summary['unassigned'])->toBe(1)
        ->and(collect($summary['by_user'])->sum('count'))->toBeGreaterThanOrEqual(8)
        ->and($rows->contains(fn ($row) => ($row->parsed->amount ?? 0) < 0))->toBeTrue()
        ->and($rows->contains(fn ($row) => in_array('**** 0005', $row->parsed->extra, true)))->toBeTrue();
});
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `php artisan test tests/Feature/Admin/PublishPeriodTest.php tests/Feature/DemoStatementTest.php`
Expected: FAIL — `Route [admin.periods.publish] not defined` and missing demo file.

- [ ] **Step 3: Implement the event, controller and route**

`app/Events/PeriodPublished.php`:
```php
<?php

namespace App\Events;

use App\Models\Period;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A period went live. Plan 5 emails and pushes each cardholder who has lines. */
class PeriodPublished implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Period $period) {}
}
```

`app/Http/Controllers/Admin/PublishPeriodController.php`:
```php
<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ImportStatus;
use App\Enums\PeriodStatus;
use App\Events\PeriodPublished;
use App\Http\Controllers\Controller;
use App\Models\Period;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PublishPeriodController extends Controller
{
    public function __invoke(Request $request, Period $period): RedirectResponse
    {
        if ($period->status !== PeriodStatus::Draft) {
            return back()->with('error', 'This period has already been published.');
        }

        if (! $period->imports()->where('status', ImportStatus::Confirmed)->exists()) {
            return back()->with('error', 'Import the statement before publishing.');
        }

        DB::transaction(function () use ($period, $request) {
            $period->update(['status' => PeriodStatus::Published, 'published_at' => now()]);

            PeriodPublished::dispatch($period);

            activity('periods')
                ->causedBy($request->user())
                ->performedOn($period)
                ->event('published')
                ->log("Published {$period->label}");
        });

        return to_route('admin.periods.show', $period)
            ->with('success', "{$period->label} is published. Cardholders can now see their lines.");
    }
}
```

In `routes/admin.php`, add `use App\Http\Controllers\Admin\PublishPeriodController;` and inside the group:
```php
    Route::post('periods/{period}/publish', PublishPeriodController::class)->name('periods.publish');
```

- [ ] **Step 4: Add the Publish button**

In `resources/js/pages/admin/periods/Show.vue`, add to the script:
```ts
const publish = () => {
    const warning = props.unassigned_count > 0
        ? `\n\n${props.unassigned_count} unassigned ${props.unassigned_count === 1 ? 'line' : 'lines'} won't go to anyone until you assign them.`
        : '';

    if (window.confirm(`Publish ${props.period.label}? Cardholders will be able to see and complete their lines.${warning}`)) {
        router.post(route('admin.periods.publish', props.period.id), {}, { preserveScroll: true });
    }
};
```
and inside `data-actions`, as the first child, add:
```vue
                    <Button v-if="period.status === 'draft' && imports.some((upload) => upload.status === 'confirmed')" @click="publish">Publish</Button>
```

- [ ] **Step 5: Create the demo statement**

`resources/demo/demo-statement.csv` (all invented; `378282246310005` is the card networks' published Amex *test* number):
```csv
Date,Description,Card Member,Account #,Amount,Category,Reference
01/09/2026,TRAINLINE.COM LONDON,S CARDHOLDER,XXXX-XXXXXX-01001,45.20,Travel,
02/09/2026,PREMIER INN MANCHESTER,S CARDHOLDER,XXXX-XXXXXX-01001,89.00,Accommodation,
03/09/2026,PRET A MANGER LEEDS,J MANAGER,XXXX-XXXXXX-01002,7.85,Meals,
04/09/2026,SCREWFIX DIRECT,J MANAGER,XXXX-XXXXXX-01002,132.40,Supplies,
05/09/2026,NCP CAR PARK BIRMINGHAM,M MICROSOFT,XXXX-XXXXXX-01003,18.00,Parking,
08/09/2026,AMAZON MARKETPLACE,M MICROSOFT,XXXX-XXXXXX-01003,24.99,Supplies,
09/09/2026,AMAZON MARKETPLACE REFUND,M MICROSOFT,XXXX-XXXXXX-01003,-24.99,Supplies,378282246310005
10/09/2026,SHELL FUEL STATION,S CARDHOLDER,XXXX-XXXXXX-01001,61.30,Fuel,
11/09/2026,ADOBE CREATIVE CLOUD,J MANAGER,XXXX-XXXXXX-01002,(19.97),Software,
12/09/2026,UNKNOWN MERCHANT,A STRANGER,XXXX-XXXXXX-09999,15.00,Other,
31/09/2026,COSTA COFFEE,S CARDHOLDER,XXXX-XXXXXX-01001,3.40,Meals,
02/09/2026,PREMIER INN MANCHESTER,S CARDHOLDER,XXXX-XXXXXX-01001,89.00,Accommodation,
```

- [ ] **Step 6: Run the tests to see them pass**

Run: `php artisan test tests/Feature/Admin/PublishPeriodTest.php tests/Feature/DemoStatementTest.php`
Expected: all PASS.

- [ ] **Step 7: Run all tests, frontend checks and Pint**

Run: `./vendor/bin/pint && php artisan test && npm run build && npx vue-tsc --noEmit 2>&1 | grep -E 'error TS' | grep -v TS2688`
Expected: Pint reports fixed/clean; all tests PASS; build succeeds; the final grep prints nothing.

- [ ] **Step 8: Walk through it on the real app**

```bash
php artisan migrate
php artisan db:seed
composer dev
```
In a browser, signed in as `admin@cardtrail.test` / `password`:
1. Periods → New period → accept the defaults → Create.
2. Upload statement → choose `resources/demo/demo-statement.csv` → the mapping page guesses Date, Description, Amount, Card (Account #) and Cardholder (Card Member). Leave "Remember this layout" ticked → Continue.
3. Preview shows 12 rows: 1 problem (31/09/2026), 1 duplicate, 1 unassigned; the Reference value shows `**** 0005`. Type `30/09/2026` into the problem row → Confirm import → "Imported 11 lines (1 unassigned, 1 duplicate skipped)."
4. Dashboard shows Jo Manager, Morgan Microsoft and Sam Cardholder with their line counts and 1 unassigned. Lines → assign the unassigned line to Sam → "Moved 1 line to Sam."
5. Publish → confirm → status becomes Published.
6. Create a second period, upload the same CSV → it goes straight to the preview with "Recognised the “Card statement” layout".
7. Narrow the window to phone width: every page is usable without sideways scrolling except the mapping sample table, which scrolls inside its own box.

Expected: every step behaves as described.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "feat: publish periods; invented demo statement for manual testing"
```
