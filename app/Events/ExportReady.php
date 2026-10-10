<?php

namespace App\Events;

use App\Models\Export;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** An export finished. Plan 5 tells the admin who asked for it. */
class ExportReady implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Export $export) {}
}
