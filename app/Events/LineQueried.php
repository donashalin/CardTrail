<?php

namespace App\Events;

use App\Models\Comment;
use App\Models\Transaction;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Accounts asked a question about a line. Plan 5 tells the cardholder. */
class LineQueried implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public Transaction $line,
        public Comment $comment,
    ) {}
}
