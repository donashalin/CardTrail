<?php

namespace App\Models;

use App\Enums\TransactionStatus;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
