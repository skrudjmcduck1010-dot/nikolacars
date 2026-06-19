<?php

use App\Support\PartNumberNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const BANK_OF_CANADA_USD_CAD_RATE = 1.3996;

    private const CAD_TO_USD_RATE = 1 / self::BANK_OF_CANADA_USD_CAD_RATE;

    private const RATE_DATE = '2026-06-16';

    private const RATE_SOURCE = 'Bank of Canada FXUSDCAD daily average, 1 USD = 1.3996 CAD';

    public function up(): void
    {
        if (! Schema::hasTable('part_catalog_items')) {
            return;
        }

        $teslaVinUsdPrices = $this->teslaVinUsdPrices();

        DB::table('part_catalog_items')
            ->select(['id', 'part_number', 'price_amount', 'currency', 'raw_attributes'])
            ->where('currency', 'CAD')
            ->orderBy('id')
            ->chunkById(500, function (Collection $items) use ($teslaVinUsdPrices): void {
                foreach ($items as $item) {
                    if ($item->price_amount === null) {
                        continue;
                    }

                    $cadPrice = round((float) $item->price_amount, 2);
                    $partNumber = PartNumberNormalizer::normalize((string) $item->part_number);
                    $teslaVinPrice = $partNumber !== null ? ($teslaVinUsdPrices[$partNumber] ?? null) : null;
                    $newPrice = $teslaVinPrice !== null
                        ? round((float) $teslaVinPrice, 2)
                        : round($cadPrice * self::CAD_TO_USD_RATE, 2);

                    $rawAttributes = $this->rawAttributes($item->raw_attributes);

                    $rawAttributes['cad_price_amount_original'] ??= $cadPrice;
                    $rawAttributes['cad_currency_original'] ??= 'CAD';
                    $rawAttributes['cad_to_usd_price_amount'] = $newPrice;
                    $rawAttributes['cad_to_usd_rate'] = self::CAD_TO_USD_RATE;
                    $rawAttributes['cad_to_usd_rate_date'] = self::RATE_DATE;
                    $rawAttributes['cad_to_usd_rate_source'] = self::RATE_SOURCE;
                    $rawAttributes['cad_to_usd_conversion_method'] = $teslaVinPrice !== null
                        ? 'tesla_vin_catalog_usd_price'
                        : 'bank_of_canada_rate';
                    $rawAttributes['cad_to_usd_converted_at'] = now()->toIso8601String();

                    DB::table('part_catalog_items')
                        ->where('id', $item->id)
                        ->update([
                            'price_amount' => $newPrice,
                            'currency' => 'USD',
                            'raw_attributes' => json_encode($rawAttributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // One-time data repair; intentionally not reversible.
    }

    protected function teslaVinUsdPrices(): array
    {
        return [
            '1044271-00-A' => 25.00,
            '1044421-00-G' => 190.00,
            '1067348-00-A' => 50.00,
            '1068767-00-E' => 65.00,
            '1068789-00-E' => 70.00,
            '1073690-S0-B' => 404.25,
            '1077372-00-L' => 1600.00,
            '1077391-00-C' => 410.49,
            '1077392-00-C' => 410.49,
            '1077593-00-B' => 14.00,
            '1077596-00-B' => 83.00,
            '1080477-S0-G' => 165.00,
            '1080478-S0-G' => 165.00,
            '1081038-02-F' => 40.00,
            '1081674-88-F' => 270.00,
            '1081831-99-F' => 160.00,
            '1082281-S0-B' => 173.25,
            '1082282-S0-B' => 180.00,
            '1083989-00-J' => 25.00,
            '1087990-00-B' => 99.26,
            '1089092-01-E' => 175.00,
            '1089092-02-H' => 180.00,
            '1089334-00-J' => 70.00,
            '1089623-S0-D' => 60.00,
            '1089624-S0-D' => 18.90,
            '1090499-00-L' => 110.00,
            '1090500-00-L' => 105.00,
            '1090510-00-L' => 110.00,
            '1090511-00-L' => 105.00,
            '1091207-97-F' => 165.00,
            '1091232-S0-F' => 14.00,
            '1092146-00-C' => 40.00,
            '1092147-00-C' => 40.00,
            '1092173-S0-B' => 65.00,
            '1094694-S0-A' => 160.00,
            '1099926-99-A' => 6.60,
            '1100170-00-C' => 17.00,
            '1100241-00-C' => 165.00,
            '1102179-00-C' => 2.56,
            '1104656-99-C' => 0.55,
            '1104972-00-A' => 0.54,
            '1109722-S0-B' => 280.00,
            '1111750-00-C' => 2.20,
            '1112060-00-C' => 30.00,
            '1450866-00-C' => 215.00,
            '1452690-00-B' => 140.00,
            '1452693-00-B' => 165.00,
            '1478576-00-B' => 49.00,
            '1489060-02-C' => 50.00,
            '1495935-S0-A' => 73.50,
            '1500237-00-H' => 165.00,
            '1505618-00-A' => 2.00,
            '1506045-S0-C' => 45.00,
            '1515051-00-B' => 0.54,
            '1526009-00-A' => 0.54,
            '1527924-00-A' => 265.00,
            '1561493-00-A' => 36.00,
            '1566302-00-A' => 160.00,
            '1625910-00-E' => 195.00,
            '1631597-00-C' => 12.00,
            '1671113-00-B' => 62.00,
            '1771470-00-B' => 580.00,
            '2067968-01-B' => 105.00,
            '8008206-00-A' => 250.00,
            '8008214-00-A' => 250.00,
            '8008284-00-B' => 340.00,
        ];
    }

    protected function rawAttributes(mixed $rawAttributes): array
    {
        if (is_array($rawAttributes)) {
            return $rawAttributes;
        }

        if (is_object($rawAttributes) && method_exists($rawAttributes, 'getArrayCopy')) {
            return $rawAttributes->getArrayCopy();
        }

        if (! is_string($rawAttributes) || trim($rawAttributes) === '') {
            return [];
        }

        $decoded = json_decode($rawAttributes, true);

        return is_array($decoded) ? $decoded : [];
    }
};
