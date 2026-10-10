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
