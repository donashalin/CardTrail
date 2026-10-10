<?php

use App\Exports\ReceiptFileNamer;

it('names a receipt after its spreadsheet row', function () {
    expect(ReceiptFileNamer::name(1, 29, 1, 'pdf'))->toBe('Row29.pdf');
});

it('numbers further receipts for the same row', function () {
    expect(ReceiptFileNamer::name(1, 29, 2, 'JPG'))->toBe('Row29-2.jpg')
        ->and(ReceiptFileNamer::name(1, 29, 3, 'png'))->toBe('Row29-3.png');
});

it('prefixes receipts from the second and later imports', function () {
    expect(ReceiptFileNamer::name(2, 29, 1, 'pdf'))->toBe('Import2-Row29.pdf')
        ->and(ReceiptFileNamer::name(3, 5, 2, 'pdf'))->toBe('Import3-Row5-2.pdf');
});

it('makes names safe for folders on any computer', function () {
    expect(ReceiptFileNamer::clean('Sam / O’Neil: "Ops"?'))->toBe('Sam O’Neil Ops')
        ->and(ReceiptFileNamer::clean('September 2026 - Jo Manager'))->toBe('September 2026 - Jo Manager')
        ->and(ReceiptFileNamer::clean(' ... '))->toBe('Untitled');
});
