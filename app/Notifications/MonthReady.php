<?php

namespace App\Notifications;

use App\Models\Reconciliation;
use Illuminate\Support\Str;

class MonthReady extends CardTrailNotification
{
    public function __construct(public Reconciliation $reconciliation) {}

    protected function subject(): string
    {
        return "{$this->reconciliation->period->label}: your card transactions are ready";
    }

    protected function lines(): array
    {
        $count = $this->reconciliation->transactions()->count();

        return [
            "You have {$count} ".Str::plural('line', $count).' to complete by '.$this->reconciliation->period->due_date->format('j F Y').'.',
            'For each one, say what it was for and add a photo or file of the receipt.',
        ];
    }

    protected function url(): string
    {
        return route('reconciliations.show', $this->reconciliation);
    }

    protected function action(): string
    {
        return 'Start now';
    }
}
