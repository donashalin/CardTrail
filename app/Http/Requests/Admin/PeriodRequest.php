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
