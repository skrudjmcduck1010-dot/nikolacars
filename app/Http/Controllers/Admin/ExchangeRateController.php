<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use App\Services\ExchangeRateService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class ExchangeRateController extends Controller
{
    public function index(ExchangeRateService $exchangeRateService): View
    {
        $today = Carbon::today();
        $todaySource = $exchangeRateService->officialSourceForDate($today);
        $fetchError = null;

        try {
            $exchangeRateService->ensureTodayUsdRateStored();
        } catch (\Throwable $exception) {
            $fetchError = 'Не удалось обновить курс за сегодня.';
            Log::warning('Could not ensure today USD exchange rate from exchange rates page.', [
                'exception' => $exception,
            ]);
        }

        $todayRate = ExchangeRate::query()
            ->where('currency', 'USD')
            ->where('source', $todaySource)
            ->whereDate('rate_date', $today->toDateString())
            ->first();

        $effectiveRate = $todayRate ?? ExchangeRate::query()
            ->where('currency', 'USD')
            ->whereIn('source', $exchangeRateService->officialSources())
            ->whereDate('rate_date', '<=', $today->toDateString())
            ->latest('rate_date')
            ->latest()
            ->first();

        return view('admin.exchange_rates.index', [
            'today' => $today,
            'todayRate' => $todayRate,
            'effectiveRate' => $effectiveRate,
            'fetchError' => $fetchError,
            'exchangeRates' => ExchangeRate::query()
                ->whereIn('source', $exchangeRateService->officialSources())
                ->latest('rate_date')
                ->latest()
                ->paginate(60),
        ]);
    }
}
