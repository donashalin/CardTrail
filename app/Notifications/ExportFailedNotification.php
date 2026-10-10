<?php

namespace App\Notifications;

use App\Models\Export;

class ExportFailedNotification extends CardTrailNotification
{
    public function __construct(public Export $export) {}

    protected function subject(): string
    {
        return 'Your export failed';
    }

    protected function lines(): array
    {
        return [
            "We couldn't build the export for {$this->export->period->label}.",
            $this->export->error ?? 'No reason was recorded.',
        ];
    }

    protected function url(): string
    {
        return route('admin.periods.show', $this->export->period_id);
    }
}
