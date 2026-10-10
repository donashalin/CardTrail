<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A deadline reminder already sent, so the daily command never repeats one. */
class ReminderSend extends Model
{
    protected $fillable = ['reconciliation_id', 'offset_days', 'due_date'];

    protected function casts(): array
    {
        return ['due_date' => 'date:Y-m-d', 'offset_days' => 'integer'];
    }
}
