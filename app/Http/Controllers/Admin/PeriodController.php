<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PeriodStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PeriodRequest;
use App\Models\Period;
use App\Periods\PeriodDashboard;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PeriodController extends Controller
{
    public function index(): Response
    {
        $periods = Period::query()
            ->withCount('transactions')
            ->orderByDesc('month')
            ->get()
            ->map(fn (Period $period) => [
                'id' => $period->id,
                'label' => $period->label,
                'due_date' => $period->due_date->format('Y-m-d'),
                'status' => $period->status->value,
                'transactions_count' => $period->transactions_count,
            ]);

        return Inertia::render('admin/periods/Index', ['periods' => $periods]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/periods/Form', [
            'period' => null,
            'defaults' => [
                // Reconciliation is usually for last month, due mid-month.
                'month' => today()->subMonthNoOverflow()->format('Y-m'),
                'due_date' => today()->day(15)->format('Y-m-d'),
                'reminder_offsets' => '3, 0',
            ],
        ]);
    }

    public function store(PeriodRequest $request): RedirectResponse
    {
        $period = Period::create($request->periodAttributes());

        return to_route('admin.periods.show', $period)->with('success', "{$period->label} has been created. Upload the statement next.");
    }

    public function show(Period $period): Response
    {
        return Inertia::render('admin/periods/Show', PeriodDashboard::for($period));
    }

    public function edit(Period $period): Response
    {
        abort_if($period->status === PeriodStatus::Closed, 403);

        return Inertia::render('admin/periods/Form', [
            'period' => [
                'id' => $period->id,
                'label' => $period->label,
                'month' => $period->month->format('Y-m'),
                'due_date' => $period->due_date->format('Y-m-d'),
                'reminder_offsets' => implode(', ', $period->reminder_offsets),
                'status' => $period->status->value,
            ],
            'defaults' => null,
        ]);
    }

    public function update(PeriodRequest $request, Period $period): RedirectResponse
    {
        abort_if($period->status === PeriodStatus::Closed, 403);

        $period->update($request->periodAttributes());

        return to_route('admin.periods.show', $period)->with('success', "{$period->label} has been updated.");
    }
}
