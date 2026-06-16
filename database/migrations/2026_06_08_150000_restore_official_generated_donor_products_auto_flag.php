<?php

use App\Models\PartCatalogItem;
use App\Models\Product;
use App\Support\CatalogTextEncoding;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Product::query()
            ->whereNotNull('generated_at')
            ->where('description', 'like', '%tesla_official%')
            ->orderBy('id')
            ->chunkById(200, function ($products): void {
                foreach ($products as $product) {
                    $description = CatalogTextEncoding::repair((string) $product->description);

                    if (! Str::contains($description, "\u{0410}\u{0432}\u{0442}\u{043E}\u{043C}\u{0430}\u{0442}\u{0438}\u{0447}\u{0435}\u{0441}\u{043A}\u{0438} \u{0441}\u{0433}\u{0435}\u{043D}\u{0435}\u{0440}\u{0438}\u{0440}\u{043E}\u{0432}\u{0430}\u{043D}\u{043E} \u{0438}\u{0437} \u{043A}\u{0430}\u{0442}\u{0430}\u{043B}\u{043E}\u{0433}\u{0430} \u{0437}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{0435}\u{0439}")) {
                        continue;
                    }

                    $officialItemId = $this->officialItemId($product, $description);
                    $payload = ['is_auto_generated' => true];

                    if ($officialItemId !== null) {
                        $payload['source_part_catalog_item_id'] = $officialItemId;
                    }

                    $product->timestamps = false;
                    $product->forceFill($payload)->saveQuietly();
                }
            });
    }

    public function down(): void
    {
        //
    }

    protected function officialItemId(Product $product, string $description): ?int
    {
        $currentItem = $product->source_part_catalog_item_id
            ? PartCatalogItem::query()->find($product->source_part_catalog_item_id)
            : null;

        if ($currentItem?->source === 'tesla_official') {
            return (int) $currentItem->id;
        }

        $rawAttributes = $currentItem?->raw_attributes ?? [];
        $sourceCatalogItemId = (int) data_get($rawAttributes, 'source_catalog_item_id');

        if ($sourceCatalogItemId > 0) {
            $officialItem = PartCatalogItem::query()
                ->where('source', 'tesla_official')
                ->whereKey($sourceCatalogItemId)
                ->first(['id']);

            if ($officialItem) {
                return (int) $officialItem->id;
            }
        }

        if (preg_match('/^Ссылка:\s*(https?:\/\/\S+)/mu', $description, $matches) === 1) {
            $officialItem = PartCatalogItem::query()
                ->where('source', 'tesla_official')
                ->where('source_url', $matches[1])
                ->first(['id']);

            if ($officialItem) {
                return (int) $officialItem->id;
            }
        }

        $partNumber = trim((string) $product->external_sku);

        if ($partNumber === '') {
            return null;
        }

        return PartCatalogItem::query()
            ->where('source', 'tesla_official')
            ->where('part_number', $partNumber)
            ->value('id');
    }
};
