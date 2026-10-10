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
