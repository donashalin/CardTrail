<?php

namespace App\Models;

use App\Enums\ImportStatus;
use Database\Factories\ImportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Import extends Model
{
    /** @use HasFactory<ImportFactory> */
    use HasFactory;

    protected $fillable = [
        'original_filename',
        'stored_path',
        'file_hash',
        'header_row',
        'column_mapping',
        'row_count',
        'status',
        'uploaded_by',
    ];

    protected $attributes = [
        'status' => 'draft',
        'header_row' => 1,
        'row_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'header_row' => 'integer',
            'row_count' => 'integer',
            'column_mapping' => 'array',
            'status' => ImportStatus::class,
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** An earlier confirmed upload of the very same file into this period, if any. */
    public function duplicateOf(): ?self
    {
        return self::query()
            ->where('period_id', $this->period_id)
            ->where('file_hash', $this->file_hash)
            ->whereKeyNot($this->id)
            ->where('status', ImportStatus::Confirmed)
            ->oldest('id')
            ->first();
    }
}
