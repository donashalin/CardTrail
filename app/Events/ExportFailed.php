<?php

namespace App\Events;

use App\Models\Export;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** An export gave up after its retries. Plan 5 tells the admin who asked for it. */
class ExportFailed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Export $export) {}
}
