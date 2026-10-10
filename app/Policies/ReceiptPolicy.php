<?php

namespace App\Policies;

use App\Models\Receipt;
use App\Models\User;

class ReceiptPolicy
{
    public function view(User $user, Receipt $receipt): bool
    {
        return $user->is_admin || $receipt->user_id === $user->id;
    }

    /** Delete, attach to a line, or move back to the inbox. */
    public function manage(User $user, Receipt $receipt): bool
    {
        if ($receipt->user_id !== $user->id) {
            return false;
        }

        return $receipt->transaction_id === null || $receipt->transaction->editableByOwner();
    }
}
