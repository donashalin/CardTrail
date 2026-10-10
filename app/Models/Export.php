<?php

namespace App\Models;

use App\Enums\ExportStatus;
use Database\Factories\ExportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** A ZIP of a period (or one manager's part of it), built in the background and kept for 7 days. */
class Export extends Model
{
    /** @use HasFactory<ExportFactory> */
    use HasFactory;

    protected $fillable = ['period_id', 'user_id', 'requested_by', 'status', 'path', 'filename', 'error', 'completed_at'];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'status' => ExportStatus::class,
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleted(function (Export $export) {
            if ($export->path) {
                Storage::disk('local')->deleteDirectory(dirname($export->path));
            }
        });
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    /** The manager this export is limited to; null for the whole period. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
