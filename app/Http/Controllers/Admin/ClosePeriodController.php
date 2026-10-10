<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PeriodStatus;
use App\Http\Controllers\Controller;
use App\Models\Period;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClosePeriodController extends Controller
{
    public function __invoke(Request $request, Period $period): RedirectResponse
    {
        $error = DB::transaction(function () use ($period, $request) {
            $period = Period::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();

            if ($period->status !== PeriodStatus::Published) {
                return 'Only a published period can be closed.';
            }

            $period->update(['status' => PeriodStatus::Closed]);

            activity('periods')
                ->causedBy($request->user())
                ->performedOn($period)
                ->event('closed')
                ->log("Closed {$period->label}");

            return null;
        });

        if ($error !== null) {
            return back()->with('error', $error);
        }

        return to_route('admin.periods.show', $period)->with('success', "{$period->label} is closed. Everything in it is now read-only.");
    }
}
