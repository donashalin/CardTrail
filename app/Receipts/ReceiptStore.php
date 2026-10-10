<?php

namespace App\Receipts;

use App\Enums\ReceiptSource;
use App\Jobs\ProcessReceiptImage;
use App\Models\Receipt;
use App\Models\Transaction;
use App\Models\User;
use Closure;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/** The one way receipt files reach storage: uploads now, email attachments in Plan 5. */
final class ReceiptStore
{
    /** @var list<string> Files stored during the current transaction(). */
    private array $written = [];

    /**
     * Runs the work in a database transaction. If it fails, files stored during it are deleted too,
     * so a rolled-back upload or email leaves nothing behind on disk.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function transaction(Closure $work): mixed
    {
        $this->written = [];

        try {
            return DB::transaction($work);
        } catch (Throwable $e) {
            Storage::disk($this->disk())->delete($this->written);

            throw $e;
        } finally {
            $this->written = [];
        }
    }

    public function storeUpload(UploadedFile $file, User $owner, ReceiptSource $source, ?Transaction $line = null): Receipt
    {
        $name = $file->getClientOriginalName();
        $extension = ReceiptType::extension($name) ?? throw new InvalidArgumentException("{$name} is not an accepted receipt type.");
        $path = $this->newPath($owner, $extension);

        Storage::disk($this->disk())->putFileAs(dirname($path), $file, basename($path));
        $this->written[] = $path;

        return $this->record($owner, $source, $line, $path, $name, ReceiptType::storedMime($extension, (string) $file->getMimeType()), (int) $file->getSize());
    }

    /** For receipts that arrive as bytes. Returns null when the file isn't an accepted, genuine receipt type. */
    public function storeContents(string $contents, string $originalName, User $owner, ReceiptSource $source, ?int $inboundMessageId = null): ?Receipt
    {
        $extension = ReceiptType::extension($originalName);
        $detected = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($contents);

        if ($extension === null || strlen($contents) > ReceiptType::MAX_KB * 1024 || ! ReceiptType::contentMatches($extension, $detected)) {
            return null;
        }

        $path = $this->newPath($owner, $extension);
        Storage::disk($this->disk())->put($path, $contents);
        $this->written[] = $path;

        return $this->record($owner, $source, null, $path, $originalName, ReceiptType::storedMime($extension, $detected), strlen($contents), $inboundMessageId);
    }

    private function record(User $owner, ReceiptSource $source, ?Transaction $line, string $path, string $name, string $mime, int $size, ?int $inboundMessageId = null): Receipt
    {
        $receipt = Receipt::create([
            'user_id' => $owner->id,
            'transaction_id' => $line?->id,
            'disk' => $this->disk(),
            'path' => $path,
            'original_name' => Str::limit($name, 250, ''),
            'mime' => $mime,
            'size' => $size,
            'source' => $source,
            'inbound_message_id' => $inboundMessageId,
        ]);

        if (str_starts_with($mime, 'image/')) {
            ProcessReceiptImage::dispatch($receipt)->afterCommit();
        }

        return $receipt;
    }

    private function newPath(User $owner, string $extension): string
    {
        return $owner->id.'/'.Str::uuid().'.'.$extension;
    }

    private function disk(): string
    {
        return config('cardtrail.receipts.disk');
    }
}
