<?php

namespace App\Models;

use App\Enums\PeriodStatus;
use App\Jobs\PlaceReceipt;
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

    protected static function booted(): void
    {
        // Receipt folders are named after the month, so correcting it moves the files.
        static::updated(function (Period $period) {
            if ($period->wasChanged('month')) {
                PlaceReceipt::dispatchFor(Receipt::query()->whereHas('transaction', fn ($query) => $query->where('period_id', $period->id)));
            }
        });
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

    public function exports(): HasMany
    {
        return $this->hasMany(Export::class);
    }
}
