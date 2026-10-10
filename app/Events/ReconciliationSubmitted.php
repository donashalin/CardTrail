<?php

namespace App\Events;

use App\Models\Reconciliation;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A cardholder submitted (or resubmitted after a query) their month. Plan 5 tells the admins. */
class ReconciliationSubmitted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public Reconciliation $reconciliation,
        public bool $resubmitted,
    ) {}
}
