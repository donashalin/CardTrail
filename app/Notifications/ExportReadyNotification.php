<?php

namespace App\Notifications;

use App\Models\Export;

class ExportReadyNotification extends CardTrailNotification
{
    public function __construct(public Export $export) {}

    protected function subject(): string
    {
        return 'Your export is ready';
    }

    protected function lines(): array
    {
        return ["{$this->export->filename} is ready to download. It will be kept for 7 days."];
    }

    protected function url(): string
    {
        return route('admin.periods.show', $this->export->period_id);
    }

    protected function action(): string
    {
        return 'Download';
    }
}
