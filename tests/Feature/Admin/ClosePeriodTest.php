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
