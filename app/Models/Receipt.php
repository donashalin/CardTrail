<?php

namespace App\Models;

use App\Enums\ReceiptSource;
use Database\Factories\ReceiptFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** An invoice or receipt file. With no transaction it sits in its owner's receipt inbox. */
class Receipt extends Model
{
    /** @use HasFactory<ReceiptFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'transaction_id', 'disk', 'path', 'original_name', 'mime', 'size', 'source', 'inbound_message_id'];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'source' => ReceiptSource::class,
        ];
    }

    protected static function booted(): void
    {
        static::deleted(fn (Receipt $receipt) => Storage::disk($receipt->disk)->delete($receipt->path));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function scopeInInbox(Builder $query): void
    {
        $query->whereNull('transaction_id');
    }

    /** Browsers can display these themselves; everything else is downloaded. */
    public function viewableInline(): bool
    {
        return in_array($this->mime, ['application/pdf', 'image/jpeg', 'image/png'], true);
    }

    /** The original name, with the extension of what is actually stored (a HEIC photo may now be a JPEG). */
    public function downloadName(): string
    {
        return pathinfo($this->original_name, PATHINFO_FILENAME).'.'.pathinfo($this->path, PATHINFO_EXTENSION);
    }

    /** @return array<string, mixed> */
    public function toListItem(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->original_name,
            'mime' => $this->mime,
            'size' => $this->size,
            'is_image' => in_array($this->mime, ['image/jpeg', 'image/png'], true),
            'is_pdf' => $this->mime === 'application/pdf',
            'url' => route('receipts.show', $this),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
