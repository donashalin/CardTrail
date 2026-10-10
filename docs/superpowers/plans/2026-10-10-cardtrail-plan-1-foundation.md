# CardTrail Plan 1 — Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A running CardTrail app where admins manage users (roles, card identifiers, Microsoft 365 flag, active flag, invitations) and everyone signs in through an email-first login that routes to a password step or a pluggable external identity provider.

**Architecture:** Laravel 12 + Inertia + Vue 3 (TypeScript) scaffolded from the official Vue starter kit, the same base as GigRadar. External sign-in goes only through an `App\Auth\IdentityProvider` interface; v1 ships a fake driver that is bound only in `local`/`testing`, and the developer later adds a `crown-ad` adapter around the internal AD Composer package. Admin screens live under `/admin`, protected by an `admin` middleware; an always-on middleware signs out deactivated users.

**Tech Stack:** PHP 8.3, Laravel 12, Inertia 2, Vue 3 + TypeScript, Tailwind (starter-kit default), radix-vue UI components from the kit, Ziggy `route()`, Pest 3, MySQL 8.4 (dev) / SQLite in-memory (tests), `spatie/laravel-activitylog` ^4.10.

**Spec:** `docs/superpowers/specs/2026-10-10-cardtrail-design.md`

## Roadmap (this plan is 1 of 5)

The spec is split into five plans, each ending in working, tested software. Later plans are written after the previous one ships, so they can build on the real code.

1. **Foundation** (this plan) — scaffold, users and roles, email-first sign-in, identity-provider interface + fake driver, Manage Users, invitations, profile lock-down, app shell, demo seeder. Spec §3, §5 (User, CardIdentifier, activity log), §6.1, §7 screen 9, §9.
2. **Periods and imports** — periods, spreadsheet parsers, column mapping and saved mappings, import wizard, duplicates, auto-assignment, Unassigned and reassignment, publish, late imports. Spec §4.1–4.3, §5 (Period, Import, ColumnMapping, Reconciliation, Transaction), §6.2, §7 screens 5–7.
3. **Cardholder flow** — home, transaction list and detail, purpose autosave and suggestions, receipt upload/storage/policy/image processing, line-done rules, submit, receipt inbox with bulk upload and matching. Spec §4.4–4.5, §5.1, §6.3, §7 screens 1–4.
4. **Review and export** — review split view, approve/query/comments, resubmit, close period, export job (spreadsheet writing + ZIP), pruning. Spec §4.6–4.8, §6.5, §6.6, §7 screen 8.
5. **Notifications, email-in and PWA** — listeners for the domain events dispatched by Plans 2–4, deadline-reminder scheduler, web push, PWA, `InboundMailSource` + `eml` driver + inbound log, settings screen. Spec §6.4, §6.7, §6.8, §7 screens 10–11.

## Global Constraints

- PHP 8.3, Laravel 12, Inertia 2, Vue 3 + TypeScript, Pest 3; MySQL database `cardtrail` in development, SQLite `:memory:` in tests (`phpunit.xml`).
- Git author for this repo is `donashalin <donashalin@gmail.com>` (already set in the repo's local config). Never commit `.env`.
- **No real data anywhere** — not in seeders, fixtures, tests or commits. Use invented names, `@cardtrail.test` email addresses and made-up last-4 digits.
- Only the last 4 digits of a card number are ever stored.
- No Laravel Socialite. External sign-in happens only through `App\Auth\IdentityProvider`. The `fake` driver must never be resolvable outside `local` and `testing`.
- No public registration. No account enumeration: the email step behaves identically for unknown, inactive and password users.
- Users are deactivated, never deleted (no self-service account deletion).
- A user with `use_microsoft = true` can never sign in with a password.
- Emails are stored lower-cased and trimmed.
- UI copy is UK English ("organisation", "authorise", "colour").
- Run commands from `/Users/shalin/Sites/CardTrail` unless a step says otherwise.

## Review Focus

1. **Email typed with different case or spaces** (`"  Sam@CardTrail.TEST "`) must reach the same account at the email step, the password step and the invitation step — pinned in Task 2 (mutator) and Task 3 (login tests).
2. **A user deactivated while signed in** must be signed out on their very next request, not just blocked at the next login — pinned in Task 3.
3. **An admin editing their own account** must not be able to remove their admin role, deactivate themselves, or switch their own sign-in method (which could lock everyone out) — pinned in Task 5.
4. **Switching a user from Microsoft 365 to password when they have never had a password** must email them a set-password link, otherwise they can't sign in at all — pinned in Task 6.
5. **An external identity whose email matches but whose external ID differs from the one already stored** must be rejected (a different directory account claiming the same email) — pinned in Task 4.

---

## File Structure

```
app/
  Auth/
    ExternalIdentity.php              # value object returned by identity providers
    IdentityProvider.php              # interface: redirect(), user(Request)
    IdentityProviderException.php
    Fake/FakeIdentityProvider.php     # local/testing-only driver
  Http/
    Controllers/
      Admin/UserController.php        # Manage Users CRUD
      Admin/UserInvitationController.php  # resend invite
      Auth/AcceptInvitationController.php # set password from invite link
      Auth/AuthenticatedSessionController.php  # (modified) email step page, password step page, login, logout
      Auth/FakeIdentityController.php # fake "Microsoft" sign-in page
      Auth/IdentityController.php     # redirect + callback for external sign-in
      Auth/LoginEmailController.php   # email step: decide password vs external
      Settings/PasswordController.php # (modified) blocked for Microsoft users
      Settings/ProfileController.php  # (modified) name only, no delete
    Middleware/
      EnsureUserIsActive.php
      EnsureUserIsAdmin.php
      HandleInertiaRequests.php       # (modified) flash messages
    Requests/
      Admin/UserRequest.php
      Auth/LoginRequest.php           # (modified) active + non-Microsoft only
      Settings/ProfileUpdateRequest.php  # (modified) name only
  Models/
    CardIdentifier.php
    User.php                          # (modified)
  Notifications/UserInvited.php
  Providers/AppServiceProvider.php    # (modified) IdentityProvider binding
config/
  auth.php                            # (modified) "invites" password broker
  cardtrail.php
  inertia.php                         # testing page path
database/
  factories/CardIdentifierFactory.php
  factories/UserFactory.php           # (modified) states
  migrations/2026_10_10_000001_add_cardtrail_fields_to_users_table.php
  migrations/2026_10_10_000002_create_card_identifiers_table.php
  seeders/DatabaseSeeder.php          # (modified)
  seeders/DemoSeeder.php
resources/js/
  components/AppLogo.vue              # (modified) CardTrail name
  components/AppSidebar.vue           # (modified) role-aware nav
  components/FlashMessage.vue
  layouts/settings/Layout.vue         # (modified) hide Password for Microsoft users
  pages/Dashboard.vue                 # (replaced)
  pages/admin/users/Index.vue
  pages/admin/users/Form.vue
  pages/auth/AcceptInvite.vue
  pages/auth/FakeIdentity.vue
  pages/auth/Login.vue                # (replaced) email step
  pages/auth/LoginPassword.vue        # password step
  pages/settings/Profile.vue          # (modified)
  types/index.ts                      # (modified) User fields
routes/
  admin.php
  auth.php                            # (modified)
  settings.php                        # (modified)
  web.php                             # (modified)
tests/Feature/
  Admin/ManageUsersTest.php
  Auth/EmailFirstLoginTest.php
  Auth/IdentityProviderTest.php
  Auth/InvitationTest.php
  DemoSeederTest.php
  ExampleTest.php                     # (replaced)
  Models/UserTest.php
  NavigationTest.php
  Settings/ProfileUpdateTest.php      # (replaced)
  Settings/MicrosoftUserPasswordTest.php
```

---

### Task 1: Scaffold CardTrail from the Vue starter kit

**Files:**
- Create: the whole starter-kit tree in `/Users/shalin/Sites/CardTrail` (existing `docs/` and `.git` are kept)
- Create: `config/inertia.php`
- Modify: `.env`, `.env.example`

**Interfaces:**
- Consumes: nothing.
- Produces: a booting Laravel app at `/Users/shalin/Sites/CardTrail` with the starter kit's auth controllers in `app/Http/Controllers/Auth/`, `app/Http/Requests/Auth/LoginRequest.php`, Vue pages in `resources/js/pages/`, Ziggy's `route()` helper, Pest in `tests/Pest.php`.

- [ ] **Step 1: Make sure MySQL is running and create the database**

```bash
brew services start mysql
mysql -u root -e 'CREATE DATABASE IF NOT EXISTS cardtrail CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
```
Expected: the `mysql` command exits silently. (If root has a password, add `-p` and use that password in Step 5.)

- [ ] **Step 2: Create the starter kit in a sibling folder and move it in**

```bash
cd /Users/shalin/Sites
composer create-project laravel/vue-starter-kit cardtrail-scaffold
rsync -a --exclude .git cardtrail-scaffold/ CardTrail/
rm -rf cardtrail-scaffold
cd CardTrail
git log --oneline
```
Expected: `ls` shows `app/ artisan composer.json docs/ resources/ routes/ tests/ ...`; `git log` still shows the spec commit.

- [ ] **Step 3: Check the kit has the structure this plan assumes**

```bash
ls app/Http/Controllers/Auth/AuthenticatedSessionController.php \
   app/Http/Controllers/Auth/RegisteredUserController.php \
   app/Http/Requests/Auth/LoginRequest.php \
   app/Http/Controllers/Settings/ProfileController.php \
   resources/js/pages/auth/Login.vue \
   resources/js/components/AppSidebar.vue \
   resources/js/types/index.ts \
   routes/auth.php routes/settings.php tests/Pest.php
grep -c '"tightenco/ziggy"' composer.json
```
Expected: every file is listed and the `grep` prints `1`.
**If any file is missing** (e.g. the kit has moved to Laravel Fortify or Wayfinder), STOP and report back. The later tasks edit these exact files and must be revised first.

- [ ] **Step 4: Confirm Pest is configured for feature tests**

Run: `grep -n "RefreshDatabase" tests/Pest.php`
Expected: a line containing `->use(Illuminate\Foundation\Testing\RefreshDatabase::class)` (or `->use(RefreshDatabase::class)` with an import), applied `->in('Feature')`.

- [ ] **Step 5: Point `.env` at MySQL and name the app**

In `.env`, set these lines (replace the `DB_CONNECTION=sqlite` line and uncomment the other `DB_*` lines):
```dotenv
APP_NAME=CardTrail
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=cardtrail
DB_USERNAME=root
DB_PASSWORD=
QUEUE_CONNECTION=database
MAIL_MAILER=log
CARDTRAIL_IDENTITY_DRIVER=fake
```
In `.env.example`, set `APP_NAME=CardTrail` and add `CARDTRAIL_IDENTITY_DRIVER=fake`. Then:
```bash
rm -f database/database.sqlite
php artisan migrate
```
Expected: migrations run against MySQL with no errors.

- [ ] **Step 6: Point Inertia's page-existence check at the kit's lowercase `pages` folder**

Create `config/inertia.php`:
```php
<?php

return [
    'testing' => [
        'ensure_pages_exist' => true,
        'page_paths' => [resource_path('js/pages')],
        'page_extensions' => ['vue'],
    ],
];
```

- [ ] **Step 7: Install frontend dependencies, build and run the tests**

```bash
npm install
npm run build
php artisan test
```
Expected: build succeeds; all starter-kit tests PASS.

- [ ] **Step 8: Commit**

```bash
git config user.email   # must print donashalin@gmail.com
git add -A
git status --short | grep -E '^A  \.env$' && echo "STOP: .env is staged" || true
git commit -m "chore: scaffold Laravel Vue starter kit

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```
Expected: no "STOP" line (the kit's `.gitignore` excludes `.env`); commit succeeds.

---

### Task 2: User roles, sign-in flags and card identifiers

**Files:**
- Create: `database/migrations/2026_10_10_000001_add_cardtrail_fields_to_users_table.php`
- Create: `database/migrations/2026_10_10_000002_create_card_identifiers_table.php`
- Create: `app/Models/CardIdentifier.php`
- Create: `database/factories/CardIdentifierFactory.php`
- Modify: `app/Models/User.php`
- Modify: `database/factories/UserFactory.php`
- Test: `tests/Feature/Models/UserTest.php`

**Interfaces:**
- Consumes: the starter kit's `User` model and `users` table (Task 1).
- Produces:
  - `users` columns: `password` (nullable), `use_microsoft` bool default false, `external_id` string nullable unique, `is_admin` bool default false, `is_cardholder` bool default true, `active` bool default true.
  - `card_identifiers` table: `id`, `user_id`, `last4` char(4) nullable, `cardholder_name` string(100) nullable, timestamps.
  - `User::cardIdentifiers(): HasMany`, `User::scopeActive(Builder)`, `CardIdentifier::user(): BelongsTo`.
  - `User` mass-assignable: `name, email, password, use_microsoft, is_admin, is_cardholder, active`. `external_id` is set only through `forceFill`.
  - `User` hidden: `password, remember_token, external_id`.
  - Factory states: `UserFactory::admin()`, `::microsoft()` (sets `password` null), `::inactive()`; `CardIdentifierFactory::nameOnly(string $name)`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Models/UserTest.php`:
```php
<?php

use App\Models\CardIdentifier;
use App\Models\User;

it('defaults new users to an active, password-based cardholder', function () {
    $user = User::factory()->create()->fresh();

    expect($user->active)->toBeTrue()
        ->and($user->is_cardholder)->toBeTrue()
        ->and($user->is_admin)->toBeFalse()
        ->and($user->use_microsoft)->toBeFalse()
        ->and($user->external_id)->toBeNull();
});

it('stores emails lower-cased and trimmed', function () {
    $user = User::factory()->create(['email' => '  Jane.Doe@CardTrail.TEST ']);

    expect($user->fresh()->email)->toBe('jane.doe@cardtrail.test');
});

it('allows Microsoft 365 users to have no password', function () {
    $user = User::factory()->microsoft()->create()->fresh();

    expect($user->password)->toBeNull()
        ->and($user->use_microsoft)->toBeTrue();
});

it('has many card identifiers', function () {
    $user = User::factory()->create();
    CardIdentifier::factory()->for($user)->create(['last4' => '1234']);
    CardIdentifier::factory()->for($user)->nameOnly('J DOE')->create();

    expect($user->cardIdentifiers)->toHaveCount(2)
        ->and($user->cardIdentifiers->pluck('last4')->all())->toContain('1234')
        ->and($user->cardIdentifiers->pluck('cardholder_name')->all())->toContain('J DOE');
});

it('deletes card identifiers with their user', function () {
    $user = User::factory()->create();
    CardIdentifier::factory()->for($user)->create();

    $user->delete();

    expect(CardIdentifier::count())->toBe(0);
});

it('hides secrets when serialised', function () {
    $user = User::factory()->create(['external_id' => 'ext-123']);

    expect($user->toArray())->not->toHaveKeys(['password', 'remember_token', 'external_id']);
});

it('scopes to active users', function () {
    User::factory()->create(['email' => 'on@cardtrail.test']);
    User::factory()->inactive()->create(['email' => 'off@cardtrail.test']);

    expect(User::active()->pluck('email')->all())->toBe(['on@cardtrail.test']);
});
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `php artisan test tests/Feature/Models/UserTest.php`
Expected: FAIL — `Class "App\Models\CardIdentifier" not found` / unknown column `active`.

- [ ] **Step 3: Write the users migration**

Create `database/migrations/2026_10_10_000001_add_cardtrail_fields_to_users_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
            $table->boolean('use_microsoft')->default(false)->after('password');
            $table->string('external_id')->nullable()->unique()->after('use_microsoft');
            $table->boolean('is_admin')->default(false)->after('external_id');
            $table->boolean('is_cardholder')->default(true)->after('is_admin');
            $table->boolean('active')->default(true)->after('is_cardholder');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['external_id']);
            $table->dropColumn(['use_microsoft', 'external_id', 'is_admin', 'is_cardholder', 'active']);
            $table->string('password')->nullable(false)->change();
        });
    }
};
```

- [ ] **Step 4: Write the card identifiers migration**

Create `database/migrations/2026_10_10_000002_create_card_identifiers_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_identifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('last4', 4)->nullable()->index();
            $table->string('cardholder_name', 100)->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_identifiers');
    }
};
```

- [ ] **Step 5: Create the CardIdentifier model and factory**

Create `app/Models/CardIdentifier.php`:
```php
<?php

namespace App\Models;

use Database\Factories\CardIdentifierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CardIdentifier extends Model
{
    /** @use HasFactory<CardIdentifierFactory> */
    use HasFactory;

    protected $fillable = ['last4', 'cardholder_name'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

Create `database/factories/CardIdentifierFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\CardIdentifier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CardIdentifier>
 */
class CardIdentifierFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'last4' => fake()->numerify('####'),
            'cardholder_name' => null,
        ];
    }

    public function nameOnly(string $name): static
    {
        return $this->state(fn () => ['last4' => null, 'cardholder_name' => $name]);
    }
}
```

- [ ] **Step 6: Update the User model**

In `app/Models/User.php`:
- Add imports:
```php
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
```
- Replace `$fillable` and `$hidden` with:
```php
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'use_microsoft',
        'is_admin',
        'is_cardholder',
        'active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'external_id',
    ];
```
- Replace the `casts()` return array with:
```php
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'use_microsoft' => 'boolean',
            'is_admin' => 'boolean',
            'is_cardholder' => 'boolean',
            'active' => 'boolean',
        ];
```
- Add these methods at the end of the class:
```php
    /** Emails are compared case-insensitively everywhere, so store them normalised. */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value) => Str::lower(trim($value)));
    }

    public function cardIdentifiers(): HasMany
    {
        return $this->hasMany(CardIdentifier::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }
```

- [ ] **Step 7: Add the factory states**

In `database/factories/UserFactory.php`, add after `unverified()`:
```php
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => ['is_admin' => true]);
    }

    public function microsoft(): static
    {
        return $this->state(fn (array $attributes) => ['use_microsoft' => true, 'password' => null]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['active' => false]);
    }
```

- [ ] **Step 8: Run the tests to see them pass**

Run: `php artisan migrate && php artisan test tests/Feature/Models/UserTest.php`
Expected: migration runs on MySQL; 7 tests PASS.

- [ ] **Step 9: Run the full suite**

Run: `php artisan test`
Expected: all PASS.

- [ ] **Step 10: Commit**

```bash
git add app/Models database/migrations database/factories tests/Feature/Models
git commit -m "feat: add user roles, sign-in flags and card identifiers

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Email-first sign-in with password step

**Files:**
- Create: `app/Http/Controllers/Auth/LoginEmailController.php`
- Create: `app/Http/Middleware/EnsureUserIsActive.php`
- Create: `resources/js/pages/auth/LoginPassword.vue`
- Modify: `app/Http/Controllers/Auth/AuthenticatedSessionController.php`
- Modify: `app/Http/Requests/Auth/LoginRequest.php`
- Modify: `bootstrap/app.php`
- Modify: `routes/auth.php`, `routes/web.php`
- Replace: `resources/js/pages/auth/Login.vue`
- Replace: `tests/Feature/ExampleTest.php`
- Delete: `app/Http/Controllers/Auth/RegisteredUserController.php`, `resources/js/pages/auth/Register.vue`, `resources/js/pages/Welcome.vue`, `tests/Feature/Auth/RegistrationTest.php`
- Test: `tests/Feature/Auth/EmailFirstLoginTest.php`

**Interfaces:**
- Consumes: `User` fields and `User::active()` from Task 2.
- Produces:
  - Routes: `GET /login` (`login`, email step, page `auth/Login`), `POST /login/email` (`login.email`), `GET /login/password` (`login.password`, page `auth/LoginPassword`), `POST /login` (unchanged name), `/` (`home`) redirects to `/dashboard`.
  - Session key `login.email` holds the normalised email chosen at the email step (Task 4's fake provider reads it).
  - Flash key `error` is shown in red on the `auth/Login` page (Task 4 and the active middleware use it).
  - The email step redirects users with `active && use_microsoft` to route `identity.redirect`, which Task 4 creates. Until Task 4 exists, this plan's tests for that branch live in Task 4.
  - Activity log (`spatie/laravel-activitylog`) installed; a successful sign-in logs `log_name = auth`, `event = login`.

- [ ] **Step 1: Install the activity log package**

```bash
composer require spatie/laravel-activitylog:^4.10
php artisan vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider" --tag="activitylog-migrations"
php artisan migrate
```
Expected: three `activity_log` migrations are published and run.

- [ ] **Step 2: Write the failing tests**

Create `tests/Feature/Auth/EmailFirstLoginTest.php`:
```php
<?php

use App\Models\User;
use Spatie\Activitylog\Models\Activity;

it('shows the email step', function () {
    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/Login'));
});

it('sends password users to the password step', function () {
    User::factory()->create(['email' => 'sam@cardtrail.test']);

    $this->post('/login/email', ['email' => 'sam@cardtrail.test'])
        ->assertRedirect(route('login.password'))
        ->assertSessionHas('login.email', 'sam@cardtrail.test');
});

it('normalises the email at the email step', function () {
    User::factory()->create(['email' => 'sam@cardtrail.test']);

    $this->post('/login/email', ['email' => '  Sam@CardTrail.TEST '])
        ->assertSessionHas('login.email', 'sam@cardtrail.test');
});

it('treats unknown and inactive emails exactly like password users', function (string $email) {
    User::factory()->inactive()->create(['email' => 'gone@cardtrail.test']);
    User::factory()->microsoft()->inactive()->create(['email' => 'gone-ms@cardtrail.test']);

    $this->post('/login/email', ['email' => $email])
        ->assertRedirect(route('login.password'));
})->with(['nobody@cardtrail.test', 'gone@cardtrail.test', 'gone-ms@cardtrail.test']);

it('shows the password step with the chosen email', function () {
    $this->withSession(['login.email' => 'sam@cardtrail.test'])
        ->get('/login/password')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('auth/LoginPassword')
            ->where('email', 'sam@cardtrail.test'));
});

it('sends people back to the email step if the password step has no email', function () {
    $this->get('/login/password')->assertRedirect(route('login'));
});

it('signs in a password user regardless of email case', function () {
    $user = User::factory()->create(['email' => 'sam@cardtrail.test']);

    $this->post('/login', ['email' => ' SAM@cardtrail.test', 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

it('logs successful password sign-ins', function () {
    $user = User::factory()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    $activity = Activity::where('log_name', 'auth')->where('event', 'login')->sole();
    expect($activity->causer_id)->toBe($user->id)
        ->and($activity->description)->toBe('Signed in with password');
});

it('refuses password sign-in for inactive users', function () {
    $user = User::factory()->inactive()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('refuses password sign-in for Microsoft 365 users even if they have a password', function () {
    $user = User::factory()->create(['use_microsoft' => true]);

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('signs out a user who was deactivated during their session', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/dashboard')->assertOk();

    $user->update(['active' => false]);

    $this->get('/dashboard')
        ->assertRedirect(route('login'))
        ->assertSessionHas('error');
    $this->assertGuest();
});

it('has no public registration', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', [])->assertNotFound();
});
```

Replace `tests/Feature/ExampleTest.php` with:
```php
<?php

it('redirects the home page to the dashboard', function () {
    $this->get('/')->assertRedirect('/dashboard');
});
```

Delete the registration test: `git rm tests/Feature/Auth/RegistrationTest.php`

- [ ] **Step 3: Run the tests to see them fail**

Run: `php artisan test tests/Feature/Auth/EmailFirstLoginTest.php tests/Feature/ExampleTest.php`
Expected: FAIL — `Route [login.password] not defined`, `/` returns 200, `/register` returns 200.

- [ ] **Step 4: Remove registration and the welcome page**

```bash
git rm app/Http/Controllers/Auth/RegisteredUserController.php resources/js/pages/auth/Register.vue resources/js/pages/Welcome.vue
```
In `routes/auth.php`, delete the `use App\Http\Controllers\Auth\RegisteredUserController;` import and the two `register` routes.

In `routes/web.php`, replace the `/` route with:
```php
Route::redirect('/', '/dashboard')->name('home');
```
and remove the now-unused `use Inertia\Inertia;` import only if nothing else in the file uses it (the dashboard closure still does — keep it).

Run: `grep -rn "route('register')\|RegisteredUser" resources/js app routes`
Expected: only `resources/js/pages/auth/Login.vue` matches (its "Sign up" link); the replacement `Login.vue` in Step 10 removes it.

- [ ] **Step 5: Restrict password sign-in to active, non-Microsoft users**

In `app/Http/Requests/Auth/LoginRequest.php`:
- Add a `prepareForValidation` method above `rules()`:
```php
    protected function prepareForValidation(): void
    {
        $this->merge(['email' => Str::lower(trim((string) $this->input('email')))]);
    }
```
- In `authenticate()`, replace the `Auth::attempt(...)` condition with:
```php
        $credentials = [
            ...$this->only('email', 'password'),
            'active' => true,
            'use_microsoft' => false,
        ];

        if (! Auth::attempt($credentials, $this->boolean('remember'))) {
```
(Extra keys become `where` clauses in Laravel's user provider, so inactive and Microsoft users fail with the same generic message.)

- [ ] **Step 6: Add the email-step controller**

Create `app/Http/Controllers/Auth/LoginEmailController.php`:
```php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class LoginEmailController extends Controller
{
    /**
     * Decide whether this email signs in with a password or the external identity provider.
     * Unknown and inactive emails get the password step so the response never reveals who has an account.
     */
    public function __invoke(Request $request): Response
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $email = $request->validate(['email' => ['required', 'string', 'email', 'max:255']])['email'];

        $request->session()->put('login.email', $email);

        $user = User::where('email', $email)->first();

        if ($user?->active && $user->use_microsoft) {
            return Inertia::location(route('identity.redirect'));
        }

        return redirect()->route('login.password');
    }
}
```

- [ ] **Step 7: Update the session controller**

In `app/Http/Controllers/Auth/AuthenticatedSessionController.php`, replace `create()` and add `createPassword()`; and log successful sign-ins in `store()`:
```php
    /**
     * Show the email step of the login page.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('auth/Login', [
            'email' => $request->session()->get('login.email'),
            'status' => $request->session()->get('status'),
            'error' => $request->session()->get('error'),
        ]);
    }

    /**
     * Show the password step for the email chosen at the email step.
     */
    public function createPassword(Request $request): Response|RedirectResponse
    {
        $email = $request->session()->get('login.email');

        if (! $email) {
            return to_route('login');
        }

        return Inertia::render('auth/LoginPassword', [
            'email' => $email,
            'canResetPassword' => Route::has('password.request'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();
        $request->session()->forget('login.email');

        activity('auth')
            ->causedBy($request->user())
            ->performedOn($request->user())
            ->event('login')
            ->log('Signed in with password');

        return redirect()->intended(route('dashboard', absolute: false));
    }
```

- [ ] **Step 8: Register the routes**

In `routes/auth.php`, add `use App\Http\Controllers\Auth\LoginEmailController;` and, inside the `guest` group directly after the existing `Route::post('login', ...)` line, add:
```php
    Route::post('login/email', LoginEmailController::class)
        ->middleware('throttle:20,1')
        ->name('login.email');

    Route::get('login/password', [AuthenticatedSessionController::class, 'createPassword'])
        ->name('login.password');
```

- [ ] **Step 9: Add the active-user middleware**

Create `app/Http/Middleware/EnsureUserIsActive.php`:
```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * Sign out anyone whose account was deactivated after they signed in.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->with('error', 'Your CardTrail account has been deactivated. Ask an admin if you think this is a mistake.');
        }

        return $next($request);
    }
}
```

In `bootstrap/app.php`, add `use App\Http\Middleware\EnsureUserIsActive;` and append it to the `web` group:
```php
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            EnsureUserIsActive::class,
        ]);
```

- [ ] **Step 10: Replace the login page with the email step**

Replace `resources/js/pages/auth/Login.vue`:
```vue
<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';

const props = defineProps<{
    email?: string | null;
    status?: string;
    error?: string;
}>();

const form = useForm({
    email: props.email ?? '',
});

const submit = () => {
    form.post(route('login.email'));
};
</script>

<template>
    <AuthBase title="Sign in to CardTrail" description="Enter your work email address to continue">
        <Head title="Sign in" />

        <div v-if="status" class="mb-4 text-center text-sm font-medium text-green-600">
            {{ status }}
        </div>

        <div v-if="error" class="mb-4 text-center text-sm font-medium text-red-600">
            {{ error }}
        </div>

        <form @submit.prevent="submit" class="flex flex-col gap-6">
            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    required
                    autofocus
                    autocomplete="email"
                    v-model="form.email"
                    placeholder="you@example.com"
                />
                <InputError :message="form.errors.email" />
            </div>

            <Button type="submit" class="w-full" :disabled="form.processing">
                <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                Continue
            </Button>
        </form>
    </AuthBase>
</template>
```

- [ ] **Step 11: Create the password step page**

Create `resources/js/pages/auth/LoginPassword.vue`:
```vue
<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';

const props = defineProps<{
    email: string;
    canResetPassword: boolean;
}>();

const form = useForm({
    email: props.email,
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <AuthBase title="Enter your password" :description="`Signing in as ${email}`">
        <Head title="Sign in" />

        <form @submit.prevent="submit" class="flex flex-col gap-6">
            <div class="grid gap-2">
                <div class="flex items-center justify-between">
                    <Label for="password">Password</Label>
                    <TextLink v-if="canResetPassword" :href="route('password.request')" class="text-sm">Forgot password?</TextLink>
                </div>
                <Input
                    id="password"
                    type="password"
                    required
                    autofocus
                    autocomplete="current-password"
                    v-model="form.password"
                    placeholder="Password"
                />
                <InputError :message="form.errors.email || form.errors.password" />
            </div>

            <Label for="remember" class="flex items-center space-x-3">
                <Checkbox id="remember" v-model:checked="form.remember" />
                <span>Remember me</span>
            </Label>

            <Button type="submit" class="w-full" :disabled="form.processing">
                <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                Sign in
            </Button>

            <div class="text-center text-sm text-muted-foreground">
                Not you?
                <TextLink :href="route('login')">Use a different email</TextLink>
            </div>
        </form>
    </AuthBase>
</template>
```

- [ ] **Step 12: Run the tests**

Run: `php artisan test`
Expected: all PASS. (The Microsoft 365 branch of the email step is tested in Task 4, once `identity.redirect` exists.) The kit's `AuthenticationTest` still passes because `POST /login` is unchanged for active password users.

- [ ] **Step 13: Build and type-check the frontend**

Run: `npm run build && npx vue-tsc --noEmit 2>&1 | grep -v TS2688`
Expected: build succeeds; no type errors printed (TS2688 is a known starter-kit tsconfig warning and is filtered out).

- [ ] **Step 14: Commit**

```bash
git add -A
git commit -m "feat: email-first sign-in with password step, no public registration

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Pluggable external identity provider with a fake driver

**Files:**
- Create: `app/Auth/ExternalIdentity.php`
- Create: `app/Auth/IdentityProvider.php`
- Create: `app/Auth/IdentityProviderException.php`
- Create: `app/Auth/Fake/FakeIdentityProvider.php`
- Create: `app/Http/Controllers/Auth/IdentityController.php`
- Create: `app/Http/Controllers/Auth/FakeIdentityController.php`
- Create: `config/cardtrail.php`
- Create: `resources/js/pages/auth/FakeIdentity.vue`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `routes/auth.php`
- Test: `tests/Feature/Auth/IdentityProviderTest.php`

**Interfaces:**
- Consumes: session key `login.email`, flash key `error`, `User` fields (Tasks 2–3).
- Produces:
  - `interface App\Auth\IdentityProvider { public function redirect(): \Symfony\Component\HttpFoundation\Response; public function user(\Illuminate\Http\Request $request): App\Auth\ExternalIdentity; }` — `user()` throws `IdentityProviderException` when sign-in can't be verified.
  - `final readonly class App\Auth\ExternalIdentity { public function __construct(public string $email, public string $externalId) }`.
  - Config `cardtrail.identity.driver` (env `CARDTRAIL_IDENTITY_DRIVER`, default `fake`) and `cardtrail.identity.drivers` (map of driver name → class). The developer later adds `'crown-ad' => App\Auth\CrownAd\CrownAdIdentityProvider::class`.
  - Routes: `GET /auth/identity/redirect` (`identity.redirect`), `GET /auth/identity/callback` (`identity.callback`); local/testing only: `GET /auth/fake-identity` (`identity.fake`), `POST /auth/fake-identity` (`identity.fake.store`).
  - Successful external sign-in logs `log_name = auth`, `event = login`, description `Signed in with Microsoft 365`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Auth/IdentityProviderTest.php`:
```php
<?php

use App\Auth\Fake\FakeIdentityProvider;
use App\Auth\IdentityProvider;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

/** Simulate the fake provider's sign-in form, then follow the callback. */
function fakeSignIn(string $email, ?string $externalId = null)
{
    return test()->post(route('identity.fake.store'), array_filter([
        'email' => $email,
        'external_id' => $externalId,
    ]));
}

it('sends active Microsoft 365 users from the email step to the identity provider', function () {
    User::factory()->microsoft()->create(['email' => 'morgan@cardtrail.test']);

    $this->post('/login/email', ['email' => 'morgan@cardtrail.test'])
        ->assertRedirect(route('identity.redirect'));
});

it('uses an Inertia location visit for Inertia requests so external redirects work', function () {
    User::factory()->microsoft()->create(['email' => 'morgan@cardtrail.test']);

    $this->withHeaders(['X-Inertia' => 'true'])
        ->post('/login/email', ['email' => 'morgan@cardtrail.test'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', route('identity.redirect'));
});

it('resolves the fake driver in testing', function () {
    expect(app(IdentityProvider::class))->toBeInstanceOf(FakeIdentityProvider::class);
});

it('refuses to resolve the fake driver in production', function () {
    app()->instance('env', 'production');

    expect(fn () => app(IdentityProvider::class))
        ->toThrow(RuntimeException::class, 'only available in local and testing');

    app()->instance('env', 'testing');
});

it('rejects an unknown driver name', function () {
    config(['cardtrail.identity.driver' => 'nope']);

    expect(fn () => app(IdentityProvider::class))
        ->toThrow(InvalidArgumentException::class, 'Unknown identity driver [nope]');
});

it('redirects to the fake sign-in page', function () {
    $this->get(route('identity.redirect'))->assertRedirect(route('identity.fake'));
});

it('prefills the fake sign-in page with the email from the email step', function () {
    $this->withSession(['login.email' => 'morgan@cardtrail.test'])
        ->get(route('identity.fake'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/FakeIdentity')->where('email', 'morgan@cardtrail.test'));
});

it('signs in a Microsoft 365 user and stores their external id on first sign-in', function () {
    $user = User::factory()->microsoft()->create(['email' => 'morgan@cardtrail.test']);

    fakeSignIn('Morgan@CardTrail.test', 'ext-morgan')->assertRedirect(route('identity.callback'));
    $this->get(route('identity.callback'))->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->external_id)->toBe('ext-morgan');
    expect(Activity::where('event', 'login')->sole()->description)->toBe('Signed in with Microsoft 365');
});

it('signs in by external id even if the email has changed', function () {
    $user = User::factory()->microsoft()->create(['email' => 'new@cardtrail.test', 'external_id' => 'ext-1']);

    fakeSignIn('old@cardtrail.test', 'ext-1');
    $this->get(route('identity.callback'));

    $this->assertAuthenticatedAs($user);
});

it('rejects a matching email whose external id differs from the stored one', function () {
    User::factory()->microsoft()->create(['email' => 'morgan@cardtrail.test', 'external_id' => 'ext-real']);

    fakeSignIn('morgan@cardtrail.test', 'ext-imposter');
    $this->get(route('identity.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('error');

    $this->assertGuest();
});

it('rejects users who are inactive, not set to Microsoft 365, or unknown', function (string $email) {
    User::factory()->microsoft()->inactive()->create(['email' => 'inactive@cardtrail.test']);
    User::factory()->create(['email' => 'password@cardtrail.test']);

    fakeSignIn($email);
    $this->get(route('identity.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('error');

    $this->assertGuest();
})->with(['inactive@cardtrail.test', 'password@cardtrail.test', 'stranger@cardtrail.test']);

it('fails gracefully when the provider cannot verify the sign-in', function () {
    $this->get(route('identity.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('error');

    $this->assertGuest();
});
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `php artisan test tests/Feature/Auth/IdentityProviderTest.php`
Expected: FAIL — `Route [identity.fake.store] not defined` / `Class "App\Auth\IdentityProvider" not found`.

- [ ] **Step 3: Create the interface, value object and exception**

Create `app/Auth/ExternalIdentity.php`:
```php
<?php

namespace App\Auth;

/** A sign-in the external identity provider has verified. */
final readonly class ExternalIdentity
{
    public function __construct(
        public string $email,
        public string $externalId,
    ) {}
}
```

Create `app/Auth/IdentityProvider.php`:
```php
<?php

namespace App\Auth;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The only way CardTrail talks to an external sign-in service.
 * Drivers are listed in config/cardtrail.php and chosen with CARDTRAIL_IDENTITY_DRIVER.
 */
interface IdentityProvider
{
    /** Start the external sign-in (usually a redirect to the provider). */
    public function redirect(): Response;

    /**
     * Read the verified identity from the provider's callback request.
     *
     * @throws IdentityProviderException when the sign-in can't be verified
     */
    public function user(Request $request): ExternalIdentity;
}
```

Create `app/Auth/IdentityProviderException.php`:
```php
<?php

namespace App\Auth;

use RuntimeException;

class IdentityProviderException extends RuntimeException {}
```

- [ ] **Step 4: Create the fake driver**

Create `app/Auth/Fake/FakeIdentityProvider.php`:
```php
<?php

namespace App\Auth\Fake;

use App\Auth\ExternalIdentity;
use App\Auth\IdentityProvider;
use App\Auth\IdentityProviderException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Local/testing stand-in for Microsoft 365: a form that lets you pick who to "be". */
class FakeIdentityProvider implements IdentityProvider
{
    public const SESSION_KEY = 'fake_identity';

    public function redirect(): Response
    {
        return redirect()->route('identity.fake');
    }

    public function user(Request $request): ExternalIdentity
    {
        $data = $request->session()->pull(self::SESSION_KEY);

        if (! is_array($data) || empty($data['email']) || empty($data['external_id'])) {
            throw new IdentityProviderException('No fake identity in the session.');
        }

        return new ExternalIdentity(Str::lower(trim($data['email'])), $data['external_id']);
    }
}
```

- [ ] **Step 5: Add the config and the container binding**

Create `config/cardtrail.php`:
```php
<?php

return [

    /*
    | External sign-in for users with "Use Microsoft 365" ticked.
    | "fake" works only in local and testing environments.
    */
    'identity' => [
        'driver' => env('CARDTRAIL_IDENTITY_DRIVER', 'fake'),

        'drivers' => [
            'fake' => App\Auth\Fake\FakeIdentityProvider::class,
        ],
    ],

];
```

In `app/Providers/AppServiceProvider.php`, add imports `use App\Auth\IdentityProvider;`, `use Illuminate\Contracts\Foundation\Application;`, `use InvalidArgumentException;`, `use RuntimeException;` and put this in `register()`:
```php
        $this->app->bind(IdentityProvider::class, function (Application $app) {
            $driver = config('cardtrail.identity.driver');
            $class = config("cardtrail.identity.drivers.{$driver}");

            if (! $class) {
                throw new InvalidArgumentException("Unknown identity driver [{$driver}].");
            }

            if ($driver === 'fake' && ! $app->environment('local', 'testing')) {
                throw new RuntimeException('The fake identity driver is only available in local and testing.');
            }

            return $app->make($class);
        });
```

- [ ] **Step 6: Create the identity controller**

Create `app/Http/Controllers/Auth/IdentityController.php`:
```php
<?php

namespace App\Http\Controllers\Auth;

use App\Auth\IdentityProvider;
use App\Auth\IdentityProviderException;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class IdentityController extends Controller
{
    public function redirect(IdentityProvider $provider): Response
    {
        return $provider->redirect();
    }

    public function callback(Request $request, IdentityProvider $provider): RedirectResponse
    {
        try {
            $identity = $provider->user($request);
        } catch (IdentityProviderException $e) {
            report($e);

            return $this->failed();
        }

        $user = User::where('external_id', $identity->externalId)->first()
            ?? User::where('email', $identity->email)->first();

        $externalIdClashes = $user?->external_id !== null && $user->external_id !== $identity->externalId;

        if (! $user || ! $user->active || ! $user->use_microsoft || $externalIdClashes) {
            return $this->failed();
        }

        if ($user->external_id === null) {
            $user->forceFill(['external_id' => $identity->externalId])->save();
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget('login.email');

        activity('auth')
            ->causedBy($user)
            ->performedOn($user)
            ->event('login')
            ->log('Signed in with Microsoft 365');

        return redirect()->intended(route('dashboard', absolute: false));
    }

    private function failed(): RedirectResponse
    {
        return redirect()->route('login')
            ->with('error', "We couldn't sign you in with Microsoft 365. Ask an admin to check your account.");
    }
}
```

- [ ] **Step 7: Create the fake sign-in controller and page**

Create `app/Http/Controllers/Auth/FakeIdentityController.php`:
```php
<?php

namespace App\Http\Controllers\Auth;

use App\Auth\Fake\FakeIdentityProvider;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/** Only routed in local and testing. Plays the part of the Microsoft sign-in screen. */
class FakeIdentityController extends Controller
{
    public function show(Request $request): Response
    {
        return Inertia::render('auth/FakeIdentity', [
            'email' => $request->session()->get('login.email'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'external_id' => ['nullable', 'string', 'max:255'],
        ]);

        $email = Str::lower(trim($data['email']));

        $request->session()->put(FakeIdentityProvider::SESSION_KEY, [
            'email' => $email,
            'external_id' => $data['external_id'] ?? 'fake-'.sha1($email),
        ]);

        return redirect()->route('identity.callback');
    }
}
```

Create `resources/js/pages/auth/FakeIdentity.vue`:
```vue
<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps<{ email?: string | null }>();

const form = useForm({
    email: props.email ?? '',
    external_id: '',
});

const submit = () => {
    form.post(route('identity.fake.store'));
};
</script>

<template>
    <AuthBase title="Fake Microsoft 365 sign-in" description="Local testing only. This stands in for the company sign-in page.">
        <Head title="Fake sign-in" />

        <form @submit.prevent="submit" class="flex flex-col gap-6">
            <div class="grid gap-2">
                <Label for="email">Directory email</Label>
                <Input id="email" type="email" required autofocus v-model="form.email" />
                <InputError :message="form.errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="external_id">Directory ID (optional)</Label>
                <Input id="external_id" v-model="form.external_id" placeholder="Leave blank to derive one from the email" />
                <InputError :message="form.errors.external_id" />
            </div>

            <Button type="submit" class="w-full" :disabled="form.processing">Sign in</Button>
        </form>
    </AuthBase>
</template>
```

- [ ] **Step 8: Register the routes**

In `routes/auth.php`, add imports for `IdentityController` and `FakeIdentityController`, and inside the `guest` group add:
```php
    Route::get('auth/identity/redirect', [IdentityController::class, 'redirect'])
        ->name('identity.redirect');

    Route::get('auth/identity/callback', [IdentityController::class, 'callback'])
        ->middleware('throttle:20,1')
        ->name('identity.callback');

    if (app()->environment('local', 'testing')) {
        Route::get('auth/fake-identity', [FakeIdentityController::class, 'show'])->name('identity.fake');
        Route::post('auth/fake-identity', [FakeIdentityController::class, 'store'])->name('identity.fake.store');
    }
```

- [ ] **Step 9: Run the tests to see them pass**

Run: `php artisan test tests/Feature/Auth`
Expected: all PASS.

- [ ] **Step 10: Run the full suite and the frontend checks**

Run: `php artisan test && npm run build && npx vue-tsc --noEmit 2>&1 | grep -v TS2688`
Expected: all PASS; build succeeds; no type errors.

- [ ] **Step 11: Commit**

```bash
git add -A
git commit -m "feat: pluggable external identity provider with local fake driver

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Manage Users (admin)

**Files:**
- Create: `app/Http/Middleware/EnsureUserIsAdmin.php`
- Create: `app/Http/Controllers/Admin/UserController.php`
- Create: `app/Http/Requests/Admin/UserRequest.php`
- Create: `routes/admin.php`
- Create: `resources/js/components/FlashMessage.vue`
- Create: `resources/js/pages/admin/users/Index.vue`
- Create: `resources/js/pages/admin/users/Form.vue`
- Modify: `app/Models/User.php`, `app/Models/CardIdentifier.php` (activity logging, `syncCardIdentifiers`)
- Modify: `app/Http/Middleware/HandleInertiaRequests.php` (flash)
- Modify: `bootstrap/app.php` (middleware alias), `routes/web.php` (require admin routes)
- Test: `tests/Feature/Admin/ManageUsersTest.php`

**Interfaces:**
- Consumes: `User`, `CardIdentifier`, factories (Task 2); activity log (Task 3).
- Produces:
  - Middleware alias `admin`.
  - Routes (prefix `/admin`, names `admin.*`, middleware `auth` + `admin`): `GET users` (`admin.users.index`), `GET users/create` (`admin.users.create`), `POST users` (`admin.users.store`), `GET users/{user}/edit` (`admin.users.edit`), `PUT users/{user}` (`admin.users.update`).
  - `UserRequest::userAttributes(): array` (name, email, is_admin, is_cardholder, use_microsoft, active) and `UserRequest::cardIdentifiers(): array<int, array{id: ?int, last4: ?string, cardholder_name: ?string}>`.
  - `User::syncCardIdentifiers(array $rows): void`.
  - Shared Inertia prop `flash: { success: ?string, error: ?string }`; component `FlashMessage.vue`.
  - Activity log name `users` for changes to users and card identifiers.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Admin/ManageUsersTest.php`:
```php
<?php

use App\Models\CardIdentifier;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

function userPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Sam Cardholder',
        'email' => 'sam@cardtrail.test',
        'is_admin' => false,
        'is_cardholder' => true,
        'use_microsoft' => false,
        'active' => true,
        'card_identifiers' => [
            ['id' => null, 'last4' => '1001', 'cardholder_name' => 'S CARDHOLDER'],
        ],
    ], $overrides);
}

beforeEach(function () {
    // Named so it sorts before "Sam" in the alphabetical list.
    $this->admin = User::factory()->admin()->create(['name' => 'Alex Admin', 'email' => 'admin@cardtrail.test']);
});

it('blocks guests and non-admins', function () {
    $this->get(route('admin.users.index'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())
        ->get(route('admin.users.index'))
        ->assertForbidden();
});

it('lists active users with their card identifiers', function () {
    $sam = User::factory()->create(['name' => 'Sam', 'email' => 'sam@cardtrail.test']);
    CardIdentifier::factory()->for($sam)->create(['last4' => '1001']);
    User::factory()->inactive()->create(['name' => 'Gone']);

    $this->actingAs($this->admin)
        ->get(route('admin.users.index'))
        ->assertInertia(fn ($page) => $page
            ->component('admin/users/Index')
            ->has('users.data', 2)
            ->where('users.data.1.name', 'Sam')
            ->where('users.data.1.card_identifiers.0.last4', '1001'));
});

it('shows inactive users when asked and searches by name or email', function () {
    User::factory()->inactive()->create(['name' => 'Gone Person', 'email' => 'gone@cardtrail.test']);

    $this->actingAs($this->admin)
        ->get(route('admin.users.index', ['inactive' => 1, 'q' => 'gone@']))
        ->assertInertia(fn ($page) => $page
            ->has('users.data', 1)
            ->where('users.data.0.name', 'Gone Person')
            ->where('filters.inactive', true));
});

it('creates a user with card identifiers', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.users.store'), userPayload(['email' => ' Sam@CardTrail.TEST ']))
        ->assertRedirect(route('admin.users.index'))
        ->assertSessionHas('success');

    $sam = User::where('email', 'sam@cardtrail.test')->sole();
    expect($sam->password)->toBeNull()
        ->and($sam->cardIdentifiers)->toHaveCount(1)
        ->and($sam->cardIdentifiers->first()->last4)->toBe('1001');
});

it('validates card identifiers', function (array $identifier, string $errorKey) {
    $this->actingAs($this->admin)
        ->post(route('admin.users.store'), userPayload(['card_identifiers' => [$identifier]]))
        ->assertSessionHasErrors($errorKey);
})->with([
    'three digits' => [['id' => null, 'last4' => '123', 'cardholder_name' => null], 'card_identifiers.0.last4'],
    'letters' => [['id' => null, 'last4' => '12ab', 'cardholder_name' => null], 'card_identifiers.0.last4'],
    'long name' => [['id' => null, 'last4' => null, 'cardholder_name' => str_repeat('A', 101)], 'card_identifiers.0.cardholder_name'],
]);

it('ignores completely blank card identifier rows', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.users.store'), userPayload(['card_identifiers' => [
            ['id' => null, 'last4' => '', 'cardholder_name' => '  '],
        ]]))
        ->assertSessionHasNoErrors();

    expect(CardIdentifier::count())->toBe(0);
});

it('rejects a duplicate email', function () {
    User::factory()->create(['email' => 'sam@cardtrail.test']);

    $this->actingAs($this->admin)
        ->post(route('admin.users.store'), userPayload(['email' => 'SAM@cardtrail.test']))
        ->assertSessionHasErrors('email');
});

it('shows the edit form', function () {
    $sam = User::factory()->create();
    CardIdentifier::factory()->for($sam)->create(['last4' => '4321']);

    $this->actingAs($this->admin)
        ->get(route('admin.users.edit', $sam))
        ->assertInertia(fn ($page) => $page
            ->component('admin/users/Form')
            ->where('user.id', $sam->id)
            ->where('user.card_identifiers.0.last4', '4321')
            ->where('user.has_password', true));
});

it('updates a user and syncs card identifiers', function () {
    $sam = User::factory()->create(['email' => 'sam@cardtrail.test']);
    $keep = CardIdentifier::factory()->for($sam)->create(['last4' => '1111']);
    $drop = CardIdentifier::factory()->for($sam)->create(['last4' => '2222']);

    $this->actingAs($this->admin)
        ->put(route('admin.users.update', $sam), userPayload([
            'name' => 'Samantha',
            'use_microsoft' => true,
            'card_identifiers' => [
                ['id' => $keep->id, 'last4' => '1112', 'cardholder_name' => null],
                ['id' => null, 'last4' => null, 'cardholder_name' => 'S NEW'],
            ],
        ]))
        ->assertRedirect(route('admin.users.index'));

    $sam->refresh();
    expect($sam->name)->toBe('Samantha')
        ->and($sam->use_microsoft)->toBeTrue()
        ->and($sam->cardIdentifiers->pluck('last4')->filter()->values()->all())->toBe(['1112'])
        ->and($sam->cardIdentifiers->pluck('cardholder_name')->filter()->values()->all())->toBe(['S NEW'])
        ->and(CardIdentifier::find($drop->id))->toBeNull();
});

it('does not let one user claim another user\'s card identifier by id', function () {
    $other = User::factory()->create();
    $theirs = CardIdentifier::factory()->for($other)->create(['last4' => '9999']);
    $sam = User::factory()->create(['email' => 'sam@cardtrail.test']);

    $this->actingAs($this->admin)->put(route('admin.users.update', $sam), userPayload([
        'card_identifiers' => [['id' => $theirs->id, 'last4' => '5555', 'cardholder_name' => null]],
    ]));

    expect($theirs->fresh()->last4)->toBe('9999')
        ->and($theirs->fresh()->user_id)->toBe($other->id)
        ->and($sam->cardIdentifiers()->pluck('last4')->all())->toBe(['5555']);
});

it('deactivates rather than deletes', function () {
    $sam = User::factory()->create(['email' => 'sam@cardtrail.test']);

    $this->actingAs($this->admin)->put(route('admin.users.update', $sam), userPayload(['active' => false]));

    expect($sam->fresh()->active)->toBeFalse();
});

it('stops admins removing their own admin role, deactivating themselves or switching their own sign-in method', function (array $change, string $errorKey) {
    $this->actingAs($this->admin)
        ->put(route('admin.users.update', $this->admin), userPayload([
            'name' => $this->admin->name,
            'email' => $this->admin->email,
            'is_admin' => true,
            ...$change,
        ]))
        ->assertSessionHasErrors($errorKey);

    expect($this->admin->fresh()->is_admin)->toBeTrue()
        ->and($this->admin->fresh()->active)->toBeTrue()
        ->and($this->admin->fresh()->use_microsoft)->toBeFalse();
})->with([
    'demote' => [['is_admin' => false], 'is_admin'],
    'deactivate' => [['active' => false], 'active'],
    'switch to Microsoft' => [['use_microsoft' => true], 'use_microsoft'],
]);

it('records who changed what in the activity log', function () {
    $sam = User::factory()->create(['email' => 'sam@cardtrail.test', 'name' => 'Sam']);

    $this->actingAs($this->admin)->put(route('admin.users.update', $sam), userPayload(['name' => 'Samantha']));

    $activity = Activity::where('log_name', 'users')
        ->where('subject_type', User::class)
        ->where('subject_id', $sam->id)
        ->where('event', 'updated')
        ->latest('id')
        ->first();

    expect($activity->causer_id)->toBe($this->admin->id)
        ->and($activity->properties['attributes']['name'])->toBe('Samantha')
        ->and($activity->properties['old']['name'])->toBe('Sam');
});
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `php artisan test tests/Feature/Admin/ManageUsersTest.php`
Expected: FAIL — `Route [admin.users.index] not defined`.

- [ ] **Step 3: Add the admin middleware and routes**

Create `app/Http/Middleware/EnsureUserIsAdmin.php`:
```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->is_admin, 403);

        return $next($request);
    }
}
```

In `bootstrap/app.php`, add `use App\Http\Middleware\EnsureUserIsAdmin;` and inside `withMiddleware` add:
```php
        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
        ]);
```

Create `routes/admin.php`:
```php
<?php

use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::get('users/create', [UserController::class, 'create'])->name('users.create');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
    Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
});
```

In `routes/web.php`, add `require __DIR__.'/admin.php';` above `require __DIR__.'/settings.php';`.

- [ ] **Step 4: Add activity logging and identifier syncing to the models**

In `app/Models/User.php`, add imports `use Spatie\Activitylog\LogOptions;` and `use Spatie\Activitylog\Traits\LogsActivity;`, add `LogsActivity` to the class's `use` list, and add:
```php
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('users')
            ->logOnly(['name', 'email', 'is_admin', 'is_cardholder', 'use_microsoft', 'active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Replace this user's card identifiers with the given rows.
     * Rows with an id update that identifier (only if it belongs to this user); rows without one are created.
     *
     * @param  array<int, array{id?: ?int, last4?: ?string, cardholder_name?: ?string}>  $rows
     */
    public function syncCardIdentifiers(array $rows): void
    {
        $keep = [];

        foreach ($rows as $row) {
            $attributes = [
                'last4' => $row['last4'] ?? null,
                'cardholder_name' => $row['cardholder_name'] ?? null,
            ];

            $identifier = isset($row['id']) ? $this->cardIdentifiers()->find($row['id']) : null;

            if ($identifier) {
                $identifier->update($attributes);
            } else {
                $identifier = $this->cardIdentifiers()->create($attributes);
            }

            $keep[] = $identifier->id;
        }

        // Delete one by one so each removal is written to the activity log.
        $this->cardIdentifiers()->whereNotIn('id', $keep)->get()->each->delete();
    }
```

In `app/Models/CardIdentifier.php`, add the same two imports, add `LogsActivity` to the `use` list, and add:
```php
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('users')
            ->logOnly(['user_id', 'last4', 'cardholder_name'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
```

- [ ] **Step 5: Create the form request**

Create `app/Http/Requests/Admin/UserRequest.php`:
```php
<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    protected function prepareForValidation(): void
    {
        $blankToNull = fn ($value) => filled($value) ? trim((string) $value) : null;

        $this->merge([
            'email' => Str::lower(trim((string) $this->input('email'))),
            'card_identifiers' => collect($this->input('card_identifiers', []))
                ->map(fn ($row) => [
                    'id' => $row['id'] ?? null,
                    'last4' => $blankToNull($row['last4'] ?? null),
                    'cardholder_name' => $blankToNull($row['cardholder_name'] ?? null),
                ])
                // A row with both fields blank means "remove this identifier".
                ->reject(fn ($row) => $row['last4'] === null && $row['cardholder_name'] === null)
                ->values()
                ->all(),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class)->ignore($this->route('user'))],
            'is_admin' => ['required', 'boolean'],
            'is_cardholder' => ['required', 'boolean'],
            'use_microsoft' => ['required', 'boolean'],
            'active' => ['required', 'boolean'],
            'card_identifiers' => ['array', 'max:10'],
            'card_identifiers.*.id' => ['nullable', 'integer'],
            'card_identifiers.*.last4' => ['nullable', 'digits:4'],
            'card_identifiers.*.cardholder_name' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** Admins can't lock themselves out by editing their own account. */
    public function after(): array
    {
        return [function (Validator $validator) {
            $editing = $this->route('user');

            if (! $editing instanceof User || ! $editing->is($this->user())) {
                return;
            }

            if (! $this->boolean('is_admin')) {
                $validator->errors()->add('is_admin', "You can't remove your own admin access.");
            }

            if (! $this->boolean('active')) {
                $validator->errors()->add('active', "You can't deactivate your own account.");
            }

            if ($this->boolean('use_microsoft') !== $editing->use_microsoft) {
                $validator->errors()->add('use_microsoft', "You can't change your own sign-in method. Ask another admin.");
            }
        }];
    }

    /** @return array<string, mixed> */
    public function userAttributes(): array
    {
        return $this->safe()->only(['name', 'email', 'is_admin', 'is_cardholder', 'use_microsoft', 'active']);
    }

    /** @return array<int, array{id: ?int, last4: ?string, cardholder_name: ?string}> */
    public function cardIdentifiers(): array
    {
        return $this->validated('card_identifiers', []);
    }
}
```

- [ ] **Step 6: Create the controller**

Create `app/Http/Controllers/Admin/UserController.php`:
```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));
        $showInactive = $request->boolean('inactive');

        $users = User::query()
            ->with('cardIdentifiers:id,user_id,last4,cardholder_name')
            ->when(! $showInactive, fn ($query) => $query->active())
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('admin/users/Index', [
            'users' => $users,
            'filters' => ['q' => $search, 'inactive' => $showInactive],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/users/Form', ['user' => null]);
    }

    public function store(UserRequest $request): RedirectResponse
    {
        $user = User::create($request->userAttributes());
        $user->syncCardIdentifiers($request->cardIdentifiers());

        return to_route('admin.users.index')->with('success', "{$user->name} has been added.");
    }

    public function edit(User $user): Response
    {
        return Inertia::render('admin/users/Form', [
            'user' => [
                ...$user->only(['id', 'name', 'email', 'is_admin', 'is_cardholder', 'use_microsoft', 'active']),
                'card_identifiers' => $user->cardIdentifiers()->get(['id', 'last4', 'cardholder_name']),
                'has_password' => $user->password !== null,
            ],
        ]);
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $user->update($request->userAttributes());
        $user->syncCardIdentifiers($request->cardIdentifiers());

        return to_route('admin.users.index')->with('success', "{$user->name} has been updated.");
    }
}
```

- [ ] **Step 7: Share flash messages**

In `app/Http/Middleware/HandleInertiaRequests.php`, add to the array returned by `share()` (after `'auth'`):
```php
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
```

Create `resources/js/components/FlashMessage.vue`:
```vue
<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage<{ flash: { success: string | null; error: string | null } }>();
const flash = computed(() => page.props.flash);
</script>

<template>
    <div v-if="flash.success" class="rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200">
        {{ flash.success }}
    </div>
    <div v-if="flash.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
        {{ flash.error }}
    </div>
</template>
```

- [ ] **Step 8: Run the backend tests to see them pass**

Run: `php artisan test tests/Feature/Admin/ManageUsersTest.php --filter='blocks|creates|validates|ignores|rejects|updates|claim|deactivates|stops|records'`
Expected: all PASS. The three page-rendering tests ("lists…", "shows inactive…", "shows the edit form") need the Vue pages from Steps 9–10 because Inertia checks the page files exist; they run in Step 11.

- [ ] **Step 9: Create the users list page**

Create `resources/js/pages/admin/users/Index.vue`:
```vue
<script setup lang="ts">
import FlashMessage from '@/components/FlashMessage.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { ref, watch } from 'vue';

interface CardIdentifier {
    id: number;
    last4: string | null;
    cardholder_name: string | null;
}

interface AdminUserRow {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
    is_cardholder: boolean;
    use_microsoft: boolean;
    active: boolean;
    card_identifiers: CardIdentifier[];
}

interface Paginated<T> {
    data: T[];
    prev_page_url: string | null;
    next_page_url: string | null;
    from: number | null;
    to: number | null;
    total: number;
}

const props = defineProps<{
    users: Paginated<AdminUserRow>;
    filters: { q: string; inactive: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Manage users', href: '/admin/users' }];

const q = ref(props.filters.q);
const inactive = ref(props.filters.inactive);

const reload = () =>
    router.get(
        route('admin.users.index'),
        { q: q.value || undefined, inactive: inactive.value ? 1 : undefined },
        { preserveState: true, replace: true },
    );

watch(q, useDebounceFn(reload, 300));
watch(inactive, reload);

const cardLabel = (card: CardIdentifier) => [card.last4 ? `•••• ${card.last4}` : null, card.cardholder_name].filter(Boolean).join(' · ');

const roles = (user: AdminUserRow) => [user.is_admin ? 'Admin' : null, user.is_cardholder ? 'Cardholder' : null].filter(Boolean).join(', ') || 'No role';
</script>

<template>
    <Head title="Manage users" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-4 p-4">
            <FlashMessage />

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex flex-1 flex-col gap-3 sm:flex-row sm:items-center">
                    <Input v-model="q" type="search" placeholder="Search name or email" class="sm:max-w-xs" />
                    <Label for="inactive" class="flex items-center gap-2">
                        <Checkbox id="inactive" v-model:checked="inactive" />
                        <span>Show inactive</span>
                    </Label>
                </div>
                <Button as-child>
                    <Link :href="route('admin.users.create')">Add user</Link>
                </Button>
            </div>

            <!-- Phone: stacked cards -->
            <ul class="divide-y rounded-lg border md:hidden">
                <li v-for="user in users.data" :key="user.id">
                    <Link :href="route('admin.users.edit', user.id)" class="block p-4" :class="{ 'opacity-60': !user.active }">
                        <div class="flex items-center justify-between">
                            <span class="font-medium">{{ user.name }}</span>
                            <span v-if="!user.active" class="text-xs text-muted-foreground">Inactive</span>
                        </div>
                        <div class="text-sm text-muted-foreground">{{ user.email }}</div>
                        <div class="mt-1 text-xs text-muted-foreground">
                            {{ roles(user) }} · {{ user.use_microsoft ? 'Microsoft 365' : 'Password' }}
                        </div>
                        <div v-if="user.card_identifiers.length" class="mt-1 text-xs">
                            {{ user.card_identifiers.map(cardLabel).join(', ') }}
                        </div>
                    </Link>
                </li>
                <li v-if="!users.data.length" class="p-4 text-sm text-muted-foreground">No users found.</li>
            </ul>

            <!-- Desktop: table -->
            <table class="hidden w-full text-left text-sm md:table">
                <thead class="border-b text-muted-foreground">
                    <tr>
                        <th class="py-2 pr-4 font-medium">Name</th>
                        <th class="py-2 pr-4 font-medium">Email</th>
                        <th class="py-2 pr-4 font-medium">Roles</th>
                        <th class="py-2 pr-4 font-medium">Cards</th>
                        <th class="py-2 pr-4 font-medium">Sign-in</th>
                        <th class="py-2 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="user in users.data" :key="user.id" class="border-b" :class="{ 'opacity-60': !user.active }">
                        <td class="py-2 pr-4">
                            <Link :href="route('admin.users.edit', user.id)" class="font-medium underline-offset-4 hover:underline">{{ user.name }}</Link>
                        </td>
                        <td class="py-2 pr-4">{{ user.email }}</td>
                        <td class="py-2 pr-4">{{ roles(user) }}</td>
                        <td class="py-2 pr-4">{{ user.card_identifiers.map(cardLabel).join(', ') || '—' }}</td>
                        <td class="py-2 pr-4">{{ user.use_microsoft ? 'Microsoft 365' : 'Password' }}</td>
                        <td class="py-2">{{ user.active ? 'Active' : 'Inactive' }}</td>
                    </tr>
                    <tr v-if="!users.data.length">
                        <td colspan="6" class="py-4 text-muted-foreground">No users found.</td>
                    </tr>
                </tbody>
            </table>

            <div v-if="users.total > 0" class="flex items-center justify-between text-sm text-muted-foreground">
                <span>{{ users.from }}–{{ users.to }} of {{ users.total }}</span>
                <div class="flex gap-2">
                    <Button v-if="users.prev_page_url" variant="outline" size="sm" as-child>
                        <Link :href="users.prev_page_url" preserve-state>Previous</Link>
                    </Button>
                    <Button v-if="users.next_page_url" variant="outline" size="sm" as-child>
                        <Link :href="users.next_page_url" preserve-state>Next</Link>
                    </Button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
```

- [ ] **Step 10: Create the add/edit form page**

Create `resources/js/pages/admin/users/Form.vue`:
```vue
<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from 'lucide-vue-next';
import { computed } from 'vue';

interface CardIdentifierRow {
    id: number | null;
    last4: string | null;
    cardholder_name: string | null;
}

interface EditableUser {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
    is_cardholder: boolean;
    use_microsoft: boolean;
    active: boolean;
    card_identifiers: CardIdentifierRow[];
    has_password: boolean;
}

const props = defineProps<{ user: EditableUser | null }>();

const isEdit = computed(() => props.user !== null);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Manage users', href: '/admin/users' },
    { title: props.user ? props.user.name : 'Add user', href: '#' },
];

const form = useForm({
    name: props.user?.name ?? '',
    email: props.user?.email ?? '',
    is_admin: props.user?.is_admin ?? false,
    is_cardholder: props.user?.is_cardholder ?? true,
    use_microsoft: props.user?.use_microsoft ?? false,
    active: props.user?.active ?? true,
    card_identifiers: (props.user?.card_identifiers ?? [{ id: null, last4: '', cardholder_name: '' }]) as CardIdentifierRow[],
});

const errors = computed(() => form.errors as Record<string, string | undefined>);

const addIdentifier = () => form.card_identifiers.push({ id: null, last4: '', cardholder_name: '' });
const removeIdentifier = (index: number) => form.card_identifiers.splice(index, 1);

const submit = () => {
    if (props.user) {
        form.put(route('admin.users.update', props.user.id));
    } else {
        form.post(route('admin.users.store'));
    }
};
</script>

<template>
    <Head :title="isEdit ? `Edit ${user?.name}` : 'Add user'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <form @submit.prevent="submit" class="flex max-w-2xl flex-col gap-6 p-4">
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input id="name" v-model="form.name" required autocomplete="off" />
                <InputError :message="form.errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">Work email</Label>
                <Input id="email" type="email" v-model="form.email" required autocomplete="off" />
                <InputError :message="form.errors.email" />
            </div>

            <fieldset class="grid gap-3">
                <legend class="mb-2 text-sm font-medium">Roles</legend>
                <Label for="is_cardholder" class="flex items-center gap-3">
                    <Checkbox id="is_cardholder" v-model:checked="form.is_cardholder" />
                    <span>Cardholder: reconciles their own card transactions</span>
                </Label>
                <Label for="is_admin" class="flex items-center gap-3">
                    <Checkbox id="is_admin" v-model:checked="form.is_admin" />
                    <span>Admin: accountant access to every user and period</span>
                </Label>
                <InputError :message="form.errors.is_admin" />
            </fieldset>

            <fieldset class="grid gap-3">
                <legend class="mb-2 text-sm font-medium">Sign-in</legend>
                <Label for="use_microsoft" class="flex items-center gap-3">
                    <Checkbox id="use_microsoft" v-model:checked="form.use_microsoft" />
                    <span>Use Microsoft 365 sign-in</span>
                </Label>
                <p class="text-xs text-muted-foreground">
                    When ticked, this person signs in with their work Microsoft account and can't use a password.
                </p>
                <InputError :message="form.errors.use_microsoft" />

                <Label for="active" class="flex items-center gap-3">
                    <Checkbox id="active" v-model:checked="form.active" />
                    <span>Active</span>
                </Label>
                <p class="text-xs text-muted-foreground">Untick to remove access. Their history is kept.</p>
                <InputError :message="form.errors.active" />
            </fieldset>

            <fieldset class="grid gap-3">
                <legend class="mb-1 text-sm font-medium">Card identifiers</legend>
                <p class="text-xs text-muted-foreground">
                    Statement rows are matched to this person by the card's last 4 digits or the cardholder name as it appears on the statement.
                </p>

                <div v-for="(identifier, index) in form.card_identifiers" :key="index" class="grid gap-2 rounded-md border p-3 sm:grid-cols-[8rem_1fr_auto] sm:items-start">
                    <div class="grid gap-1">
                        <Label :for="`last4-${index}`" class="text-xs">Last 4 digits</Label>
                        <Input :id="`last4-${index}`" v-model="identifier.last4" inputmode="numeric" maxlength="4" placeholder="1234" />
                        <InputError :message="errors[`card_identifiers.${index}.last4`]" />
                    </div>
                    <div class="grid gap-1">
                        <Label :for="`cardholder-${index}`" class="text-xs">Name on statement</Label>
                        <Input :id="`cardholder-${index}`" v-model="identifier.cardholder_name" placeholder="J SMITH" />
                        <InputError :message="errors[`card_identifiers.${index}.cardholder_name`]" />
                    </div>
                    <Button type="button" variant="ghost" size="icon" class="sm:mt-5" @click="removeIdentifier(index)" aria-label="Remove card identifier">
                        <Trash2 class="size-4" />
                    </Button>
                </div>

                <Button type="button" variant="outline" class="w-fit" @click="addIdentifier">
                    <Plus class="size-4" /> Add card identifier
                </Button>
            </fieldset>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing">{{ isEdit ? 'Save changes' : 'Add user' }}</Button>
                <Button variant="ghost" as-child>
                    <Link :href="route('admin.users.index')">Cancel</Link>
                </Button>
            </div>
        </form>
    </AppLayout>
</template>
```

- [ ] **Step 11: Run the full suite and the frontend checks**

Run: `php artisan test && npm run build && npx vue-tsc --noEmit 2>&1 | grep -v TS2688`
Expected: all PASS; build succeeds; no type errors.

- [ ] **Step 12: Commit**

```bash
git add -A
git commit -m "feat: admin Manage Users with card identifiers and activity log

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Invitations

**Files:**
- Create: `app/Notifications/UserInvited.php`
- Create: `app/Http/Controllers/Auth/AcceptInvitationController.php`
- Create: `app/Http/Controllers/Admin/UserInvitationController.php`
- Create: `resources/js/pages/auth/AcceptInvite.vue`
- Modify: `config/auth.php` (add `invites` broker)
- Modify: `app/Models/User.php` (`sendInvite()`)
- Modify: `app/Http/Controllers/Admin/UserController.php` (send invites on store/update)
- Modify: `routes/auth.php`, `routes/admin.php`
- Modify: `resources/js/pages/admin/users/Form.vue` (resend button)
- Test: `tests/Feature/Auth/InvitationTest.php`

**Interfaces:**
- Consumes: `UserController::store/update`, `User` (Task 5).
- Produces:
  - Password broker `invites` (7-day expiry).
  - `User::sendInvite(): void` — sends `UserInvited` with a set-password token for password users, or with `token = null` (sign-in link) for Microsoft 365 users.
  - `App\Notifications\UserInvited` with public `?string $token`.
  - Routes: `GET /invite/{token}` (`invite.show`, guest), `POST /invite` (`invite.store`, guest), `POST /admin/users/{user}/invite` (`admin.users.invite`).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Auth/InvitationTest.php`:
```php
<?php

use App\Models\User;
use App\Notifications\UserInvited;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

function adminPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Sam Cardholder',
        'email' => 'sam@cardtrail.test',
        'is_admin' => false,
        'is_cardholder' => true,
        'use_microsoft' => false,
        'active' => true,
        'card_identifiers' => [],
    ], $overrides);
}

beforeEach(function () {
    Notification::fake();
    $this->admin = User::factory()->admin()->create();
});

it('emails a set-password link to new password users', function () {
    $this->actingAs($this->admin)->post(route('admin.users.store'), adminPayload());

    $sam = User::where('email', 'sam@cardtrail.test')->sole();
    Notification::assertSentTo($sam, UserInvited::class, fn (UserInvited $n) => $n->token !== null);
});

it('emails a sign-in link (no token) to new Microsoft 365 users', function () {
    $this->actingAs($this->admin)->post(route('admin.users.store'), adminPayload(['use_microsoft' => true]));

    $sam = User::where('email', 'sam@cardtrail.test')->sole();
    Notification::assertSentTo($sam, UserInvited::class, fn (UserInvited $n) => $n->token === null);
});

it('emails a set-password link when a passwordless user is switched from Microsoft 365 to password', function () {
    $sam = User::factory()->microsoft()->create(['email' => 'sam@cardtrail.test']);

    $this->actingAs($this->admin)
        ->put(route('admin.users.update', $sam), adminPayload(['use_microsoft' => false]))
        ->assertSessionHas('success', fn ($message) => str_contains($message, 'set a password'));

    Notification::assertSentTo($sam, UserInvited::class, fn (UserInvited $n) => $n->token !== null);
});

it('does not re-invite on ordinary edits', function () {
    $sam = User::factory()->create(['email' => 'sam@cardtrail.test']);

    $this->actingAs($this->admin)->put(route('admin.users.update', $sam), adminPayload(['name' => 'Samantha']));

    Notification::assertNothingSentTo($sam);
});

it('lets an admin resend an invitation', function () {
    $sam = User::factory()->create();

    $this->actingAs($this->admin)
        ->post(route('admin.users.invite', $sam))
        ->assertRedirect()
        ->assertSessionHas('success');

    Notification::assertSentTo($sam, UserInvited::class);
});

it('will not resend an invitation to an inactive user', function () {
    $gone = User::factory()->inactive()->create();

    $this->actingAs($this->admin)
        ->post(route('admin.users.invite', $gone))
        ->assertSessionHas('error');

    Notification::assertNothingSentTo($gone);
});

it('links to the set-password page from the invitation email', function () {
    $sam = User::factory()->create(['email' => 'sam@cardtrail.test', 'password' => null]);
    $token = Password::broker('invites')->createToken($sam);

    $mail = (new UserInvited($token))->toMail($sam);

    expect($mail->actionUrl)->toBe(route('invite.show', ['token' => $token, 'email' => 'sam@cardtrail.test']));
});

it('shows the set-password page', function () {
    $this->get(route('invite.show', ['token' => 'abc', 'email' => 'sam@cardtrail.test']))
        ->assertInertia(fn ($page) => $page
            ->component('auth/AcceptInvite')
            ->where('token', 'abc')
            ->where('email', 'sam@cardtrail.test'));
});

it('sets a password from a valid invitation and allows sign-in', function () {
    $sam = User::factory()->create(['email' => 'sam@cardtrail.test', 'password' => null]);
    $token = Password::broker('invites')->createToken($sam);

    $this->post(route('invite.store'), [
        'token' => $token,
        'email' => ' Sam@CardTrail.test',
        'password' => 'a-strong-password',
        'password_confirmation' => 'a-strong-password',
    ])->assertRedirect(route('login'))->assertSessionHas('status');

    $this->post('/login', ['email' => 'sam@cardtrail.test', 'password' => 'a-strong-password']);
    $this->assertAuthenticatedAs($sam);
});

it('rejects an expired invitation', function () {
    $sam = User::factory()->create(['email' => 'sam@cardtrail.test', 'password' => null]);
    $token = Password::broker('invites')->createToken($sam);

    $this->travel(8)->days();

    $this->post(route('invite.store'), [
        'token' => $token,
        'email' => 'sam@cardtrail.test',
        'password' => 'a-strong-password',
        'password_confirmation' => 'a-strong-password',
    ])->assertSessionHasErrors('email');

    expect($sam->fresh()->password)->toBeNull();
});

it('rejects invitations for inactive or Microsoft 365 users', function (string $state) {
    $sam = User::factory()->{$state}()->create(['email' => 'sam@cardtrail.test']);
    $token = Password::broker('invites')->createToken($sam);

    $this->post(route('invite.store'), [
        'token' => $token,
        'email' => 'sam@cardtrail.test',
        'password' => 'a-strong-password',
        'password_confirmation' => 'a-strong-password',
    ])->assertSessionHasErrors('email');
})->with(['inactive', 'microsoft']);
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `php artisan test tests/Feature/Auth/InvitationTest.php`
Expected: FAIL — `Class "App\Notifications\UserInvited" not found`.

- [ ] **Step 3: Add the invites password broker**

In `config/auth.php`, inside the `'passwords'` array, add a second broker after `'users'`:
```php
        // Admin invitations reuse the reset-token table but last a week.
        'invites' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 10080,
            'throttle' => 0,
        ],
```

- [ ] **Step 4: Create the notification**

Create `app/Notifications/UserInvited.php`:
```php
<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserInvited extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  string|null  $token  set-password token, or null for Microsoft 365 users */
    public function __construct(public ?string $token) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('You have been added to CardTrail')
            ->greeting("Hello {$notifiable->name},")
            ->line('You now have access to CardTrail, where you complete your monthly card reconciliation.');

        if ($this->token === null) {
            return $mail
                ->line('Sign in with your Microsoft 365 work account.')
                ->action('Sign in to CardTrail', route('login'));
        }

        return $mail
            ->line('Choose a password to get started. This link expires in 7 days.')
            ->action('Set your password', route('invite.show', ['token' => $this->token, 'email' => $notifiable->email]));
    }
}
```

- [ ] **Step 5: Add `sendInvite()` to the User model**

In `app/Models/User.php`, add imports `use App\Notifications\UserInvited;` and `use Illuminate\Support\Facades\Password;`, and add:
```php
    public function sendInvite(): void
    {
        $token = $this->use_microsoft ? null : Password::broker('invites')->createToken($this);

        $this->notify(new UserInvited($token));
    }
```

- [ ] **Step 6: Send invitations from Manage Users**

In `app/Http/Controllers/Admin/UserController.php`, change `store()` to:
```php
    public function store(UserRequest $request): RedirectResponse
    {
        $user = User::create($request->userAttributes());
        $user->syncCardIdentifiers($request->cardIdentifiers());
        $user->sendInvite();

        return to_route('admin.users.index')->with('success', "{$user->name} has been added and emailed an invitation.");
    }
```
and `update()` to:
```php
    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $wasMicrosoft = $user->use_microsoft;

        $user->update($request->userAttributes());
        $user->syncCardIdentifiers($request->cardIdentifiers());

        $message = "{$user->name} has been updated.";

        // Someone moved off Microsoft 365 with no password would otherwise be unable to sign in.
        if ($wasMicrosoft && ! $user->use_microsoft && $user->password === null) {
            $user->sendInvite();
            $message .= ' They have been emailed a link to set a password.';
        }

        return to_route('admin.users.index')->with('success', $message);
    }
```

- [ ] **Step 7: Create the resend controller and route**

Create `app/Http/Controllers/Admin/UserInvitationController.php`:
```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class UserInvitationController extends Controller
{
    public function store(User $user): RedirectResponse
    {
        if (! $user->active) {
            return back()->with('error', "{$user->name} is inactive. Reactivate them before sending an invitation.");
        }

        $user->sendInvite();

        return back()->with('success', "Invitation sent to {$user->email}.");
    }
}
```

In `routes/admin.php`, add `use App\Http\Controllers\Admin\UserInvitationController;` and inside the group:
```php
    Route::post('users/{user}/invite', [UserInvitationController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('users.invite');
```

- [ ] **Step 8: Create the accept-invitation controller, routes and page**

Create `app/Http/Controllers/Auth/AcceptInvitationController.php`:
```php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AcceptInvitationController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        return Inertia::render('auth/AcceptInvite', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);

        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = User::where('email', $request->input('email'))->first();

        if (! $user || ! $user->active || $user->use_microsoft) {
            throw ValidationException::withMessages([
                'email' => 'This invitation is no longer valid. Ask an admin to send a new one.',
            ]);
        }

        $status = Password::broker('invites')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user) use ($request) {
                $user->forceFill([
                    'password' => Hash::make($request->input('password')),
                    'remember_token' => Str::random(60),
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ])->save();
            }
        );

        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages([
                'email' => 'This invitation link is invalid or has expired. Ask an admin to send a new one.',
            ]);
        }

        return to_route('login')->with('status', 'Your password has been set. You can now sign in.');
    }
}
```

In `routes/auth.php`, add `use App\Http\Controllers\Auth\AcceptInvitationController;` and inside the `guest` group:
```php
    Route::get('invite/{token}', [AcceptInvitationController::class, 'show'])->name('invite.show');
    Route::post('invite', [AcceptInvitationController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('invite.store');
```

Create `resources/js/pages/auth/AcceptInvite.vue`:
```vue
<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';

const props = defineProps<{ token: string; email: string }>();

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('invite.store'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <AuthLayout title="Welcome to CardTrail" description="Choose a password to finish setting up your account">
        <Head title="Set your password" />

        <form @submit.prevent="submit" class="grid gap-6">
            <div class="grid gap-2">
                <Label for="email">Email</Label>
                <Input id="email" type="email" v-model="form.email" readonly autocomplete="email" />
                <InputError :message="form.errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="password">Password</Label>
                <Input id="password" type="password" v-model="form.password" required autofocus autocomplete="new-password" />
                <InputError :message="form.errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Confirm password</Label>
                <Input id="password_confirmation" type="password" v-model="form.password_confirmation" required autocomplete="new-password" />
            </div>

            <Button type="submit" class="w-full" :disabled="form.processing">
                <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                Set password
            </Button>
        </form>
    </AuthLayout>
</template>
```

- [ ] **Step 9: Add a "Resend invitation" button to the edit form**

In `resources/js/pages/admin/users/Form.vue`:
- Change the `@inertiajs/vue3` import to `import { Head, Link, router, useForm } from '@inertiajs/vue3';`
- Add to the script:
```ts
const resendInvite = () => {
    if (props.user) {
        router.post(route('admin.users.invite', props.user.id), {}, { preserveScroll: true });
    }
};
```
- Add `import FlashMessage from '@/components/FlashMessage.vue';` and put `<FlashMessage />` as the first child of the `<form>`.
- In the final button row, after the Cancel button, add:
```vue
                <Button v-if="user && user.active" type="button" variant="outline" class="ml-auto" @click="resendInvite">
                    Resend invitation
                </Button>
```

- [ ] **Step 10: Run the tests to see them pass**

Run: `php artisan test tests/Feature/Auth/InvitationTest.php tests/Feature/Admin`
Expected: all PASS.

- [ ] **Step 11: Run the full suite and the frontend checks**

Run: `php artisan test && npm run build && npx vue-tsc --noEmit 2>&1 | grep -v TS2688`
Expected: all PASS; build succeeds; no type errors.

- [ ] **Step 12: Commit**

```bash
git add -A
git commit -m "feat: invitation emails and set-password flow

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Lock down self-service profile settings

**Files:**
- Modify: `app/Http/Controllers/Settings/ProfileController.php`
- Modify: `app/Http/Requests/Settings/ProfileUpdateRequest.php`
- Modify: `app/Http/Controllers/Settings/PasswordController.php`
- Modify: `routes/settings.php`
- Modify: `resources/js/pages/settings/Profile.vue`
- Modify: `resources/js/layouts/settings/Layout.vue`
- Modify: `resources/js/types/index.ts`
- Delete: `resources/js/components/DeleteUser.vue`
- Replace: `tests/Feature/Settings/ProfileUpdateTest.php`
- Test: `tests/Feature/Settings/MicrosoftUserPasswordTest.php`

**Interfaces:**
- Consumes: `User` fields (Task 2).
- Produces: TypeScript `User` interface gains `is_admin`, `is_cardholder`, `use_microsoft`, `active` (booleans), used by Task 8's navigation.

- [ ] **Step 1: Write the failing tests**

Replace `tests/Feature/Settings/ProfileUpdateTest.php` with:
```php
<?php

use App\Models\User;

it('shows the profile page', function () {
    $this->actingAs(User::factory()->create())
        ->get('/settings/profile')
        ->assertOk();
});

it('lets users change their name', function () {
    $user = User::factory()->create(['name' => 'Sam']);

    $this->actingAs($user)
        ->patch('/settings/profile', ['name' => 'Samantha'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/settings/profile');

    expect($user->fresh()->name)->toBe('Samantha');
});

it('ignores attempts to change email from the profile page', function () {
    $user = User::factory()->create(['email' => 'sam@cardtrail.test']);

    $this->actingAs($user)->patch('/settings/profile', [
        'name' => 'Sam',
        'email' => 'someone-else@cardtrail.test',
    ]);

    expect($user->fresh()->email)->toBe('sam@cardtrail.test');
});

it('does not allow users to delete their own account', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->delete('/settings/profile', ['password' => 'password'])
        ->assertStatus(405);

    expect($user->fresh())->not->toBeNull();
});
```

Create `tests/Feature/Settings/MicrosoftUserPasswordTest.php`:
```php
<?php

use App\Models\User;

it('redirects Microsoft 365 users away from the password page', function () {
    $this->actingAs(User::factory()->microsoft()->create())
        ->get('/settings/password')
        ->assertRedirect(route('profile.edit'));
});

it('forbids Microsoft 365 users from setting a password', function () {
    $user = User::factory()->microsoft()->create();

    $this->actingAs($user)
        ->put('/settings/password', [
            'current_password' => '',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ])
        ->assertForbidden();

    expect($user->fresh()->password)->toBeNull();
});

it('still lets password users open the password page', function () {
    $this->actingAs(User::factory()->create())
        ->get('/settings/password')
        ->assertOk();
});
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `php artisan test tests/Feature/Settings`
Expected: FAIL — email changes, delete returns a redirect instead of 405, Microsoft user gets 200 on the password page.

- [ ] **Step 3: Restrict the profile to the name only**

Replace the body of `rules()` in `app/Http/Requests/Settings/ProfileUpdateRequest.php` with:
```php
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
```
and remove the now-unused `use App\Models\User;` and `use Illuminate\Validation\Rule;` imports.

Replace `app/Http/Controllers/Settings/ProfileController.php` with:
```php
<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Profile', [
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's name. Email, roles and access are managed by admins.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->update($request->validated());

        return to_route('profile.edit');
    }
}
```

In `routes/settings.php`, delete the `Route::delete('settings/profile', ...)` line.

- [ ] **Step 4: Block the password page for Microsoft 365 users**

In `app/Http/Controllers/Settings/PasswordController.php`, change `edit()` to:
```php
    public function edit(Request $request): Response|RedirectResponse
    {
        if ($request->user()->use_microsoft) {
            return to_route('profile.edit');
        }

        return Inertia::render('settings/Password', [
            'status' => $request->session()->get('status'),
        ]);
    }
```
and add as the first line of `update()`:
```php
        abort_if($request->user()->use_microsoft, 403);
```
Remove the now-unused `use Illuminate\Contracts\Auth\MustVerifyEmail;` import.

- [ ] **Step 5: Update the frontend**

Delete the account-deletion component: `git rm resources/js/components/DeleteUser.vue`

In `resources/js/types/index.ts`, add to the `User` interface:
```ts
    is_admin: boolean;
    is_cardholder: boolean;
    use_microsoft: boolean;
    active: boolean;
```

In `resources/js/pages/settings/Profile.vue`:
- Remove the `DeleteUser` import and the `<DeleteUser />` element.
- Remove `mustVerifyEmail` from `Props` and delete the whole `<div v-if="mustVerifyEmail && !user.email_verified_at">…</div>` block; remove `Link` from the `@inertiajs/vue3` import.
- Change the form to `useForm({ name: user.name })`.
- Change the heading description to `"Update your name"`.
- Replace the email `<Input …/>` and its `<InputError>` with:
```vue
                        <Input id="email" type="email" class="mt-1 block w-full" :model-value="user.email" disabled />
                        <p class="text-xs text-muted-foreground">Ask an admin if your email address needs to change.</p>
```

In `resources/js/layouts/settings/Layout.vue`, replace the script block with:
```vue
<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage<SharedData>();

const sidebarNavItems = computed<NavItem[]>(() => [
    { title: 'Profile', href: '/settings/profile' },
    // Microsoft 365 users have no CardTrail password to change.
    ...(page.props.auth.user.use_microsoft ? [] : [{ title: 'Password', href: '/settings/password' }]),
    { title: 'Appearance', href: '/settings/appearance' },
]);

const currentPath = window.location.pathname;
</script>
```
(The template's `v-for="item in sidebarNavItems"` works unchanged with the computed.)

- [ ] **Step 6: Run the tests to see them pass**

Run: `php artisan test tests/Feature/Settings`
Expected: all PASS, including the kit's `PasswordUpdateTest`.

- [ ] **Step 7: Run the full suite and the frontend checks**

Run: `php artisan test && npm run build && npx vue-tsc --noEmit 2>&1 | grep -v TS2688`
Expected: all PASS; build succeeds; no type errors.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "feat: lock down profile settings (name only, no self-deletion, no password for Microsoft users)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: CardTrail shell, role-aware navigation and demo data

**Files:**
- Modify: `resources/js/components/AppLogo.vue`
- Modify: `resources/js/components/AppSidebar.vue`
- Replace: `resources/js/pages/Dashboard.vue`
- Create: `database/seeders/DemoSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Test: `tests/Feature/NavigationTest.php`, `tests/Feature/DemoSeederTest.php`

**Interfaces:**
- Consumes: TS `User` fields (Task 7), `admin.users.index` route (Task 5), factories (Task 2).
- Produces: demo accounts for manual testing — `admin@cardtrail.test` (admin, password `password`), `sam@cardtrail.test` and `jo@cardtrail.test` (cardholders, password `password`, last-4 `1001`/`1002`), `morgan@cardtrail.test` (cardholder, Microsoft 365 via the fake provider, last-4 `1003`).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/NavigationTest.php`:
```php
<?php

use App\Models\User;

it('shares role flags with the frontend so the menu can adapt', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('auth.user.is_admin', true)
            ->where('auth.user.is_cardholder', true)
            ->missing('auth.user.password')
            ->missing('auth.user.external_id'));
});

it('shows the dashboard to cardholders without admin access', function () {
    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('auth.user.is_admin', false));
});
```

Create `tests/Feature/DemoSeederTest.php`:
```php
<?php

use App\Models\User;
use Database\Seeders\DemoSeeder;

it('seeds fake demo accounts', function () {
    $this->seed(DemoSeeder::class);

    expect(User::where('email', 'admin@cardtrail.test')->sole()->is_admin)->toBeTrue()
        ->and(User::where('email', 'morgan@cardtrail.test')->sole()->use_microsoft)->toBeTrue()
        ->and(User::where('email', 'sam@cardtrail.test')->sole()->cardIdentifiers->first()->last4)->toBe('1001')
        ->and(User::where('email', 'like', '%@cardtrail.test')->count())->toBe(User::count());
});

it('can be run twice without duplicating accounts', function () {
    $this->seed(DemoSeeder::class);
    $this->seed(DemoSeeder::class);

    expect(User::count())->toBe(4);
});
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `php artisan test tests/Feature/NavigationTest.php tests/Feature/DemoSeederTest.php`
Expected: `NavigationTest` already PASSES (it pins behaviour from Tasks 2 and 7 that the new menu relies on); `DemoSeederTest` FAILS with `Class "Database\Seeders\DemoSeeder" not found`.

- [ ] **Step 3: Create the demo seeder**

Create `database/seeders/DemoSeeder.php`:
```php
<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/** Invented accounts for local testing. Never put real staff or card details here. */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->account('Alex Accounts', 'admin@cardtrail.test', ['is_admin' => true, 'is_cardholder' => false]);
        $this->account('Sam Cardholder', 'sam@cardtrail.test', [], '1001', 'S CARDHOLDER');
        $this->account('Jo Manager', 'jo@cardtrail.test', [], '1002', 'J MANAGER');
        $this->account('Morgan Microsoft', 'morgan@cardtrail.test', ['use_microsoft' => true, 'password' => null], '1003', 'M MICROSOFT');
    }

    private function account(string $name, string $email, array $attributes = [], ?string $last4 = null, ?string $cardholderName = null): void
    {
        $user = User::updateOrCreate(['email' => $email], [
            'name' => $name,
            'password' => Hash::make('password'),
            ...$attributes,
        ]);

        if ($last4) {
            $user->cardIdentifiers()->firstOrCreate(['last4' => $last4], ['cardholder_name' => $cardholderName]);
        }
    }
}
```

Replace the body of `run()` in `database/seeders/DatabaseSeeder.php` with:
```php
        $this->call(DemoSeeder::class);
```
and remove the unused `use App\Models\User;` import.

- [ ] **Step 4: Rename the app in the logo**

In `resources/js/components/AppLogo.vue`, change `Laravel Starter Kit` to `CardTrail`.

- [ ] **Step 5: Make the sidebar role-aware**

Replace `resources/js/components/AppSidebar.vue` with:
```vue
<script setup lang="ts">
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { LayoutGrid, Users } from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from './AppLogo.vue';

const page = usePage<SharedData>();

const mainNavItems = computed<NavItem[]>(() => [
    { title: 'Dashboard', href: '/dashboard', icon: LayoutGrid },
    ...(page.props.auth.user.is_admin ? [{ title: 'Manage users', href: '/admin/users', icon: Users }] : []),
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

- [ ] **Step 6: Replace the dashboard**

Replace `resources/js/pages/Dashboard.vue`:
```vue
<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

const page = usePage<SharedData>();
const user = page.props.auth.user;
const firstName = user.name.split(' ')[0];
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-4 p-4">
            <h1 class="text-xl font-semibold">Hello, {{ firstName }}</h1>

            <section v-if="user.is_cardholder" class="rounded-xl border p-4">
                <h2 class="font-medium">Your card reconciliations</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    When the accounts team publishes a month, your transactions will appear here ready to complete.
                </p>
            </section>

            <section v-if="user.is_admin" class="rounded-xl border p-4">
                <h2 class="font-medium">Admin</h2>
                <p class="mt-1 text-sm text-muted-foreground">Add cardholders and their card identifiers before the first statement upload.</p>
                <Button class="mt-3" as-child>
                    <Link :href="route('admin.users.index')">Manage users</Link>
                </Button>
            </section>
        </div>
    </AppLayout>
</template>
```

- [ ] **Step 7: Run the full suite and the frontend checks**

Run: `php artisan test && npm run build && npx vue-tsc --noEmit 2>&1 | grep -v TS2688`
Expected: all PASS; build succeeds; no type errors.

- [ ] **Step 8: Format with Pint**

Run: `./vendor/bin/pint && php artisan test`
Expected: Pint reports fixed/clean files; tests still PASS.

- [ ] **Step 9: Seed the dev database and check it by hand**

```bash
php artisan migrate:fresh --seed
composer dev
```
Then in a browser at `http://localhost:8000` (the URL `composer dev` prints):
1. Sign in as `admin@cardtrail.test` / `password` → dashboard shows the Admin card; sidebar shows **Manage users**.
2. Open Manage users → four demo users listed; add a user → success message; the invitation email appears in `storage/logs/laravel.log`.
3. Sign out; enter `morgan@cardtrail.test` → the fake Microsoft 365 page appears with the email prefilled → Sign in → dashboard shows only the cardholder card, no Manage users link, and Settings has no Password tab.
4. Sign out; enter `sam@cardtrail.test` → password step → `password` → signed in.
5. Resize the browser to phone width → users list switches to stacked cards; the sidebar collapses to a menu button.

Expected: every check behaves as described. Stop `composer dev` with Ctrl+C.

- [ ] **Step 10: Commit**

```bash
git add -A
git commit -m "feat: CardTrail branding, role-aware navigation and demo seeder

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```
