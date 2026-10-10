<?php

namespace App\Policies;

use App\Enums\PeriodStatus;
use App\Enums\ReconciliationStatus;
use App\Models\Reconciliation;
use App\Models\User;

class ReconciliationPolicy
{
    public function view(User $user, Reconciliation $reconciliation): bool
    {
        return $user->is_admin
            || ($reconciliation->user_id === $user->id && $reconciliation->period->status !== PeriodStatus::Draft);
    }

    public function submit(User $user, Reconciliation $reconciliation): bool
    {
        return $reconciliation->user_id === $user->id
            && $reconciliation->period->status === PeriodStatus::Published
            && $reconciliation->status === ReconciliationStatus::InProgress;
    }
}
