<?php

use App\Imports\Parsing\HeaderSignature;

it('ignores case, spacing and trailing blank columns', function () {
    expect(HeaderSignature::for([' Date ', 'Card  Member', 'AMOUNT', null, '']))
        ->toBe(HeaderSignature::for(['date', 'card member', 'amount']));
});

it('changes when columns are reordered or renamed', function () {
    $original = HeaderSignature::for(['Date', 'Amount']);

    expect(HeaderSignature::for(['Amount', 'Date']))->not->toBe($original)
        ->and(HeaderSignature::for(['Date', 'Value']))->not->toBe($original);
});

it('produces a 40-character signature', function () {
    expect(HeaderSignature::for(['Date']))->toHaveLength(40);
});
