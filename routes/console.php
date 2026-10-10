<?php

use App\Models\Export;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('exports:prune', function () {
    $old = Export::query()->where('created_at', '<', now()->subDays(7))->get();
    $old->each->delete();
    $this->info("Removed {$old->count()} old exports.");
})->purpose('Delete exports older than 7 days');

Schedule::command('exports:prune')->daily();
