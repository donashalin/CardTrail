<?php

use App\Models\ColumnMapping;
use App\Models\Setting;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

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

it('explains that reminder days can’t be negative', function () {
    $this->actingAs($this->admin)->put(route('admin.settings.update'), ['reminder_offsets' => '3, -1'])
        ->assertSessionHasErrors(['reminder_offsets' => 'Reminder days can’t be negative. Use 0 for the due date itself.']);
});

it('refuses reminder days sent as a list instead of text', function () {
    $this->actingAs($this->admin)->put(route('admin.settings.update'), ['reminder_offsets' => ['3', '0']])
        ->assertSessionHasErrors(['reminder_offsets' => 'Reminder days must be whole numbers, like 3, 0.']);
});

it('records renaming and forgetting a column match in the activity log', function () {
    $mapping = ColumnMapping::create(['name' => 'Amex export', 'header_signature' => str_repeat('a', 64), 'header_row' => 1, 'mapping' => ['date' => 0, 'description' => 1, 'amount' => 2]]);

    $this->actingAs($this->admin)->put(route('admin.column-mappings.update', $mapping), ['name' => 'Amex corporate']);
    $this->actingAs($this->admin)->delete(route('admin.column-mappings.destroy', $mapping));

    expect(Activity::query()->where('log_name', 'settings')->where('causer_id', $this->admin->id)->pluck('description')->all())
        ->toBe(['Renamed column match “Amex export” to “Amex corporate”', 'Forgot column match “Amex corporate”']);
});
