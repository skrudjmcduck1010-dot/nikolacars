<?php

use App\Support\PartNumberNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PART_NUMBERS = [
        '1095042-00-B',
        '1104656-99-C',
        '1515051-00-B',
    ];

    private const REASON = 'auto: Tesla VIN price check found bolt under 1 USD';

    public function up(): void
    {
        if (! Schema::hasTable('part_catalog_items')) {
            return;
        }

        $partNumbers = collect(self::PART_NUMBERS)
            ->map(fn (string $partNumber): ?string => PartNumberNormalizer::normalize($partNumber))
            ->filter()
            ->unique()
            ->values();

        if ($partNumbers->isEmpty()) {
            return;
        }

        DB::table('part_catalog_items')
            ->select(['id', 'part_number', 'raw_attributes'])
            ->whereIn('part_number', $partNumbers->all())
            ->orderBy('id')
            ->chunkById(500, function (Collection $items): void {
                foreach ($items as $item) {
                    $partNumber = PartNumberNormalizer::normalize((string) $item->part_number);

                    if ($partNumber === null) {
                        continue;
                    }

                    $rawAttributes = $this->rawAttributes($item->raw_attributes);

                    if ((bool) data_get($rawAttributes, 'donor_vin_small_part')) {
                        continue;
                    }

                    $rawAttributes['donor_vin_small_part'] = true;
                    $rawAttributes['donor_vin_small_part_part_number'] = $partNumber;
                    $rawAttributes['donor_vin_small_part_reason'] = self::REASON;
                    $rawAttributes['donor_vin_small_part_marked_at'] = now()->toIso8601String();

                    DB::table('part_catalog_items')
                        ->where('id', $item->id)
                        ->update([
                            'raw_attributes' => json_encode($rawAttributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // One-time data classification; intentionally not reversible.
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
