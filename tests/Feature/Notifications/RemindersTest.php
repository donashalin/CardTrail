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
