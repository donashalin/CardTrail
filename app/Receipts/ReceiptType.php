<?php

namespace App\Receipts;

use Closure;
use Illuminate\Http\UploadedFile;

/** Which files count as receipts, and how to check them. */
final class ReceiptType
{
    public const MIMES = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'heic' => 'image/heic',
        'heif' => 'image/heif',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    public const MAX_KB = 20480;

    private const INLINE = ['application/pdf', 'image/jpeg', 'image/png'];

    /** The lower-cased extension when it is an accepted type, otherwise null. */
    public static function extension(string $filename): ?string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return array_key_exists($extension, self::MIMES) ? $extension : null;
    }

    /**
     * Browsers display PDFs and images themselves, so those bytes must really be a PDF or an image.
     * Other types are always downloaded, never displayed, so their contents aren't checked.
     */
    public static function contentMatches(string $extension, string $detected): bool
    {
        return match (self::MIMES[$extension]) {
            'application/pdf' => $detected === 'application/pdf',
            'image/jpeg', 'image/png' => in_array($detected, ['image/jpeg', 'image/png'], true),
            default => true,
        };
    }

    /** What we record and serve the file as. A ".jpg" that is really a PNG is served as a PNG. */
    public static function storedMime(string $extension, string $detected): string
    {
        $expected = self::MIMES[$extension];

        return in_array($expected, self::INLINE, true) && in_array($detected, self::INLINE, true) ? $detected : $expected;
    }

    /** Validation rules for one uploaded receipt. */
    public static function rules(): array
    {
        return [
            'file',
            'max:'.self::MAX_KB,
            'extensions:'.implode(',', array_keys(self::MIMES)),
            function (string $attribute, mixed $file, Closure $fail) {
                $extension = $file instanceof UploadedFile ? self::extension($file->getClientOriginalName()) : null;

                if ($extension !== null && ! self::contentMatches($extension, (string) $file->getMimeType())) {
                    $fail('That file doesn’t look like a real '.strtoupper($extension).'.');
                }
            },
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'files.required' => 'Choose at least one file.',
            'files.max' => 'Upload up to :max files at a time.',
            'files.*.max' => 'Each file must be 20 MB or smaller.',
            'files.*.extensions' => 'Receipts must be PDF, JPG, PNG, HEIC, DOC or DOCX files.',
        ];
    }
}
