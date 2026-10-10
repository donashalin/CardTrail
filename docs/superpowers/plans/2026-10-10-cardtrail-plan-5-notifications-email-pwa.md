# CardTrail Plan 5 — Notifications, Email-in and PWA Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** CardTrail does the chasing. It emails people and, on devices that opted in, sends push notifications when:
- a month is published or new lines arrive;
- a deadline is near or an accountant nudges;
- accounts query a line, or a cardholder submits;
- an export finishes or fails.

Receipts emailed to the receipts address land in the sender's inbox. The app installs to an iPhone Home Screen from Safari.

**Architecture:**
- **Events to notifications.** One listener class (`App\Listeners\SendNotifications`) turns the domain events from Plans 2–4 into queued notifications. All of them extend `CardTrailNotification`, which sends mail always and web push when the user has a push subscription (`laravel-notification-channels/webpush`).
- **Reminders and nudges.** A daily `reminders:send` command handles deadline reminders and records each one in `reminder_sends`, so nothing is sent twice.
- **Email-in.** This goes through the spec's `InboundMailSource` interface. The `eml` driver reads `.eml` files, and `ReceiptIngestor` records an `InboundMessage` and stores accepted attachments through Plan 3's `ReceiptStore::storeContents()`.
- **PWA.** A static web app manifest and a small hand-written service worker in `public/`, plus a one-time prompt to enable notifications.

**Tech Stack:** Laravel 12, Inertia 2, Vue 3 + TypeScript, Pest 3, `laravel-notification-channels/webpush` ^13 (VAPID), `zbateson/mail-mime-parser` ^4, axios.

**Spec:** `docs/superpowers/specs/2026-10-10-cardtrail-design.md`. This plan covers §4.3, §5 InboundMessage, §6.4, §6.7, §6.8, §7 screens 5 (nudge), 10 and 11, and §8 inbound problems.

**Builds on:** Plans 1–4 on `main`. Existing pieces this plan uses:
- Events: `PeriodPublished(Period)`, `ReconciliationLinesAdded(Reconciliation, int $count)`, `LineQueried(Transaction, Comment)`, `ReconciliationSubmitted(Reconciliation, bool $resubmitted)`, `ExportReady(Export)`, `ExportFailed(Export)`.
- Receipts and models: `ReceiptStore::storeContents(string $contents, string $originalName, User, ReceiptSource, ?int $inboundMessageId): ?Receipt`, `ReceiptSource::Email`, `Receipt.inbound_message_id`, `User::scopeActive()`, `ColumnMapping` (`name`, `header_signature`).
- Pages and config: `PeriodController::create()` (default reminder offsets string), `admin/periods/Show.vue`, `HandleInertiaRequests::share()`, `config/cardtrail.php`.

## Global Constraints

- Everything in Plans 1–4's Global Constraints still applies: git author `donashalin <donashalin@gmail.com>`, never commit `.env`, invented data only, UK English.
- Commit messages are plain. No Co-Authored-By, session or other AI attribution lines. The user is the sole author.
- Notifications go to these people:

  | Event | Recipient |
  |---|---|
  | Period published / new lines | Cardholders with lines |
  | Deadline reminder (`reminder_offsets` days before the due date, daily; only incomplete reconciliations) | Cardholder |
  | Manual nudge | Cardholder |
  | Line queried | Cardholder |
  | Submitted / resubmitted | All admins |
  | Export ready / failed | Requesting admin |

- Inactive users never receive notifications.
- Email-in: the sender must match an active cardholder's email, compared case-insensitively. Accepted attachment types become inbox receipts with `source = email`.
  - Unknown sender, no attachments, or only rejected types are recorded in `InboundMessage` with that outcome. Nobody gets a bounce reply.
  - Processing is idempotent on `message_id`.
- No real Crown mailbox, tenant, hostname or credential appears anywhere. The Microsoft Graph driver is out of scope (spec §11); only the interface and the `eml` driver are built.
- VAPID keys live only in `.env`, generated with `php artisan webpush:vapid`. `.env.example` gets empty placeholders.
- Web push needs HTTPS (or `localhost`) and, on iPhone, an app installed to the Home Screen. Email always works.

## Review Focus

1. **The same email processed twice** (re-running the command, overlapping schedules). It must create its receipts once. Pinned in Task 4.
2. **An email from someone who has left, or from an address that differs only in capitals.** A leaver's mail goes to "unknown sender" and never becomes a receipt. `SAM@…` matches `sam@…`. Pinned in Task 4.
3. **The reminder command run twice in a day, or a month already submitted.** Nobody is reminded twice for the same day, and finished people aren't nagged. Pinned in Task 3.
4. **An admin submitting their own cardholder month.** They shouldn't be notified about their own submission, but every other active admin should. Pinned in Task 2.
5. **A notification tap that tries to open another site.** The service worker only opens CardTrail's own pages. Pinned in Task 6 (asserted in the worker source).

## Rulings made while planning

- **No `vite-plugin-pwa`.** The spec names it, but v1 has no offline caching (§6.8), and the plugin's output lives under `/build/`, where a service worker can't control `/`. A static `public/manifest.webmanifest` and a hand-written `public/sw.js` give the same manifest and push handling with nothing to configure.
- **One listener class with a method per event**, found by Laravel's event discovery, keeps the event → recipient table in one readable file.
- **Mixed emails count as accepted.** An email with some accepted and some rejected attachments is recorded as `accepted`, and `accepted_count` shows how many receipts were made.
- **Settings live in a `settings` key/value table.** It holds the default reminder offsets for new periods. Saved column mappings already have their own table and are only listed, renamed and deleted on the Settings page.

---

## File Structure

```
app/
  Console/Commands/{IngestReceipts,SendDeadlineReminders}.php
  Enums/InboundOutcome.php
  Http/Controllers/PushSubscriptionController.php
  Http/Controllers/Admin/{NudgeController,InboundMessageController,SettingsController,ColumnMappingController}.php
  Http/Middleware/HandleInertiaRequests.php          # + push.vapid_public_key
  Listeners/SendNotifications.php
  Mail/Inbound/{InboundMailSource,InboundEmail,InboundAttachment,EmlMailSource,ReceiptIngestor}.php
  Models/{InboundMessage,ReminderSend,Setting}.php
  Models/User.php                                    # + HasPushSubscriptions
  Notifications/{CardTrailNotification,MonthReady,LinesAdded,DeadlineReminder,Nudge,LineQueriedNotification,ReconciliationSubmittedNotification,ExportReadyNotification,ExportFailedNotification}.php
  Providers/AppServiceProvider.php                   # InboundMailSource binding
config/cardtrail.php                                 # + inbound
config/webpush.php                                   # published
database/migrations/2026_10_14_00000{1..4}_*.php     # push_subscriptions, reminder_sends, inbound_messages, settings
public/manifest.webmanifest, public/sw.js, public/icons/*.png
resources/demo/demo-receipt.eml
resources/views/app.blade.php
resources/js/app.ts                                  # register service worker
resources/js/components/EnablePush.vue
resources/js/pages/admin/inbound/Index.vue, resources/js/pages/admin/settings/Edit.vue
routes/web.php, routes/admin.php, routes/console.php
tests/Feature/Notifications/{PushSubscriptionTest,NotificationsTest,RemindersTest}.php
tests/Feature/Receipts/EmailInTest.php
tests/Feature/Admin/{InboundLogTest,SettingsTest}.php
tests/Feature/PwaTest.php
```

---

### Task 1: Notification channels and push subscriptions

**Files:**
- Create: `config/webpush.php` (published), `database/migrations/2026_10_14_000001_create_push_subscriptions_table.php`, `app/Notifications/CardTrailNotification.php`, `app/Http/Controllers/PushSubscriptionController.php`
- Modify: `composer.json` (packages, already required while planning), `app/Models/User.php`, `app/Http/Middleware/HandleInertiaRequests.php`, `resources/js/types/index.ts`, `routes/web.php`, `.env.example`
- Test: `tests/Feature/Notifications/PushSubscriptionTest.php`

**Interfaces:**
- Produces:
  - `CardTrailNotification`, an abstract queued notification. Subclasses implement `subject(): string`, `lines(): list<string>` (the first line is the push body) and `url(): string`, and may override `action(): string`.
  - `via()` returns `['mail']`, or `['mail', WebPushChannel::class]` when the user has a push subscription.
  - The `User` push-subscription methods.
  - Routes `push-subscriptions.store` (POST JSON `endpoint`, `keys.p256dh`, `keys.auth`, `content_encoding`) and `push-subscriptions.destroy` (DELETE JSON `endpoint`).
  - Shared Inertia prop `push.vapid_public_key`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Notifications/PushSubscriptionTest.php`:

```php
<?php

use App\Models\User;
use App\Notifications\CardTrailNotification;
use NotificationChannels\WebPush\WebPushChannel;

function subscriptionPayload(string $endpoint = 'https://push.example.test/send/abc'): array
{
    return ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'BPublicKeyInvented', 'auth' => 'AuthInvented'], 'content_encoding' => 'aes128gcm'];
}

it('stores a device’s push subscription for the signed-in user', function () {
    $sam = User::factory()->create();

    $this->actingAs($sam)->postJson(route('push-subscriptions.store'), subscriptionPayload())->assertNoContent();

    expect($sam->pushSubscriptions()->sole())
        ->endpoint->toBe('https://push.example.test/send/abc')
        ->public_key->toBe('BPublicKeyInvented');
});

it('refuses subscriptions that are not secure push endpoints', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('push-subscriptions.store'), subscriptionPayload('http://push.example.test/x'))
        ->assertUnprocessable();
});

it('removes a subscription when the device opts out', function () {
    $sam = User::factory()->create();
    $sam->updatePushSubscription('https://push.example.test/send/abc', 'k', 't', 'aes128gcm');

    $this->actingAs($sam)->deleteJson(route('push-subscriptions.destroy'), ['endpoint' => 'https://push.example.test/send/abc'])->assertNoContent();

    expect($sam->pushSubscriptions()->count())->toBe(0);
});

it('needs sign-in', function () {
    $this->postJson(route('push-subscriptions.store'), subscriptionPayload())->assertUnauthorized();
});

it('emails everyone and pushes only to people with a subscribed device', function () {
    $notification = new class extends CardTrailNotification
    {
        protected function subject(): string
        {
            return 'Test subject';
        }

        protected function lines(): array
        {
            return ['First line', 'Second line'];
        }

        protected function url(): string
        {
            return url('/dashboard');
        }
    };
    $sam = User::factory()->create(['name' => 'Sam Cardholder']);

    expect($notification->via($sam))->toBe(['mail']);

    $sam->updatePushSubscription('https://push.example.test/send/abc', 'k', 't', 'aes128gcm');

    expect($notification->via($sam))->toBe(['mail', WebPushChannel::class]);

    $mail = $notification->toMail($sam);
    $push = $notification->toWebPush($sam, $notification)->toArray();

    expect($mail->subject)->toBe('Test subject')
        ->and($mail->greeting)->toBe('Hello Sam,')
        ->and($mail->introLines)->toBe(['First line', 'Second line'])
        ->and($mail->actionUrl)->toBe(url('/dashboard'))
        ->and($push['title'])->toBe('Test subject')
        ->and($push['body'])->toBe('First line')
        ->and($push['data'])->toBe(['url' => url('/dashboard')]);
});

it('shares the public VAPID key with the app', function () {
    config(['webpush.vapid.public_key' => 'BInventedPublicKey']);

    $this->actingAs(User::factory()->create())->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('push.vapid_public_key', 'BInventedPublicKey'));
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Notifications/PushSubscriptionTest.php`
Expected: FAIL. Route `push-subscriptions.store` is not defined.

- [ ] **Step 3: Configure the package, subscription storage and the base notification**

Run: `php artisan vendor:publish --provider="NotificationChannels\WebPush\WebPushServiceProvider" --tag=config`
Expected: `config/webpush.php` created.

Run: `php artisan webpush:vapid --force`
Expected: `VAPID_PUBLIC_KEY` and `VAPID_PRIVATE_KEY` written to `.env`. **Do not commit `.env`.**

Append to `.env.example`:

```
VAPID_SUBJECT="${APP_URL}"
VAPID_PUBLIC_KEY=
VAPID_PRIVATE_KEY=

# Email-in receipts. Leave the address blank until IT sets one up.
CARDTRAIL_RECEIPTS_ADDRESS=
CARDTRAIL_INBOUND_DRIVER=eml
CARDTRAIL_INBOUND_EML_PATH=
CARDTRAIL_INBOUND_SCHEDULE=false
```

`database/migrations/2026_10_14_000001_create_push_subscriptions_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->morphs('subscribable', 'push_subscriptions_subscribable_morph_idx');
            $table->string('endpoint', 1024)->unique();
            $table->string('public_key')->nullable();
            $table->string('auth_token')->nullable();
            $table->string('content_encoding')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
```

In `app/Models/User.php`, add `use NotificationChannels\WebPush\HasPushSubscriptions;` and change the trait line to:

```php
    use HasFactory, HasPushSubscriptions, LogsActivity, Notifiable;
```

`app/Notifications/CardTrailNotification.php`:

```php
<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/** Every CardTrail notification: always an email, plus a push to any device the person turned notifications on for. */
abstract class CardTrailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    abstract protected function subject(): string;

    /** @return list<string> the first line is also the push notification's text */
    abstract protected function lines(): array;

    abstract protected function url(): string;

    protected function action(): string
    {
        return 'Open CardTrail';
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $notifiable->pushSubscriptions()->exists() ? ['mail', WebPushChannel::class] : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->subject())
            ->greeting('Hello '.Str::before($notifiable->name, ' ').',');

        foreach ($this->lines() as $line) {
            $mail->line($line);
        }

        return $mail->action($this->action(), $this->url());
    }

    public function toWebPush(object $notifiable, mixed $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->subject())
            ->body($this->lines()[0] ?? '')
            ->icon('/icons/icon-192.png')
            ->data(['url' => $this->url()])
            ->options(['TTL' => 86400]);
    }
}
```

`app/Http/Controllers/PushSubscriptionController.php`:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** A browser or installed app turning push notifications on or off for the signed-in user. */
class PushSubscriptionController extends Controller
{
    public function store(Request $request): Response
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'url', 'starts_with:https://', 'max:1024'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['nullable', Rule::in(['aesgcm', 'aes128gcm'])],
        ]);

        $request->user()->updatePushSubscription(
            $data['endpoint'],
            $data['keys']['p256dh'],
            $data['keys']['auth'],
            $data['content_encoding'] ?? 'aes128gcm',
        );

        return response()->noContent();
    }

    public function destroy(Request $request): Response
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:1024']]);

        $request->user()->deletePushSubscription($data['endpoint']);

        return response()->noContent();
    }
}
```

`routes/web.php` (whole file, replacing the current one):

```php
<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PushSubscriptionController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::get('dashboard', DashboardController::class)->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth', 'throttle:20,1'])->group(function () {
    Route::post('push-subscriptions', [PushSubscriptionController::class, 'store'])->name('push-subscriptions.store');
    Route::delete('push-subscriptions', [PushSubscriptionController::class, 'destroy'])->name('push-subscriptions.destroy');
});

require __DIR__.'/cardholder.php';
require __DIR__.'/admin.php';
require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
```

In `app/Http/Middleware/HandleInertiaRequests.php`, add this key to the shared array after `'auth'`:

```php
            'push' => [
                'vapid_public_key' => config('webpush.vapid.public_key'),
            ],
```

In `resources/js/types/index.ts`, add this property to `SharedData` after `auth: Auth;`:

```ts
    push: { vapid_public_key: string | null };
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Notifications/PushSubscriptionTest.php`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add -A app config database routes resources/js composer.json composer.lock .env.example tests
git commit -m "feat: push subscriptions and the shared notification base"
```

---

### Task 2: Telling people what happened

**Files:**
- Create: `app/Listeners/SendNotifications.php`, `app/Notifications/MonthReady.php`, `app/Notifications/LinesAdded.php`, `app/Notifications/LineQueriedNotification.php`, `app/Notifications/ReconciliationSubmittedNotification.php`, `app/Notifications/ExportReadyNotification.php`, `app/Notifications/ExportFailedNotification.php`
- Test: `tests/Feature/Notifications/NotificationsTest.php`

**Interfaces:**
- Consumes: the six domain events (see "Builds on") and `CardTrailNotification` (Task 1).
- Produces: one notification class per event row. Each exposes its constructor arguments as public properties so tests can inspect them.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Notifications/NotificationsTest.php`:

```php
<?php

use App\Enums\ImportStatus;
use App\Enums\ReconciliationStatus;
use App\Enums\TransactionStatus;
use App\Events\ExportFailed;
use App\Events\ExportReady;
use App\Events\ReconciliationLinesAdded;
use App\Models\Export;
use App\Models\Import;
use App\Models\Period;
use App\Models\User;
use App\Notifications\ExportFailedNotification;
use App\Notifications\ExportReadyNotification;
use App\Notifications\LineQueriedNotification;
use App\Notifications\LinesAdded;
use App\Notifications\MonthReady;
use App\Notifications\ReconciliationSubmittedNotification;
use App\Reconciliations\ReconciliationSubmitter;
use App\Reviews\LineReviewer;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->admin = User::factory()->admin()->create(['name' => 'Alex Accounts', 'is_cardholder' => false]);
    $this->sam = User::factory()->create(['name' => 'Sam Cardholder']);
});

it('tells each active cardholder with lines when a month is published', function () {
    $period = Period::factory()->create(['label' => 'September 2026']);
    Import::factory()->for($period)->create(['status' => ImportStatus::Confirmed]);
    $leaver = User::factory()->inactive()->create();
    lineFor($this->sam, [], $period);
    lineFor($leaver, [], $period);

    $this->actingAs($this->admin)->post(route('admin.periods.publish', $period));

    Notification::assertSentTo($this->sam, MonthReady::class, function (MonthReady $notification) use ($period) {
        $mail = $notification->toMail($this->sam);

        return $notification->reconciliation->period->is($period)
            && $mail->subject === 'September 2026: your card transactions are ready'
            && $mail->actionUrl === route('reconciliations.show', $notification->reconciliation);
    });
    Notification::assertNotSentTo($leaver, MonthReady::class);
});

it('tells a cardholder when more lines land in a published month', function () {
    $line = lineFor($this->sam);

    ReconciliationLinesAdded::dispatch($line->reconciliation, 2);

    Notification::assertSentTo($this->sam, LinesAdded::class, fn (LinesAdded $notification) => $notification->count === 2);
});

it('tells the cardholder when accounts query a line', function () {
    $line = lineFor($this->sam, ['status' => TransactionStatus::Done, 'purpose' => 'Lunch', 'description' => 'DEPOT CAFE']);
    $line->reconciliation->update(['status' => ReconciliationStatus::Submitted, 'submitted_at' => now()]);

    app(LineReviewer::class)->query($line->fresh(), $this->admin, 'Who attended?');

    Notification::assertSentTo($this->sam, LineQueriedNotification::class, function (LineQueriedNotification $notification) use ($line) {
        $push = $notification->toWebPush($this->sam, $notification)->toArray();

        return $push['title'] === 'Accounts have a question about DEPOT CAFE'
            && $push['body'] === '“Who attended?”'
            && $push['data']['url'] === route('transactions.show', $line);
    });
});

it('tells every other active admin when a month is submitted', function () {
    $otherAdmin = User::factory()->admin()->create();
    $inactiveAdmin = User::factory()->admin()->inactive()->create();
    $adminCardholder = User::factory()->admin()->create();
    $line = lineFor($adminCardholder, ['status' => TransactionStatus::Done]);

    app(ReconciliationSubmitter::class)->submit($line->reconciliation, $adminCardholder);

    Notification::assertSentTo([$this->admin, $otherAdmin], ReconciliationSubmittedNotification::class, function (ReconciliationSubmittedNotification $notification) {
        return ! $notification->resubmitted
            && $notification->toMail($this->admin)->actionUrl === route('admin.reconciliations.review', $notification->reconciliation);
    });
    Notification::assertNotSentTo([$inactiveAdmin, $adminCardholder], ReconciliationSubmittedNotification::class);
});

it('says when a month was resubmitted after a query', function () {
    $line = lineFor($this->sam, ['status' => TransactionStatus::Done]);
    $line->reconciliation->update(['submitted_at' => now()->subDay()]);

    app(ReconciliationSubmitter::class)->submit($line->reconciliation, $this->sam);

    Notification::assertSentTo($this->admin, ReconciliationSubmittedNotification::class, function (ReconciliationSubmittedNotification $notification) {
        return $notification->toMail($this->admin)->subject === 'Sam Cardholder resubmitted '.$notification->reconciliation->period->label;
    });
});

it('tells the admin who asked when an export is ready or has failed', function () {
    $export = Export::factory()->create(['requested_by' => $this->admin->id, 'filename' => 'September 2026.zip']);

    ExportReady::dispatch($export);
    $export->update(['error' => 'The receipt for row 2 is missing from storage.']);
    ExportFailed::dispatch($export);

    Notification::assertSentTo($this->admin, ExportReadyNotification::class);
    Notification::assertSentTo($this->admin, ExportFailedNotification::class, function (ExportFailedNotification $notification) {
        return in_array('The receipt for row 2 is missing from storage.', $notification->toMail($this->admin)->introLines, true);
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Notifications/NotificationsTest.php`
Expected: FAIL. Class `App\Notifications\MonthReady` is not found.

- [ ] **Step 3: Write the notifications and the listener**

`app/Notifications/MonthReady.php`:

```php
<?php

namespace App\Notifications;

use App\Models\Reconciliation;
use Illuminate\Support\Str;

class MonthReady extends CardTrailNotification
{
    public function __construct(public Reconciliation $reconciliation) {}

    protected function subject(): string
    {
        return "{$this->reconciliation->period->label}: your card transactions are ready";
    }

    protected function lines(): array
    {
        $count = $this->reconciliation->transactions()->count();

        return [
            "You have {$count} ".Str::plural('line', $count).' to complete by '.$this->reconciliation->period->due_date->format('j F Y').'.',
            'For each one, say what it was for and add a photo or file of the receipt.',
        ];
    }

    protected function url(): string
    {
        return route('reconciliations.show', $this->reconciliation);
    }

    protected function action(): string
    {
        return 'Start now';
    }
}
```

`app/Notifications/LinesAdded.php`:

```php
<?php

namespace App\Notifications;

use App\Models\Reconciliation;
use Illuminate\Support\Str;

class LinesAdded extends CardTrailNotification
{
    public function __construct(public Reconciliation $reconciliation, public int $count) {}

    protected function subject(): string
    {
        return "{$this->count} more ".Str::plural('line', $this->count)." in {$this->reconciliation->period->label}";
    }

    protected function lines(): array
    {
        return [
            "Accounts have added {$this->count} ".Str::plural('line', $this->count).' to your '.$this->reconciliation->period->label.' card reconciliation.',
            'If you had already submitted, please complete the new lines and submit again.',
        ];
    }

    protected function url(): string
    {
        return route('reconciliations.show', $this->reconciliation);
    }
}
```

`app/Notifications/LineQueriedNotification.php`:

```php
<?php

namespace App\Notifications;

use App\Models\Comment;
use App\Models\Transaction;
use Illuminate\Support\Str;

class LineQueriedNotification extends CardTrailNotification
{
    public function __construct(public Transaction $line, public Comment $comment) {}

    protected function subject(): string
    {
        return 'Accounts have a question about '.Str::limit($this->line->description, 60);
    }

    protected function lines(): array
    {
        return [
            '“'.Str::limit($this->comment->body, 300).'”',
            'Row '.$this->line->row_number.' · '.$this->line->transacted_on->format('j M Y').' · £'.number_format(abs($this->line->amount) / 100, 2).($this->line->amount < 0 ? ' credit' : ''),
            'Reply or update the line, then submit the month again.',
        ];
    }

    protected function url(): string
    {
        return route('transactions.show', $this->line);
    }

    protected function action(): string
    {
        return 'Answer the question';
    }
}
```

`app/Notifications/ReconciliationSubmittedNotification.php`:

```php
<?php

namespace App\Notifications;

use App\Models\Reconciliation;

class ReconciliationSubmittedNotification extends CardTrailNotification
{
    public function __construct(public Reconciliation $reconciliation, public bool $resubmitted) {}

    protected function subject(): string
    {
        return $this->reconciliation->user->name.($this->resubmitted ? ' resubmitted ' : ' submitted ').$this->reconciliation->period->label;
    }

    protected function lines(): array
    {
        return [
            $this->resubmitted
                ? "{$this->reconciliation->user->name} has answered your questions and submitted again."
                : "{$this->reconciliation->user->name} has finished their lines and they're ready for review.",
        ];
    }

    protected function url(): string
    {
        return route('admin.reconciliations.review', $this->reconciliation);
    }

    protected function action(): string
    {
        return 'Review';
    }
}
```

`app/Notifications/ExportReadyNotification.php`:

```php
<?php

namespace App\Notifications;

use App\Models\Export;

class ExportReadyNotification extends CardTrailNotification
{
    public function __construct(public Export $export) {}

    protected function subject(): string
    {
        return 'Your export is ready';
    }

    protected function lines(): array
    {
        return ["{$this->export->filename} is ready to download. It will be kept for 7 days."];
    }

    protected function url(): string
    {
        return route('admin.periods.show', $this->export->period_id);
    }

    protected function action(): string
    {
        return 'Download';
    }
}
```

`app/Notifications/ExportFailedNotification.php`:

```php
<?php

namespace App\Notifications;

use App\Models\Export;

class ExportFailedNotification extends CardTrailNotification
{
    public function __construct(public Export $export) {}

    protected function subject(): string
    {
        return 'Your export failed';
    }

    protected function lines(): array
    {
        return [
            "We couldn't build the export for {$this->export->period->label}.",
            $this->export->error ?? 'No reason was recorded.',
        ];
    }

    protected function url(): string
    {
        return route('admin.periods.show', $this->export->period_id);
    }
}
```

`app/Listeners/SendNotifications.php`:

```php
<?php

namespace App\Listeners;

use App\Events\ExportFailed;
use App\Events\ExportReady;
use App\Events\LineQueried;
use App\Events\PeriodPublished;
use App\Events\ReconciliationLinesAdded;
use App\Events\ReconciliationSubmitted;
use App\Models\Reconciliation;
use App\Models\User;
use App\Notifications\ExportFailedNotification;
use App\Notifications\ExportReadyNotification;
use App\Notifications\LineQueriedNotification;
use App\Notifications\LinesAdded;
use App\Notifications\MonthReady;
use App\Notifications\ReconciliationSubmittedNotification;

/** Who hears about what (spec §6.7). Inactive users are never notified. Found by Laravel's event discovery. */
class SendNotifications
{
    public function handlePeriodPublished(PeriodPublished $event): void
    {
        $event->period->reconciliations()->with('user')->get()
            ->filter(fn (Reconciliation $reconciliation) => $reconciliation->user->active)
            ->each(fn (Reconciliation $reconciliation) => $reconciliation->user->notify(new MonthReady($reconciliation)));
    }

    public function handleLinesAdded(ReconciliationLinesAdded $event): void
    {
        $user = $event->reconciliation->user;

        if ($user->active) {
            $user->notify(new LinesAdded($event->reconciliation, $event->count));
        }
    }

    public function handleLineQueried(LineQueried $event): void
    {
        $user = $event->line->reconciliation->user;

        if ($user->active) {
            $user->notify(new LineQueriedNotification($event->line, $event->comment));
        }
    }

    public function handleSubmitted(ReconciliationSubmitted $event): void
    {
        User::query()->active()
            ->where('is_admin', true)
            ->whereKeyNot($event->reconciliation->user_id)
            ->get()
            ->each(fn (User $admin) => $admin->notify(new ReconciliationSubmittedNotification($event->reconciliation, $event->resubmitted)));
    }

    public function handleExportReady(ExportReady $event): void
    {
        if ($event->export->requester->active) {
            $event->export->requester->notify(new ExportReadyNotification($event->export));
        }
    }

    public function handleExportFailed(ExportFailed $event): void
    {
        if ($event->export->requester->active) {
            $event->export->requester->notify(new ExportFailedNotification($event->export));
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan event:list | grep -i SendNotifications && php artisan test tests/Feature/Notifications/NotificationsTest.php`
Expected: six events listed against `SendNotifications`; PASS (6 tests).

- [ ] **Step 5: Run the full suite and commit**

Run: `php artisan test`
Expected: all pass.

```bash
git add -A app tests
git commit -m "feat: email and push notifications for publishing, new lines, queries, submissions and exports"
```

---
### Task 3: Deadline reminders and nudges

**Files:**
- Create: `database/migrations/2026_10_14_000002_create_reminder_sends_table.php`, `app/Models/ReminderSend.php`, `app/Notifications/DeadlineReminder.php`, `app/Notifications/Nudge.php`, `app/Console/Commands/SendDeadlineReminders.php`, `app/Http/Controllers/Admin/NudgeController.php`
- Modify: `routes/admin.php`, `routes/console.php`, `resources/js/pages/admin/periods/Show.vue`
- Test: `tests/Feature/Notifications/RemindersTest.php`

**Interfaces:**
- Consumes: `Period.reminder_offsets` (days before `due_date`), `ReconciliationStatus::InProgress` and `CardTrailNotification`.
- Produces:
  - Command `reminders:send`, scheduled daily at 08:00.
  - `DeadlineReminder(Reconciliation, int $daysLeft)` and `Nudge(Reconciliation, User $admin)`.
  - Route `admin.reconciliations.nudge` (POST).

- [ ] **Step 1: Write the failing test**

`tests/Feature/Notifications/RemindersTest.php`:

```php
<?php

use App\Enums\ReconciliationStatus;
use App\Models\Period;
use App\Models\User;
use App\Notifications\DeadlineReminder;
use App\Notifications\Nudge;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Notification::fake();
    $this->travelTo(Carbon::parse('2026-10-12 08:00'));
    $this->sam = User::factory()->create();
    $this->period = Period::factory()->published()->create(['label' => 'September 2026', 'due_date' => '2026-10-15', 'reminder_offsets' => [3, 0]]);
});

it('reminds cardholders still working on a month on each reminder day', function () {
    $jo = User::factory()->create();
    $leaver = User::factory()->inactive()->create();
    lineFor($this->sam, [], $this->period);
    lineFor($jo, [], $this->period)->reconciliation->update(['status' => ReconciliationStatus::Submitted]);
    lineFor($leaver, [], $this->period);

    $this->artisan('reminders:send')->assertSuccessful();

    Notification::assertSentTo($this->sam, DeadlineReminder::class, function (DeadlineReminder $notification) {
        return $notification->daysLeft === 3
            && $notification->toMail($this->sam)->subject === 'September 2026 is due in 3 days';
    });
    Notification::assertNotSentTo([$jo, $leaver], DeadlineReminder::class);
});

it('sends each reminder only once', function () {
    lineFor($this->sam, [], $this->period);

    $this->artisan('reminders:send');
    $this->artisan('reminders:send');

    Notification::assertSentToTimes($this->sam, DeadlineReminder::class, 1);
});

it('reminds on the due date itself', function () {
    $this->travelTo(Carbon::parse('2026-10-15 08:00'));
    lineFor($this->sam, [], $this->period);

    $this->artisan('reminders:send');

    Notification::assertSentTo($this->sam, DeadlineReminder::class, fn (DeadlineReminder $notification) => $notification->toMail($this->sam)->subject === 'September 2026 is due today');
});

it('stays quiet on other days and for months that aren’t published', function () {
    $this->travelTo(Carbon::parse('2026-10-13 08:00'));
    lineFor($this->sam, [], $this->period);
    lineFor($this->sam, [], Period::factory()->create(['due_date' => '2026-10-16', 'reminder_offsets' => [3]]));

    $this->artisan('reminders:send');

    Notification::assertNothingSent();
});

it('lets an admin nudge someone who hasn’t finished', function () {
    $admin = User::factory()->admin()->create(['name' => 'Alex Accounts']);
    $line = lineFor($this->sam, [], $this->period);

    $this->actingAs($admin)->post(route('admin.reconciliations.nudge', $line->reconciliation))->assertSessionHas('success');

    Notification::assertSentTo($this->sam, Nudge::class, fn (Nudge $notification) => $notification->admin->is($admin));
    expect(Activity::query()->where('event', 'nudged')->exists())->toBeTrue();
});

it('refuses to nudge a submitted month or someone inactive', function () {
    $admin = User::factory()->admin()->create();
    $submitted = lineFor($this->sam, [], $this->period)->reconciliation;
    $submitted->update(['status' => ReconciliationStatus::Submitted]);
    $leaver = lineFor(User::factory()->inactive()->create(), [], $this->period)->reconciliation;

    $this->actingAs($admin)->post(route('admin.reconciliations.nudge', $submitted))->assertSessionHas('error');
    $this->actingAs($admin)->post(route('admin.reconciliations.nudge', $leaver))->assertSessionHas('error');

    Notification::assertNothingSent();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Notifications/RemindersTest.php`
Expected: FAIL. Command `reminders:send` is not defined.

- [ ] **Step 3: Write the reminder log, notifications, command and nudge**

`database/migrations/2026_10_14_000002_create_reminder_sends_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminder_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reconciliation_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('offset_days');
            $table->date('due_date');
            $table->timestamps();
            // A changed due date gets its own reminders; the same day's reminder never goes twice.
            $table->unique(['reconciliation_id', 'offset_days', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_sends');
    }
};
```

`app/Models/ReminderSend.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A deadline reminder already sent, so the daily command never repeats one. */
class ReminderSend extends Model
{
    protected $fillable = ['reconciliation_id', 'offset_days', 'due_date'];

    protected function casts(): array
    {
        return ['due_date' => 'date:Y-m-d', 'offset_days' => 'integer'];
    }
}
```

`app/Notifications/DeadlineReminder.php`:

```php
<?php

namespace App\Notifications;

use App\Models\Reconciliation;
use App\Enums\TransactionStatus;
use Illuminate\Support\Str;

class DeadlineReminder extends CardTrailNotification
{
    public function __construct(public Reconciliation $reconciliation, public int $daysLeft) {}

    protected function subject(): string
    {
        $label = $this->reconciliation->period->label;

        return $this->daysLeft === 0 ? "{$label} is due today" : "{$label} is due in {$this->daysLeft} ".Str::plural('day', $this->daysLeft);
    }

    protected function lines(): array
    {
        $left = $this->reconciliation->transactions()->whereIn('status', [TransactionStatus::Todo, TransactionStatus::Queried])->count();
        $total = $this->reconciliation->transactions()->count();

        return [
            $left > 0
                ? "You have {$left} of {$total} ".Str::plural('line', $total).' still to finish.'
                : 'Every line is done – just submit it to accounts.',
        ];
    }

    protected function url(): string
    {
        return route('reconciliations.show', $this->reconciliation);
    }

    protected function action(): string
    {
        return 'Finish now';
    }
}
```

`app/Notifications/Nudge.php`:

```php
<?php

namespace App\Notifications;

use App\Models\Reconciliation;
use App\Models\User;

class Nudge extends CardTrailNotification
{
    public function __construct(public Reconciliation $reconciliation, public User $admin) {}

    protected function subject(): string
    {
        return "Reminder: {$this->reconciliation->period->label} card reconciliation";
    }

    protected function lines(): array
    {
        return [
            "{$this->admin->name} in accounts asked us to remind you to finish {$this->reconciliation->period->label}.",
            'It’s due '.$this->reconciliation->period->due_date->format('j F Y').'.',
        ];
    }

    protected function url(): string
    {
        return route('reconciliations.show', $this->reconciliation);
    }

    protected function action(): string
    {
        return 'Finish now';
    }
}
```

`app/Console/Commands/SendDeadlineReminders.php`:

```php
<?php

namespace App\Console\Commands;

use App\Enums\PeriodStatus;
use App\Enums\ReconciliationStatus;
use App\Models\Period;
use App\Models\Reconciliation;
use App\Models\ReminderSend;
use App\Notifications\DeadlineReminder;
use Illuminate\Console\Command;

class SendDeadlineReminders extends Command
{
    protected $signature = 'reminders:send';

    protected $description = 'Remind cardholders who haven’t submitted, on each period’s reminder days';

    public function handle(): int
    {
        $sent = 0;

        Period::query()->where('status', PeriodStatus::Published)->get()->each(function (Period $period) use (&$sent) {
            foreach (array_unique($period->reminder_offsets ?? []) as $offset) {
                if (! $period->due_date->copy()->subDays((int) $offset)->isToday()) {
                    continue;
                }

                $period->reconciliations()
                    ->where('status', ReconciliationStatus::InProgress)
                    ->with('user')
                    ->get()
                    ->filter(fn (Reconciliation $reconciliation) => $reconciliation->user->active)
                    ->each(function (Reconciliation $reconciliation) use ($period, $offset, &$sent) {
                        $record = ReminderSend::firstOrCreate([
                            'reconciliation_id' => $reconciliation->id,
                            'offset_days' => (int) $offset,
                            'due_date' => $period->due_date->format('Y-m-d'),
                        ]);

                        if ($record->wasRecentlyCreated) {
                            $reconciliation->user->notify(new DeadlineReminder($reconciliation, (int) $offset));
                            $sent++;
                        }
                    });
            }
        });

        $this->info("Sent {$sent} ".str('reminder')->plural($sent).'.');

        return self::SUCCESS;
    }
}
```

`app/Http/Controllers/Admin/NudgeController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PeriodStatus;
use App\Enums\ReconciliationStatus;
use App\Http\Controllers\Controller;
use App\Models\Reconciliation;
use App\Notifications\Nudge;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NudgeController extends Controller
{
    public function __invoke(Request $request, Reconciliation $reconciliation): RedirectResponse
    {
        $user = $reconciliation->user;

        if ($reconciliation->period->status !== PeriodStatus::Published || $reconciliation->status !== ReconciliationStatus::InProgress) {
            return back()->with('error', 'Only people still working on a published month can be nudged.');
        }

        if (! $user->active) {
            return back()->with('error', "{$user->name} is inactive. Reassign their lines instead.");
        }

        $user->notify(new Nudge($reconciliation, $request->user()));

        activity('reminders')
            ->causedBy($request->user())
            ->performedOn($reconciliation)
            ->event('nudged')
            ->log("Nudged {$user->name}");

        return back()->with('success', "Reminder sent to {$user->name}.");
    }
}
```

In `routes/admin.php`, add `use App\Http\Controllers\Admin\NudgeController;` and add at the end of the admin group:

```php
    Route::post('reconciliations/{reconciliation}/nudge', NudgeController::class)->middleware('throttle:20,1')->name('reconciliations.nudge');
```

In `routes/console.php`, add after the `exports:prune` schedule line:

```php
Schedule::command('reminders:send')->dailyAt('08:00');
```

In `resources/js/pages/admin/periods/Show.vue`, add this function before `const statusLabel`:

```ts
const nudge = (row: DashboardReconciliation) => {
    if (window.confirm(`Send ${row.user.name} a reminder to finish ${props.period.label}?`)) {
        router.post(route('admin.reconciliations.nudge', row.id), {}, { preserveScroll: true });
    }
};
```

and add this button immediately before the row's Review button:

```vue
                            <Button v-if="period.status === 'published' && row.status === 'in_progress' && row.user.active" size="sm" variant="ghost" @click="nudge(row)">
                                Nudge
                            </Button>
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Notifications/RemindersTest.php && php artisan schedule:list`
Expected: PASS (6 tests); the schedule lists `reminders:send` at 08:00 and `exports:prune` daily.

- [ ] **Step 5: Build and commit**

Run: `npm run build`
Expected: build succeeds.

```bash
git add -A app database routes resources/js tests
git commit -m "feat: deadline reminders and nudges"
```

---

### Task 4: Email-in receipts

**Files:**
- Create: `app/Enums/InboundOutcome.php`, `database/migrations/2026_10_14_000003_create_inbound_messages_table.php`, `app/Models/InboundMessage.php`, `app/Mail/Inbound/InboundAttachment.php`, `app/Mail/Inbound/InboundEmail.php`, `app/Mail/Inbound/InboundMailSource.php`, `app/Mail/Inbound/EmlMailSource.php`, `app/Mail/Inbound/ReceiptIngestor.php`, `app/Console/Commands/IngestReceipts.php`, `resources/demo/demo-receipt.eml`
- Modify: `config/cardtrail.php` (`inbound`), `app/Providers/AppServiceProvider.php` (binding), `routes/console.php` (optional schedule)
- Test: `tests/Feature/Receipts/EmailInTest.php`

**Interfaces:**
- Consumes: `ReceiptStore::storeContents()` and `ReceiptSource::Email` (Plan 3).
- Produces:
  - Interface `InboundMailSource { fetch(): iterable<InboundEmail>; markProcessed(InboundEmail): void }`.
  - `InboundEmail(messageId, from, subject, receivedAt, attachments, reference)` and `InboundAttachment(filename, contents)`.
  - `EmlMailSource(string $path)` and `EmlMailSource::parse(string $raw): InboundEmail`.
  - `ReceiptIngestor::ingest(InboundEmail, string $driver): ?InboundMessage` (null when the message was already processed).
  - Command `receipts:ingest {path?}`.
  - `InboundMessage` model (`outcome` cast to `InboundOutcome`).

- [ ] **Step 1: Write the failing test**

`tests/Feature/Receipts/EmailInTest.php`:

```php
<?php

use App\Enums\InboundOutcome;
use App\Enums\ReceiptSource;
use App\Models\InboundMessage;
use App\Models\Receipt;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('receipts');
    $this->sam = User::factory()->create(['email' => 'sam@cardtrail.test']);
    $this->dir = sys_get_temp_dir().'/cardtrail-eml-'.uniqid();
    File::ensureDirectoryExists($this->dir);
});

afterEach(fn () => File::deleteDirectory($this->dir));

/** Write an invented email to the test folder. Each attachment is [filename, contents, mime type]. */
function eml(string $from, array $attachments = [], ?string $id = null): string
{
    $boundary = 'b'.uniqid();
    $id ??= uniqid().'@mail.example.test';
    $body = "--{$boundary}\r\nContent-Type: text/plain\r\n\r\nReceipt attached.\r\n";

    foreach ($attachments as [$name, $contents, $mime]) {
        $body .= "--{$boundary}\r\nContent-Type: {$mime}; name=\"{$name}\"\r\nContent-Disposition: attachment; filename=\"{$name}\"\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            .chunk_split(base64_encode($contents))."\r\n";
    }

    $raw = "From: {$from}\r\nTo: receipts@cardtrail.test\r\nSubject: Taxi receipt\r\nDate: Fri, 09 Oct 2026 10:15:00 +0100\r\n"
        ."Message-ID: <{$id}>\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n\r\n{$body}--{$boundary}--\r\n";

    $path = test()->dir.'/'.md5($id).'.eml';
    file_put_contents($path, $raw);

    return $path;
}

function pdfBytes(): string
{
    return "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
}

function jpegBytes(): string
{
    $image = imagecreatetruecolor(20, 20);
    ob_start();
    imagejpeg($image);

    return (string) ob_get_clean();
}

it('turns attachments from a cardholder into inbox receipts', function () {
    $path = eml('Sam Cardholder <SAM@cardtrail.test>', [['taxi.pdf', pdfBytes(), 'application/pdf'], ['photo.jpg', jpegBytes(), 'image/jpeg']], 'abc@mail.example.test');

    $this->artisan('receipts:ingest', ['path' => $path])->assertSuccessful();

    $message = InboundMessage::sole();
    expect($message)
        ->message_id->toBe('abc@mail.example.test')
        ->from_email->toBe('sam@cardtrail.test')
        ->subject->toBe('Taxi receipt')
        ->user_id->toBe($this->sam->id)
        ->outcome->toBe(InboundOutcome::Accepted)
        ->attachment_count->toBe(2)
        ->accepted_count->toBe(2)
        ->and($message->received_at->toIso8601String())->toBe('2026-10-09T09:15:00+00:00');

    expect(Receipt::query()->inInbox()->whereBelongsTo($this->sam)->where('source', ReceiptSource::Email)->where('inbound_message_id', $message->id)->count())->toBe(2);
});

it('records mail from unknown senders and leavers without making receipts', function () {
    $leaver = User::factory()->inactive()->create(['email' => 'gone@cardtrail.test']);
    eml('stranger@example.test', [['a.pdf', pdfBytes(), 'application/pdf']]);
    eml('gone@cardtrail.test', [['b.pdf', pdfBytes(), 'application/pdf']]);

    $this->artisan('receipts:ingest', ['path' => $this->dir])->assertSuccessful();

    expect(InboundMessage::pluck('outcome')->all())->toBe([InboundOutcome::UnknownSender, InboundOutcome::UnknownSender])
        ->and(InboundMessage::whereNotNull('user_id')->count())->toBe(0)
        ->and(Receipt::count())->toBe(0);
});

it('records mail with no attachments, or only files that aren’t receipts', function () {
    eml('sam@cardtrail.test', [], 'empty@mail.example.test');
    eml('sam@cardtrail.test', [['tool.exe', 'MZ', 'application/octet-stream'], ['fake.pdf', '<html></html>', 'application/pdf']], 'bad@mail.example.test');

    $this->artisan('receipts:ingest', ['path' => $this->dir])->assertSuccessful();

    expect(InboundMessage::firstWhere('message_id', 'empty@mail.example.test')->outcome)->toBe(InboundOutcome::NoAttachments)
        ->and(InboundMessage::firstWhere('message_id', 'bad@mail.example.test'))
        ->outcome->toBe(InboundOutcome::RejectedType)
        ->accepted_count->toBe(0)
        ->and(Receipt::count())->toBe(0);
});

it('processes each email only once', function () {
    $path = eml('sam@cardtrail.test', [['taxi.pdf', pdfBytes(), 'application/pdf']]);

    $this->artisan('receipts:ingest', ['path' => $path])->assertSuccessful();
    $this->artisan('receipts:ingest', ['path' => $path])->expectsOutputToContain('already processed: 1')->assertSuccessful();

    expect(InboundMessage::count())->toBe(1)->and(Receipt::count())->toBe(1);
});

it('reads the configured folder when no path is given', function () {
    config(['cardtrail.inbound.eml_path' => $this->dir]);
    eml('sam@cardtrail.test', [['taxi.pdf', pdfBytes(), 'application/pdf']]);

    $this->artisan('receipts:ingest')->assertSuccessful();

    expect(Receipt::count())->toBe(1);
});

it('explains what to do when there is nothing to read', function () {
    config(['cardtrail.inbound.eml_path' => null]);

    $this->artisan('receipts:ingest')->expectsOutputToContain('CARDTRAIL_INBOUND_EML_PATH')->assertFailed();
});

it('ships a demo email that the eml driver accepts', function () {
    User::factory()->create(['email' => 'jo@cardtrail.test']);
    $this->sam->update(['email' => 'sam@cardtrail.test']);

    $this->artisan('receipts:ingest', ['path' => resource_path('demo/demo-receipt.eml')])->assertSuccessful();

    expect(InboundMessage::sole()->outcome)->toBe(InboundOutcome::Accepted);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Receipts/EmailInTest.php`
Expected: FAIL. Class `App\Enums\InboundOutcome` is not found.

- [ ] **Step 3: Write the inbound log, mail source, ingestor and command**

`app/Enums/InboundOutcome.php`:

```php
<?php

namespace App\Enums;

enum InboundOutcome: string
{
    case Accepted = 'accepted';
    case UnknownSender = 'unknown_sender';
    case NoAttachments = 'no_attachments';
    case RejectedType = 'rejected_type';
}
```

`database/migrations/2026_10_14_000003_create_inbound_messages_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_messages', function (Blueprint $table) {
            $table->id();
            $table->string('source_driver', 30);
            $table->string('message_id')->unique();
            $table->string('from_email')->nullable();
            $table->string('subject')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('outcome', 30);
            $table->unsignedSmallInteger('attachment_count')->default(0);
            $table->unsignedSmallInteger('accepted_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_messages');
    }
};
```

`app/Models/InboundMessage.php`:

```php
<?php

namespace App\Models;

use App\Enums\InboundOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One email sent to the receipts address: what happened to it, and a guard against processing it twice. */
class InboundMessage extends Model
{
    protected $fillable = ['source_driver', 'message_id', 'from_email', 'subject', 'received_at', 'user_id', 'outcome', 'attachment_count', 'accepted_count'];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'outcome' => InboundOutcome::class,
            'attachment_count' => 'integer',
            'accepted_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }
}
```

`app/Mail/Inbound/InboundAttachment.php`:

```php
<?php

namespace App\Mail\Inbound;

final readonly class InboundAttachment
{
    public function __construct(
        public string $filename,
        public string $contents,
    ) {}
}
```

`app/Mail/Inbound/InboundEmail.php`:

```php
<?php

namespace App\Mail\Inbound;

use Carbon\CarbonImmutable;

/** An email as any mail source sees it. `reference` is the source's own handle (a file path, a mailbox id). */
final readonly class InboundEmail
{
    /** @param  list<InboundAttachment>  $attachments */
    public function __construct(
        public string $messageId,
        public ?string $from,
        public ?string $subject,
        public ?CarbonImmutable $receivedAt,
        public array $attachments,
        public mixed $reference = null,
    ) {}
}
```

`app/Mail/Inbound/InboundMailSource.php`:

```php
<?php

namespace App\Mail\Inbound;

/**
 * Where emailed receipts come from. v1 has the `eml` driver; a Microsoft Graph mailbox driver
 * can be added later once IT grants access (spec §6.4) without touching the rest of the app.
 */
interface InboundMailSource
{
    /** @return iterable<InboundEmail> */
    public function fetch(): iterable;

    /** Called after an email has been recorded, so the source can move or flag it. */
    public function markProcessed(InboundEmail $email): void;
}
```

`app/Mail/Inbound/EmlMailSource.php`:

```php
<?php

namespace App\Mail\Inbound;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\Header\DateHeader;
use ZBateson\MailMimeParser\Message;

/** Development driver: reads one .eml file or every .eml file in a folder. Files are left where they are. */
final class EmlMailSource implements InboundMailSource
{
    public function __construct(private string $path) {}

    public function fetch(): iterable
    {
        if (is_dir($this->path)) {
            $files = glob(rtrim($this->path, '/').'/*.eml') ?: [];
            sort($files);
        } elseif (is_file($this->path)) {
            $files = [$this->path];
        } else {
            throw new InvalidArgumentException("There’s no .eml file or folder at {$this->path}.");
        }

        foreach ($files as $file) {
            yield self::parse((string) file_get_contents($file), $file);
        }
    }

    public function markProcessed(InboundEmail $email): void
    {
        // Nothing to do: message ids stop a file being processed twice.
    }

    public static function parse(string $raw, mixed $reference = null): InboundEmail
    {
        $message = Message::from($raw, true);

        $from = $message->getHeader('From');
        $email = $from instanceof AddressHeader ? $from->getEmail() : null;
        $date = $message->getHeader('Date');
        $sent = $date instanceof DateHeader ? $date->getDateTimeImmutable() : null;
        $id = trim((string) $message->getHeaderValue('Message-ID'), "<> \t");

        $attachments = [];
        foreach ($message->getAllAttachmentParts() as $part) {
            $contents = $part->getBinaryContentStream()?->getContents();

            if ($contents !== null && $contents !== '') {
                $attachments[] = new InboundAttachment($part->getFilename() ?: 'attachment', $contents);
            }
        }

        return new InboundEmail(
            messageId: Str::limit($id !== '' ? $id : 'sha256:'.hash('sha256', $raw), 250, ''),
            from: $email ? Str::lower(trim($email)) : null,
            subject: ($subject = $message->getSubject()) !== null ? Str::limit($subject, 250, '') : null,
            receivedAt: $sent ? CarbonImmutable::instance($sent)->utc() : null,
            attachments: $attachments,
            reference: $reference,
        );
    }
}
```

`app/Mail/Inbound/ReceiptIngestor.php`:

```php
<?php

namespace App\Mail\Inbound;

use App\Enums\InboundOutcome;
use App\Enums\ReceiptSource;
use App\Models\InboundMessage;
use App\Models\User;
use App\Receipts\ReceiptStore;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** Records an inbound email and turns its accepted attachments into the sender's inbox receipts. */
final class ReceiptIngestor
{
    public function __construct(private ReceiptStore $store) {}

    /** @return InboundMessage|null null when this message was processed before */
    public function ingest(InboundEmail $email, string $driver): ?InboundMessage
    {
        if (InboundMessage::query()->where('message_id', $email->messageId)->exists()) {
            return null;
        }

        try {
            return DB::transaction(fn () => $this->record($email, $driver));
        } catch (UniqueConstraintViolationException) {
            return null; // Another run recorded it a moment ago.
        }
    }

    private function record(InboundEmail $email, string $driver): InboundMessage
    {
        $sender = $email->from === null ? null : User::query()
            ->active()
            ->where('is_cardholder', true)
            ->where('email', $email->from)
            ->first();

        $message = InboundMessage::create([
            'source_driver' => $driver,
            'message_id' => $email->messageId,
            'from_email' => $email->from,
            'subject' => $email->subject,
            'received_at' => $email->receivedAt,
            'user_id' => $sender?->id,
            'outcome' => InboundOutcome::NoAttachments,
            'attachment_count' => count($email->attachments),
        ]);

        if (! $sender) {
            $message->update(['outcome' => InboundOutcome::UnknownSender]);

            return $message;
        }

        if ($email->attachments === []) {
            return $message;
        }

        $accepted = 0;
        foreach ($email->attachments as $attachment) {
            if ($this->store->storeContents($attachment->contents, $attachment->filename, $sender, ReceiptSource::Email, $message->id)) {
                $accepted++;
            }
        }

        $message->update([
            'outcome' => $accepted > 0 ? InboundOutcome::Accepted : InboundOutcome::RejectedType,
            'accepted_count' => $accepted,
        ]);

        return $message;
    }
}
```

`app/Console/Commands/IngestReceipts.php`:

```php
<?php

namespace App\Console\Commands;

use App\Mail\Inbound\EmlMailSource;
use App\Mail\Inbound\InboundMailSource;
use App\Mail\Inbound\ReceiptIngestor;
use Illuminate\Console\Command;
use Throwable;

class IngestReceipts extends Command
{
    protected $signature = 'receipts:ingest {path? : An .eml file or a folder of them (uses the eml driver)}';

    protected $description = 'Turn emails sent to the receipts address into inbox receipts';

    public function handle(ReceiptIngestor $ingestor): int
    {
        $path = $this->argument('path');
        $driver = $path ? 'eml' : (string) config('cardtrail.inbound.driver');

        if (! $path && $driver === 'eml' && ! config('cardtrail.inbound.eml_path')) {
            $this->error('Give a path to an .eml file or folder, or set CARDTRAIL_INBOUND_EML_PATH.');

            return self::FAILURE;
        }

        $source = $path ? new EmlMailSource($path) : app(InboundMailSource::class);
        $counts = [];

        try {
            foreach ($source->fetch() as $email) {
                try {
                    $message = $ingestor->ingest($email, $driver);
                } catch (Throwable $e) {
                    report($e);
                    $this->error("Couldn’t process {$email->messageId}: {$e->getMessage()}");

                    continue;
                }

                $source->markProcessed($email);
                $label = $message ? str_replace('_', ' ', $message->outcome->value) : 'already processed';
                $counts[$label] = ($counts[$label] ?? 0) + 1;
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($counts === []) {
            $this->info('No emails to process.');
        }

        foreach ($counts as $label => $count) {
            $this->line("{$label}: {$count}");
        }

        return self::SUCCESS;
    }
}
```

In `config/cardtrail.php`, add after the `receipts` block:

```php
    /*
    | Email-in receipts. "eml" reads .eml files from a folder (development).
    | A mailbox driver is added later when IT grants access; set "schedule"
    | to true to poll every five minutes.
    */
    'inbound' => [
        'driver' => env('CARDTRAIL_INBOUND_DRIVER', 'eml'),
        'eml_path' => env('CARDTRAIL_INBOUND_EML_PATH'),
        'schedule' => (bool) env('CARDTRAIL_INBOUND_SCHEDULE', false),

        'drivers' => [
            'eml' => App\Mail\Inbound\EmlMailSource::class,
        ],
    ],
```

In `app/Providers/AppServiceProvider.php`, add `use App\Mail\Inbound\EmlMailSource;` and `use App\Mail\Inbound\InboundMailSource;`, then add inside `register()`:

```php
        $this->app->bind(InboundMailSource::class, function (Application $app) {
            $driver = config('cardtrail.inbound.driver');
            $class = config("cardtrail.inbound.drivers.{$driver}");

            if (! $class) {
                throw new InvalidArgumentException("Unknown inbound mail driver [{$driver}].");
            }

            return $class === EmlMailSource::class
                ? new EmlMailSource((string) config('cardtrail.inbound.eml_path'))
                : $app->make($class);
        });
```

In `routes/console.php`, add after the `reminders:send` schedule line:

```php
Schedule::command('receipts:ingest')->everyFiveMinutes()->withoutOverlapping()->when(fn () => config('cardtrail.inbound.schedule'));
```

- [ ] **Step 4: Create the invented demo email**

Run this from the repo root. It builds a valid one-page PDF that says "Invented demo receipt" and wraps it in an email from `sam@cardtrail.test`:

```bash
php -r '
$stream = "BT /F1 20 Tf 72 760 Td (Invented demo receipt) Tj 0 -30 Td /F1 14 Tf (Depot Cafe - GBP 12.50 - 03/10/2026) Tj ET";
$objects = [
    "<< /Type /Catalog /Pages 2 0 R >>",
    "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
    "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>",
    "<< /Length ".strlen($stream)." >>\nstream\n$stream\nendstream",
    "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
];
$pdf = "%PDF-1.4\n"; $offsets = [];
foreach ($objects as $i => $object) { $offsets[] = strlen($pdf); $pdf .= ($i + 1)." 0 obj\n$object\nendobj\n"; }
$xref = strlen($pdf);
$pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
foreach ($offsets as $offset) { $pdf .= sprintf("%010d 00000 n \n", $offset); }
$pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
$eml = "From: Sam Cardholder <sam@cardtrail.test>\r\nTo: receipts@cardtrail.test\r\nSubject: Depot cafe receipt\r\nDate: Sat, 03 Oct 2026 12:30:00 +0100\r\nMessage-ID: <demo-receipt-1@cardtrail.test>\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"demo\"\r\n\r\n--demo\r\nContent-Type: text/plain\r\n\r\nInvented demo email for testing CardTrail email-in.\r\n--demo\r\nContent-Type: application/pdf; name=\"depot-cafe.pdf\"\r\nContent-Disposition: attachment; filename=\"depot-cafe.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($pdf))."--demo--\r\n";
file_put_contents("resources/demo/demo-receipt.eml", $eml);
'
```

Expected: `resources/demo/demo-receipt.eml` exists and contains `demo-receipt-1@cardtrail.test`.

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/Receipts/EmailInTest.php`
Expected: PASS (7 tests).

- [ ] **Step 6: Commit**

```bash
git add -A app config database resources/demo routes tests
git commit -m "feat: email-in receipts through the eml driver, with an inbound log"
```

---

### Task 5: Inbound log and settings screens

**Files:**
- Create: `database/migrations/2026_10_14_000004_create_settings_table.php`, `app/Models/Setting.php`, `app/Http/Controllers/Admin/InboundMessageController.php`, `app/Http/Controllers/Admin/SettingsController.php`, `app/Http/Controllers/Admin/ColumnMappingController.php`, `resources/js/pages/admin/inbound/Index.vue`, `resources/js/pages/admin/settings/Edit.vue`
- Modify: `app/Http/Controllers/Admin/PeriodController.php` (default offsets), `routes/admin.php`, `resources/js/components/AppSidebar.vue`
- Test: `tests/Feature/Admin/InboundLogTest.php`, `tests/Feature/Admin/SettingsTest.php`

**Interfaces:**
- Consumes: `InboundMessage` (Task 4) and `ColumnMapping` (Plan 2).
- Produces:
  - `Setting::get(string $key, mixed $default = null)`, `Setting::put(string $key, mixed $value)` and `Setting::reminderOffsets(): list<int>`.
  - Routes `admin.inbound.index`, `admin.settings.edit`, `admin.settings.update`, `admin.column-mappings.update` and `admin.column-mappings.destroy`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Admin/InboundLogTest.php`:

```php
<?php

use App\Enums\InboundOutcome;
use App\Models\InboundMessage;
use App\Models\User;

it('shows admins what happened to each inbound email, newest first', function () {
    $sam = User::factory()->create(['name' => 'Sam Cardholder']);
    InboundMessage::create(['source_driver' => 'eml', 'message_id' => 'a@x', 'from_email' => 'stranger@example.test', 'subject' => 'Hello', 'outcome' => InboundOutcome::UnknownSender]);
    InboundMessage::create(['source_driver' => 'eml', 'message_id' => 'b@x', 'from_email' => 'sam@cardtrail.test', 'subject' => 'Taxi', 'user_id' => $sam->id, 'outcome' => InboundOutcome::Accepted, 'attachment_count' => 2, 'accepted_count' => 1]);

    $this->actingAs(User::factory()->admin()->create())->get(route('admin.inbound.index'))
        ->assertInertia(fn ($page) => $page
            ->component('admin/inbound/Index')
            ->has('messages.data', 2)
            ->where('messages.data.0.subject', 'Taxi')
            ->where('messages.data.0.user', 'Sam Cardholder')
            ->where('messages.data.0.outcome', 'accepted')
            ->where('messages.data.0.accepted_count', 1)
            ->where('messages.data.1.outcome', 'unknown_sender'));
});

it('keeps the inbound log to admins', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.inbound.index'))->assertForbidden();
});
```

`tests/Feature/Admin/SettingsTest.php`:

```php
<?php

use App\Models\ColumnMapping;
use App\Models\Setting;
use App\Models\User;

beforeEach(fn () => $this->admin = User::factory()->admin()->create());

it('saves the default reminder days and uses them for new periods', function () {
    $this->actingAs($this->admin)->put(route('admin.settings.update'), ['reminder_offsets' => '7, 1, 0'])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(Setting::reminderOffsets())->toBe([7, 1, 0]);

    $this->actingAs($this->admin)->get(route('admin.periods.create'))
        ->assertInertia(fn ($page) => $page->where('defaults.reminder_offsets', '7, 1, 0'));
});

it('defaults to three days before and on the day', function () {
    expect(Setting::reminderOffsets())->toBe([3, 0]);
});

it('refuses reminder days that make no sense', function (string $value) {
    $this->actingAs($this->admin)->put(route('admin.settings.update'), ['reminder_offsets' => $value])
        ->assertSessionHasErrors('reminder_offsets');
})->with(['abc', '3, 3', '90', '1, 2, 3, 4, 5, 6']);

it('lists, renames and deletes saved column mappings', function () {
    $mapping = ColumnMapping::create(['name' => 'Amex export', 'header_signature' => str_repeat('a', 64), 'header_row' => 1, 'mapping' => ['date' => 0, 'description' => 1, 'amount' => 2]]);

    $this->actingAs($this->admin)->get(route('admin.settings.edit'))
        ->assertInertia(fn ($page) => $page->component('admin/settings/Edit')->where('mappings.0.name', 'Amex export'));

    $this->actingAs($this->admin)->put(route('admin.column-mappings.update', $mapping), ['name' => 'Amex corporate'])->assertRedirect();
    expect($mapping->fresh()->name)->toBe('Amex corporate');

    $this->actingAs($this->admin)->delete(route('admin.column-mappings.destroy', $mapping))->assertRedirect();
    expect(ColumnMapping::count())->toBe(0);
});

it('keeps settings to admins', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.settings.edit'))->assertForbidden();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Admin/InboundLogTest.php tests/Feature/Admin/SettingsTest.php`
Expected: FAIL. Route `admin.inbound.index` is not defined.

- [ ] **Step 3: Write the settings store, controllers and routes**

`database/migrations/2026_10_14_000004_create_settings_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
```

`app/Models/Setting.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** App-wide settings an admin can change, stored as JSON by key. */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::query()->find($key)?->value ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /** @return list<int> days before the due date that new periods remind on */
    public static function reminderOffsets(): array
    {
        return array_values(array_map('intval', (array) static::get('reminder_offsets', [3, 0])));
    }
}
```

`app/Http/Controllers/Admin/InboundMessageController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InboundMessage;
use Inertia\Inertia;
use Inertia\Response;

class InboundMessageController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/inbound/Index', [
            'address' => config('cardtrail.receipts.address'),
            'messages' => InboundMessage::query()
                ->with('user:id,name')
                ->latest('id')
                ->paginate(50)
                ->through(fn (InboundMessage $message) => [
                    'id' => $message->id,
                    'from_email' => $message->from_email,
                    'subject' => $message->subject,
                    'user' => $message->user?->name,
                    'outcome' => $message->outcome->value,
                    'attachment_count' => $message->attachment_count,
                    'accepted_count' => $message->accepted_count,
                    'received_at' => ($message->received_at ?? $message->created_at)->toIso8601String(),
                ]),
        ]);
    }
}
```

`app/Http/Controllers/Admin/SettingsController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ColumnMapping;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('admin/settings/Edit', [
            'reminder_offsets' => implode(', ', Setting::reminderOffsets()),
            'mappings' => ColumnMapping::query()->orderBy('name')->get()->map(fn (ColumnMapping $mapping) => [
                'id' => $mapping->id,
                'name' => $mapping->name,
                'header_row' => $mapping->header_row,
                'created_at' => $mapping->created_at->toIso8601String(),
            ]),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $offsets = collect(explode(',', (string) $request->input('reminder_offsets', '')))
            ->map(fn (string $value) => trim($value))
            ->filter(fn (string $value) => $value !== '')
            ->values()
            ->all();

        Validator::make(['reminder_offsets' => $offsets], [
            'reminder_offsets' => ['array', 'max:5'],
            'reminder_offsets.*' => ['integer', 'min:0', 'max:60', 'distinct'],
        ], [
            'reminder_offsets.max' => 'Choose up to 5 reminder days.',
            'reminder_offsets.*.integer' => 'Reminder days must be whole numbers, like 3, 0.',
            'reminder_offsets.*.max' => 'Reminders can be at most 60 days before the due date.',
            'reminder_offsets.*.distinct' => 'Each reminder day can only appear once.',
        ])->after(function ($validator) {
            // Show every problem against the one field the form has.
            foreach ($validator->errors()->keys() as $key) {
                if ($key !== 'reminder_offsets') {
                    $validator->errors()->add('reminder_offsets', $validator->errors()->first($key));
                }
            }
        })->validate();

        $offsets = collect($offsets)->map(fn ($days) => (int) $days)->sortDesc()->values()->all();
        Setting::put('reminder_offsets', $offsets);

        activity('settings')
            ->causedBy($request->user())
            ->event('settings_updated')
            ->withProperties(['reminder_offsets' => $offsets])
            ->log('Updated default reminder days');

        return back()->with('success', 'Settings saved.');
    }
}
```

`app/Http/Controllers/Admin/ColumnMappingController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ColumnMapping;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Saved column matches are applied automatically to files with the same headers; admins can rename or forget them. */
class ColumnMappingController extends Controller
{
    public function update(Request $request, ColumnMapping $mapping): RedirectResponse
    {
        $mapping->update($request->validate(['name' => ['required', 'string', 'max:100']]));

        return back()->with('success', 'Column match renamed.');
    }

    public function destroy(ColumnMapping $mapping): RedirectResponse
    {
        $mapping->delete();

        return back()->with('success', "Forgot “{$mapping->name}”. The next file with those headers will ask for its columns again.");
    }
}
```

In `routes/admin.php`, add the imports `use App\Http\Controllers\Admin\ColumnMappingController;`, `use App\Http\Controllers\Admin\InboundMessageController;` and `use App\Http\Controllers\Admin\SettingsController;`, then add at the end of the admin group:

```php
    Route::get('inbound', [InboundMessageController::class, 'index'])->name('inbound.index');
    Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::put('column-mappings/{mapping}', [ColumnMappingController::class, 'update'])->name('column-mappings.update');
    Route::delete('column-mappings/{mapping}', [ColumnMappingController::class, 'destroy'])->name('column-mappings.destroy');
```

In `app/Http/Controllers/Admin/PeriodController.php`, add `use App\Models\Setting;` and change the default in `create()` from `'reminder_offsets' => '3, 0',` to:

```php
                'reminder_offsets' => implode(', ', Setting::reminderOffsets()),
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Admin`
Expected: PASS.

- [ ] **Step 5: Write the screens and menu entries**

`resources/js/pages/admin/inbound/Index.vue`:

```vue
<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';

type Outcome = 'accepted' | 'unknown_sender' | 'no_attachments' | 'rejected_type';

interface InboundRow {
    id: number;
    from_email: string | null;
    subject: string | null;
    user: string | null;
    outcome: Outcome;
    attachment_count: number;
    accepted_count: number;
    received_at: string;
}

defineProps<{
    address: string | null;
    messages: { data: InboundRow[]; prev_page_url: string | null; next_page_url: string | null; current_page: number; last_page: number };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Inbound email', href: '/admin/inbound' }];

const labels: Record<Outcome, string> = {
    accepted: 'Accepted',
    unknown_sender: 'Unknown sender',
    no_attachments: 'No attachments',
    rejected_type: 'Not a receipt type',
};

const styles: Record<Outcome, string> = {
    accepted: 'bg-green-100 text-green-900 dark:bg-green-950 dark:text-green-200',
    unknown_sender: 'bg-red-100 text-red-900 dark:bg-red-950 dark:text-red-200',
    no_attachments: 'bg-neutral-200 text-neutral-800 dark:bg-neutral-800 dark:text-neutral-200',
    rejected_type: 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200',
};
</script>

<template>
    <Head title="Inbound email" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-4 p-4">
            <div>
                <h1 class="text-xl font-semibold">Inbound email</h1>
                <p class="text-sm text-muted-foreground">
                    What happened to each email sent to
                    <strong v-if="address">{{ address }}</strong>
                    <span v-else>the receipts address (not set up yet)</span>. Nobody gets a reply when an email is turned away.
                </p>
            </div>

            <ul class="divide-y rounded-lg border">
                <li v-for="message in messages.data" :key="message.id" class="flex flex-col gap-1 p-4 text-sm sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <div class="truncate font-medium">{{ message.subject || '(no subject)' }}</div>
                        <div class="truncate text-muted-foreground">
                            {{ message.user ?? message.from_email ?? 'Unknown sender' }} · {{ formatDate(message.received_at) }}
                            <span v-if="message.attachment_count"> · {{ message.accepted_count }} of {{ message.attachment_count }} attachments kept</span>
                        </div>
                    </div>
                    <span class="inline-flex shrink-0 items-center self-start rounded-full px-2 py-0.5 text-xs font-medium sm:self-auto" :class="styles[message.outcome]">
                        {{ labels[message.outcome] }}
                    </span>
                </li>
                <li v-if="!messages.data.length" class="p-4 text-sm text-muted-foreground">No emails yet.</li>
            </ul>

            <div v-if="messages.last_page > 1" class="flex items-center justify-between text-sm">
                <Button variant="outline" size="sm" :disabled="!messages.prev_page_url" as-child>
                    <Link :href="messages.prev_page_url ?? '#'" preserve-scroll>Newer</Link>
                </Button>
                <span class="text-muted-foreground">Page {{ messages.current_page }} of {{ messages.last_page }}</span>
                <Button variant="outline" size="sm" :disabled="!messages.next_page_url" as-child>
                    <Link :href="messages.next_page_url ?? '#'" preserve-scroll>Older</Link>
                </Button>
            </div>
        </div>
    </AppLayout>
</template>
```

`resources/js/pages/admin/settings/Edit.vue`:

```vue
<script setup lang="ts">
import FlashMessage from '@/components/FlashMessage.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { reactive } from 'vue';

interface Mapping {
    id: number;
    name: string;
    header_row: number;
    created_at: string;
}

const props = defineProps<{ reminder_offsets: string; mappings: Mapping[] }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Admin settings', href: '/admin/settings' }];

const form = useForm({ reminder_offsets: props.reminder_offsets });
const save = () => form.put(route('admin.settings.update'), { preserveScroll: true });

const names = reactive<Record<number, string>>(Object.fromEntries(props.mappings.map((mapping) => [mapping.id, mapping.name])));

const rename = (mapping: Mapping) => {
    router.put(route('admin.column-mappings.update', mapping.id), { name: names[mapping.id] }, { preserveScroll: true });
};

const forget = (mapping: Mapping) => {
    if (confirm(`Forget “${mapping.name}”? The next file with those headers will ask for its columns again.`)) {
        router.delete(route('admin.column-mappings.destroy', mapping.id), { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Admin settings" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex max-w-2xl flex-col gap-6 p-4">
            <FlashMessage />
            <h1 class="text-xl font-semibold">Admin settings</h1>

            <form class="flex flex-col gap-2 rounded-xl border p-4" @submit.prevent="save">
                <Label for="reminder_offsets">Default reminder days for new periods</Label>
                <Input id="reminder_offsets" v-model="form.reminder_offsets" placeholder="3, 0" />
                <p class="text-xs text-muted-foreground">Days before the due date, separated by commas. 0 means on the day itself.</p>
                <p v-if="form.errors.reminder_offsets" class="text-sm text-red-600 dark:text-red-400">{{ form.errors.reminder_offsets }}</p>
                <Button type="submit" class="self-start" :disabled="form.processing">Save</Button>
            </form>

            <section class="flex flex-col gap-2">
                <h2 class="font-medium">Saved column matches</h2>
                <p class="text-sm text-muted-foreground">Used automatically when an upload has the same column headings.</p>
                <ul class="divide-y rounded-lg border">
                    <li v-for="mapping in mappings" :key="mapping.id" class="flex flex-col gap-2 p-4 sm:flex-row sm:items-center">
                        <Input v-model="names[mapping.id]" class="sm:flex-1" :aria-label="`Name for ${mapping.name}`" />
                        <span class="text-xs text-muted-foreground">Headers on row {{ mapping.header_row }} · saved {{ formatDate(mapping.created_at) }}</span>
                        <div class="flex gap-2">
                            <Button size="sm" variant="outline" :disabled="!names[mapping.id]?.trim() || names[mapping.id] === mapping.name" @click="rename(mapping)">Rename</Button>
                            <Button size="sm" variant="ghost" @click="forget(mapping)">Forget</Button>
                        </div>
                    </li>
                    <li v-if="!mappings.length" class="p-4 text-sm text-muted-foreground">No saved column matches yet. They’re saved when you confirm an upload.</li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
```

In `resources/js/components/AppSidebar.vue`, change the lucide import to `import { CalendarRange, Inbox, LayoutGrid, Mail, SlidersHorizontal, Users } from 'lucide-vue-next';` and replace the admin items array with:

```ts
              { title: 'Periods', href: '/admin/periods', icon: CalendarRange },
              { title: 'Manage users', href: '/admin/users', icon: Users },
              { title: 'Inbound email', href: '/admin/inbound', icon: Mail },
              { title: 'Admin settings', href: '/admin/settings', icon: SlidersHorizontal },
```

- [ ] **Step 6: Build and commit**

Run: `npm run build`
Expected: build succeeds.

```bash
git add -A app database routes resources/js tests
git commit -m "feat: inbound email log and admin settings"
```

---

### Task 6: Installable app and push prompt

**Files:**
- Create: `public/manifest.webmanifest`, `public/sw.js`, `public/icons/icon-192.png`, `public/icons/icon-512.png`, `public/icons/icon-maskable-512.png`, `public/icons/apple-touch-icon.png`, `resources/js/components/EnablePush.vue`
- Modify: `resources/views/app.blade.php`, `resources/js/app.ts`, `resources/js/pages/Dashboard.vue`
- Test: `tests/Feature/PwaTest.php`

**Interfaces:**
- Consumes: `push.vapid_public_key` (Task 1), route `push-subscriptions.store`, and the web-push payload from `CardTrailNotification::toWebPush()` (`title`, `body`, `icon`, `data.url`).
- Produces: the installable app shell and a one-time prompt to enable notifications.

- [ ] **Step 1: Write the failing test**

`tests/Feature/PwaTest.php`:

```php
<?php

it('describes the installable app with icons that exist at the stated sizes', function () {
    $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest)
        ->name->toBe('CardTrail')
        ->short_name->toBe('CardTrail')
        ->display->toBe('standalone')
        ->start_url->toBe('/dashboard')
        ->scope->toBe('/');

    foreach ($manifest['icons'] as $icon) {
        $path = public_path(ltrim($icon['src'], '/'));
        expect($path)->toBeFile();
        [$width, $height] = getimagesize($path);
        expect("{$width}x{$height}")->toBe($icon['sizes']);
    }
});

it('links the manifest and Home Screen icon from every page', function () {
    $this->get('/login')
        ->assertSee('<link rel="manifest" href="/manifest.webmanifest">', false)
        ->assertSee('<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">', false)
        ->assertSee('<meta name="apple-mobile-web-app-capable" content="yes">', false);

    expect(public_path('icons/apple-touch-icon.png'))->toBeFile();
});

it('has a service worker that shows pushes and only ever opens CardTrail pages', function () {
    $worker = file_get_contents(public_path('sw.js'));

    expect($worker)
        ->toContain("addEventListener('push'")
        ->toContain("addEventListener('notificationclick'")
        ->toContain('url.origin !== self.location.origin');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/PwaTest.php`
Expected: FAIL. `public/manifest.webmanifest` does not exist.

- [ ] **Step 3: Make the icons**

Run this from the repo root. It draws a simple invented icon (a white card with a stripe and a trail of dots on dark blue) at each size:

```bash
mkdir -p public/icons && php -r '
function icon(int $size, string $path, float $inset = 0.0): void {
    $image = imagecreatetruecolor($size, $size);
    imagefill($image, 0, 0, imagecolorallocate($image, 30, 58, 138));
    $white = imagecolorallocate($image, 255, 255, 255);
    $blue = imagecolorallocate($image, 30, 58, 138);
    $pad = (int) round($size * (0.2 + $inset));
    $cardTop = (int) round($size * (0.3 + $inset / 2));
    $cardBottom = (int) round($size * (0.62 - $inset / 2));
    imagefilledrectangle($image, $pad, $cardTop, $size - $pad, $cardBottom, $white);
    imagefilledrectangle($image, $pad, $cardTop + (int) round($size * 0.06), $size - $pad, $cardTop + (int) round($size * 0.11), $blue);
    $dot = max(2, (int) round($size * 0.05));
    foreach ([0.3, 0.45, 0.6, 0.75] as $i => $x) {
        imagefilledellipse($image, (int) round($size * ($x - $inset / 2 + $inset * $i / 3)), (int) round($size * (0.76 - $inset / 3)), $dot, $dot, $white);
    }
    imagepng($image, $path);
}
icon(192, "public/icons/icon-192.png");
icon(512, "public/icons/icon-512.png");
icon(512, "public/icons/icon-maskable-512.png", 0.08);
icon(180, "public/icons/apple-touch-icon.png");
'
```

Expected: four PNG files in `public/icons/`.

- [ ] **Step 4: Write the manifest, service worker, layout tags and push prompt**

`public/manifest.webmanifest`:

```json
{
    "name": "CardTrail",
    "short_name": "CardTrail",
    "description": "Card reconciliations for Crown Group cardholders and accounts",
    "start_url": "/dashboard",
    "scope": "/",
    "display": "standalone",
    "background_color": "#ffffff",
    "theme_color": "#1e3a8a",
    "icons": [
        { "src": "/icons/icon-192.png", "sizes": "192x192", "type": "image/png" },
        { "src": "/icons/icon-512.png", "sizes": "512x512", "type": "image/png" },
        { "src": "/icons/icon-maskable-512.png", "sizes": "512x512", "type": "image/png", "purpose": "maskable" }
    ]
}
```

`public/sw.js`:

```js
// CardTrail service worker: shows push notifications and opens the right page when one is tapped.
// v1 deliberately caches nothing for offline use.

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
    let payload = {};

    try {
        payload = event.data ? event.data.json() : {};
    } catch {
        payload = { body: event.data ? event.data.text() : '' };
    }

    event.waitUntil(
        self.registration.showNotification(payload.title || 'CardTrail', {
            body: payload.body || '',
            icon: payload.icon || '/icons/icon-192.png',
            data: payload.data || {},
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const url = new URL((event.notification.data && event.notification.data.url) || '/dashboard', self.location.origin);

    // Only ever open CardTrail's own pages, whatever a payload says.
    if (url.origin !== self.location.origin) {
        return;
    }

    event.waitUntil(
        (async () => {
            const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

            for (const client of windows) {
                if ('focus' in client) {
                    await client.focus();

                    return 'navigate' in client ? client.navigate(url.href) : undefined;
                }
            }

            return self.clients.openWindow(url.href);
        })(),
    );
});
```

`resources/views/app.blade.php` (whole file, replacing the current one):

```blade
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <link rel="manifest" href="/manifest.webmanifest">
        <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
        <meta name="theme-color" content="#1e3a8a">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-title" content="CardTrail">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

        @routes
        @vite(['resources/js/app.ts'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
```

At the end of `resources/js/app.ts`, append:

```ts
// The service worker only handles push notifications; it needs HTTPS (or localhost).
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {
            // Push simply stays unavailable; email still works.
        });
    });
}
```

`resources/js/components/EnablePush.vue`:

```vue
<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { BellRing } from 'lucide-vue-next';
import { onMounted, ref } from 'vue';

const DISMISSED = 'cardtrail.push-prompt-dismissed';

const page = usePage<SharedData>();
const visible = ref(false);
const working = ref(false);
const problem = ref<string | null>(null);

const standalone = () =>
    window.matchMedia('(display-mode: standalone)').matches || (navigator as Navigator & { standalone?: boolean }).standalone === true;

const isIos = () => /iphone|ipad|ipod/i.test(navigator.userAgent);

const remembered = () => {
    try {
        return localStorage.getItem(DISMISSED) === '1';
    } catch {
        return false;
    }
};

onMounted(() => {
    const supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window && !!page.props.push.vapid_public_key;

    // iPhones only allow web push from an app added to the Home Screen.
    visible.value = supported && Notification.permission === 'default' && (standalone() || !isIos()) && !remembered();
});

const dismiss = () => {
    visible.value = false;

    try {
        localStorage.setItem(DISMISSED, '1');
    } catch {
        // Private browsing: the prompt may come back next time, which is fine.
    }
};

const keyBytes = (base64: string) => {
    const padded = (base64 + '='.repeat((4 - (base64.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');

    return Uint8Array.from(atob(padded), (character) => character.charCodeAt(0));
};

const enable = async () => {
    working.value = true;
    problem.value = null;

    try {
        if ((await Notification.requestPermission()) !== 'granted') {
            dismiss();

            return;
        }

        const registration = await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: keyBytes(page.props.push.vapid_public_key as string),
        });
        const json = subscription.toJSON();
        const encodings = (PushManager as unknown as { supportedContentEncodings?: string[] }).supportedContentEncodings ?? [];

        await axios.post(route('push-subscriptions.store'), {
            endpoint: json.endpoint,
            keys: json.keys,
            content_encoding: encodings.includes('aes128gcm') || encodings.length === 0 ? 'aes128gcm' : 'aesgcm',
        });

        visible.value = false;
    } catch {
        problem.value = 'Couldn’t turn on notifications on this device. You’ll still get emails.';
    } finally {
        working.value = false;
    }
};
</script>

<template>
    <section v-if="visible" class="flex flex-col gap-3 rounded-xl border p-4 sm:flex-row sm:items-center">
        <BellRing class="size-5 shrink-0" />
        <div class="flex-1 text-sm">
            <p>Get a notification on this device when a month is ready, a deadline is near, or accounts have a question.</p>
            <p v-if="problem" class="mt-1 text-red-600 dark:text-red-400">{{ problem }}</p>
        </div>
        <div class="flex gap-2">
            <Button :disabled="working" @click="enable">Turn on</Button>
            <Button variant="ghost" @click="dismiss">Not now</Button>
        </div>
    </section>
</template>
```

In `resources/js/pages/Dashboard.vue`, add `import EnablePush from '@/components/EnablePush.vue';` to the imports and put `<EnablePush />` on the line after `<FlashMessage />`.

- [ ] **Step 5: Run test to verify it passes, then the full suite and build**

Run: `php artisan test tests/Feature/PwaTest.php && php artisan test && npm run build`
Expected: PASS (3 tests); all pass; build succeeds.

- [ ] **Step 6: Commit**

```bash
git add -A public/manifest.webmanifest public/sw.js public/icons resources tests
git commit -m "feat: installable app with push notifications"
```
