<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const FALLBACK_USD_RATE = 43.0;

    public function up(): void
    {
        $rate = $this->usdRate();

        DB::table('part_catalog_items')
            ->where(function ($query): void {
                $query
                    ->where('currency', 'UAH')
                    ->orWhere(function ($query): void {
                        $query
                            ->whereNull('currency')
                            ->whereIn('source', ['tcarservice', 'driveparts', 'dkparts']);
                    });
            })
            ->whereNotNull('price_amount')
            ->orderBy('id')
            ->chunkById(500, function ($items) use ($rate): void {
                foreach ($items as $item) {
                    DB::table('part_catalog_items')
                        ->where('id', $item->id)
                        ->update([
                            'price_amount' => round((float) $item->price_amount / $rate, 2),
                            'currency' => 'USD',
                        ]);
                }
            });
    }

    public function down(): void
    {
        // The original currency is not tracked per row, so this data migration is intentionally one-way.
    }

    private function usdRate(): float
    {
        $rate = DB::table('exchange_rates')
            ->where('currency', 'USD')
            ->orderByDesc('rate_date')
            ->value('rate');

        return is_numeric($rate) && (float) $rate > 0 ? (float) $rate : self::FALLBACK_USD_RATE;
    }
};
