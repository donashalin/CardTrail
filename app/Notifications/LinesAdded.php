<?php

namespace App\Notifications;

use App\Models\Reconciliation;
use Illuminate\Support\Str;

class LinesAdded extends CardTrailNotification
{
    public function __construct(public Reconciliation $reconciliation, public int $count) {}

    protected function subject(): string
    {
        return "{$this->count} more ".Str::plural('line', $this->count)." in {$this->reconciliation->period->label}";
    }

    protected function lines(): array
    {
        return [
            "Accounts have added {$this->count} ".Str::plural('line', $this->count).' to your '.$this->reconciliation->period->label.' card reconciliation.',
            'If you had already submitted, please complete the new lines and submit again.',
        ];
    }

    protected function url(): string
    {
        return route('reconciliations.show', $this->reconciliation);
    }
}
