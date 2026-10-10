<?php

namespace App\Console\Commands;

use App\Enums\PeriodStatus;
use App\Enums\ReconciliationStatus;
use App\Models\Period;
use App\Models\Reconciliation;
use App\Models\ReminderSend;
use App\Notifications\DeadlineReminder;
use Illuminate\Console\Command;

class SendDeadlineReminders extends Command
{
    protected $signature = 'reminders:send';

    protected $description = 'Remind cardholders who haven’t submitted, on each period’s reminder days';

    public function handle(): int
    {
        $sent = 0;

        Period::query()->where('status', PeriodStatus::Published)->get()->each(function (Period $period) use (&$sent) {
            foreach (array_unique($period->reminder_offsets ?? []) as $offset) {
                if (! $period->due_date->copy()->subDays((int) $offset)->isToday()) {
                    continue;
                }

                $period->reconciliations()
                    ->where('status', ReconciliationStatus::InProgress)
                    ->with('user')
                    ->get()
                    ->filter(fn (Reconciliation $reconciliation) => $reconciliation->user->active)
                    ->each(function (Reconciliation $reconciliation) use ($period, $offset, &$sent) {
                        $record = ReminderSend::firstOrCreate([
                            'reconciliation_id' => $reconciliation->id,
                            'offset_days' => (int) $offset,
                            'due_date' => $period->due_date->format('Y-m-d'),
                        ]);

                        if ($record->wasRecentlyCreated) {
                            $reconciliation->user->notify(new DeadlineReminder($reconciliation, (int) $offset));
                            $sent++;
                        }
                    });
            }
        });

        $this->info("Sent {$sent} ".str('reminder')->plural($sent).'.');

        return self::SUCCESS;
    }
}
