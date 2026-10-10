<?php

namespace App\Notifications;

use App\Models\Reconciliation;

class ReconciliationSubmittedNotification extends CardTrailNotification
{
    public function __construct(public Reconciliation $reconciliation, public bool $resubmitted) {}

    protected function subject(): string
    {
        return $this->reconciliation->user->name.($this->resubmitted ? ' resubmitted ' : ' submitted ').$this->reconciliation->period->label;
    }

    protected function lines(): array
    {
        return [
            $this->resubmitted
                ? "{$this->reconciliation->user->name} has answered your questions and submitted again."
                : "{$this->reconciliation->user->name} has finished their lines and they're ready for review.",
        ];
    }

    protected function url(): string
    {
        return route('admin.reconciliations.review', $this->reconciliation);
    }

    protected function action(): string
    {
        return 'Review';
    }
}
