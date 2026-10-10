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
