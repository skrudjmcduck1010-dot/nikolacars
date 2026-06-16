<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Category;
use App\Models\DonorCar;
use App\Models\Location;
use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Models\Product;
use App\Models\Warehouse;
use App\Support\PartCatalogRawAttributes;
use App\Support\PartNumberNormalizer;
use App\Support\ProductPhotoNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DonorProductGenerationService
{
    public const DAMAGE_ZONES = [
        'front' => 'Перед',
        'rear' => 'Зад',
        'left' => 'Левая сторона',
        'right' => 'Правая сторона',
        'left_front' => 'Левая передняя часть',
        'right_front' => 'Правая передняя часть',
        'left_rear' => 'Левая задняя часть',
        'right_rear' => 'Правая задняя часть',
        'roof' => 'Крыша',
        'interior' => 'Салон',
        'battery' => 'Батарея',
        'suspension' => 'Ходовая',
        'glass' => 'Стекла',
        'wheels' => 'Колеса',
        'airbags' => 'Airbag / безопасность',
    ];

    protected const DAMAGE_KEYWORDS = [
        'front' => ['front bumper', 'bumper front', 'front fascia', 'front panel', 'front reinforcement', 'front rail', 'front impact', 'front absorber', 'bumper absorber front', 'frunk', 'hood', 'bonnet', 'headlight', 'radiator', 'condenser', 'передний бампер', 'бампер перед', 'переднего бампера', 'бампера переднего', 'переднього бампера', 'бампера переднього', 'передняя панель', 'передня панель', 'передняя балка', 'передня балка', 'усилитель переднего бампера', 'підсилювач переднього бампера', 'абсорбер переднего бампера', 'абсорбер переднього бампера', 'абсорбер бампера переднего', 'абсорбер бампера переднього', 'пінопласт підсилювача переднього бампера', 'капот', 'фара', 'радиатор', 'радіатор'],
        'rear' => ['rear bumper', 'bumper rear', 'rear fascia', 'rear panel', 'rear reinforcement', 'rear rail', 'rear impact', 'rear absorber', 'bumper absorber rear', 'tailgate', 'liftgate', 'trunk lid', 'trunk floor', 'quarter panel', 'taillight', 'tail light', 'rear lamp', 'задний бампер', 'бампер зад', 'заднего бампера', 'бампера заднего', 'заднього бампера', 'бампера заднього', 'задняя панель', 'задня панель', 'задняя балка', 'задня балка', 'усилитель заднего бампера', 'підсилювач заднього бампера', 'абсорбер заднего бампера', 'абсорбер заднього бампера', 'крышка багажника', 'кришка багажника', 'дверь багажника', 'двері багажника', 'пол багажника', 'підлога багажника', 'четверть', 'заднее крыло', 'заднє крило', 'фонарь зад', 'задний фонарь', 'задній ліхтар'],
        'left' => ['left door', 'left fender', 'left quarter', 'left mirror', 'left rocker', 'лев дверь', 'левая дверь', 'левое крыло', 'левое зеркало', 'левая четверть', 'левый порог', 'driver door', 'driver mirror'],
        'right' => ['right door', 'right fender', 'right quarter', 'right mirror', 'right rocker', 'прав дверь', 'правая дверь', 'правое крыло', 'правое зеркало', 'правая четверть', 'правый порог', 'passenger door', 'passenger mirror'],
        'left_front' => ['left front', 'front left', 'лев перед', 'перед лев', 'driver front'],
        'right_front' => ['right front', 'front right', 'прав перед', 'перед прав', 'passenger front'],
        'left_rear' => ['left rear', 'rear left', 'лев зад', 'зад лев', 'driver rear'],
        'right_rear' => ['right rear', 'rear right', 'прав зад', 'зад прав', 'passenger rear'],
        'roof' => ['roof', 'крыша', 'потолок', 'panoramic', 'панорам'],
        'interior' => ['interior', 'салон', 'seat', 'сиден', 'dashboard', 'торпед', 'console', 'консоль'],
        'battery' => ['battery', 'батар', 'аккумулятор', 'hv', 'high voltage', 'высоковольт'],
        'suspension' => ['suspension', 'подвес', 'control arm', 'рычаг', 'shock', 'амортиз', 'knuckle', 'ступиц'],
        'glass' => ['glass', 'стекл', 'windshield', 'лобов', 'quarter window'],
        'wheels' => ['wheel', 'колес', 'rim', 'диск', 'tire', 'шина'],
        'airbags' => ['airbag', 'srs', 'подуш', 'seat belt', 'ремень безопасности'],
    ];

    public function preview(DonorCar $donorCar, array $damageZones): array
    {
        $damageZones = collect($damageZones)
            ->filter(fn (string $zone): bool => array_key_exists($zone, self::DAMAGE_ZONES))
            ->values()
            ->all();

        $items = $this->candidateCatalogItems($donorCar)
            ->map(function (PartCatalogItem $item) use ($donorCar, $damageZones): array {
                $isDamaged = $this->isProbablyDamaged($item, $damageZones);
                $alreadyGenerated = $this->alreadyGenerated($donorCar, $item);
                $status = $isDamaged ? 'damaged' : ($alreadyGenerated ? 'existing' : 'creatable');

                return [
                    'id' => $item->id,
                    'status' => $status,
                    'already_generated' => $alreadyGenerated,
                    'condition_label' => $isDamaged ? '' : '',
                    'name' => $item->name,
                    'part_number' => $item->part_number,
                    'category' => $this->catalogCategoryName($item),
                    'source' => $item->source,
                    'source_url' => $item->source_url,
                    'price_amount' => null,
                    'currency' => null,
                    'model' => $item->model_label ?: $item->model_name,
                    'zones' => $this->itemZones($item),
                    'reason' => match ($status) {
                        'damaged' => '    ',
                        'existing' => 'Уже создано для этого донора',
                        default => 'Можно создать',
                    },
                ];
            })
            ->values();

        return [
            'damage_zones' => $damageZones,
            'summary' => [
                'creatable' => $items->where('status', 'creatable')->count(),
                'existing' => $items->where('status', 'existing')->count(),
                'damaged' => $items->where('status', 'damaged')->count(),
                'selectable' => $items
                    ->whereIn('status', ['creatable', 'damaged'])
                    ->where('already_generated', false)
                    ->count(),
                'updatable' => $items->where('already_generated', true)->count(),
                'total' => $items->count(),
            ],
            'items' => $items->all(),
        ];
    }

    public function generate(DonorCar $donorCar, array $damageZones, array $catalogItemIds = []): array
    {
        $preview = $this->preview($donorCar, $damageZones);
        $selectedIds = collect($catalogItemIds)
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        $previewItems = collect($preview['items']);

        $creatableIds = $previewItems
            ->whereIn('status', ['creatable', 'damaged'])
            ->where('already_generated', false)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id);

        $creatableIds = $selectedIds->isNotEmpty()
            ? $creatableIds->intersect($selectedIds)->values()
            : collect();

        $damageZones = $preview['damage_zones'];
        $damagedByCatalogItemId = $previewItems
            ->mapWithKeys(fn (array $item): array => [(int) $item['id'] => $item['status'] === 'damaged'])
            ->all();
        $existingConditionsByCatalogItemId = $previewItems
            ->where('already_generated', true)
            ->mapWithKeys(fn (array $item): array => [
                (int) $item['id'] => [
                    'is_damaged' => $item['status'] === 'damaged',
                ],
            ])
            ->all();
        $catalogItems = PartCatalogItem::query()
            ->with(['category.parent.parent.parent', 'zones'])
            ->whereIn('id', $creatableIds)
            ->orderBy('name')
            ->get();
        $this->refreshSmallVinCatalogFlags($catalogItems);
        $created = 0;
        $createdDamaged = 0;
        $skippedExisting = 0;
        $updatedExisting = 0;

        $updatedExisting = $this->updateExistingGeneratedProducts($donorCar, $existingConditionsByCatalogItemId, $damageZones);

        foreach ($catalogItems as $catalogItem) {
            if ($this->alreadyGenerated($donorCar, $catalogItem)) {
                $skippedExisting++;

                continue;
            }

            $isDamaged = (bool) ($damagedByCatalogItemId[(int) $catalogItem->id] ?? false);
            $imageUrls = $this->catalogItemImageUrls($catalogItem);

            $createdProduct = DB::transaction(function () use ($donorCar, $catalogItem, &$created, &$createdDamaged, &$skippedExisting, $damageZones, $isDamaged, $imageUrls): ?Product {
                if ($this->alreadyGenerated($donorCar, $catalogItem)) {
                    $skippedExisting++;

                    return null;
                }

                $sku = app(DonorProductSkuService::class)->uniqueAutoCode($donorCar);
                $category = $this->categoryFromCatalogItem($catalogItem);

                $product = Product::query()->create([
                    'sku' => $sku,
                    'external_sku' => $catalogItem->part_number,
                    'name' => $catalogItem->name,
                    'slug' => $this->uniqueProductSlug($catalogItem->name, $sku),
                    'category_id' => $category?->id,
                    'brand_id' => $this->teslaBrand()?->id,
                    'donor_car_id' => $donorCar->id,
                    'part_origin' => Product::PART_ORIGIN_ORIGINAL,
                    'source_part_catalog_item_id' => $catalogItem->id,
                    'is_auto_generated' => true,
                    'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
                    'generated_at' => now(),
                    'description' => $this->description($catalogItem, $damageZones, $isDamaged),
                    'compatibility' => $catalogItem->compatibility_text,
                    'model' => $donorCar->model,
                    'color' => $this->productColorFromDonor($donorCar, $catalogItem),
                    'generation' => $catalogItem->model_label ?: $catalogItem->model_name,
                    'condition_type' => 'used',
                    'testing_status' => 'not_tested',
                    'unit' => 'pcs',
                    'purchase_price' => 0,
                    'selling_price' => 0,
                    'currency' => 'USD',
                    'barcode' => $sku,
                    'qr_code' => $sku,
                    'main_image' => $imageUrls[0] ?? null,
                    'images_json' => $imageUrls !== [] ? $imageUrls : null,
                    'is_active' => true,
                ]);

                app(TeslaCatalogDonorProductSync::class)->syncProduct($product);

                $location = $this->donorStockLocation($donorCar);

                app(StockService::class)->intake([
                    'product_id' => $product->id,
                    'warehouse_id' => $location->warehouse_id,
                    'location_id' => $location->id,
                    'quantity' => 1,
                    'comment' => 'Auto-generated donor part. Still installed on the donor car.',
                ]);

                $created++;
                $createdDamaged += $isDamaged ? 1 : 0;

                return $product;
            }, 3);

            if ($createdProduct instanceof Product && $this->canAutofillLocalizedCatalogNames()) {
                app(DonorProductLocalizedNameAutofillService::class)->fillMissingNames($createdProduct);
            }
        }

        $vinSpecificCleanup = app(TeslaOfficialVinSpecificCatalogCleanupService::class)->cleanupItems($catalogItems);

        return [
            'created' => $created,
            'created_damaged' => $createdDamaged,
            'created_whole' => $created - $createdDamaged,
            'skipped_existing' => $skippedExisting,
            'updated_existing' => $updatedExisting,
            'vin_specific_items_deleted' => $vinSpecificCleanup['items_deleted'],
            'vin_specific_products_relinked' => $vinSpecificCleanup['products_relinked'],
            'skipped_damaged' => 0,
            'selected' => $selectedIds->count(),
            'damage_zones' => $damageZones,
        ];
    }

    protected function canAutofillLocalizedCatalogNames(): bool
    {
        return Schema::hasColumn('part_catalog_items', 'name_ru_manually_locked_at')
            && Schema::hasColumn('part_catalog_items', 'name_ua_manually_locked_at');
    }

    protected function updateExistingGeneratedProducts(DonorCar $donorCar, array $conditionsByCatalogItemId, array $damageZones): int
    {
        if ($conditionsByCatalogItemId === []) {
            return 0;
        }

        $updated = 0;

        Product::query()
            ->with('sourcePartCatalogItem')
            ->where('donor_car_id', $donorCar->id)
            ->where('is_auto_generated', true)
            ->whereIn('source_part_catalog_item_id', array_keys($conditionsByCatalogItemId))
            ->get()
            ->each(function (Product $product) use ($conditionsByCatalogItemId, $damageZones, &$updated): void {
                $condition = $conditionsByCatalogItemId[(int) $product->source_part_catalog_item_id] ?? null;

                if ($condition === null) {
                    return;
                }

                $changes = [];

                if ($product->sourcePartCatalogItem) {
                    $changes['description'] = $this->description($product->sourcePartCatalogItem, $damageZones, (bool) $condition['is_damaged']);
                }

                if (! array_key_exists('description', $changes) || $product->description === $changes['description']) {
                    return;
                }

                $product->forceFill($changes)->save();
                $updated++;
            });

        return $updated;
    }

    protected function catalogItemImageUrls(PartCatalogItem $catalogItem): array
    {
        $rawAttributes = PartCatalogRawAttributes::from($catalogItem);

        return collect((array) data_get($rawAttributes, 'part_image_urls', []))
            ->filter()
            ->reject(fn (string $url): bool => ProductPhotoNormalizer::isCatalogSchemeImage($url))
            ->sortBy(fn (string $url): int => str_contains($url, 'tesla-official/part-images/') ? 0 : 1)
            ->unique(fn (string $url): string => ProductPhotoNormalizer::imageKey($url))
            ->values()
            ->all();
    }

    public function refreshSmallVinCatalogFlags(Collection $items): int
    {
        $updated = 0;
        $smallPartNumbers = $this->smallVinCatalogPartNumbers();

        foreach ($items as $item) {
            if (! $item instanceof PartCatalogItem || ! $this->isVinCatalogItem($item)) {
                continue;
            }

            $rawAttributes = PartCatalogRawAttributes::from($item);
            [$isSmall, $reason] = $this->smallVinCatalogPartStatus($item, $smallPartNumbers);

            if ((bool) data_get($rawAttributes, 'donor_vin_small_part') === $isSmall
                && (string) data_get($rawAttributes, 'donor_vin_small_part_reason', '') === (string) $reason) {
                continue;
            }

            $rawAttributes['donor_vin_small_part'] = $isSmall;
            if ($isSmall) {
                $rawAttributes['donor_vin_small_part_part_number'] = PartNumberNormalizer::normalize((string) $item->part_number);
                $rawAttributes['donor_vin_small_part_reason'] = $reason;
            } else {
                unset(
                    $rawAttributes['donor_vin_small_part_part_number'],
                    $rawAttributes['donor_vin_small_part_reason']
                );
            }

            $item->forceFill(['raw_attributes' => $rawAttributes])->save();
            $updated++;
        }

        return $updated;
    }

    protected function smallVinCatalogPartNumbers(): Collection
    {
        return PartCatalogItem::query()
            ->where('raw_attributes->donor_vin_small_part', true)
            ->get(['part_number', 'raw_attributes'])
            ->map(fn (PartCatalogItem $item): ?string => PartNumberNormalizer::normalize(
                (string) ($item->part_number ?: data_get($item->raw_attributes, 'donor_vin_small_part_part_number'))
            ))
            ->filter()
            ->unique()
            ->values();
    }

    protected function smallVinCatalogPartStatus(PartCatalogItem $item, Collection $smallPartNumbers): array
    {
        $partNumber = PartNumberNormalizer::normalize((string) $item->part_number);

        return $partNumber !== null && $smallPartNumbers->contains($partNumber)
            ? [true, 'part_number: '.$partNumber]
            : [false, null];
    }

    protected function compactPartNumber(string $value): string
    {
        return Str::upper(preg_replace('/[^A-Z0-9]/i', '', $value) ?: '');
    }

    protected function candidateCatalogItems(DonorCar $donorCar): Collection
    {
        $model = Str::lower(trim($donorCar->display_model ?: $donorCar->model));
        $year = $donorCar->year ? (int) $donorCar->year : null;

        $catalogItemsQuery = fn (): Builder => PartCatalogItem::query()
            ->with(['category.parent.parent.parent', 'zones'])
            ->whereRaw('trim(coalesce(name, ?)) <> ?', ['', ''])
            ->when($model !== '', function (Builder $query) use ($model): void {
                $query->where(function (Builder $builder) use ($model): void {
                    $builder
                        ->whereRaw('LOWER(COALESCE(model_label, ?)) = ?', ['', $model])
                        ->orWhereRaw('LOWER(COALESCE(model_name, ?)) = ?', ['', $model])
                        ->orWhereRaw('LOWER(COALESCE(model_label, ?)) like ?', ['', '%'.$model.'%'])
                        ->orWhereRaw('LOWER(COALESCE(model_name, ?)) like ?', ['', '%'.$model.'%']);
                });
            })
            ->when($year, function (Builder $query) use ($year): void {
                $query->where(function (Builder $builder) use ($year): void {
                    $builder
                        ->whereNull('year_from')
                        ->orWhere('year_from', '<=', $year);
                })->where(function (Builder $builder) use ($year): void {
                    $builder
                        ->whereNull('year_to')
                        ->orWhere('year_to', '>=', $year);
                });
            });

        $officialItems = $catalogItemsQuery()
            ->where('source', 'tesla_official')
            ->where('source_url', 'like', 'https://parts.tesla.com/%')
            ->orderBy('name')
            ->get();
        $vin = Str::upper(trim((string) $donorCar->vin));
        $vinItems = $vin !== ''
            ? $officialItems
                ->filter(fn (PartCatalogItem $item): bool => $this->catalogItemDonorVin($item) === $vin)
                ->values()
            : collect();

        $items = $vinItems->isNotEmpty()
            ? $vinItems
            : ($officialItems->isNotEmpty()
            ? $officialItems
            : $catalogItemsQuery()
                ->orderBy('name')
                ->get());

        return $this->filterByBatteryAndDrive($items, $donorCar)
            ->reject(fn (PartCatalogItem $item): bool => $this->isSupersededVinCatalogItem($item))
            ->pipe(fn (Collection $items): Collection => $this->filterNonRecommendedVinCatalogItems($items))
            ->pipe(fn (Collection $items): Collection => $this->filterByPerformance($items, $donorCar))
            ->pipe(fn (Collection $items): Collection => app(PartCatalogDeduplicator::class)->deduplicate($items))
            ->values();
    }

    protected function filterNonRecommendedVinCatalogItems(Collection $items): Collection
    {
        $vinItems = $items->filter(fn (PartCatalogItem $item): bool => $this->isVinCatalogItem($item));

        if ($vinItems->isNotEmpty()) {
            return $items
                ->reject(fn (PartCatalogItem $item): bool => $this->isVinCatalogItem($item)
                    && ! $this->isRecommendedVinCatalogItem($item))
                ->values();
        }

        $recommendedKeys = $items
            ->filter(fn (PartCatalogItem $item): bool => $this->isRecommendedVinCatalogItem($item))
            ->mapWithKeys(fn (PartCatalogItem $item): array => [$this->vinCatalogRecommendationKey($item) => true]);

        if ($recommendedKeys->isEmpty()) {
            return $items;
        }

        return $items
            ->reject(fn (PartCatalogItem $item): bool => $this->isVinCatalogItem($item)
                && ! $this->isRecommendedVinCatalogItem($item)
                && $recommendedKeys->has($this->vinCatalogRecommendationKey($item)))
            ->values();
    }

    protected function catalogItemDonorVin(PartCatalogItem $item): string
    {
        $rawAttributes = PartCatalogRawAttributes::from($item);

        return Str::upper(trim((string) data_get($rawAttributes, 'donor_vin', '')));
    }

    protected function isSupersededVinCatalogItem(PartCatalogItem $item): bool
    {
        $rawAttributes = PartCatalogRawAttributes::from($item);

        $recommendedPartNumber = $this->compactPartNumber((string) data_get($rawAttributes, 'recommended_part_number', ''));
        $currentPartNumber = $this->compactPartNumber((string) $item->part_number);

        return data_get($rawAttributes, 'donor_vin')
            && $recommendedPartNumber !== ''
            && $currentPartNumber !== ''
            && $recommendedPartNumber !== $currentPartNumber;
    }

    protected function isVinCatalogItem(PartCatalogItem $item): bool
    {
        $rawAttributes = PartCatalogRawAttributes::from($item);

        return (bool) data_get($rawAttributes, 'donor_vin');
    }

    protected function isRecommendedVinCatalogItem(PartCatalogItem $item): bool
    {
        $rawAttributes = PartCatalogRawAttributes::from($item);

        if (! data_get($rawAttributes, 'donor_vin')) {
            return false;
        }

        $recommendedPartNumber = $this->compactPartNumber((string) data_get($rawAttributes, 'recommended_part_number', ''));

        return strtoupper((string) data_get($rawAttributes, 'recommendation_type', '')) === 'RECOMMENDED'
            || ($recommendedPartNumber !== '' && $recommendedPartNumber === $this->compactPartNumber((string) $item->part_number));
    }

    protected function vinCatalogRecommendationKey(PartCatalogItem $item): string
    {
        $rawAttributes = PartCatalogRawAttributes::from($item);

        return implode('|', [
            data_get($rawAttributes, 'donor_vin'),
            data_get($rawAttributes, 'system_group_external_reference'),
            data_get($rawAttributes, 'annotation'),
        ]);
    }

    protected function filterByBatteryAndDrive(Collection $items, DonorCar $donorCar): Collection
    {
        if (! $donorCar->battery_type && ! $donorCar->drive_type) {
            return $items;
        }

        return $items->reject(fn (PartCatalogItem $item): bool => $this->isExplicitlyIncompatibleBatteryOrDriveItem($item, $donorCar));
    }

    protected function isExplicitlyIncompatibleBatteryOrDriveItem(PartCatalogItem $item, DonorCar $donorCar): bool
    {
        $haystack = $this->trimCompatibilityHaystack($item);
        $isStandardRange = $this->matchesAny($haystack, [
            '/\bstandard[-\s]?range\b/i',
            '/\bstandard\s+battery\b/i',
            '/\bcoil\s+sr\b/i',
            '/\bsr\b/i',
        ]);
        $isLongRange = $this->matchesAny($haystack, [
            '/\blong[-\s]?range\b/i',
            '/\blr\b/i',
            '/\bbt37\b/i',
        ]);
        $isRearWheel = $this->matchesAny($haystack, [
            '/\brear[-\s]?wheel[-\s]?drive\b/i',
            '/\brwd\b/i',
        ]);
        $isAllWheel = $this->matchesAny($haystack, [
            '/\ball[-\s]?wheel[-\s]?drive\b/i',
            '/\bawd\b/i',
            '/\bdual\s+motor\b/i',
        ]);

        if ($donorCar->drive_type === DonorCar::DRIVE_TYPE_REAR && $isAllWheel && ! $isRearWheel) {
            return true;
        }

        if ($donorCar->drive_type === DonorCar::DRIVE_TYPE_ALL && $isRearWheel && ! $isAllWheel) {
            return true;
        }

        return match ($donorCar->battery_type) {
            DonorCar::BATTERY_TYPE_STANDARD_RANGE => $isLongRange || $this->isExplicitPerformanceItem($item),
            DonorCar::BATTERY_TYPE_LONG_RANGE => $isStandardRange,
            default => false,
        };
    }

    protected function filterByPerformance(Collection $items, DonorCar $donorCar): Collection
    {
        if ($donorCar->is_performance === null) {
            return $items;
        }

        if ($donorCar->is_performance) {
            return $items->reject(fn (PartCatalogItem $item): bool => $this->isExplicitNonPerformanceItem($item));
        }

        return $items->reject(fn (PartCatalogItem $item): bool => $this->isExplicitPerformanceItem($item));
    }

    protected function isExplicitPerformanceItem(PartCatalogItem $item): bool
    {
        $haystack = $this->performanceHaystack($item);

        if (preg_match('/\bnon[-\s]?performance\b/i', $haystack)) {
            return false;
        }

        return preg_match('/\bperformance\b|\bperf\b/i', $haystack) === 1;
    }

    protected function isExplicitNonPerformanceItem(PartCatalogItem $item): bool
    {
        return preg_match('/\bnon[-\s]?performance\b/i', $this->performanceHaystack($item)) === 1;
    }

    protected function performanceHaystack(PartCatalogItem $item): string
    {
        return $this->trimCompatibilityHaystack($item);
    }

    protected function trimCompatibilityHaystack(PartCatalogItem $item): string
    {
        $rawAttributes = PartCatalogRawAttributes::from($item);
        $rawAttributesText = $rawAttributes === [] ? null : json_encode($rawAttributes, JSON_UNESCAPED_UNICODE);

        return collect([
            $item->name,
            $item->compatibility_text,
            $item->notes_en,
            $item->notes_ru,
            $item->notes_ua,
            $rawAttributesText,
        ])->filter()->implode(' ');
    }

    protected function matchesAny(string $haystack, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $haystack) === 1) {
                return true;
            }
        }

        return false;
    }

    protected function isProbablyDamaged(PartCatalogItem $item, array $damageZones): bool
    {
        if ($damageZones === []) {
            return false;
        }

        $itemZones = $this->itemZones($item);
        $affectedZones = collect($damageZones)
            ->flatMap(fn (string $zone): array => $this->affectedZones($zone))
            ->unique()
            ->values();

        if ($itemZones !== []) {
            return $affectedZones->intersect($itemZones)->isNotEmpty();
        }

        $haystack = Str::lower(collect([
            $item->name,
            $item->part_number,
            $item->main_category_code,
            $item->main_category_name,
            $item->subcategory_code,
            $item->subcategory_name,
            $item->node_name,
            $item->compatibility_text,
            $item->category?->code,
            $item->category?->name,
            $item->category?->parent?->code,
            $item->category?->parent?->name,
            $item->category?->parent?->parent?->code,
            $item->category?->parent?->parent?->name,
        ])->filter()->implode(' '));

        foreach ($damageZones as $zone) {
            foreach (self::DAMAGE_KEYWORDS[$zone] ?? [] as $keyword) {
                if (Str::contains($haystack, Str::lower($keyword))) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function itemZones(PartCatalogItem $item): array
    {
        $zones = $item->relationLoaded('zones')
            ? $item->zones->pluck('zone')->all()
            : $item->zones()->pluck('zone')->all();

        if ($zones !== []) {
            return array_values(array_unique($zones));
        }

        return collect(app(PartCatalogZoneClassifier::class)->classify($item))
            ->pluck('zone')
            ->unique()
            ->values()
            ->all();
    }

    protected function affectedZones(string $damageZone): array
    {
        return match ($damageZone) {
            'left_front' => ['left_front', 'front', 'left'],
            'right_front' => ['right_front', 'front', 'right'],
            'left_rear' => ['left_rear', 'rear', 'left'],
            'right_rear' => ['right_rear', 'rear', 'right'],
            default => [$damageZone],
        };
    }

    protected function alreadyGenerated(DonorCar $donorCar, PartCatalogItem $catalogItem): bool
    {
        return Product::query()
            ->where('donor_car_id', $donorCar->id)
            ->where('source_part_catalog_item_id', $catalogItem->id)
            ->exists()
            || app(PartCatalogDeduplicator::class)->hasEquivalentGeneratedProduct($catalogItem, (int) $donorCar->id);
    }

    protected function donorStockLocation(DonorCar $donorCar): Location
    {
        $warehouse = Warehouse::query()
            ->where('type', Warehouse::TYPE_DONOR)
            ->orWhere('name', Warehouse::DONOR_WAREHOUSE_NAME)
            ->first();

        if (! $warehouse) {
            $warehouse = Warehouse::query()->create([
                'name' => Warehouse::DONOR_WAREHOUSE_NAME,
                'type' => Warehouse::TYPE_DONOR,
                'floor_count' => 1,
                'is_active' => true,
            ]);
        }

        if ($warehouse->name !== Warehouse::DONOR_WAREHOUSE_NAME || $warehouse->type !== Warehouse::TYPE_DONOR || ! $warehouse->is_active) {
            $warehouse->forceFill([
                'name' => Warehouse::DONOR_WAREHOUSE_NAME,
                'type' => Warehouse::TYPE_DONOR,
                'floor_count' => max(1, (int) ($warehouse->floor_count ?: 1)),
                'is_active' => true,
            ])->save();
        }

        $cell = $donorCar->vin ?: 'DONOR-'.$donorCar->id;

        return Location::query()->firstOrCreate(
            [
                'warehouse_id' => $warehouse->id,
                'full_code' => 'ON-DONOR-'.$donorCar->id,
            ],
            [
                'floor' => 'floor_1',
                'cell' => Str::limit($cell, 50, ''),
                'is_active' => true,
            ],
        );
    }

    protected function categoryFromCatalogItem(PartCatalogItem $catalogItem): ?Category
    {
        $categoryName = $this->catalogCategoryName($catalogItem);

        if ($categoryName === null) {
            return null;
        }

        $slug = Str::limit(($catalogItem->source ?: 'catalog').'-'.(Str::slug($categoryName) ?: 'category'), 255, '');

        return Category::query()->firstOrCreate(
            ['slug' => $slug],
            [
                'name' => Str::limit($categoryName, 255, ''),
                'description' => null,
                'is_active' => true,
                'sort_order' => (int) (Category::query()->max('sort_order') ?? 0) + 1,
            ],
        );
    }

    protected function productColorFromDonor(DonorCar $donorCar, PartCatalogItem $catalogItem): ?string
    {
        return $this->isBodyCatalogItem($catalogItem) ? $donorCar->color : null;
    }

    protected function isBodyCatalogItem(PartCatalogItem $catalogItem): bool
    {
        $mainCategoryName = Str::lower(trim((string) $catalogItem->main_category_name));
        $mainCategoryCode = Str::lower(trim((string) $catalogItem->main_category_code));

        if ($mainCategoryName === 'body' || $mainCategoryCode === 'body') {
            return true;
        }

        $category = $catalogItem->category;

        while ($category instanceof PartCatalogCategory) {
            $name = Str::lower(trim((string) $category->name));
            $code = Str::lower(trim((string) $category->code));

            if ($name === 'body' || $code === 'body') {
                return true;
            }

            $category = $category->parent;
        }

        return false;
    }

    protected function catalogCategoryName(PartCatalogItem $catalogItem): ?string
    {
        if ($catalogItem->category) {
            $trail = collect();
            $category = $catalogItem->category;

            while ($category instanceof PartCatalogCategory && (int) $category->depth > 0) {
                $trail->prepend(trim(collect([$category->code, $category->name])->filter()->join(' - ')));
                $category = $category->parent;
            }

            $name = $trail->filter()->implode(' / ');

            if ($name !== '') {
                return $name;
            }
        }

        $name = collect([
            $catalogItem->main_category_name,
            $catalogItem->subcategory_name,
            $catalogItem->node_name,
        ])->filter()->implode(' / ');

        return $name !== '' ? $name : null;
    }

    protected function uniqueProductSlug(string $name, string $sku): string
    {
        $base = Str::slug($name) ?: Str::slug($sku);
        $slug = $base;
        $counter = 2;

        while (Product::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    protected function description(PartCatalogItem $catalogItem, array $damageZones, bool $isDamaged = false): string
    {
        $damageLabels = collect($damageZones)
            ->map(fn (string $zone): ?string => self::DAMAGE_ZONES[$zone] ?? null)
            ->filter()
            ->implode(', ');

        return collect([
            ' : '.($isDamaged ? '' : '').'.',
            'Автоматически сгенерировано из каталога запчастей.',
            $damageLabels ? 'Учтенные повреждения донора: '.$damageLabels.'.' : null,
            $catalogItem->source ? 'Источник: '.$catalogItem->source : null,
            $catalogItem->source_url ? 'Ссылка: '.$catalogItem->source_url : null,
        ])->filter()->implode(PHP_EOL);
    }

    protected function teslaBrand(): ?Brand
    {
        return Brand::query()->firstOrCreate(
            ['slug' => 'tesla'],
            ['name' => 'Tesla', 'is_active' => true],
        );
    }
}
