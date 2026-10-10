<?php

namespace App\Events;

use App\Models\Period;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A period went live. Plan 5 emails and pushes each cardholder who has lines. */
class PeriodPublished implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Period $period) {}
}
