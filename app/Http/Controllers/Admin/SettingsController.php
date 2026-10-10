<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ColumnMapping;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
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
        $raw = $request->input('reminder_offsets', '');

        if (! is_string($raw) && $raw !== null) {
            throw ValidationException::withMessages(['reminder_offsets' => 'Reminder days must be whole numbers, like 3, 0.']);
        }

        $offsets = collect(explode(',', (string) $raw))
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
            'reminder_offsets.*.min' => 'Reminder days can’t be negative. Use 0 for the due date itself.',
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
