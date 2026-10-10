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
