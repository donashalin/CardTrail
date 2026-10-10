<?php

namespace App\Exports;

/** Today's naming: Row29.pdf, Row29-2.pdf, and Import2-Row29.pdf for a period's later imports. */
final class ReceiptFileNamer
{
    public static function name(int $importOrdinal, int $rowNumber, int $receiptIndex, string $extension): string
    {
        $prefix = $importOrdinal > 1 ? "Import{$importOrdinal}-" : '';
        $suffix = $receiptIndex > 1 ? "-{$receiptIndex}" : '';

        return "{$prefix}Row{$rowNumber}{$suffix}.".strtolower($extension);
    }

    /** Safe as a folder or file name inside a ZIP opened on Windows or a Mac. */
    public static function clean(string $name): string
    {
        $clean = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', ' ', $name);
        $clean = trim(preg_replace('/\s+/u', ' ', (string) $clean));
        $clean = rtrim($clean, ' .');

        return $clean === '' ? 'Untitled' : $clean;
    }
}
