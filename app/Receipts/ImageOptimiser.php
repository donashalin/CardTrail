<?php

namespace App\Receipts;

use App\Models\Receipt;
use GdImage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Imagick;

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

        $result = class_exists(Imagick::class) ? $this->withImagick($original, $heic) : $this->withGd($original, $receipt->mime);

        if ($result === null) {
            return;
        }

        [$bytes, $mime, $extension] = $result;
        $path = preg_replace('/\.[^.\/]+$/', '.'.$extension, $receipt->path);

        $disk->put($path, $bytes);

        if ($path !== $receipt->path) {
            $disk->delete($receipt->path);
        }

        $receipt->update(['path' => $path, 'mime' => $mime, 'size' => strlen($bytes)]);
    }

    /** @return array{0: string, 1: string, 2: string}|null */
    private function withImagick(string $bytes, bool $heic): ?array
    {
        $image = new Imagick;
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
