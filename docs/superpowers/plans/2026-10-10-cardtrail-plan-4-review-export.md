# CardTrail Plan 4 — Review and Export Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Accountants review each submitted line beside its receipt, then approve it, query it with a comment, or approve everything remaining. They close the month and download a ZIP in today's format: the original spreadsheet with purposes written in, plus `Row29.pdf` receipt files.

**Architecture:** `LineReviewer` holds the approve and query rules and runs them under period → reconciliation → line locks, the same order the importer and assigner use. It fires `LineQueried` for Plan 5's notifications. Exports are rows in an `exports` table, built by a queued `BuildExport` job using these pieces:
- `ExportBuilder` makes the ZIP.
- `SpreadsheetFiller` writes purposes into a copy of each original file.
- `ReceiptFileNamer` holds the naming rules.

Finished ZIPs live on the private `local` disk for 7 days.

**Tech Stack:** Laravel 12, Inertia 2, Vue 3 + TypeScript, Pest 3, `phpoffice/phpspreadsheet` ^5, PHP `zip` extension (installed).

**Spec:** `docs/superpowers/specs/2026-10-10-cardtrail-design.md`. This plan covers §4.6–4.8, §5.1 query rules, §6.5, §6.6, §7 screens 5 (review/export/close) and 8, and §8 export failures.

**Builds on:** Plans 1–3 on `main`. Existing pieces this plan uses:
- Models and services: `Transaction::refreshStatus()`, `editableByOwner()`, `receipts()`, `comments()`; `Receipt::toListItem()`; `Comment`; `ReconciliationSummary`; `PeriodDashboard::for()`.
- Spreadsheet code: `SpreadsheetReader` (CSV delimiter detection, CP1252 fallback, text-only CSV cells), `ColumnMap::fromArray()` (`purpose` column, 0-based).
- Routes and pages: `routes/admin.php`; the `transactions.comments.store` route (admins may comment, see `TransactionPolicy::comment`); the `admin/periods/Show.vue` page.
- Test helpers: `lineFor()`, `statementCsv()` and `importFromCsv()` in `tests/Pest.php`.

## Global Constraints

- Everything in Plans 1–3's Global Constraints still applies: git author `donashalin <donashalin@gmail.com>`, never commit `.env`, invented data only, UK English, money in integer pence, `whereDate()` for date columns.
- Commit messages are plain. No Co-Authored-By, session or other AI attribution lines. The user is the sole author.
- A query sets the line to `queried` and the reconciliation to `in_progress`, and the cardholder must resubmit. Approving the last line approves the reconciliation.
- Approve only works on `done` lines in a `submitted` reconciliation of a `published` period. Query works on `done` or `approved` lines in a `submitted` or `approved` reconciliation of a `published` period.
- Lock order is period, then reconciliation, then line. `ImportCommitter` and `TransactionAssigner` already lock the period first, so keeping this order avoids deadlocks.
- Export spreadsheet: rows are never deleted or reordered. The purpose goes in the mapped purpose column, or a new "Purpose" column is appended. " (No receipt: {reason})" is appended when the no-receipt box is ticked and the line has no receipt. A per-manager export fills in only that manager's purposes.
- Receipt names are `Row{row}.{ext}`, then `Row{row}-2.{ext}`, `-3` and so on. Lines from a period's second and later imports get an `Import{n}-` prefix. The first import keeps plain names.
- ZIP layout:
  - Per manager: `{Period label} - {User name}/` holding the spreadsheet(s) and that manager's receipts.
  - Whole period: the spreadsheet(s) at the root, plus one `{User name}/` folder of receipts per manager.
- Exports are queued, retried 3 times, and kept for 7 days. A failure is recorded on the export and fires `ExportFailed`. Domain events implement `ShouldDispatchAfterCommit`; Plan 5 adds the listeners.

## Review Focus

1. **Approving a month the cardholder has reopened.** A late import or reassignment reopens a submitted reconciliation. Approving any of its lines must then be refused until they resubmit, never silently approved. Pinned in Task 1.
2. **Querying a line after the whole month was approved.** The month must reopen, both approvals must be cleared, and the cardholder must be able to edit that line. Pinned in Task 1.
3. **A purpose that looks like a formula** (`=HYPERLINK(...)`, `+44…`, `@SUM`). It must be written as plain text in XLSX and defused in CSV, so opening the export never runs it. Pinned in Task 4.
4. **Two imports in one period.** Both have a row 29 and may share a file name. Receipts become `Row29.pdf` and `Import2-Row29.pdf`, and both spreadsheets survive. Pinned in Tasks 4 and 5.
5. **A receipt file missing from storage.** The export must fail with a message naming the row, never hand over a ZIP that is quietly incomplete. Pinned in Task 5.

## Rulings made while planning

- **Reviewer locks the period first.** This serialises reviews within a period. That is fine at this volume, and it matches the importer and assigner lock order.
- **CSV exports are written as UTF-8 with a byte-order mark**, whatever the original encoding, so Excel shows "£" correctly. Cells that start with `=`, `+`, `@`, a tab or a carriage return get a leading `'`. A leading `-` is left alone because credits and notes often start with one.
- **Exports need a published or closed period.** A draft has no cardholder work in it to export.
- **A query reopens the month, not just the line, for editing.** Once the reconciliation is back `in_progress`, the cardholder can also change their other unapproved lines (§5.1 taken literally). The other reading of §4.6, "reopens only that line", would lock lines that autosave turns `done` while the cardholder is still typing. Unapproved lines get reviewed again on resubmission anyway. Approved lines stay locked.
- **Managers who share a name share a receipt folder** in a whole-period export. Row numbers keep their files apart within an import, so nothing is overwritten.

---

## File Structure

```
app/
  Enums/ExportStatus.php                    # pending | ready | failed
  Events/{LineQueried,ExportReady,ExportFailed}.php
  Exports/
    ExportBuilder.php                       # Export → temp ZIP path
    ReceiptFileNamer.php                    # Row29.pdf, Row29-2.pdf, Import2-Row29.pdf; clean()
    SpreadsheetFiller.php                   # original file + purposes → bytes
  Imports/SpreadsheetReader.php             # + workbook(), public delimiter()
  Jobs/BuildExport.php
  Models/Export.php
  Models/Period.php                         # + exports()
  Models/Transaction.php                    # approved_at/approved_by fillable
  Periods/PeriodDashboard.php               # + exports, approved counts
  Reviews/{CannotReview,LineReviewer}.php
  Http/Controllers/Admin/
    ClosePeriodController.php
    ExportController.php                    # store + download
    LineApprovalController.php
    LineQueryController.php
    ReconciliationApprovalController.php    # approve all remaining
    ReviewController.php
database/migrations/2026_10_13_000001_create_exports_table.php
database/factories/ExportFactory.php
resources/js/pages/admin/review/Show.vue
resources/js/pages/admin/periods/Show.vue
routes/admin.php, routes/console.php
tests/Feature/Reviews/LineReviewerTest.php
tests/Feature/Admin/{ReviewTest,ClosePeriodTest}.php
tests/Unit/Exports/ReceiptFileNamerTest.php
tests/Feature/Exports/{SpreadsheetFillerTest,ExportTest}.php
```

---

### Task 1: Approving and querying lines

**Files:**
- Create: `app/Reviews/CannotReview.php`, `app/Reviews/LineReviewer.php`, `app/Events/LineQueried.php`
- Modify: `app/Models/Transaction.php` (fillable)
- Test: `tests/Feature/Reviews/LineReviewerTest.php`

**Interfaces:**
- Consumes: `Transaction`, `Reconciliation`, `Comment` (Plan 3).
- Produces:
  - `LineReviewer::approve(Transaction, User): Transaction`.
  - `LineReviewer::approveRemaining(Reconciliation, User): int`.
  - `LineReviewer::query(Transaction, User, string $body): Comment`.
  - All three throw `CannotReview` (a `RuntimeException` whose message is safe to show).
  - Event `LineQueried(Transaction $line, Comment $comment)`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Reviews/LineReviewerTest.php`:

```php
<?php

use App\Enums\ReconciliationStatus;
use App\Enums\TransactionStatus;
use App\Events\LineQueried;
use App\Models\Period;
use App\Models\User;
use App\Reviews\CannotReview;
use App\Reviews\LineReviewer;
use Illuminate\Support\Facades\Event;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Event::fake([LineQueried::class]);
    $this->sam = User::factory()->create();
    $this->admin = User::factory()->admin()->create(['is_cardholder' => false]);
    $this->reviewer = app(LineReviewer::class);
});

/** A line on Sam's submitted month. */
function reviewLine(array $attributes = [], ?Period $period = null)
{
    $line = lineFor(test()->sam, ['status' => TransactionStatus::Done, 'purpose' => 'Client lunch', ...$attributes], $period);
    $line->reconciliation->update(['status' => ReconciliationStatus::Submitted, 'submitted_at' => now()]);

    return $line->fresh();
}

it('approves a completed line, and the month once every line is approved', function () {
    $first = reviewLine();
    $second = reviewLine([], $first->period);

    $this->reviewer->approve($first, $this->admin);

    expect($first->fresh())
        ->status->toBe(TransactionStatus::Approved)
        ->approved_by->toBe($this->admin->id)
        ->approved_at->not->toBeNull()
        ->and($first->reconciliation->fresh()->status)->toBe(ReconciliationStatus::Submitted);

    $this->reviewer->approve($second, $this->admin);

    expect($first->reconciliation->fresh())
        ->status->toBe(ReconciliationStatus::Approved)
        ->approved_by->toBe($this->admin->id);
    expect(Activity::query()->where('event', 'line_approved')->count())->toBe(2);
});

it('refuses to approve until the month is submitted', function () {
    $line = reviewLine();
    $line->reconciliation->update(['status' => ReconciliationStatus::InProgress]);

    expect(fn () => $this->reviewer->approve($line, $this->admin))->toThrow(CannotReview::class);
    expect($line->fresh()->status)->toBe(TransactionStatus::Done);
});

it('refuses to approve a line that is not complete, or in a closed month', function () {
    $todo = reviewLine(['status' => TransactionStatus::Todo]);
    $closed = reviewLine([], Period::factory()->closed()->create());

    expect(fn () => $this->reviewer->approve($todo, $this->admin))->toThrow(CannotReview::class)
        ->and(fn () => $this->reviewer->approve($closed, $this->admin))->toThrow(CannotReview::class);
});

it('approves every remaining completed line at once', function () {
    $first = reviewLine();
    reviewLine(['status' => TransactionStatus::Approved], $first->period);
    reviewLine([], $first->period);

    expect($this->reviewer->approveRemaining($first->reconciliation, $this->admin))->toBe(2)
        ->and($first->reconciliation->fresh()->status)->toBe(ReconciliationStatus::Approved);
});

it('queries a line: the line reopens, the month goes back to the cardholder and the question is kept', function () {
    $line = reviewLine();

    $comment = $this->reviewer->query($line, $this->admin, 'Who attended?');

    expect($line->fresh()->status)->toBe(TransactionStatus::Queried)
        ->and($line->reconciliation->fresh()->status)->toBe(ReconciliationStatus::InProgress)
        ->and($comment)->body->toBe('Who attended?')->user_id->toBe($this->admin->id)
        ->and($this->sam->can('update', $line->fresh()))->toBeTrue();
    Event::assertDispatched(LineQueried::class, fn ($event) => $event->line->is($line) && $event->comment->is($comment));
    expect(Activity::query()->where('event', 'line_queried')->exists())->toBeTrue();
});

it('reopens an approved month when one of its lines is queried', function () {
    $line = reviewLine();
    $this->reviewer->approve($line, $this->admin);

    $this->reviewer->query($line->fresh(), $this->admin, 'Is this personal?');

    expect($line->fresh())
        ->status->toBe(TransactionStatus::Queried)
        ->approved_at->toBeNull()
        ->approved_by->toBeNull()
        ->and($line->reconciliation->fresh())
        ->status->toBe(ReconciliationStatus::InProgress)
        ->approved_at->toBeNull();
});

it('refuses to query a line still with the cardholder, or before they submit', function () {
    $queried = reviewLine(['status' => TransactionStatus::Queried]);
    $inProgress = reviewLine();
    $inProgress->reconciliation->update(['status' => ReconciliationStatus::InProgress]);

    expect(fn () => $this->reviewer->query($queried, $this->admin, 'Again?'))->toThrow(CannotReview::class)
        ->and(fn () => $this->reviewer->query($inProgress->fresh(), $this->admin, 'Hmm?'))->toThrow(CannotReview::class);
    Event::assertNotDispatched(LineQueried::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Reviews/LineReviewerTest.php`
Expected: FAIL. Class `App\Events\LineQueried` is not found.

- [ ] **Step 3: Write the reviewer**

In `app/Models/Transaction.php`, add `'approved_at'` and `'approved_by'` to the end of `$fillable`.

`app/Reviews/CannotReview.php`:

```php
<?php

namespace App\Reviews;

use RuntimeException;

/** A review action that isn't allowed right now. The message is safe to show the accountant. */
final class CannotReview extends RuntimeException {}
```

`app/Events/LineQueried.php`:

```php
<?php

namespace App\Events;

use App\Models\Comment;
use App\Models\Transaction;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Accounts asked a question about a line. Plan 5 tells the cardholder. */
class LineQueried implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public Transaction $line,
        public Comment $comment,
    ) {}
}
```

`app/Reviews/LineReviewer.php`:

```php
<?php

namespace App\Reviews;

use App\Enums\PeriodStatus;
use App\Enums\ReconciliationStatus;
use App\Enums\TransactionStatus;
use App\Events\LineQueried;
use App\Models\Comment;
use App\Models\Period;
use App\Models\Reconciliation;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Approve and query lines. Locks period → reconciliation → line, the same order as imports and reassignment. */
final class LineReviewer
{
    /** @throws CannotReview */
    public function approve(Transaction $line, User $admin): Transaction
    {
        return DB::transaction(function () use ($line, $admin) {
            [$period, $reconciliation, $line] = $this->lock($line);
            $this->ensureReviewable($period, $reconciliation);

            if ($line->status !== TransactionStatus::Done) {
                throw new CannotReview('Only completed lines can be approved.');
            }

            $this->markApproved($line, $admin);
            $this->approveIfComplete($reconciliation, $admin);

            activity('reviews')
                ->causedBy($admin)
                ->performedOn($line)
                ->event('line_approved')
                ->log("Approved row {$line->row_number}");

            return $line;
        });
    }

    /** @throws CannotReview */
    public function approveRemaining(Reconciliation $reconciliation, User $admin): int
    {
        return DB::transaction(function () use ($reconciliation, $admin) {
            $period = Period::query()->whereKey($reconciliation->period_id)->lockForUpdate()->firstOrFail();
            $reconciliation = Reconciliation::query()->whereKey($reconciliation->id)->lockForUpdate()->firstOrFail();
            $this->ensureReviewable($period, $reconciliation);

            $lines = $reconciliation->transactions()->where('status', TransactionStatus::Done)->lockForUpdate()->get();

            foreach ($lines as $line) {
                $this->markApproved($line, $admin);
            }

            $this->approveIfComplete($reconciliation, $admin);

            activity('reviews')
                ->causedBy($admin)
                ->performedOn($reconciliation)
                ->event('lines_approved')
                ->withProperties(['count' => $lines->count()])
                ->log('Approved '.$lines->count().' '.Str::plural('line', $lines->count()));

            return $lines->count();
        });
    }

    /** @throws CannotReview */
    public function query(Transaction $line, User $admin, string $body): Comment
    {
        return DB::transaction(function () use ($line, $admin, $body) {
            [$period, $reconciliation, $line] = $this->lock($line);

            if ($period->status !== PeriodStatus::Published) {
                throw new CannotReview('This period isn’t open, so lines can’t be queried.');
            }

            if (! in_array($reconciliation->status, [ReconciliationStatus::Submitted, ReconciliationStatus::Approved], true)) {
                throw new CannotReview('Lines can be queried once the cardholder has submitted.');
            }

            if (! in_array($line->status, [TransactionStatus::Done, TransactionStatus::Approved], true)) {
                throw new CannotReview('This line is already waiting on the cardholder.');
            }

            $line->update(['status' => TransactionStatus::Queried, 'approved_at' => null, 'approved_by' => null]);
            $reconciliation->update(['status' => ReconciliationStatus::InProgress, 'approved_at' => null, 'approved_by' => null]);
            $comment = $line->comments()->create(['user_id' => $admin->id, 'body' => $body]);

            LineQueried::dispatch($line, $comment);

            activity('reviews')
                ->causedBy($admin)
                ->performedOn($line)
                ->event('line_queried')
                ->withProperties(['body' => $body])
                ->log("Queried row {$line->row_number}");

            return $comment;
        });
    }

    /** @return array{0: Period, 1: Reconciliation, 2: Transaction} */
    private function lock(Transaction $line): array
    {
        if (! $line->reconciliation_id) {
            throw new CannotReview('This line isn’t assigned to anyone.');
        }

        $period = Period::query()->whereKey($line->period_id)->lockForUpdate()->firstOrFail();
        $reconciliation = Reconciliation::query()->whereKey($line->reconciliation_id)->lockForUpdate()->firstOrFail();
        $line = Transaction::query()->whereKey($line->id)->lockForUpdate()->firstOrFail();

        if ($line->reconciliation_id !== $reconciliation->id) {
            throw new CannotReview('This line has just moved to someone else. Refresh and try again.');
        }

        return [$period, $reconciliation, $line];
    }

    private function ensureReviewable(Period $period, Reconciliation $reconciliation): void
    {
        if ($period->status !== PeriodStatus::Published) {
            throw new CannotReview('This period isn’t open for review.');
        }

        if ($reconciliation->status !== ReconciliationStatus::Submitted) {
            throw new CannotReview('Lines can be approved once the cardholder has submitted.');
        }
    }

    private function markApproved(Transaction $line, User $admin): void
    {
        $line->update(['status' => TransactionStatus::Approved, 'approved_at' => now(), 'approved_by' => $admin->id]);
    }

    private function approveIfComplete(Reconciliation $reconciliation, User $admin): void
    {
        if ($reconciliation->transactions()->where('status', '!=', TransactionStatus::Approved)->exists()) {
            return;
        }

        $reconciliation->update(['status' => ReconciliationStatus::Approved, 'approved_at' => now(), 'approved_by' => $admin->id]);

        activity('reviews')
            ->causedBy($admin)
            ->performedOn($reconciliation)
            ->event('reconciliation_approved')
            ->log('Approved the month');
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Reviews/LineReviewerTest.php`
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
git add -A app tests
git commit -m "feat: approve and query lines"
```

---

### Task 2: Review screen

**Files:**
- Create: `app/Http/Controllers/Admin/ReviewController.php`, `app/Http/Controllers/Admin/LineApprovalController.php`, `app/Http/Controllers/Admin/LineQueryController.php`, `app/Http/Controllers/Admin/ReconciliationApprovalController.php`, `resources/js/pages/admin/review/Show.vue`
- Modify: `routes/admin.php`
- Test: `tests/Feature/Admin/ReviewTest.php`

**Interfaces:**
- Consumes: `LineReviewer` (Task 1); `ReconciliationSummary` and `Receipt::toListItem()` (Plan 3); the `transactions.comments.store` route.
- Produces routes:
  - `admin.reconciliations.review` (GET, optional `line`).
  - `admin.lines.approve` (POST).
  - `admin.lines.query` (POST `body`).
  - `admin.reconciliations.approve` (POST).

- [ ] **Step 1: Write the failing test**

`tests/Feature/Admin/ReviewTest.php`:

```php
<?php

use App\Enums\ReconciliationStatus;
use App\Enums\TransactionStatus;
use App\Models\Comment;
use App\Models\Receipt;
use App\Models\User;

beforeEach(function () {
    $this->sam = User::factory()->create(['name' => 'Sam']);
    $this->admin = User::factory()->admin()->create(['name' => 'Alex', 'is_cardholder' => false]);
});

function submittedMonth(array ...$lines): array
{
    $made = [];
    $period = null;

    foreach ($lines as $attributes) {
        $line = lineFor(test()->sam, ['status' => TransactionStatus::Done, 'purpose' => 'Client lunch', ...$attributes], $period);
        $period = $line->period;
        $made[] = $line;
    }

    $made[0]->reconciliation->update(['status' => ReconciliationStatus::Submitted, 'submitted_at' => now()]);

    return array_map(fn ($line) => $line->fresh(), $made);
}

it('shows the month with the first line waiting for review selected', function () {
    [$approved, $waiting] = submittedMonth(['row_number' => 2, 'status' => TransactionStatus::Approved], ['row_number' => 3, 'description' => 'DEPOT CAFE']);
    Receipt::factory()->attachedTo($waiting)->create(['original_name' => 'cafe.pdf']);
    Comment::factory()->for($waiting)->for($this->sam)->create(['body' => 'Team breakfast']);

    $this->actingAs($this->admin)->get(route('admin.reconciliations.review', $waiting->reconciliation))
        ->assertInertia(fn ($page) => $page
            ->component('admin/review/Show')
            ->where('reconciliation.owner', 'Sam')
            ->where('reconciliation.approvable', 1)
            ->has('lines', 2)
            ->where('selected.id', $waiting->id)
            ->where('selected.receipts.0.name', 'cafe.pdf')
            ->where('selected.comments.0.body', 'Team breakfast')
            ->where('selected.can_approve', true)
            ->where('selected.can_query', true));
});

it('selects the line asked for', function () {
    [$first, $second] = submittedMonth([], []);

    $this->actingAs($this->admin)->get(route('admin.reconciliations.review', [$first->reconciliation, 'line' => $second->id]))
        ->assertInertia(fn ($page) => $page->where('selected.id', $second->id));
});

it('keeps the review screen to admins', function () {
    [$line] = submittedMonth([]);

    $this->actingAs($this->sam)->get(route('admin.reconciliations.review', $line->reconciliation))->assertForbidden();
});

it('approves a line and moves on to the next one waiting', function () {
    [$first, $second] = submittedMonth(['row_number' => 2], ['row_number' => 3]);

    $this->actingAs($this->admin)->post(route('admin.lines.approve', $first))
        ->assertRedirect(route('admin.reconciliations.review', [$first->reconciliation, 'line' => $second->id]))
        ->assertSessionHas('success');

    expect($first->fresh()->status)->toBe(TransactionStatus::Approved);
});

it('queries a line with a question', function () {
    [$line] = submittedMonth([]);

    $this->actingAs($this->admin)->post(route('admin.lines.query', $line), ['body' => ''])->assertSessionHasErrors('body');
    $this->actingAs($this->admin)->post(route('admin.lines.query', $line), ['body' => 'Receipt is for a different amount?'])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($line->fresh()->status)->toBe(TransactionStatus::Queried)
        ->and($line->comments()->sole()->body)->toBe('Receipt is for a different amount?');
});

it('approves all remaining lines', function () {
    [$line] = submittedMonth([], [], []);

    $this->actingAs($this->admin)->post(route('admin.reconciliations.approve', $line->reconciliation))
        ->assertSessionHas('success', 'Approved 3 lines. Sam’s month is approved.');

    expect($line->reconciliation->fresh()->status)->toBe(ReconciliationStatus::Approved);
});

it('explains why an action is refused', function () {
    [$line] = submittedMonth([]);
    $line->reconciliation->update(['status' => ReconciliationStatus::InProgress]);

    $this->actingAs($this->admin)->post(route('admin.lines.approve', $line))
        ->assertSessionHas('error', 'Lines can be approved once the cardholder has submitted.');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Admin/ReviewTest.php`
Expected: FAIL. Route `admin.reconciliations.review` is not defined.

- [ ] **Step 3: Write the controllers and routes**

`app/Http/Controllers/Admin/ReviewController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PeriodStatus;
use App\Enums\ReconciliationStatus;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Reconciliation;
use App\Models\Receipt;
use App\Models\Transaction;
use App\Reconciliations\ReconciliationSummary;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReviewController extends Controller
{
    public function show(Request $request, Reconciliation $reconciliation): Response
    {
        $reconciliation = ReconciliationSummary::withCounts(Reconciliation::query()->whereKey($reconciliation->id))
            ->with('user:id,name,active')
            ->firstOrFail();

        $lines = $reconciliation->transactions()->withCount('receipts')->orderBy('import_id')->orderBy('row_number')->get();
        $approvable = $lines->where('status', TransactionStatus::Done)->count();
        $selected = $lines->firstWhere('id', (int) $request->query('line'))
            ?? $lines->firstWhere('status', TransactionStatus::Done)
            ?? $lines->first();

        return Inertia::render('admin/review/Show', [
            'reconciliation' => [
                ...ReconciliationSummary::present($reconciliation),
                'owner' => $reconciliation->user->name,
                'owner_active' => $reconciliation->user->active,
                'approvable' => $approvable,
                'can_approve_all' => $approvable > 0
                    && $reconciliation->status === ReconciliationStatus::Submitted
                    && $reconciliation->period->status === PeriodStatus::Published,
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
            'selected' => $selected ? $this->detail($reconciliation, $selected, $request) : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function detail(Reconciliation $reconciliation, Transaction $line, Request $request): array
    {
        $line->load(['receipts', 'comments.user:id,name']);
        $open = $reconciliation->period->status === PeriodStatus::Published;

        return [
            'id' => $line->id,
            'row_number' => $line->row_number,
            'date' => $line->transacted_on->format('Y-m-d'),
            'description' => $line->description,
            'amount' => $line->amount,
            'card_ref' => $line->card_ref,
            'extra' => (object) ($line->extra ?? []),
            'purpose' => $line->purpose,
            'no_receipt' => $line->no_receipt,
            'no_receipt_reason' => $line->no_receipt_reason,
            'status' => $line->status->value,
            'receipts' => $line->receipts->map(fn (Receipt $receipt) => $receipt->toListItem()),
            'comments' => $line->comments->map(fn (Comment $comment) => [
                'id' => $comment->id,
                'body' => $comment->body,
                'author' => $comment->user->name,
                'mine' => $comment->user_id === $request->user()->id,
                'created_at' => $comment->created_at->toIso8601String(),
            ]),
            'can_approve' => $open
                && $reconciliation->status === ReconciliationStatus::Submitted
                && $line->status === TransactionStatus::Done,
            'can_query' => $open
                && in_array($reconciliation->status, [ReconciliationStatus::Submitted, ReconciliationStatus::Approved], true)
                && in_array($line->status, [TransactionStatus::Done, TransactionStatus::Approved], true),
            'can_comment' => $open,
        ];
    }
}
```

`app/Http/Controllers/Admin/LineApprovalController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Reviews\CannotReview;
use App\Reviews\LineReviewer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LineApprovalController extends Controller
{
    public function __invoke(Request $request, Transaction $transaction, LineReviewer $reviewer): RedirectResponse
    {
        try {
            $line = $reviewer->approve($transaction, $request->user());
        } catch (CannotReview $e) {
            return back()->with('error', $e->getMessage());
        }

        $next = Transaction::query()
            ->where('reconciliation_id', $line->reconciliation_id)
            ->where('status', TransactionStatus::Done)
            ->orderBy('import_id')
            ->orderBy('row_number')
            ->value('id');

        return to_route('admin.reconciliations.review', [$line->reconciliation_id, 'line' => $next ?? $line->id])
            ->with('success', "Approved row {$line->row_number}.");
    }
}
```

`app/Http/Controllers/Admin/LineQueryController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Reviews\CannotReview;
use App\Reviews\LineReviewer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LineQueryController extends Controller
{
    public function __invoke(Request $request, Transaction $transaction, LineReviewer $reviewer): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']], ['body.required' => 'Say what you need from the cardholder.']);

        try {
            $reviewer->query($transaction, $request->user(), $data['body']);
        } catch (CannotReview $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Queried row {$transaction->row_number}. The month is back with the cardholder.");
    }
}
```

`app/Http/Controllers/Admin/ReconciliationApprovalController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReconciliationStatus;
use App\Http\Controllers\Controller;
use App\Models\Reconciliation;
use App\Reviews\CannotReview;
use App\Reviews\LineReviewer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ReconciliationApprovalController extends Controller
{
    public function __invoke(Request $request, Reconciliation $reconciliation, LineReviewer $reviewer): RedirectResponse
    {
        try {
            $count = $reviewer->approveRemaining($reconciliation, $request->user());
        } catch (CannotReview $e) {
            return back()->with('error', $e->getMessage());
        }

        $message = "Approved {$count} ".Str::plural('line', $count).'.';

        if ($reconciliation->fresh()->status === ReconciliationStatus::Approved) {
            $message .= " {$reconciliation->user->name}’s month is approved.";
        }

        return back()->with('success', $message);
    }
}
```

In `routes/admin.php`, add these imports:

```php
use App\Http\Controllers\Admin\LineApprovalController;
use App\Http\Controllers\Admin\LineQueryController;
use App\Http\Controllers\Admin\ReconciliationApprovalController;
use App\Http\Controllers\Admin\ReviewController;
```

and these routes at the end of the admin group:

```php
    Route::get('reconciliations/{reconciliation}/review', [ReviewController::class, 'show'])->name('reconciliations.review');
    Route::post('reconciliations/{reconciliation}/approve', ReconciliationApprovalController::class)->name('reconciliations.approve');
    Route::post('lines/{transaction}/approve', LineApprovalController::class)->name('lines.approve');
    Route::post('lines/{transaction}/query', LineQueryController::class)->name('lines.query');
```

- [ ] **Step 4: Write the screen**

`resources/js/pages/admin/review/Show.vue`:

```vue
<script setup lang="ts">
import FlashMessage from '@/components/FlashMessage.vue';
import LineStatusBadge from '@/components/LineStatusBadge.vue';
import ReconciliationStatusBadge from '@/components/ReconciliationStatusBadge.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatPence } from '@/lib/money';
import { formatBytes } from '@/lib/receipts';
import { type BreadcrumbItem } from '@/types';
import type { LineStatus, ReceiptItem, ReconciliationSummary } from '@/types/cardtrail';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Download, ExternalLink, FileText } from 'lucide-vue-next';
import { computed, nextTick, ref, watch } from 'vue';

interface ReviewLine {
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

interface CommentItem {
    id: number;
    body: string;
    author: string;
    mine: boolean;
    created_at: string;
}

interface Selected {
    id: number;
    row_number: number;
    date: string;
    description: string;
    amount: number;
    card_ref: string | null;
    extra: Record<string, string>;
    purpose: string | null;
    no_receipt: boolean;
    no_receipt_reason: string | null;
    status: LineStatus;
    receipts: ReceiptItem[];
    comments: CommentItem[];
    can_approve: boolean;
    can_query: boolean;
    can_comment: boolean;
}

type Filter = 'all' | 'done' | 'queried' | 'approved' | 'todo';

const props = defineProps<{
    reconciliation: ReconciliationSummary & { owner: string; owner_active: boolean; approvable: number; can_approve_all: boolean };
    lines: ReviewLine[];
    selected: Selected | null;
}>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Periods', href: '/admin/periods' },
    { title: props.reconciliation.period.label, href: `/admin/periods/${props.reconciliation.period.id}` },
    { title: props.reconciliation.owner, href: '#' },
]);

const filter = ref<Filter>('all');
const filters: { key: Filter; label: string }[] = [
    { key: 'all', label: 'All' },
    { key: 'done', label: 'To review' },
    { key: 'queried', label: 'Queried' },
    { key: 'approved', label: 'Approved' },
    { key: 'todo', label: 'To do' },
];
const visible = computed(() => (filter.value === 'all' ? props.lines : props.lines.filter((line) => line.status === filter.value)));
const count = (key: Filter) => (key === 'all' ? props.lines.length : props.lines.filter((line) => line.status === key).length);

const receiptIndex = ref(0);
const receipt = computed(() => props.selected?.receipts[receiptIndex.value] ?? null);
const extraColumns = computed(() => Object.entries(props.selected?.extra ?? {}).filter(([, value]) => value !== ''));

const detail = ref<HTMLElement>();
watch(
    () => props.selected?.id,
    async () => {
        receiptIndex.value = 0;
        showQuery.value = false;
        await nextTick();
        if (window.innerWidth < 768) {
            detail.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    },
);

const select = (line: ReviewLine) => router.get(route('admin.reconciliations.review', { reconciliation: props.reconciliation.id, line: line.id }), {}, { preserveScroll: true, preserveState: true });

const busy = ref(false);
const options = { preserveScroll: true, preserveState: true, onStart: () => (busy.value = true), onFinish: () => (busy.value = false) };

const approve = () => props.selected && router.post(route('admin.lines.approve', props.selected.id), {}, options);

const approveAll = () => {
    if (confirm(`Approve all ${props.reconciliation.approvable} remaining completed lines for ${props.reconciliation.owner}?`)) {
        router.post(route('admin.reconciliations.approve', props.reconciliation.id), {}, options);
    }
};

const showQuery = ref(false);
const queryForm = useForm({ body: '' });
const sendQuery = () => {
    if (!props.selected) return;
    queryForm.post(route('admin.lines.query', props.selected.id), { preserveScroll: true, preserveState: true, onSuccess: () => { queryForm.reset(); showQuery.value = false; } });
};

const replyForm = useForm({ body: '' });
const sendReply = () => {
    if (!props.selected) return;
    replyForm.post(route('transactions.comments.store', props.selected.id), { preserveScroll: true, preserveState: true, onSuccess: () => replyForm.reset() });
};

const textareaClass =
    'w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring';
</script>

<template>
    <Head :title="`Review · ${reconciliation.owner}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-4 p-4">
            <FlashMessage />

            <header class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-xl font-semibold">{{ reconciliation.owner }}</h1>
                        <ReconciliationStatusBadge :status="reconciliation.status" />
                    </div>
                    <p class="text-sm text-muted-foreground">
                        {{ reconciliation.period.label }} · {{ reconciliation.done }} of {{ reconciliation.lines }} done
                        <span v-if="reconciliation.queried"> · {{ reconciliation.queried }} queried</span>
                        <span v-if="!reconciliation.owner_active" class="text-red-600 dark:text-red-400"> · Inactive user</span>
                    </p>
                </div>
                <Button v-if="reconciliation.can_approve_all" :disabled="busy" @click="approveAll">Approve all remaining ({{ reconciliation.approvable }})</Button>
            </header>

            <p v-if="reconciliation.status === 'in_progress'" class="rounded-md border bg-muted px-4 py-3 text-sm">
                {{ reconciliation.owner }} hasn’t submitted yet{{ reconciliation.queried ? ' (they have questions to answer)' : '' }}. You can look, and approve once they submit.
            </p>

            <div class="grid gap-4 md:grid-cols-[minmax(0,22rem)_1fr]">
                <aside class="flex flex-col gap-2">
                    <div class="flex flex-wrap gap-1">
                        <button
                            v-for="item in filters"
                            :key="item.key"
                            type="button"
                            class="rounded-full border px-3 py-1 text-xs"
                            :class="filter === item.key ? 'bg-primary text-primary-foreground' : 'hover:bg-accent'"
                            @click="filter = item.key"
                        >
                            {{ item.label }} ({{ count(item.key) }})
                        </button>
                    </div>
                    <ul class="divide-y rounded-lg border md:max-h-[75vh] md:overflow-y-auto">
                        <li v-for="line in visible" :key="line.id">
                            <button
                                type="button"
                                class="flex w-full items-start gap-2 p-3 text-left text-sm hover:bg-accent"
                                :class="{ 'bg-accent': selected?.id === line.id }"
                                @click="select(line)"
                            >
                                <div class="min-w-0 flex-1">
                                    <div class="truncate font-medium">{{ line.description }}</div>
                                    <div class="truncate text-muted-foreground">Row {{ line.row_number }} · {{ line.purpose || 'No purpose yet' }}</div>
                                </div>
                                <div class="shrink-0 text-right">
                                    <div class="tabular-nums">{{ formatPence(line.amount) }}</div>
                                    <LineStatusBadge :status="line.status" />
                                </div>
                            </button>
                        </li>
                        <li v-if="!visible.length" class="p-3 text-sm text-muted-foreground">No lines here.</li>
                    </ul>
                </aside>

                <section v-if="selected" ref="detail" class="flex min-w-0 flex-col gap-4">
                    <div class="rounded-xl border p-4">
                        <div class="flex items-start justify-between gap-3">
                            <h2 class="text-lg font-semibold break-words">{{ selected.description }}</h2>
                            <LineStatusBadge :status="selected.status" />
                        </div>
                        <p class="mt-1 text-2xl font-semibold tabular-nums">{{ formatPence(selected.amount) }}</p>
                        <p class="text-sm text-muted-foreground">
                            {{ formatDate(selected.date) }} · Row {{ selected.row_number }}<span v-if="selected.card_ref"> · {{ selected.card_ref }}</span>
                        </p>
                        <dl v-if="extraColumns.length" class="mt-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                            <template v-for="[label, value] in extraColumns" :key="label">
                                <dt class="text-muted-foreground">{{ label }}</dt>
                                <dd class="break-words">{{ value }}</dd>
                            </template>
                        </dl>
                        <div class="mt-4 text-sm">
                            <div class="font-medium">Purpose</div>
                            <p class="whitespace-pre-line break-words">{{ selected.purpose || '—' }}</p>
                            <p v-if="selected.no_receipt" class="mt-2"><span class="font-medium">No receipt:</span> {{ selected.no_receipt_reason || '—' }}</p>
                        </div>
                    </div>

                    <div class="flex flex-col gap-2">
                        <div v-if="selected.receipts.length > 1" class="flex flex-wrap gap-1">
                            <button
                                v-for="(item, index) in selected.receipts"
                                :key="item.id"
                                type="button"
                                class="rounded-full border px-3 py-1 text-xs"
                                :class="receiptIndex === index ? 'bg-primary text-primary-foreground' : 'hover:bg-accent'"
                                @click="receiptIndex = index"
                            >
                                Receipt {{ index + 1 }}
                            </button>
                        </div>
                        <template v-if="receipt">
                            <iframe v-if="receipt.is_pdf" :src="receipt.url" :title="receipt.name" class="h-[70vh] w-full rounded-lg border" />
                            <img v-else-if="receipt.is_image" :src="receipt.url" :alt="receipt.name" class="max-h-[70vh] w-full rounded-lg border object-contain" />
                            <div v-else class="flex items-center gap-3 rounded-lg border p-4 text-sm">
                                <FileText class="size-6 shrink-0 text-muted-foreground" />
                                <span class="min-w-0 flex-1 break-all">{{ receipt.name }} · {{ formatBytes(receipt.size) }}</span>
                                <a :href="receipt.url" class="flex items-center gap-1 font-medium underline underline-offset-4"><Download class="size-4" /> Download</a>
                            </div>
                            <a :href="receipt.url" target="_blank" rel="noopener" class="flex items-center gap-1 self-start text-sm text-muted-foreground hover:text-foreground">
                                <ExternalLink class="size-4" /> Open {{ receipt.name }} in a new tab
                            </a>
                        </template>
                        <p v-else class="rounded-lg border p-4 text-sm text-muted-foreground">No receipt attached.</p>
                    </div>

                    <div v-if="selected.can_approve || selected.can_query" class="flex flex-wrap gap-2">
                        <Button v-if="selected.can_approve" :disabled="busy" @click="approve">Approve</Button>
                        <Button v-if="selected.can_query && !showQuery" variant="outline" @click="showQuery = true">Query</Button>
                    </div>

                    <form v-if="showQuery" class="flex flex-col gap-2 rounded-xl border p-4" @submit.prevent="sendQuery">
                        <label for="query" class="text-sm font-medium">What do you need from {{ reconciliation.owner }}?</label>
                        <textarea id="query" v-model="queryForm.body" rows="3" maxlength="2000" :class="textareaClass" />
                        <p v-if="queryForm.errors.body" class="text-sm text-red-600 dark:text-red-400">{{ queryForm.errors.body }}</p>
                        <div class="flex gap-2">
                            <Button type="submit" :disabled="queryForm.processing">Send query</Button>
                            <Button type="button" variant="ghost" @click="showQuery = false">Cancel</Button>
                        </div>
                    </form>

                    <div v-if="selected.comments.length" class="flex flex-col gap-3 rounded-xl border p-4">
                        <h3 class="font-medium">Conversation</h3>
                        <ul class="flex flex-col gap-2">
                            <li
                                v-for="item in selected.comments"
                                :key="item.id"
                                class="max-w-[85%] rounded-lg px-3 py-2 text-sm"
                                :class="item.mine ? 'self-end bg-primary text-primary-foreground' : 'self-start bg-muted'"
                            >
                                <div class="whitespace-pre-line break-words">{{ item.body }}</div>
                                <div class="mt-1 text-xs opacity-75">{{ item.author }} · {{ formatDate(item.created_at) }}</div>
                            </li>
                        </ul>
                        <form v-if="selected.can_comment" class="flex flex-col gap-2" @submit.prevent="sendReply">
                            <textarea v-model="replyForm.body" rows="2" maxlength="2000" :class="textareaClass" placeholder="Reply without changing the line’s status" />
                            <Button type="submit" variant="outline" class="self-end" :disabled="!replyForm.body.trim() || replyForm.processing">Send reply</Button>
                        </form>
                    </div>
                </section>
                <p v-else class="text-sm text-muted-foreground">No lines.</p>
            </div>

            <Link :href="route('admin.periods.show', reconciliation.period.id)" class="text-sm text-muted-foreground hover:text-foreground">← Back to {{ reconciliation.period.label }}</Link>
        </div>
    </AppLayout>
</template>
```

- [ ] **Step 5: Run test to verify it passes and build**

Run: `php artisan test tests/Feature/Admin/ReviewTest.php && npm run build`
Expected: PASS (7 tests); build succeeds.

- [ ] **Step 6: Commit**

```bash
git add -A app routes resources/js tests
git commit -m "feat: review screen with approve, query and approve all"
```

---
### Task 3: Closing a period

**Files:**
- Create: `app/Http/Controllers/Admin/ClosePeriodController.php`
- Modify: `routes/admin.php`
- Test: `tests/Feature/Admin/ClosePeriodTest.php`

**Interfaces:**
- Produces: route `admin.periods.close` (POST). After closing, the Plan 3 policies make every line read-only.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Admin/ClosePeriodTest.php`:

```php
<?php

use App\Enums\PeriodStatus;
use App\Models\Period;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['is_cardholder' => false]);
});

it('closes a published period, making it read-only', function () {
    $sam = User::factory()->create();
    $line = lineFor($sam);

    $this->actingAs($this->admin)->post(route('admin.periods.close', $line->period))
        ->assertRedirect(route('admin.periods.show', $line->period))
        ->assertSessionHas('success');

    expect($line->period->fresh()->status)->toBe(PeriodStatus::Closed)
        ->and($sam->can('update', $line->fresh()))->toBeFalse()
        ->and(Activity::query()->where('event', 'closed')->exists())->toBeTrue();
});

it('only closes published periods', function (Period $period) {
    $this->actingAs($this->admin)->post(route('admin.periods.close', $period))->assertSessionHas('error');
})->with([
    'draft' => fn () => Period::factory()->create(),
    'closed' => fn () => Period::factory()->closed()->create(),
]);

it('keeps closing to admins', function () {
    $period = Period::factory()->published()->create();

    $this->actingAs(User::factory()->create())->post(route('admin.periods.close', $period))->assertForbidden();
    expect($period->fresh()->status)->toBe(PeriodStatus::Published);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Admin/ClosePeriodTest.php`
Expected: FAIL. Route `admin.periods.close` is not defined.

- [ ] **Step 3: Write the controller and route**

`app/Http/Controllers/Admin/ClosePeriodController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PeriodStatus;
use App\Http\Controllers\Controller;
use App\Models\Period;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClosePeriodController extends Controller
{
    public function __invoke(Request $request, Period $period): RedirectResponse
    {
        $error = DB::transaction(function () use ($period, $request) {
            $period = Period::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();

            if ($period->status !== PeriodStatus::Published) {
                return 'Only a published period can be closed.';
            }

            $period->update(['status' => PeriodStatus::Closed]);

            activity('periods')
                ->causedBy($request->user())
                ->performedOn($period)
                ->event('closed')
                ->log("Closed {$period->label}");

            return null;
        });

        if ($error !== null) {
            return back()->with('error', $error);
        }

        return to_route('admin.periods.show', $period)->with('success', "{$period->label} is closed. Everything in it is now read-only.");
    }
}
```

In `routes/admin.php`, add `use App\Http\Controllers\Admin\ClosePeriodController;` and add this after the `periods.publish` route:

```php
    Route::post('periods/{period}/close', ClosePeriodController::class)->name('periods.close');
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Admin/ClosePeriodTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add -A app routes tests
git commit -m "feat: close a period"
```

---

### Task 4: Receipt names and filling in the spreadsheet

**Files:**
- Create: `app/Exports/ReceiptFileNamer.php`, `app/Exports/SpreadsheetFiller.php`
- Modify: `app/Imports/SpreadsheetReader.php` (add `workbook()`, make `delimiter()` public)
- Test: `tests/Unit/Exports/ReceiptFileNamerTest.php`, `tests/Feature/Exports/SpreadsheetFillerTest.php`

**Interfaces:**
- Consumes: `ColumnMap::fromArray()`, `Import` (`stored_path` on the `local` disk, `original_filename`, `header_row`, `column_mapping`).
- Produces:
  - `ReceiptFileNamer::name(int $importOrdinal, int $rowNumber, int $receiptIndex, string $extension): string` and `ReceiptFileNamer::clean(string $name): string`.
  - `SpreadsheetFiller::fill(Import, iterable<Transaction>): string` (the bytes, in the original format), plus `purposeText(Transaction): ?string` and `defuse(string): string`.
  - `SpreadsheetReader::workbook(string $absolutePath, string $originalName): Spreadsheet` and `SpreadsheetReader::delimiter(string $path): string`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Exports/ReceiptFileNamerTest.php`:

```php
<?php

use App\Exports\ReceiptFileNamer;

it('names a receipt after its spreadsheet row', function () {
    expect(ReceiptFileNamer::name(1, 29, 1, 'pdf'))->toBe('Row29.pdf');
});

it('numbers further receipts for the same row', function () {
    expect(ReceiptFileNamer::name(1, 29, 2, 'JPG'))->toBe('Row29-2.jpg')
        ->and(ReceiptFileNamer::name(1, 29, 3, 'png'))->toBe('Row29-3.png');
});

it('prefixes receipts from the second and later imports', function () {
    expect(ReceiptFileNamer::name(2, 29, 1, 'pdf'))->toBe('Import2-Row29.pdf')
        ->and(ReceiptFileNamer::name(3, 5, 2, 'pdf'))->toBe('Import3-Row5-2.pdf');
});

it('makes names safe for folders on any computer', function () {
    expect(ReceiptFileNamer::clean('Sam / O’Neil: "Ops"?'))->toBe('Sam O’Neil Ops')
        ->and(ReceiptFileNamer::clean('September 2026 - Jo Manager'))->toBe('September 2026 - Jo Manager')
        ->and(ReceiptFileNamer::clean(' ... '))->toBe('Untitled');
});
```

`tests/Feature/Exports/SpreadsheetFillerTest.php`:

```php
<?php

use App\Exports\SpreadsheetFiller;
use App\Imports\SpreadsheetReader;
use App\Models\Import;
use App\Models\Receipt;
use App\Models\Transaction;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

beforeEach(fn () => Storage::fake('local'));

/** Read filled bytes back with our own reader (CSV cells stay text). Rows keyed by row number, cells 0-based. */
function readFilled(string $bytes, string $extension): array
{
    $path = tempnam(sys_get_temp_dir(), 'filled').'.'.$extension;
    file_put_contents($path, $bytes);

    try {
        return (new SpreadsheetReader)->read($path, "filled.{$extension}");
    } finally {
        unlink($path);
    }
}

function threeRowCsvImport(): Import
{
    return importFromCsv(statementCsv([
        ['03/10/2026', 'DEPOT CAFE', 'S CARDHOLDER', '1001', '12.50'],
        ['04/10/2026', 'TRAIN', 'S CARDHOLDER', '1001', '45.20'],
        ['05/10/2026', 'HOTEL', 'J MANAGER', '1002', '120.00'],
    ]));
}

it('appends a Purpose column to a CSV and fills only the lines given', function () {
    $import = threeRowCsvImport();
    $cafe = Transaction::factory()->for($import)->create(['row_number' => 2, 'purpose' => 'Supplier meeting']);
    $train = Transaction::factory()->for($import)->create(['row_number' => 3, 'purpose' => 'Site visit', 'no_receipt' => true, 'no_receipt_reason' => 'Ticket machine broken']);
    Transaction::factory()->for($import)->create(['row_number' => 4, 'purpose' => 'Not in this export']);

    $rows = readFilled(app(SpreadsheetFiller::class)->fill($import, [$cafe, $train]), 'csv');

    expect($rows[1][5])->toBe('Purpose')
        ->and($rows[2][5])->toBe('Supplier meeting')
        ->and($rows[3][5])->toBe('Site visit (No receipt: Ticket machine broken)')
        ->and($rows[4][5] ?? null)->toBeNull()
        ->and($rows[2][1])->toBe('DEPOT CAFE')
        ->and($rows[4][4])->toBe('120.00')
        ->and($rows[3][3])->toBe('1001');
});

it('keeps the CSV’s separator and adds a byte-order mark so Excel shows £ correctly', function () {
    $import = importFromCsv("Date;Description;Amount\n03/10/2026;CAFÉ;£12,50\n", [
        'column_mapping' => ['date' => 0, 'description' => 1, 'amount' => 2],
    ]);
    $line = Transaction::factory()->for($import)->create(['row_number' => 2, 'purpose' => 'Coffee for visitors']);

    $bytes = app(SpreadsheetFiller::class)->fill($import, [$line]);

    expect($bytes)->toStartWith("\xEF\xBB\xBF")
        ->toContain('Date;Description;Amount;Purpose')
        ->toContain('CAFÉ;£12,50;Coffee for visitors');
});

it('defuses purposes that a spreadsheet app would run as a formula', function () {
    expect(SpreadsheetFiller::defuse('=HYPERLINK("http://example.test")'))->toBe('\'=HYPERLINK("http://example.test")')
        ->and(SpreadsheetFiller::defuse('+44 call'))->toBe("'+44 call")
        ->and(SpreadsheetFiller::defuse('@SUM(A1)'))->toBe("'@SUM(A1)")
        ->and(SpreadsheetFiller::defuse('-£5 refund note'))->toBe('-£5 refund note')
        ->and(SpreadsheetFiller::defuse('Client lunch'))->toBe('Client lunch');
});

it('leaves out the no-receipt note when a receipt was attached anyway', function () {
    $line = lineFor(\App\Models\User::factory()->create(), ['purpose' => 'Parking', 'no_receipt' => true, 'no_receipt_reason' => 'Lost']);
    Receipt::factory()->attachedTo($line)->create();

    expect(SpreadsheetFiller::purposeText($line))->toBe('Parking')
        ->and(SpreadsheetFiller::purposeText(new Transaction(['purpose' => '  '])))->toBeNull();
});

it('writes into a workbook’s mapped purpose column as plain text, leaving everything else alone', function () {
    $book = new Spreadsheet;
    $book->getActiveSheet()->fromArray([
        ['Amex statement'],
        ['Date', 'Description', 'Amount', 'Notes'],
        ['03/10/2026', 'DEPOT CAFE', 12.5, null],
        ['04/10/2026', 'FORMULA SHOP', 9.99, null],
    ]);
    $source = tempnam(sys_get_temp_dir(), 'book').'.xlsx';
    (new Xlsx($book))->save($source);

    $import = Import::factory()->create([
        'original_filename' => 'statement.xlsx',
        'stored_path' => 'imports/statement.xlsx',
        'header_row' => 2,
        'column_mapping' => ['date' => 0, 'description' => 1, 'amount' => 2, 'purpose' => 3],
    ]);
    Storage::disk('local')->put($import->stored_path, file_get_contents($source));
    $cafe = Transaction::factory()->for($import)->create(['row_number' => 3, 'purpose' => 'Supplier meeting']);
    $shop = Transaction::factory()->for($import)->create(['row_number' => 4, 'purpose' => '=1+1']);

    $out = tempnam(sys_get_temp_dir(), 'out').'.xlsx';
    file_put_contents($out, app(SpreadsheetFiller::class)->fill($import, [$cafe, $shop]));
    $sheet = IOFactory::load($out)->getSheet(0);

    expect($sheet->getCell('D3')->getValue())->toBe('Supplier meeting')
        ->and($sheet->getCell('D4')->getValue())->toBe('=1+1')
        ->and($sheet->getCell('D4')->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($sheet->getCell('A1')->getValue())->toBe('Amex statement')
        ->and($sheet->getCell('C3')->getValue())->toBe(12.5)
        ->and($sheet->getCell('E2')->getValue())->toBeNull();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Unit/Exports tests/Feature/Exports/SpreadsheetFillerTest.php`
Expected: FAIL. Class `App\Exports\ReceiptFileNamer` is not found.

- [ ] **Step 3: Write the namer, the reader changes and the filler**

`app/Exports/ReceiptFileNamer.php`:

```php
<?php

namespace App\Exports;

/** Today's naming: Row29.pdf, Row29-2.pdf, and Import2-Row29.pdf for a period's later imports. */
final class ReceiptFileNamer
{
    public static function name(int $importOrdinal, int $rowNumber, int $receiptIndex, string $extension): string
    {
        $prefix = $importOrdinal > 1 ? "Import{$importOrdinal}-" : '';
        $suffix = $receiptIndex > 1 ? "-{$receiptIndex}" : '';

        return "{$prefix}Row{$rowNumber}{$suffix}.".strtolower($extension);
    }

    /** Safe as a folder or file name inside a ZIP opened on Windows or a Mac. */
    public static function clean(string $name): string
    {
        $clean = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', ' ', $name);
        $clean = trim(preg_replace('/\s+/u', ' ', (string) $clean));
        $clean = rtrim($clean, ' .');

        return $clean === '' ? 'Untitled' : $clean;
    }
}
```

`app/Imports/SpreadsheetReader.php` (whole file, replacing the current one):

```php
<?php

namespace App\Imports;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
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
        $reader = $this->readerFor($extension, $absolutePath);

        $reader->setReadDataOnly(true);
        $this->guardSize($reader, $absolutePath);

        $book = $this->load($reader, $absolutePath);
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

    /**
     * The whole workbook, formatting included, for writing purposes back into a copy (exports).
     * Rows keep the same numbers as read() gives them.
     *
     * @throws UnreadableSpreadsheet
     */
    public function workbook(string $absolutePath, string $originalName): Spreadsheet
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        return $this->load($this->readerFor($extension, $absolutePath), $absolutePath);
    }

    /**
     * PhpSpreadsheet's own guess can pick a space when a title line sits above the headers,
     * so choose between the separators exports really use by counting them in the first lines.
     */
    public static function delimiter(string $path): string
    {
        $sample = (string) file_get_contents($path, length: 16384);
        $counts = [];

        foreach ([',', ';', "\t", '|'] as $candidate) {
            $counts[$candidate] = substr_count($sample, $candidate);
        }

        arsort($counts);

        return reset($counts) > 0 ? array_key_first($counts) : ',';
    }

    private function readerFor(string $extension, string $path): Csv|Xlsx|Xls
    {
        return match ($extension) {
            'csv', 'txt' => $this->csvReader($path),
            'xlsx' => new Xlsx,
            'xls' => new Xls,
            default => throw new UnreadableSpreadsheet('Upload a CSV or Excel file (.csv, .xlsx or .xls).'),
        };
    }

    /** Count rows without loading the cells, so a huge file is refused instead of exhausting memory. */
    private function guardSize(Csv|Xlsx|Xls $reader, string $path): void
    {
        try {
            $rows = $reader->listWorksheetInfo($path)[0]['totalRows'] ?? 0;
        } catch (Throwable $e) {
            throw new UnreadableSpreadsheet("We couldn't read this file. Check it opens in Excel and try again.", previous: $e);
        }

        if ($rows > self::MAX_ROWS + 1) {
            throw new UnreadableSpreadsheet('This file has more than '.self::MAX_ROWS.' rows. Split it into smaller files.');
        }
    }

    private function csvReader(string $path): Csv
    {
        $reader = new Csv;
        $reader->setInputEncoding(Csv::GUESS_ENCODING);
        $reader->setFallbackEncoding('CP1252');
        $reader->setDelimiter(self::delimiter($path));

        return $reader;
    }

    private function load(IReader $reader, string $path): Spreadsheet
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

`app/Exports/SpreadsheetFiller.php`:

```php
<?php

namespace App\Exports;

use App\Imports\ColumnMap;
use App\Imports\SpreadsheetReader;
use App\Models\Import;
use App\Models\Transaction;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Writes purposes into a copy of an import's original file. Rows are never added, removed or reordered. */
final class SpreadsheetFiller
{
    public function __construct(private SpreadsheetReader $reader) {}

    /**
     * @param  iterable<Transaction>  $lines  lines from this import whose purposes should be written in
     * @return string the filled file, in the same format as the original
     */
    public function fill(Import $import, iterable $lines): string
    {
        $path = Storage::disk('local')->path($import->stored_path);
        $extension = strtolower(pathinfo($import->original_filename, PATHINFO_EXTENSION));
        $csv = in_array($extension, ['csv', 'txt'], true);

        $book = $this->reader->workbook($path, $import->original_filename);
        $sheet = $book->getSheet(0);
        $map = ColumnMap::fromArray($import->column_mapping);
        $column = $map->purpose !== null ? $map->purpose + 1 : $this->appendPurposeColumn($sheet, $import->header_row);

        foreach ($lines as $line) {
            $text = self::purposeText($line);

            if ($text !== null) {
                $sheet->setCellValueExplicit([$column, $line->row_number], $csv ? self::defuse($text) : $text, DataType::TYPE_STRING);
            }
        }

        $writer = match (true) {
            $csv => (new Csv($book))->setDelimiter(SpreadsheetReader::delimiter($path))->setEnclosureRequired(false)->setUseBOM(true),
            $extension === 'xls' => new Xls($book),
            default => new Xlsx($book),
        };

        $temp = tempnam(sys_get_temp_dir(), 'fill');

        try {
            $writer->save($temp);

            return (string) file_get_contents($temp);
        } finally {
            @unlink($temp);
            $book->disconnectWorksheets();
        }
    }

    /** "Client lunch", or "Parking (No receipt: machine broken)" when the line has no receipt. Null without a purpose. */
    public static function purposeText(Transaction $line): ?string
    {
        $purpose = trim((string) $line->purpose);

        if ($purpose === '') {
            return null;
        }

        $reason = trim((string) $line->no_receipt_reason);
        $hasReceipt = ($line->receipts_count ?? ($line->exists ? $line->receipts()->count() : 0)) > 0;

        return $line->no_receipt && $reason !== '' && ! $hasReceipt ? "{$purpose} (No receipt: {$reason})" : $purpose;
    }

    /** Stop a spreadsheet app treating text in a CSV as a formula when it is opened. */
    public static function defuse(string $text): string
    {
        return preg_match('/^[=+@\t\r]/', $text) ? "'".$text : $text;
    }

    private function appendPurposeColumn(Worksheet $sheet, int $headerRow): int
    {
        $column = Coordinate::columnIndexFromString($sheet->getHighestDataColumn()) + 1;
        $sheet->setCellValueExplicit([$column, $headerRow], 'Purpose', DataType::TYPE_STRING);

        return $column;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Unit tests/Feature/Exports/SpreadsheetFillerTest.php tests/Feature/Imports tests/Feature/Admin/ImportWizardTest.php`
Expected: PASS (the reader refactor keeps the import tests green).

- [ ] **Step 5: Commit**

```bash
git add -A app tests
git commit -m "feat: export file naming and writing purposes into the original spreadsheet"
```

---

### Task 5: Building, downloading and pruning exports

**Files:**
- Create: `app/Enums/ExportStatus.php`, `app/Models/Export.php`, `database/migrations/2026_10_13_000001_create_exports_table.php`, `database/factories/ExportFactory.php`, `app/Events/ExportReady.php`, `app/Events/ExportFailed.php`, `app/Exports/ExportBuilder.php`, `app/Jobs/BuildExport.php`, `app/Http/Controllers/Admin/ExportController.php`
- Modify: `app/Models/Period.php` (`exports()`), `app/Periods/PeriodDashboard.php` (`exports`), `routes/admin.php`, `routes/console.php`, `resources/js/pages/admin/periods/Show.vue`
- Test: `tests/Feature/Exports/ExportTest.php`

**Interfaces:**
- Consumes: `SpreadsheetFiller` and `ReceiptFileNamer` (Task 4); review and close routes (Tasks 2–3) for the dashboard buttons.
- Produces:
  - `Export` model (`period_id`, `user_id` nullable = whole period, `requested_by`, `status`, `path`, `filename`, `error`, `completed_at`).
  - `ExportBuilder::build(Export): string` (path to a temp ZIP), `BuildExport` job, and events `ExportReady(Export)` and `ExportFailed(Export)`.
  - Routes `admin.exports.store` (POST, optional `user_id`) and `admin.exports.show` (download).
  - Command `exports:prune` (daily).
  - Dashboard prop `exports[]` with `id, status, scope, filename, error, requested_by, created_at, download_url`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Exports/ExportTest.php`:

```php
<?php

use App\Enums\ExportStatus;
use App\Enums\ImportStatus;
use App\Events\ExportFailed;
use App\Events\ExportReady;
use App\Jobs\BuildExport;
use App\Models\Export;
use App\Models\Import;
use App\Models\Period;
use App\Models\Receipt;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Contracts\Bus\Dispatcher;
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Exports/ExportTest.php`
Expected: FAIL. Class `App\Enums\ExportStatus` is not found.

- [ ] **Step 3: Write the model, builder, job, controller, routes and command**

`database/migrations/2026_10_13_000001_create_exports_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->string('path')->nullable();
            $table->string('filename')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exports');
    }
};
```

`app/Enums/ExportStatus.php`:

```php
<?php

namespace App\Enums;

enum ExportStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';
}
```

`app/Models/Export.php`:

```php
<?php

namespace App\Models;

use App\Enums\ExportStatus;
use Database\Factories\ExportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** A ZIP of a period (or one manager's part of it), built in the background and kept for 7 days. */
class Export extends Model
{
    /** @use HasFactory<ExportFactory> */
    use HasFactory;

    protected $fillable = ['period_id', 'user_id', 'requested_by', 'status', 'path', 'filename', 'error', 'completed_at'];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'status' => ExportStatus::class,
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleted(function (Export $export) {
            if ($export->path) {
                Storage::disk('local')->deleteDirectory(dirname($export->path));
            }
        });
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    /** The manager this export is limited to; null for the whole period. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
```

`database/factories/ExportFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Export;
use App\Models\Period;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Export>
 */
class ExportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'period_id' => Period::factory()->published(),
            'user_id' => null,
            'requested_by' => User::factory()->admin(),
        ];
    }
}
```

In `app/Models/Period.php`, add after `unassignedTransactions()`:

```php
    public function exports(): HasMany
    {
        return $this->hasMany(Export::class);
    }
```

`app/Events/ExportReady.php`:

```php
<?php

namespace App\Events;

use App\Models\Export;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** An export finished. Plan 5 tells the admin who asked for it. */
class ExportReady implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Export $export) {}
}
```

`app/Events/ExportFailed.php`:

```php
<?php

namespace App\Events;

use App\Models\Export;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** An export gave up after its retries. Plan 5 tells the admin who asked for it. */
class ExportFailed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Export $export) {}
}
```

`app/Exports/ExportBuilder.php`:

```php
<?php

namespace App\Exports;

use App\Enums\ImportStatus;
use App\Models\Export;
use App\Models\Import;
use App\Models\Receipt;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/** Builds an export's ZIP in today's layout: filled spreadsheet(s) plus receipts named by row. */
final class ExportBuilder
{
    public function __construct(private SpreadsheetFiller $filler) {}

    /** @return string path to a temporary ZIP file; the caller moves or deletes it */
    public function build(Export $export): string
    {
        $period = $export->period;
        $imports = $period->imports()->where('status', ImportStatus::Confirmed)->orderBy('id')->get()->values();
        $ordinals = $imports->mapWithKeys(fn (Import $import, int $index) => [$import->id => $index + 1]);

        $lines = $period->transactions()
            ->with(['reconciliation.user:id,name', 'receipts'])
            ->withCount('receipts')
            ->when($export->user_id, fn ($query) => $query->whereHas('reconciliation', fn ($query) => $query->where('user_id', $export->user_id)))
            ->orderBy('import_id')
            ->orderBy('row_number')
            ->get();

        $root = $export->user_id ? ReceiptFileNamer::clean("{$period->label} - {$export->user->name}").'/' : '';
        $zipPath = tempnam(sys_get_temp_dir(), 'export');
        $temporary = [];
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Couldn’t create the ZIP file.');
        }

        try {
            foreach ($imports as $import) {
                $name = $this->spreadsheetName($import, $imports, $ordinals[$import->id]);
                $zip->addFromString($root.$name, $this->filler->fill($import, $lines->where('import_id', $import->id)));
            }

            foreach ($lines->whereNotNull('reconciliation_id') as $line) {
                $folder = $root !== '' ? $root : ReceiptFileNamer::clean($line->reconciliation->user->name).'/';

                foreach ($line->receipts->values() as $index => $receipt) {
                    $name = ReceiptFileNamer::name($ordinals[$line->import_id], $line->row_number, $index + 1, pathinfo($receipt->path, PATHINFO_EXTENSION));
                    $zip->addFile($this->localCopy($receipt, $line, $temporary), $folder.$name);
                }
            }

            $zip->close();
        } finally {
            foreach ($temporary as $file) {
                @unlink($file);
            }
        }

        return $zipPath;
    }

    /** The original file name; when two imports share one, later ones get "Import2 - ". */
    private function spreadsheetName(Import $import, Collection $imports, int $ordinal): string
    {
        $name = ReceiptFileNamer::clean($import->original_filename);
        $shared = $imports->filter(fn (Import $other) => ReceiptFileNamer::clean($other->original_filename) === $name)->count() > 1;

        return $shared && $ordinal > 1 ? "Import{$ordinal} - {$name}" : $name;
    }

    /** A local path ZipArchive can read when it closes. Files on remote disks are copied to a temp file first. */
    private function localCopy(Receipt $receipt, Transaction $line, array &$temporary): string
    {
        $disk = Storage::disk($receipt->disk);

        if (! $disk->exists($receipt->path)) {
            throw new RuntimeException("The receipt for row {$line->row_number} ({$line->reconciliation->user->name}) is missing from storage.");
        }

        $path = $disk->path($receipt->path);

        if (is_file($path)) {
            return $path;
        }

        $copy = tempnam(sys_get_temp_dir(), 'receipt');
        file_put_contents($copy, $disk->get($receipt->path));
        $temporary[] = $copy;

        return $copy;
    }
}
```

`app/Jobs/BuildExport.php`:

```php
<?php

namespace App\Jobs;

use App\Enums\ExportStatus;
use App\Events\ExportFailed;
use App\Events\ExportReady;
use App\Exports\ExportBuilder;
use App\Exports\ReceiptFileNamer;
use App\Models\Export;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class BuildExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(public Export $export) {}

    public function handle(ExportBuilder $builder): void
    {
        $export = $this->export;
        $zip = $builder->build($export);

        try {
            $filename = ReceiptFileNamer::clean($export->user ? "{$export->period->label} - {$export->user->name}" : $export->period->label).'.zip';
            $path = Storage::disk('local')->putFileAs("exports/{$export->id}", new File($zip), Str::uuid().'.zip');
        } finally {
            @unlink($zip);
        }

        $export->update([
            'status' => ExportStatus::Ready,
            'path' => $path,
            'filename' => $filename,
            'error' => null,
            'completed_at' => now(),
        ]);

        ExportReady::dispatch($export);

        activity('exports')
            ->causedBy($export->requester)
            ->performedOn($export->period)
            ->event('exported')
            ->withProperties(['export_id' => $export->id, 'user_id' => $export->user_id])
            ->log("Exported {$filename}");
    }

    public function failed(?Throwable $exception): void
    {
        $this->export->update([
            'status' => ExportStatus::Failed,
            'error' => Str::limit($exception?->getMessage() ?? 'Unknown error.', 500),
            'completed_at' => now(),
        ]);

        ExportFailed::dispatch($this->export);
    }
}
```

`app/Http/Controllers/Admin/ExportController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ExportStatus;
use App\Enums\PeriodStatus;
use App\Http\Controllers\Controller;
use App\Jobs\BuildExport;
use App\Models\Export;
use App\Models\Period;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function store(Request $request, Period $period): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'integer', Rule::exists('reconciliations', 'user_id')->where('period_id', $period->id)],
        ]);

        if ($period->status === PeriodStatus::Draft) {
            return back()->with('error', 'Publish the period before exporting it.');
        }

        $export = $period->exports()->create([
            'user_id' => $data['user_id'] ?? null,
            'requested_by' => $request->user()->id,
        ]);

        BuildExport::dispatch($export);

        return back()->with('success', 'Building the export. It will appear under Exports when it’s ready.');
    }

    public function show(Export $export): StreamedResponse
    {
        abort_unless($export->status === ExportStatus::Ready && $export->path && Storage::disk('local')->exists($export->path), 404);

        return Storage::disk('local')->download($export->path, $export->filename);
    }
}
```

In `routes/admin.php`, add `use App\Http\Controllers\Admin\ExportController;` and add at the end of the admin group:

```php
    Route::post('periods/{period}/exports', [ExportController::class, 'store'])->middleware('throttle:20,1')->name('exports.store');
    Route::get('exports/{export}', [ExportController::class, 'show'])->name('exports.show');
```

`routes/console.php` (whole file, replacing the current one):

```php
<?php

use App\Models\Export;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('exports:prune', function () {
    $old = Export::query()->where('created_at', '<', now()->subDays(7))->get();
    $old->each->delete();
    $this->info("Removed {$old->count()} old exports.");
})->purpose('Delete exports older than 7 days');

Schedule::command('exports:prune')->daily();
```

In `app/Periods/PeriodDashboard.php`, add `use App\Enums\ExportStatus;` and `use App\Models\Export;`, then add this key to the returned array after `'imports'`:

```php
            'exports' => $period->exports()->with(['user:id,name', 'requester:id,name'])->latest('id')->limit(10)->get()->map(fn (Export $export) => [
                'id' => $export->id,
                'status' => $export->status->value,
                'scope' => $export->user?->name ?? 'Whole period',
                'filename' => $export->filename,
                'error' => $export->error,
                'requested_by' => $export->requester->name,
                'created_at' => $export->created_at->toIso8601String(),
                'download_url' => $export->status === ExportStatus::Ready ? route('admin.exports.show', $export) : null,
            ])->all(),
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Exports tests/Feature/Admin`
Expected: PASS.

- [ ] **Step 5: Update the period dashboard screen**

`resources/js/pages/admin/periods/Show.vue` (whole file, replacing the current one):

```vue
<script setup lang="ts">
import FlashMessage from '@/components/FlashMessage.vue';
import PeriodStatusBadge from '@/components/PeriodStatusBadge.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatPence } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, watch } from 'vue';

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

interface DashboardExport {
    id: number;
    status: 'pending' | 'ready' | 'failed';
    scope: string;
    filename: string | null;
    error: string | null;
    requested_by: string;
    created_at: string;
    download_url: string | null;
}

const props = defineProps<{
    period: DashboardPeriod;
    reconciliations: DashboardReconciliation[];
    imports: DashboardImport[];
    exports: DashboardExport[];
    unassigned_count: number;
    totals: { lines: number; amount: number };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Periods', href: '/admin/periods' },
    { title: props.period.label, href: `/admin/periods/${props.period.id}` },
];

const canExport = computed(() => props.period.status !== 'draft' && props.totals.lines > 0);

const removeImport = (upload: DashboardImport) => {
    if (window.confirm(`Remove ${upload.original_filename}? Any lines it created will be deleted.`)) {
        router.delete(route('admin.imports.destroy', upload.id), { preserveScroll: true });
    }
};

const publish = () => {
    const warning = props.unassigned_count > 0
        ? `\n\n${props.unassigned_count} unassigned ${props.unassigned_count === 1 ? 'line' : 'lines'} won't go to anyone until you assign them.`
        : '';

    if (window.confirm(`Publish ${props.period.label}? Cardholders will be able to see and complete their lines.${warning}`)) {
        router.post(route('admin.periods.publish', props.period.id), {}, { preserveScroll: true });
    }
};

const close = () => {
    const open = props.reconciliations.filter((row) => row.status !== 'approved').length;
    const warning = open > 0 ? `\n\n${open} ${open === 1 ? 'person hasn’t' : 'people haven’t'} been fully approved yet.` : '';

    if (window.confirm(`Close ${props.period.label}? Nobody will be able to change anything in it.${warning}`)) {
        router.post(route('admin.periods.close', props.period.id), {}, { preserveScroll: true });
    }
};

const exportFor = (userId: number | null = null) => {
    router.post(route('admin.exports.store', props.period.id), userId ? { user_id: userId } : {}, { preserveScroll: true });
};

// Exports build in the background: refresh the list until none are pending.
let poll: ReturnType<typeof setInterval> | undefined;
watch(
    () => props.exports.some((item) => item.status === 'pending'),
    (pending) => {
        clearInterval(poll);
        poll = pending ? setInterval(() => router.reload({ only: ['exports'] }), 3000) : undefined;
    },
    { immediate: true },
);
onBeforeUnmount(() => clearInterval(poll));

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
                    <Button v-if="period.status === 'draft' && imports.some((upload) => upload.status === 'confirmed')" @click="publish">Publish</Button>
                    <Button v-if="period.status !== 'closed'" as-child>
                        <Link :href="route('admin.imports.create', period.id)">Upload statement</Link>
                    </Button>
                    <Button v-if="canExport" variant="outline" @click="exportFor()">Export all</Button>
                    <Button v-if="totals.lines > 0" variant="outline" as-child>
                        <Link :href="route('admin.periods.lines', period.id)">Lines</Link>
                    </Button>
                    <Button v-if="period.status !== 'closed'" variant="outline" as-child>
                        <Link :href="route('admin.periods.edit', period.id)">Edit</Link>
                    </Button>
                    <Button v-if="period.status === 'published'" variant="outline" @click="close">Close period</Button>
                </div>
            </header>

            <section v-if="unassigned_count > 0" data-unassigned class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
                {{ unassigned_count }} {{ unassigned_count === 1 ? 'line is' : 'lines are' }} not matched to anyone yet.
                <Link :href="route('admin.periods.lines', period.id)" class="ml-1 font-medium underline underline-offset-4">Assign them</Link>
            </section>

            <section class="flex flex-col gap-2">
                <h2 class="font-medium">Cardholders</h2>

                <ul class="divide-y rounded-lg border">
                    <li v-for="row in reconciliations" :key="row.id" class="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <div class="font-medium">
                                {{ row.user.name }}
                                <span v-if="!row.user.active" class="ml-1 text-xs font-normal text-red-600">Inactive: reassign their lines</span>
                            </div>
                            <div class="text-sm text-muted-foreground">
                                {{ row.done }} of {{ row.lines }} done<span v-if="row.queried"> · {{ row.queried }} queried</span>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <span v-if="row.overdue" class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800 dark:bg-red-950 dark:text-red-200">Overdue</span>
                            <span>{{ statusLabel(row) }}</span>
                            <Button v-if="period.status !== 'draft'" size="sm" :variant="row.status === 'submitted' ? 'default' : 'outline'" as-child>
                                <Link :href="route('admin.reconciliations.review', row.id)">Review</Link>
                            </Button>
                            <Button v-if="canExport" size="sm" variant="ghost" @click="exportFor(row.user.id)">Export</Button>
                        </div>
                    </li>
                    <li v-if="!reconciliations.length" class="p-4 text-sm text-muted-foreground">
                        No lines assigned yet. Upload the statement to get started.
                    </li>
                </ul>
            </section>

            <section v-if="exports.length" data-exports class="flex flex-col gap-2">
                <h2 class="font-medium">Exports</h2>
                <p class="text-xs text-muted-foreground">Exports are kept for 7 days.</p>

                <ul class="divide-y rounded-lg border">
                    <li v-for="item in exports" :key="item.id" class="flex items-center justify-between gap-3 p-4 text-sm">
                        <div class="min-w-0">
                            <div class="font-medium">{{ item.scope }}</div>
                            <div class="text-muted-foreground">{{ item.requested_by }} · {{ formatDate(item.created_at) }}</div>
                            <div v-if="item.status === 'failed'" class="text-red-600 dark:text-red-400">Failed: {{ item.error }}</div>
                        </div>
                        <a v-if="item.download_url" :href="item.download_url" class="shrink-0 font-medium underline underline-offset-4">Download</a>
                        <span v-else-if="item.status === 'pending'" class="shrink-0 text-muted-foreground">Building…</span>
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
                        <div class="flex shrink-0 gap-2">
                            <Button v-if="upload.status === 'draft'" size="sm" variant="outline" as-child>
                                <Link :href="route('admin.imports.preview', upload.id)">Continue</Link>
                            </Button>
                            <Button v-if="upload.status === 'draft' || period.status === 'draft'" size="sm" variant="ghost" @click="removeImport(upload)">
                                Remove
                            </Button>
                        </div>
                    </li>
                    <li v-if="!imports.length" class="p-4 text-sm text-muted-foreground">No files uploaded yet.</li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
```

- [ ] **Step 6: Run the full suite, build and commit**

Run: `php artisan test && npm run build`
Expected: all pass; build succeeds.

```bash
git add -A app database routes resources/js tests
git commit -m "feat: build, download and prune ZIP exports"
```
