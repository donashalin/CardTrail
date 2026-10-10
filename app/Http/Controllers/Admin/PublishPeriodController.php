<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ImportStatus;
use App\Enums\PeriodStatus;
use App\Events\PeriodPublished;
use App\Http\Controllers\Controller;
use App\Models\Period;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PublishPeriodController extends Controller
{
    public function __invoke(Request $request, Period $period): RedirectResponse
    {
        if ($period->status !== PeriodStatus::Draft) {
            return back()->with('error', 'This period has already been published.');
        }

        if (! $period->imports()->where('status', ImportStatus::Confirmed)->exists()) {
            return back()->with('error', 'Import the statement before publishing.');
        }

        DB::transaction(function () use ($period, $request) {
            $period->update(['status' => PeriodStatus::Published, 'published_at' => now()]);

            PeriodPublished::dispatch($period);

            activity('periods')
                ->causedBy($request->user())
                ->performedOn($period)
                ->event('published')
                ->log("Published {$period->label}");
        });

        return to_route('admin.periods.show', $period)
            ->with('success', "{$period->label} is published. Cardholders can now see their lines.");
    }
}
