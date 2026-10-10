<?php

namespace App\Events;

use App\Models\Reconciliation;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** New lines landed on a cardholder's reconciliation in a published period. Plan 5 notifies them. */
class ReconciliationLinesAdded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public Reconciliation $reconciliation,
        public int $count,
    ) {}
}
