<?php

namespace App\Notifications;

use App\Enums\TransactionStatus;
use App\Models\Reconciliation;
use Illuminate\Support\Str;

class DeadlineReminder extends CardTrailNotification
{
    public function __construct(public Reconciliation $reconciliation, public int $daysLeft) {}

    protected function subject(): string
    {
        $label = $this->reconciliation->period->label;

        return $this->daysLeft === 0 ? "{$label} is due today" : "{$label} is due in {$this->daysLeft} ".Str::plural('day', $this->daysLeft);
    }

    protected function lines(): array
    {
        $left = $this->reconciliation->transactions()->whereIn('status', [TransactionStatus::Todo, TransactionStatus::Queried])->count();
        $total = $this->reconciliation->transactions()->count();

        return [
            $left > 0
                ? "You have {$left} of {$total} ".Str::plural('line', $total).' still to finish.'
                : 'Every line is done – just submit it to accounts.',
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
