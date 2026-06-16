<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNKNOWN_STATUS = "\u{041D}\u{0435}\u{0438}\u{0437}\u{0432}\u{0435}\u{0441}\u{0442}\u{043D}\u{043E}";

    private const NO_DAMAGE_STATUS = "\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}";

    public function up(): void
    {
        $now = now();
        $hasProductUpdatedAt = Schema::hasColumn('products', 'updated_at');
        $hasCatalogUpdatedAt = Schema::hasColumn('part_catalog_items', 'updated_at');

        $items = DB::table('part_catalog_items')
            ->where('source', 'nikolacars')
            ->where('source_url', 'like', 'nikolacars://donor-product/%')
            ->select(['id', 'source_url', 'raw_attributes'])
            ->orderBy('id')
            ->get();

        foreach ($items as $item) {
            if (preg_match('~^nikolacars://donor-product/(\d+)$~', (string) $item->source_url, $matches) !== 1) {
                continue;
            }

            $productId = (int) $matches[1];
            $product = DB::table('products')
                ->where('id', $productId)
                ->whereNotNull('donor_car_id')
                ->where('is_auto_generated', true)
                ->where('sku', 'like', 'DON%')
                ->where(function ($query): void {
                    $query
                        ->whereNull('notes')
                        ->orWhere('notes', '')
                        ->orWhere('notes', self::UNKNOWN_STATUS);
                })
                ->first(['id']);

            if (! $product) {
                continue;
            }

            $productPayload = ['notes' => self::NO_DAMAGE_STATUS];
            if ($hasProductUpdatedAt) {
                $productPayload['updated_at'] = $now;
            }

            DB::table('products')
                ->where('id', $productId)
                ->update($productPayload);

            $rawAttributes = json_decode((string) $item->raw_attributes, true);
            $rawAttributes = is_array($rawAttributes) ? $rawAttributes : [];
            $rawAttributes['donor_damage_status'] = self::NO_DAMAGE_STATUS;
            $rawAttributes['donor_damage_checked_at'] = $now->toIso8601String();

            $catalogPayload = [
                'quality' => self::NO_DAMAGE_STATUS,
                'raw_attributes' => json_encode($rawAttributes, JSON_UNESCAPED_UNICODE),
            ];
            if ($hasCatalogUpdatedAt) {
                $catalogPayload['updated_at'] = $now;
            }

            DB::table('part_catalog_items')
                ->where('id', $item->id)
                ->update($catalogPayload);
        }
    }

    public function down(): void
    {
        //
    }
};
