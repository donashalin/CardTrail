<?php

namespace App\Models;

use Database\Factories\CardIdentifierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CardIdentifier extends Model
{
    /** @use HasFactory<CardIdentifierFactory> */
    use HasFactory;

    protected $fillable = ['last4', 'cardholder_name'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
