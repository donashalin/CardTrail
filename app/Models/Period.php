<?php

namespace App\Models;

use App\Enums\PeriodStatus;
use Database\Factories\PeriodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Period extends Model
{
    /** @use HasFactory<PeriodFactory> */
    use HasFactory;

    protected $fillable = ['label', 'month', 'due_date', 'reminder_offsets', 'status', 'published_at'];

    /** In-memory defaults mirroring the DB, so new models behave the same before a re-read. */
    protected $attributes = [
        'status' => 'draft',
        'reminder_offsets' => '[3,0]',
    ];

    protected function casts(): array
    {
        return [
            'month' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'status' => PeriodStatus::class,
            'reminder_offsets' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function imports(): HasMany
    {
        return $this->hasMany(Import::class);
    }

    public function reconciliations(): HasMany
    {
        return $this->hasMany(Reconciliation::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function unassignedTransactions(): HasMany
    {
        return $this->transactions()->whereNull('reconciliation_id');
    }
}
