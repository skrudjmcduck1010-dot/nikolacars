<?php

namespace App\Http\Controllers\Admin;

use App\Models\CashTransaction;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MonthlyReportController extends CashTransactionController
{
    public function index(Request $request): View
    {
        $this->keepOnlyMonthlyReportFilters($request);

        $sourceSheet = trim((string) $request->input('source_sheet'));

        if ($sourceSheet === '') {
            $sourceSheet = $this->defaultMonthlyReportSheet(
                now()->startOfMonth()->toDateString(),
                now()->toDateString(),
            );
        }

        if ($sourceSheet) {
            $period = $this->sourceSheetPeriod($sourceSheet);

            if ($period) {
                $request->merge([
                    'from' => $period->first_operation_date,
                    'to' => $period->latest_operation_date,
                    'source_sheet' => $sourceSheet,
                ]);
            }
        }

        if (! $request->filled('from') || ! $request->filled('to')) {
            $request->merge([
                'from' => now()->startOfMonth()->toDateString(),
                'to' => now()->toDateString(),
            ]);
        }

        return view('admin.reports.monthly', $this->buildCashbookDashboardData($request));
    }

    protected function keepOnlyMonthlyReportFilters(Request $request): void
    {
        foreach (['from', 'to', 'label', 'operation_type', 'employee', 'search', 'show_all', 'per_page', 'sort', 'direction'] as $key) {
            $request->query->remove($key);
            $request->request->remove($key);
        }
    }

    protected function sourceSheetPeriod(string $sourceSheet): ?object
    {
        return CashTransaction::query()
            ->where('source_sheet', $sourceSheet)
            ->selectRaw('MIN(operation_date) as first_operation_date')
            ->selectRaw('MAX(operation_date) as latest_operation_date')
            ->first();
    }

    protected function defaultMonthlyReportSheet(string $from, string $to): ?string
    {
        return CashTransaction::query()
            ->whereBetween('operation_date', [$from, $to])
            ->whereNotNull('source_sheet')
            ->where('source_sheet', '<>', '')
            ->select('source_sheet')
            ->selectRaw('MAX(operation_date) as latest_operation_date')
            ->groupBy('source_sheet')
            ->orderByDesc('latest_operation_date')
            ->value('source_sheet')
            ?: CashTransaction::query()
                ->whereNotNull('source_sheet')
                ->where('source_sheet', '<>', '')
                ->select('source_sheet')
                ->selectRaw('MAX(operation_date) as latest_operation_date')
                ->groupBy('source_sheet')
                ->orderByDesc('latest_operation_date')
                ->value('source_sheet');
    }
}
