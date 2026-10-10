<?php

namespace App\Models;

use App\Enums\PeriodStatus;
use App\Enums\ReconciliationStatus;
use App\Enums\TransactionStatus;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One row from an uploaded statement. Amount is in pence; negative is a credit. */
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    protected $fillable = [
        'period_id',
        'import_id',
        'reconciliation_id',
        'row_number',
        'transacted_on',
        'description',
        'amount',
        'card_ref',
        'extra',
        'purpose',
        'no_receipt',
        'no_receipt_reason',
        'status',
        'approved_at',
        'approved_by',
    ];

    protected $attributes = [
        'status' => 'todo',
        'no_receipt' => false,
    ];

    protected function casts(): array
    {
        return [
            'row_number' => 'integer',
            'transacted_on' => 'date:Y-m-d',
            'amount' => 'integer',
            'extra' => 'array',
            'no_receipt' => 'boolean',
            'status' => TransactionStatus::class,
            'approved_at' => 'datetime',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(Reconciliation::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class)->oldest('id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class)->oldest('id');
    }

    public function ownerId(): ?int
    {
        return $this->reconciliation?->user_id;
    }

    /** Purpose filled, and a receipt or a ticked no-receipt box with a reason. Credits need only a purpose. */
    public function meetsDoneRule(): bool
    {
        if (trim((string) $this->purpose) === '') {
            return false;
        }

        if ($this->amount < 0) {
            return true;
        }

        if ($this->no_receipt && trim((string) $this->no_receipt_reason) !== '') {
            return true;
        }

        return $this->receipts()->exists();
    }

    /** Re-derive the status after the cardholder changed something. Sets it; the caller saves. */
    public function refreshStatus(): void
    {
        $this->status = match ($this->status) {
            TransactionStatus::Approved => TransactionStatus::Approved,
            TransactionStatus::Queried => $this->meetsDoneRule() ? TransactionStatus::Done : TransactionStatus::Queried,
            default => $this->meetsDoneRule() ? TransactionStatus::Done : TransactionStatus::Todo,
        };
    }

    /** Whether the cardholder who owns this line may change it right now. */
    public function editableByOwner(): bool
    {
        $reconciliation = $this->reconciliation;

        if (! $reconciliation || $this->period->status !== PeriodStatus::Published) {
            return false;
        }

        if ($this->status === TransactionStatus::Approved) {
            return false;
        }

        return $reconciliation->status === ReconciliationStatus::InProgress || $this->status === TransactionStatus::Queried;
    }
}
