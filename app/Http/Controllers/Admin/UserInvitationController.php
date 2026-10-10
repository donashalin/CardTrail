<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class UserInvitationController extends Controller
{
    public function store(User $user): RedirectResponse
    {
        if (! $user->active) {
            return back()->with('error', "{$user->name} is inactive. Reactivate them before sending an invitation.");
        }

        $user->sendInvite();

        return back()->with('success', "Invitation sent to {$user->email}.");
    }
}
