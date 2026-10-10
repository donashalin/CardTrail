<?php

namespace App\Imports;

use RuntimeException;

/** The import can't be confirmed or removed yet. The message is safe to show the admin. */
class ImportNotReady extends RuntimeException
{
    /** @param  list<int>  $rowNumbers */
    public static function unresolvedRows(array $rowNumbers): self
    {
        return new self('Rows '.implode(', ', $rowNumbers).' still have problems. Fix or skip them before confirming.');
    }
}
