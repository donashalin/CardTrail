<?php

namespace App\Receipts;

use App\Models\Receipt;
use GdImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Imagick;
use Throwable;

/** Turns HEIC photos into JPEGs and shrinks big photos, so receipts load quickly on phones. */
final class ImageOptimiser
{
    public const MAX_EDGE = 2000;

    public function optimise(Receipt $receipt): void
    {
        $heic = in_array($receipt->mime, ['image/heic', 'image/heif'], true);

        if (! $heic && ! in_array($receipt->mime, ['image/jpeg', 'image/png'], true)) {
            return;
        }

        if ($heic && ! (class_exists(Imagick::class) && Imagick::queryFormats('HEIC') !== [])) {
            Log::warning('Receipt kept as HEIC because Imagick with HEIC support is not installed.', ['receipt_id' => $receipt->id]);

            return;
        }

        $disk = Storage::disk($receipt->disk);
        $original = $disk->get($receipt->path);

        if ($original === null) {
            return;
        }

        $result = class_exists(Imagick::class) ? $this->withImagick($original, $heic, $receipt->mime === 'image/png' ? 'PNG' : 'JPEG') : $this->withGd($original, $receipt->mime);

        if ($result === null) {
            return;
        }

        [$bytes, $mime, $extension] = $result;

        // A new file, recorded before the original goes, so the receipt always points at a file that exists.
        $path = dirname($receipt->path).'/'.Str::uuid().'.'.$extension;
        $disk->put($path, $bytes);

        try {
            $recorded = DB::transaction(function () use ($receipt, $path, $mime, $bytes) {
                $current = Receipt::query()->whereKey($receipt->id)->lockForUpdate()->first();

                // Deleted, or its file replaced, while we were working.
                if ($current === null || $current->path !== $receipt->path) {
                    return false;
                }

                $current->update(['path' => $path, 'mime' => $mime, 'size' => strlen($bytes)]);

                return true;
            });
        } catch (Throwable $e) {
            $disk->delete($path);

            throw $e;
        }

        $disk->delete($recorded ? $receipt->path : $path);

        if ($recorded) {
            $receipt->refresh();
        }
    }

    /** @return array{0: string, 1: string, 2: string}|null */
    private function withImagick(string $bytes, bool $heic, string $format): ?array
    {
        // Name the format so ImageMagick never guesses from the contents (and never reaches its riskier decoders).
        $image = new Imagick;
        $image->setFormat($heic ? 'HEIC' : $format);
        $image->readImageBlob($bytes);
        $large = max($image->getImageWidth(), $image->getImageHeight()) > self::MAX_EDGE;

        if (! $heic && ! $large) {
            return null;
        }

        if (method_exists($image, 'autoOrient')) {
            $image->autoOrient();
        }

        if ($large) {
            $image->thumbnailImage(self::MAX_EDGE, self::MAX_EDGE, true);
        }

        if ($heic) {
            $image->setImageFormat('jpeg');
        }

        $image->setImageCompressionQuality(85);
        $png = strtolower($image->getImageFormat()) === 'png';

        return [$image->getImagesBlob(), $png ? 'image/png' : 'image/jpeg', $png ? 'png' : 'jpg'];
    }

    /** @return array{0: string, 1: string, 2: string}|null */
    private function withGd(string $bytes, string $mime): ?array
    {
        $size = @getimagesizefromstring($bytes);

        if (! $size || max($size[0], $size[1]) <= self::MAX_EDGE) {
            return null;
        }

        $this->allowMemoryFor($size[0], $size[1]);
        $image = @imagecreatefromstring($bytes);

        if (! $image) {
            return null;
        }

        if ($mime === 'image/jpeg') {
            $image = $this->orient($image, $bytes);
        }

        $scale = self::MAX_EDGE / max(imagesx($image), imagesy($image));
        $resized = imagescale($image, max(1, (int) round(imagesx($image) * $scale)), max(1, (int) round(imagesy($image) * $scale)));

        ob_start();
        if ($mime === 'image/png') {
            imagesavealpha($resized, true);
            imagepng($resized, null, 6);
        } else {
            imagejpeg($resized, null, 85);
        }

        return [(string) ob_get_clean(), $mime, $mime === 'image/png' ? 'png' : 'jpg'];
    }

    /**
     * PHP's bundled GD counts its pixels against memory_limit: a 48 megapixel photo needs about 200 MB,
     * more than the usual 128 MB. Raise the limit for this job rather than fail.
     */
    private function allowMemoryFor(int $width, int $height): void
    {
        $limit = ini_get('memory_limit');
        $needed = memory_get_usage() + $width * $height * 5 + 64 * 1024 * 1024;

        if ($limit !== false && $limit !== '-1' && ini_parse_quantity($limit) < $needed) {
            ini_set('memory_limit', (string) min($needed, 1024 * 1024 * 1024));
        }
    }

    /** GD drops EXIF data, so turn the pixels the way the camera intended before re-saving. */
    private function orient(GdImage $image, string $bytes): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));

        return match ((int) ($exif['Orientation'] ?? 1)) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };
    }
}
