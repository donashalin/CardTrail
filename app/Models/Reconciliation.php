<?php

namespace App\Models;

use App\Enums\PeriodStatus;
use App\Enums\ReconciliationStatus;
use App\Events\ReconciliationLinesAdded;
use Database\Factories\ReconciliationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One cardholder's lines for one period: what they submit and the accountant approves. */
class Reconciliation extends Model
{
    /** @use HasFactory<ReconciliationFactory> */
    use HasFactory;

    protected $fillable = ['period_id', 'user_id', 'status', 'submitted_at', 'approved_at', 'approved_by'];

    protected $attributes = [
        'status' => 'in_progress',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReconciliationStatus::class,
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** New lines arrived: anything already submitted or approved needs looking at again. Call inside a transaction. */
    public function linesAdded(int $count): void
    {
        // Locked so a submit happening at the same moment can't slip past the reopen.
        $current = self::query()->whereKey($this->id)->lockForUpdate()->firstOrFail();

        if (in_array($current->status, [ReconciliationStatus::Submitted, ReconciliationStatus::Approved], true)) {
            $current->update([
                'status' => ReconciliationStatus::InProgress,
                'approved_at' => null,
                'approved_by' => null,
            ]);
        }

        $this->setRawAttributes($current->getAttributes(), true);

        if ($this->period->status === PeriodStatus::Published) {
            ReconciliationLinesAdded::dispatch($this, $count);
        }
    }
}
