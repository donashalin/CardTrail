<?php

namespace App\Models;

use Database\Factories\CardIdentifierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CardIdentifier extends Model
{
    /** @use HasFactory<CardIdentifierFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = ['last4', 'cardholder_name'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('users')
            ->logOnly(['user_id', 'last4', 'cardholder_name'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
