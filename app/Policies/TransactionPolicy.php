<?php

namespace App\Policies;

use App\Enums\PeriodStatus;
use App\Models\Transaction;
use App\Models\User;

class TransactionPolicy
{
    public function view(User $user, Transaction $line): bool
    {
        return $user->is_admin || $this->ownsVisible($user, $line);
    }

    public function update(User $user, Transaction $line): bool
    {
        return $this->ownsVisible($user, $line) && $line->editableByOwner();
    }

    public function comment(User $user, Transaction $line): bool
    {
        if ($line->period->status === PeriodStatus::Closed) {
            return false;
        }

        return $user->is_admin || $this->ownsVisible($user, $line);
    }

    /** Cardholders only ever see their own lines, and only once the month is published. */
    private function ownsVisible(User $user, Transaction $line): bool
    {
        return $line->ownerId() === $user->id && $line->period->status !== PeriodStatus::Draft;
    }
}
