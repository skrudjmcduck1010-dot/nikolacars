<?php

namespace App\Services;

use App\Models\ExchangeRate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class ExchangeRateService
{
    private const DEFAULT_USD_RATE = 43.0;

    public function currentUsdRate(): array
    {
        return $this->usdRateForDate(Carbon::today());
    }

    public function displayUsdRate(Carbon|string|null $date = null): array
    {
        $date = $date instanceof Carbon
            ? $date->copy()->startOfDay()
            : Carbon::parse($date ?? 'today')->startOfDay();

        $storedRate = $this->storedUsdRateForDate($date) ?? $this->latestStoredUsdRate($date);

        if ($storedRate !== null) {
            return $this->payload((float) $storedRate->rate, $storedRate->source, $this->sourceLabel($storedRate->source), $storedRate->rate_date);
        }

        return $this->payload(self::DEFAULT_USD_RATE, 'fallback', 'резервный курс', $date);
    }

    public function ensureTodayUsdRateStored(): ?ExchangeRate
    {
        $date = Carbon::today();

        return $this->storedUsdRateForDate($date) ?? $this->fetchAndStoreUsdRate($date);
    }

    public function usdRateForDate(Carbon|string|null $date = null): array
    {
        $date = $date instanceof Carbon
            ? $date->copy()->startOfDay()
            : Carbon::parse($date ?? 'today')->startOfDay();

        $storedRate = $this->storedUsdRateForDate($date);

        if ($storedRate !== null) {
            return $this->payload((float) $storedRate->rate, $storedRate->source, $this->sourceLabel($storedRate->source), $date);
        }

        $storedRate = $this->fetchAndStoreUsdRate($date);

        if ($storedRate !== null) {
            return $this->payload((float) $storedRate->rate, $storedRate->source, $this->sourceLabel($storedRate->source), $date);
        }

        $latestRate = $this->latestStoredUsdRate($date);

        if ($latestRate !== null) {
            return $this->payload((float) $latestRate->rate, $latestRate->source, $this->sourceLabel($latestRate->source), $latestRate->rate_date);
        }

        return $this->payload(self::DEFAULT_USD_RATE, 'fallback', 'резервный курс', $date);
    }

    public function fetchAndStoreUsdRate(Carbon|string|null $date = null): ?ExchangeRate
    {
        $date = $date instanceof Carbon
            ? $date->copy()->startOfDay()
            : Carbon::parse($date ?? 'today')->startOfDay();

        $rate = $this->nbuUsdRate($date);

        if ($rate === null) {
            return null;
        }

        $exchangeRate = ExchangeRate::query()
            ->where('currency', 'USD')
            ->whereDate('rate_date', $date->toDateString())
            ->firstOrNew([
                'currency' => 'USD',
                'rate_date' => $date->toDateString(),
            ]);

        $exchangeRate->fill([
            'rate' => $rate,
            'source' => 'nbu',
            'fetched_at' => now(),
        ])->save();

        Cache::put($this->cacheKey($date), $rate, now()->addHours(6));

        if ($date->isSameDay(Carbon::today())) {
            Cache::put('exchange_rate_usd_nbu_latest', $rate, now()->addHours(6));
        }

        return $exchangeRate;
    }

    public function productSellingPriceUah(float $sellingPrice, ?string $currency, ?array $usdRate = null): float
    {
        $currency = strtoupper((string) ($currency ?: 'UAH'));
        $usdRate ??= $this->currentUsdRate();

        return round($currency === 'USD' ? $sellingPrice * (float) $usdRate['rate'] : $sellingPrice, 2);
    }

    public function roundUahToTen(float $amount): float
    {
        return round($amount / 10) * 10;
    }

    public function productSellingPriceUahRoundedToTen(float $sellingPrice, ?string $currency, ?array $usdRate = null): float
    {
        return $this->roundUahToTen($this->productSellingPriceUah($sellingPrice, $currency, $usdRate));
    }

    public function catalogPriceToUsd(float|string|null $amount, ?string $currency, ?array $usdRate = null): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        $amount = (float) $amount;

        if ($amount <= 0) {
            return null;
        }

        $currency = strtoupper((string) ($currency ?: 'USD'));

        if ($currency === 'UAH') {
            $usdRate ??= $this->displayUsdRate();
            $rate = (float) ($usdRate['rate'] ?? 0);

            if ($rate <= 0) {
                return null;
            }

            $amount /= $rate;
        }

        return number_format($amount, 2, '.', '');
    }

    private function storedUsdRateForDate(Carbon $date): ?ExchangeRate
    {
        return ExchangeRate::query()
            ->where('currency', 'USD')
            ->where('source', 'nbu')
            ->whereDate('rate_date', $date->toDateString())
            ->first();
    }

    private function latestStoredUsdRate(Carbon $date): ?ExchangeRate
    {
        return ExchangeRate::query()
            ->where('currency', 'USD')
            ->where('source', 'nbu')
            ->whereDate('rate_date', '<=', $date->toDateString())
            ->latest('rate_date')
            ->first();
    }

    private function nbuUsdRate(Carbon $date): ?float
    {
        return Cache::remember($this->cacheKey($date), now()->addHours(6), function () use ($date): ?float {
            try {
                $response = Http::timeout(5)
                    ->acceptJson()
                    ->get('https://bank.gov.ua/NBUStatService/v1/statdirectory/exchange', [
                        'valcode' => 'USD',
                        'date' => $date->format('Ymd'),
                        'json' => '',
                    ]);
            } catch (Throwable) {
                return null;
            }

            if (! $response->ok()) {
                return null;
            }

            $row = collect($response->json())->first();
            $rate = is_array($row) ? ($row['rate'] ?? null) : null;

            return is_numeric($rate) && (float) $rate > 0 ? (float) $rate : null;
        });
    }

    private function cacheKey(Carbon $date): string
    {
        if ($date->isSameDay(Carbon::today())) {
            return 'exchange_rate_usd_nbu_latest';
        }

        return 'exchange_rate_usd_nbu_'.$date->toDateString();
    }

    private function sourceLabel(string $source): string
    {
        return $source === 'nbu' ? 'курс НБУ' : $source;
    }

    private function payload(float $rate, string $source, string $sourceLabel, Carbon $date): array
    {
        return [
            'rate' => round($rate, 6),
            'source' => $source,
            'source_label' => $sourceLabel,
            'date' => $date->toDateString(),
            'label' => '$ '.number_format($rate, 2, '.', ' ').' · '.$sourceLabel,
        ];
    }
}
