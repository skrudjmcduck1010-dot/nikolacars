<?php

namespace App\Services;

use App\Models\DonorCar;
use App\Models\PartCatalogItem;
use App\Models\Product;
use App\Support\PartCatalogRawAttributes;
use Illuminate\Support\Str;

class NikolaCarsCatalogProductSyncService
{
    public function syncAll(): array
    {
        $stats = ['items_seen' => 0, 'products_saved' => 0, 'items_skipped' => 0];

        PartCatalogItem::query()
            ->where('source', 'nikolacars')
            ->orderBy('id')
            ->chunkById(300, function ($items) use (&$stats): void {
                foreach ($items as $item) {
                    $stats['items_seen']++;
                    $result = $this->syncItem($item);
                    $stats['products_saved'] += $result['saved'] ? 1 : 0;
                    $stats['items_skipped'] += $result['saved'] ? 0 : 1;
                }
            });

        return $stats;
    }

    public function syncDonor(DonorCar $donorCar): array
    {
        $vin = Str::upper(trim((string) $donorCar->vin));
        $stats = ['items_seen' => 0, 'products_saved' => 0, 'items_skipped' => 0];

        PartCatalogItem::query()
            ->where('source', 'nikolacars')
            ->where(function ($query) use ($vin): void {
                $query
                    ->where('compatibility_text', $vin)
                    ->orWhere('raw_attributes', 'like', '%'.$vin.'%');
            })
            ->orderBy('id')
            ->chunkById(300, function ($items) use (&$stats): void {
                foreach ($items as $item) {
                    $stats['items_seen']++;
                    $result = $this->syncItem($item);
                    $stats['products_saved'] += $result['saved'] ? 1 : 0;
                    $stats['items_skipped'] += $result['saved'] ? 0 : 1;
                }
            });

        return $stats;
    }

    public function syncItem(PartCatalogItem $item): array
    {
        if ($item->source !== 'nikolacars') {
            return ['saved' => false, 'product' => null];
        }

        if (app(NikolaCarsInventoryService::class)->isManuallySold($item)) {
            $this->markLinkedProductsSold($item);

            return ['saved' => false, 'product' => null];
        }

        $donorVin = $this->donorVin($item);
        $donorCar = null;
        $damageStatus = null;

        if ($donorVin !== '') {
            $donorCar = DonorCar::query()->where('vin', $donorVin)->first();
            if (! $donorCar) {
                return ['saved' => false, 'product' => null];
            }

            $damageStatus = $this->damageStatus($item);
            if (! in_array($damageStatus, NikolaCarsProductInventorySyncService::CHECKED_DAMAGE_STATUSES, true)) {
                return ['saved' => false, 'product' => null];
            }
        }

        $product = $this->sourceProduct($item, $donorCar);

        if ($product) {
            $product->forceFill($this->existingSourceProductPayload($item, $donorCar, $damageStatus))->save();
        } else {
            $product = Product::query()->updateOrCreate(
                [
                    'source_part_catalog_item_id' => $item->id,
                ],
                $this->payload($item, $donorCar, $damageStatus)
            );
        }

        $this->rememberLinkedProduct($item, $product);
        app(NikolaCarsProductInventorySyncService::class)->syncProduct($product->refresh());

        return ['saved' => true, 'product' => $product];
    }

    protected function payload(PartCatalogItem $item, ?DonorCar $donorCar, ?string $damageStatus): array
    {
        $code = $this->code($item);
        $sku = $this->uniqueValue('sku', $code !== '' ? 'NC-'.$code : 'NC-CATALOG-'.$item->id, $item);
        $slug = $this->uniqueValue('slug', Str::slug($sku) ?: 'nikolacars-'.$item->id, $item);
        $images = collect((array) data_get($item->raw_attributes, 'image_urls'))->filter()->values();
        $name = $this->productName($item, $sku);
        $categoryDisplay = trim((string) (data_get($item->raw_attributes, 'category_display') ?: data_get($item->raw_attributes, 'category_path') ?: $item->compatibility_text));

        return [
            'sku' => $sku,
            'external_sku' => trim((string) ($item->part_number ?: $code ?: $sku)) ?: null,
            'name' => $name,
            'slug' => $slug,
            'category_id' => null,
            'part_origin' => Product::PART_ORIGIN_ORIGINAL,
            'donor_car_id' => $donorCar?->id,
            'is_auto_generated' => false,
            'storage_status' => $donorCar ? Product::STORAGE_STATUS_ON_DONOR : Product::STORAGE_STATUS_IN_STOCK,
            'generated_at' => null,
            'description' => $item->notes_ru ?: $item->notes_ua,
            'compatibility' => $donorCar?->vin ?: ($categoryDisplay ?: null),
            'model' => $item->model_name ?: $donorCar?->model,
            'generation' => $item->model_label,
            'condition_type' => 'used',
            'testing_status' => $donorCar ? 'tested' : 'not_tested',
            'unit' => 'pcs',
            'purchase_price' => 0,
            'selling_price' => $item->price_amount ?: 0,
            'currency' => $item->currency ?: 'USD',
            'barcode' => $this->uniqueNullableValue('barcode', data_get($item->raw_attributes, 'barcode'), $item),
            'main_image' => $images->first(),
            'images_json' => $images->skip(1)->values()->all(),
            'notes' => $damageStatus ?: trim((string) $item->quality) ?: null,
            'is_active' => true,
        ];
    }

    protected function damageStatus(PartCatalogItem $item): string
    {
        $status = trim((string) ($item->quality ?: data_get($item->raw_attributes, 'donor_damage_status')));

        if ($status !== '') {
            $normalized = mb_strtolower($status);

            foreach (NikolaCarsProductInventorySyncService::CHECKED_DAMAGE_STATUSES as $checkedStatus) {
                if ($normalized === mb_strtolower($checkedStatus)) {
                    return $checkedStatus;
                }
            }

            return $status;
        }

        return "\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}";
    }

    protected function productName(PartCatalogItem $item, string $sku): string
    {
        return trim((string) (
            data_get($item->raw_attributes, 'source_row.name')
            ?: $item->name
            ?: $item->name_ru
            ?: $item->name_ua
            ?: $item->part_number
            ?: $sku
        ));
    }

    protected function code(PartCatalogItem $item): string
    {
        $sourceRowCode = trim((string) data_get($item->raw_attributes, 'source_row.code'));
        if ($sourceRowCode !== '') {
            return $sourceRowCode;
        }

        $code = trim((string) data_get($item->raw_attributes, 'code'));

        return preg_replace('/^NC-/i', '', $code) ?: $code;
    }

    protected function sourceProduct(PartCatalogItem $item, ?DonorCar $donorCar): ?Product
    {
        $sourceUrl = trim((string) $item->source_url);

        if (preg_match('#^nikolacars://(?:donor|inventory)-product/(\d+)$#', $sourceUrl, $matches) === 1) {
            return Product::query()->find((int) $matches[1]);
        }

        $partNumber = trim((string) $item->part_number);
        if ($donorCar && $partNumber !== '') {
            return Product::query()
                ->where('donor_car_id', $donorCar->id)
                ->where('external_sku', $partNumber)
                ->where(function ($query) use ($item): void {
                    $query
                        ->whereNull('source_part_catalog_item_id')
                        ->orWhere('source_part_catalog_item_id', '!=', $item->id);
                })
                ->where(function ($query): void {
                    $query
                        ->whereDoesntHave('sourcePartCatalogItem')
                        ->orWhereHas(
                            'sourcePartCatalogItem',
                            fn ($sourceQuery) => $sourceQuery->where('source', '!=', 'nikolacars')
                        );
                })
                ->orderByDesc('is_auto_generated')
                ->orderBy('id')
                ->first();
        }

        return null;
    }

    protected function existingSourceProductPayload(PartCatalogItem $item, ?DonorCar $donorCar, ?string $damageStatus): array
    {
        return collect($this->payload($item, $donorCar, $damageStatus))
            ->except([
                'sku',
                'slug',
                'category_id',
                'donor_car_id',
                'storage_status',
                'generated_at',
            ])
            ->all();
    }

    protected function rememberLinkedProduct(PartCatalogItem $item, Product $product): void
    {
        $sourceItem = $product->sourcePartCatalogItem;
        $rawAttributes = PartCatalogRawAttributes::from($item);

        $rawAttributes['product_id'] = $product->id;
        $rawAttributes['source_catalog_item_id'] = $sourceItem?->id;
        $rawAttributes['source_catalog_source'] = $sourceItem?->source;

        $item->forceFill(['raw_attributes' => $rawAttributes])->save();
    }

    protected function markLinkedProductsSold(PartCatalogItem $item): void
    {
        $rawAttributes = PartCatalogRawAttributes::from($item);
        $productId = (int) data_get($rawAttributes, 'product_id');

        Product::query()
            ->where(function ($query) use ($item, $productId): void {
                $query->where('source_part_catalog_item_id', $item->id);

                if ($productId > 0) {
                    $query->orWhere('id', $productId);
                }
            })
            ->update([
                'storage_status' => Product::STORAGE_STATUS_SOLD,
                'is_active' => false,
                'updated_at' => now(),
            ]);

        Product::query()
            ->where(function ($query) use ($item, $productId): void {
                $query->where('source_part_catalog_item_id', $item->id);

                if ($productId > 0) {
                    $query->orWhere('id', $productId);
                }
            })
            ->get()
            ->each(fn (Product $product) => app(SoldProductStockAdjustmentService::class)->zeroStock($product, [
                'document_number' => 'manual-sold-before-june-2026',
                'comment' => 'NikolaCars catalog sync marked product sold.',
            ]));
    }

    protected function donorVin(PartCatalogItem $item): string
    {
        $candidate = collect([
            data_get($item->raw_attributes, 'donor_vin'),
        ])->filter()->implode(' ');

        if (preg_match('/\b[A-Z0-9]{17}\b/i', $candidate, $matches) === 1) {
            return Str::upper(strtr($matches[0], ['O' => '0', 'I' => '1']));
        }

        $candidate = trim($candidate);
        if ($candidate === '') {
            return '';
        }

        return DonorCar::query()
            ->pluck('vin')
            ->first(fn (string $vin): bool => Str::lower(trim($vin)) === Str::lower($candidate)) ?: '';
    }

    protected function uniqueValue(string $column, string $base, PartCatalogItem $item): string
    {
        $value = $base;
        $suffix = 2;

        while (Product::query()
            ->where($column, $value)
            ->where(function ($query) use ($item): void {
                $query
                    ->whereNull('source_part_catalog_item_id')
                    ->orWhere('source_part_catalog_item_id', '!=', $item->id);
            })
            ->exists()) {
            $value = $base.'-'.$suffix;
            $suffix++;
        }

        return $value;
    }

    protected function uniqueNullableValue(string $column, mixed $value, PartCatalogItem $item): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return Product::query()
            ->where($column, $value)
            ->where(function ($query) use ($item): void {
                $query
                    ->whereNull('source_part_catalog_item_id')
                    ->orWhere('source_part_catalog_item_id', '!=', $item->id);
            })
            ->exists()
            ? null
            : $value;
    }
}
