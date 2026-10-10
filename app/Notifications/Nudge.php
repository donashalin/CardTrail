<?php

namespace App\Notifications;

use App\Models\Reconciliation;
use App\Models\User;

class Nudge extends CardTrailNotification
{
    public function __construct(public Reconciliation $reconciliation, public User $admin) {}

    protected function subject(): string
    {
        return "Reminder: {$this->reconciliation->period->label} card reconciliation";
    }

    protected function lines(): array
    {
        return [
            "{$this->admin->name} in accounts asked us to remind you to finish {$this->reconciliation->period->label}.",
            'It’s due '.$this->reconciliation->period->due_date->format('j F Y').'.',
        ];
    }

    protected function url(): string
    {
        return route('reconciliations.show', $this->reconciliation);
    }

    protected function action(): string
    {
        return 'Finish now';
    }
}
