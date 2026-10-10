<?php

namespace App\Models;

use App\Enums\InboundOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One email sent to the receipts address: what happened to it, and a guard against processing it twice. */
class InboundMessage extends Model
{
    protected $fillable = ['source_driver', 'message_id', 'from_email', 'subject', 'received_at', 'user_id', 'outcome', 'attachment_count', 'accepted_count'];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'outcome' => InboundOutcome::class,
            'attachment_count' => 'integer',
            'accepted_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }
}
