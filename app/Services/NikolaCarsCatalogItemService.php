<?php

namespace App\Services;

use App\Models\CustomerOrderItem;
use App\Models\DonorCar;
use App\Models\Location;
use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Models\PartSale;
use App\Models\Product;
use App\Models\Warehouse;
use App\Support\PartCatalogRawAttributes;
use App\Support\PublicStorageUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class NikolaCarsCatalogItemService
{
    public function createManualItem(array $validated): Product
    {
        return DB::transaction(function () use ($validated): Product {
            $sourceType = ($validated['source_type'] ?? null) === 'donor' || ! empty($validated['donor_car_id'])
                ? 'donor'
                : 'purchase';
            $donorCar = ! empty($validated['donor_car_id'])
                ? DonorCar::query()->findOrFail($validated['donor_car_id'])
                : null;
            $warehouse = Warehouse::query()->findOrFail($validated['warehouse_id']);
            $isDonorWarehouse = $this->isDonorWarehouse($warehouse);
            $location = $isDonorWarehouse && $donorCar
                ? $this->resolveDonorLocation($warehouse, $donorCar)
                : $this->resolveInitialLocation($warehouse, $validated['floor'] ?? null, $validated['location_cell'] ?? null);
            $sku = $this->uniqueProductSku($donorCar, $sourceType);
            $name = trim((string) ($validated['name_ua'] ?? $validated['name'] ?? ''));
            $nameRu = trim((string) ($validated['name_ru'] ?? ''));
            $partNumber = trim((string) $validated['part_number']);
            $conditionType = (string) ($validated['condition_type'] ?? 'used');
            $damageStatus = trim((string) ($validated['damage_note'] ?? ''))
                ?: "\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}";
            $purchasePrice = $sourceType === 'purchase'
                ? round((float) ($validated['purchase_price_usd'] ?? 0), 2)
                : 0.0;

            $product = Product::query()->create([
                'sku' => $sku,
                'external_sku' => $partNumber,
                'name' => $name,
                'slug' => $this->uniqueProductSlug($name, $sku),
                'category_id' => null,
                'brand_id' => null,
                'donor_car_id' => $donorCar?->id,
                'part_origin' => Product::PART_ORIGIN_ORIGINAL,
                'source_part_catalog_item_id' => null,
                'is_auto_generated' => false,
                'storage_status' => $isDonorWarehouse ? Product::STORAGE_STATUS_ON_DONOR : Product::STORAGE_STATUS_IN_STOCK,
                'generated_at' => null,
                'description' => $validated['description'] ?? null,
                'compatibility' => $donorCar?->vin,
                'model' => $donorCar?->model,
                'color' => $donorCar?->color,
                'condition_type' => $conditionType,
                'testing_status' => 'not_tested',
                'unit' => 'pcs',
                'purchase_price' => $purchasePrice,
                'selling_price' => $validated['selling_price'] ?? 0,
                'currency' => 'USD',
                'barcode' => $sku,
                'qr_code' => $sku,
                'notes' => $damageStatus,
                'is_active' => true,
            ]);

            app(StockService::class)->intake([
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'location_id' => $location->id,
                'quantity' => 1,
                'comment' => 'Первичное размещение при добавлении запчасти из /admin/zapchasti.',
            ]);

            $syncResult = app(NikolaCarsProductInventorySyncService::class)->syncProduct($product->refresh());
            $item = $syncResult['item'] ?? null;

            if ($item instanceof PartCatalogItem) {
                $rawAttributes = PartCatalogRawAttributes::from($item);

                $rawAttributes['manual_create_source_type'] = $sourceType;
                $rawAttributes['manual_create_has_ru_name'] = $nameRu !== '';
                $rawAttributes['manual_create_name_ru'] = $nameRu;
                $rawAttributes['purchase_price_usd'] = $sourceType === 'purchase' ? $purchasePrice : null;
                $rawAttributes['source_type'] = $sourceType;

                $item->forceFill([
                    'name' => $name,
                    'name_ua' => $name,
                    'name_ru' => $nameRu !== '' ? $nameRu : null,
                    'quality' => $damageStatus,
                    'raw_attributes' => $rawAttributes,
                ])->save();
            }

            return $product->refresh();
        });
    }

    public function updateItem(PartCatalogItem $item, array $validated, bool $applyToPartNumber): array
    {
        abort_unless($item->source === 'nikolacars', 404);
        abort_if($this->isSold($item), 422, "\u{041F}\u{0440}\u{043E}\u{0434}\u{0430}\u{043D}\u{043D}\u{0443}\u{044E} \u{0437}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{044C} \u{043D}\u{0435}\u{043B}\u{044C}\u{0437}\u{044F} \u{0438}\u{0437}\u{043C}\u{0435}\u{043D}\u{044F}\u{0442}\u{044C}.");
        abort_if($this->isReserved($item), 422, "\u{041F}\u{043E}\u{0437}\u{0438}\u{0446}\u{0438}\u{044F} \u{0432} \u{0440}\u{0435}\u{0437}\u{0435}\u{0440}\u{0432}\u{0435}.");

        $priceAmount = array_key_exists('price_amount', $validated) && $validated['price_amount'] !== null
            ? round((float) $validated['price_amount'], 2)
            : null;

        if (array_key_exists('part_number', $validated)) {
            $partNumber = $this->normalizePartNumber((string) ($validated['part_number'] ?? ''));
            $item->part_number = $partNumber !== '' ? $partNumber : null;
        }

        if (array_key_exists('quantity', $validated)) {
            $quantity = $validated['quantity'] !== null ? round((float) $validated['quantity'], 3) : null;
            $rawAttributes = $this->rawAttributes($item);

            $rawAttributes['stock_quantity'] = $quantity;
            $item->availability = $quantity !== null ? $this->availability($quantity) : null;
            $item->raw_attributes = $rawAttributes;
        }

        if (array_key_exists('price_amount', $validated) && ! $applyToPartNumber) {
            $item->price_amount = $priceAmount;
            $item->currency = $priceAmount !== null ? 'USD' : null;
        }

        if (array_key_exists('notes_ua', $validated)) {
            $notesUk = trim((string) ($validated['notes_ua'] ?? ''));
            $notesUk = $this->withoutPartNumber($notesUk, (string) $item->part_number);
            $item->notes_ua = $notesUk !== '' ? $notesUk : null;
        }

        $item->save();
        $freshItem = $item->fresh();
        if ($freshItem instanceof PartCatalogItem) {
            app(NikolaCarsTeslaCategoryResolver::class)->resolveItem($freshItem);
        }

        $syncResult = $freshItem instanceof PartCatalogItem
            ? app(NikolaCarsCatalogProductSyncService::class)->syncItem($freshItem)
            : ['saved' => false, 'product' => null];

        $manualNameUpdates = collect($validated)
            ->only(['name_ru', 'name_ua'])
            ->all();

        $manualNameItem = $freshItem;
        $syncedProduct = $syncResult['product'] ?? null;
        if ($syncedProduct instanceof Product) {
            $manualNameItem = $syncedProduct->refresh()->sourcePartCatalogItem ?: $manualNameItem;
        }

        if ($manualNameUpdates !== [] && $manualNameItem instanceof PartCatalogItem) {
            app(PartCatalogManualNameService::class)->lockAndPropagate($manualNameItem, $manualNameUpdates);
        }

        $updatedItems = collect([$manualNameItem?->fresh() ?: $manualNameItem])->filter();

        if (array_key_exists('price_amount', $validated) && $applyToPartNumber) {
            $groupPartNumber = $this->normalizePartNumber((string) $item->part_number);

            if ($groupPartNumber !== '') {
                $matchingItemIds = PartCatalogItem::query()
                    ->where('source', 'nikolacars')
                    ->whereNotNull('part_number')
                    ->get(['id', 'part_number'])
                    ->filter(fn (PartCatalogItem $catalogItem): bool => $this->normalizePartNumber((string) $catalogItem->part_number) === $groupPartNumber)
                    ->pluck('id')
                    ->values();

                PartCatalogItem::query()
                    ->whereKey($matchingItemIds->all())
                    ->update([
                        'price_amount' => $priceAmount,
                        'currency' => $priceAmount !== null ? 'USD' : null,
                    ]);

                $updatedItems = PartCatalogItem::query()
                    ->whereKey($matchingItemIds->all())
                    ->get();
            }
        }

        /** @var PartCatalogItem $freshItem */
        $freshItem = $updatedItems->first() ?: $item->fresh();
        $usdRate = app(ExchangeRateService::class)->displayUsdRate();
        $rowTotal = $this->inventoryTotalUsd($updatedItems, $usdRate);
        $priceAmountUah = $freshItem->price_amount !== null
            ? app(ExchangeRateService::class)->productSellingPriceUahRoundedToTen((float) $freshItem->price_amount, $freshItem->currency, $usdRate)
            : null;

        return [
            'part_number' => $freshItem->part_number,
            'item' => [
                'id' => $freshItem->id,
                'name_ru' => $freshItem->name_ru,
                'name_ua' => $freshItem->name_ua,
            ],
            'quantity' => data_get($freshItem->raw_attributes, 'stock_quantity'),
            'availability' => $freshItem->availability,
            'notes_ua' => $freshItem->notes_ua,
            'price_amount' => $freshItem->price_amount !== null ? (float) $freshItem->price_amount : null,
            'price_amount_uah' => $priceAmountUah,
            'price_amount_uah_text' => $priceAmountUah !== null
                ? number_format($priceAmountUah, 0, '.', ' ')." \u{0433}\u{0440}\u{043D}"
                : null,
            'row_total_value_usd' => $rowTotal > 0 ? number_format($rowTotal, 2, '.', ' ').' USD' : '-',
            'items_count' => $this->itemsCount(),
            'unique_articles_count' => $this->uniqueArticleCount(),
            'added_today_count' => $this->addedTodayCount(),
            'total_value_usd' => $this->formattedInventoryTotalUsd(),
        ];
    }

    public function updateCategory(PartCatalogItem $item, int $categoryId): array
    {
        abort_unless($item->source === 'nikolacars', 404);
        abort_if($this->isSold($item), 422, "\u{041F}\u{0440}\u{043E}\u{0434}\u{0430}\u{043D}\u{043D}\u{0443}\u{044E} \u{0437}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{044C} \u{043D}\u{0435}\u{043B}\u{044C}\u{0437}\u{044F} \u{0438}\u{0437}\u{043C}\u{0435}\u{043D}\u{044F}\u{0442}\u{044C}.");

        $category = PartCatalogCategory::query()
            ->where('source', 'nikolacars')
            ->whereKey($categoryId)
            ->firstOrFail();

        $categoryLabel = app(NikolaCarsCatalogCategoryService::class)->displayLabel($category);
        $categoryParts = collect(preg_split('/\s*\/\s*/u', $categoryLabel) ?: [])
            ->map(fn (string $part): string => trim($part))
            ->filter()
            ->values();
        $rawAttributes = $this->rawAttributes($item);

        unset($rawAttributes['category_display'], $rawAttributes['category_path']);
        $rawAttributes['manual_category'] = true;
        $rawAttributes['manual_category_id'] = $category->id;

        $item->forceFill([
            'part_catalog_category_id' => $category->id,
            'model_label' => $category->model_label ?: $item->model_label,
            'model_name' => $category->model_name ?: $item->model_name,
            'main_category_name' => $categoryParts->get(0) ?: $categoryLabel,
            'subcategory_name' => $categoryParts->get(1),
            'node_name' => $categoryParts->get(2) ?: ($categoryParts->count() === 1 ? $categoryParts->get(0) : null),
            'raw_attributes' => $rawAttributes,
        ])->save();

        app(NikolaCarsCatalogProductSyncService::class)->syncItem($item->fresh());

        return [
            'item_id' => $item->id,
            'category_id' => $category->id,
            'category' => $categoryLabel,
        ];
    }

    public function storePhotos(PartCatalogItem $item, array $photos): void
    {
        abort_unless($item->source === 'nikolacars', 404);
        abort_if($this->isSold($item), 422, "\u{041F}\u{0440}\u{043E}\u{0434}\u{0430}\u{043D}\u{043D}\u{0443}\u{044E} \u{0437}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{044C} \u{043D}\u{0435}\u{043B}\u{044C}\u{0437}\u{044F} \u{0438}\u{0437}\u{043C}\u{0435}\u{043D}\u{044F}\u{0442}\u{044C}.");

        $rawAttributes = $this->rawAttributes($item);
        $imageUrls = collect((array) data_get($rawAttributes, 'image_urls', []))->filter()->values();

        foreach ($photos as $photo) {
            if (! $photo instanceof UploadedFile) {
                continue;
            }

            $path = $photo->store('nikolacars/catalog/'.$item->id, 'public');
            if ($path) {
                $imageUrls->push(Storage::url($path));
            }
        }

        $rawAttributes['image_urls'] = $imageUrls->filter()->unique()->values()->all();
        $item->raw_attributes = $rawAttributes;
        $item->save();

        app(NikolaCarsCatalogProductSyncService::class)->syncItem($item->fresh());
    }

    public function destroyPhoto(PartCatalogItem $item, string $imageUrl): void
    {
        abort_unless($item->source === 'nikolacars', 404);
        abort_if($this->isSold($item), 422, "\u{041F}\u{0440}\u{043E}\u{0434}\u{0430}\u{043D}\u{043D}\u{0443}\u{044E} \u{0437}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{044C} \u{043D}\u{0435}\u{043B}\u{044C}\u{0437}\u{044F} \u{0438}\u{0437}\u{043C}\u{0435}\u{043D}\u{044F}\u{0442}\u{044C}.");

        $rawAttributes = $this->rawAttributes($item);
        $rawAttributes['image_urls'] = collect((array) data_get($rawAttributes, 'image_urls', []))
            ->filter(fn ($url): bool => (string) $url !== $imageUrl)
            ->values()
            ->all();

        $this->deleteLocalPhoto($imageUrl);

        $item->raw_attributes = $rawAttributes;
        $item->save();

        app(NikolaCarsCatalogProductSyncService::class)->syncItem($item->fresh());
    }

    public function destroyItem(PartCatalogItem $item): array
    {
        abort_unless($item->source === 'nikolacars', 404);
        abort_if($this->isReserved($item), 422, "\u{041F}\u{043E}\u{0437}\u{0438}\u{0446}\u{0438}\u{044F} \u{0432} \u{0440}\u{0435}\u{0437}\u{0435}\u{0440}\u{0432}\u{0435}.");

        $deletedItemId = $item->id;

        DB::transaction(function () use ($item): void {
            $rawAttributes = $this->rawAttributes($item);
            $productId = (int) data_get($rawAttributes, 'product_id');

            $products = Product::query()
                ->with(['donorCar:id,vin,model,year,color', 'sourcePartCatalogItem'])
                ->where(function (Builder $query) use ($item, $productId): void {
                    $query->where('source_part_catalog_item_id', $item->id);

                    if ($productId > 0) {
                        $query->orWhere('id', $productId);
                    }
                })
                ->get();

            app(DeletedPartArchiveService::class)->archiveNikolaCarsItem($item, $products);
            $products->each->delete();
            $item->delete();
        });

        return [
            'deleted_item_id' => $deletedItemId,
        ];
    }

    public function markSoldBeforeJune(PartCatalogItem $item, string $displayName): array
    {
        abort_unless($item->source === 'nikolacars', 404);
        abort_if($this->isReserved($item), 422, "\u{041F}\u{043E}\u{0437}\u{0438}\u{0446}\u{0438}\u{044F} \u{0432} \u{0440}\u{0435}\u{0437}\u{0435}\u{0440}\u{0432}\u{0435}.");

        DB::transaction(function () use ($item, $displayName): void {
            $rawAttributes = $this->rawAttributes($item);

            $rawAttributes['manual_sold_at'] = NikolaCarsInventoryService::MANUAL_SOLD_AT;
            $rawAttributes['manual_sold_note'] = 'sold_before_june_2026_cleanup';
            $rawAttributes['stock_quantity_before_manual_sold'] = data_get($rawAttributes, 'stock_quantity');
            $rawAttributes['stock_quantity'] = 0;
            $rawAttributes['storage_status'] = Product::STORAGE_STATUS_SOLD;

            $item->forceFill([
                'availability' => app(NikolaCarsInventoryService::class)->availability(0),
                'raw_attributes' => $rawAttributes,
            ])->save();

            $productIds = collect([
                (int) data_get($rawAttributes, 'product_id'),
            ])->filter()->values();

            $productQuery = fn (): Builder => Product::query()
                ->where(function (Builder $query) use ($item, $productIds): void {
                    $query->where('source_part_catalog_item_id', $item->id);

                    if ($productIds->isNotEmpty()) {
                        $query->orWhereIn('id', $productIds->all());
                    }
                });

            $productQuery()->update([
                'storage_status' => Product::STORAGE_STATUS_SOLD,
                'is_active' => false,
                'updated_at' => now(),
            ]);

            $productQuery()
                ->get()
                ->each(fn (Product $product) => app(SoldProductStockAdjustmentService::class)->zeroStock($product, [
                    'document_number' => 'manual-sold-before-june-2026',
                    'comment' => 'Manual NikolaCars sold cleanup corrected product stock to zero.',
                ]));

            $this->recordManualSale($item->fresh(), $displayName);
        });

        return [
            'sold_item_id' => $item->id,
            'availability' => app(NikolaCarsInventoryService::class)->availability(0),
            'stock_quantity' => 0,
        ];
    }

    public function activeWarehousesForCreate(): Collection
    {
        $donorWarehouse = $this->donorWarehouseForCreate();

        return Warehouse::query()
            ->where('is_active', true)
            ->orWhere('id', $donorWarehouse->id)
            ->select(['id', 'name', 'type', 'floor_count'])
            ->get()
            ->sortBy(fn (Warehouse $warehouse): string => ($this->isDonorWarehouse($warehouse) ? '0' : '1').$warehouse->name)
            ->values();
    }

    public function donorOptionsForCreate(): Collection
    {
        return DonorCar::query()
            ->select(['id', 'vin', 'status', 'model', 'year', 'color', 'warehouse_arrival_date', 'photos'])
            ->orderByRaw('warehouse_arrival_date is null')
            ->orderByDesc('warehouse_arrival_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (DonorCar $donorCar): array => [
                'id' => $donorCar->id,
                'label' => trim(collect([
                    $donorCar->display_model ?: $donorCar->model,
                    $donorCar->year,
                    $donorCar->display_vin,
                ])->filter()->implode(' ')),
                'meta' => trim(collect([$donorCar->color, $donorCar->status_label])->filter()->implode(' · ')),
                'preview_url' => $this->donorPreviewUrl($donorCar),
            ]);
    }

    public function donorWarehouseForCreate(): Warehouse
    {
        $warehouse = Warehouse::query()
            ->where('type', Warehouse::TYPE_DONOR)
            ->orWhere('name', Warehouse::DONOR_WAREHOUSE_NAME)
            ->first();

        if (! $warehouse) {
            return Warehouse::query()->create([
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
                'floor_count' => 1,
                'is_active' => true,
            ])->save();
        }

        return $warehouse;
    }

    public function isDonorWarehouse(?Warehouse $warehouse): bool
    {
        return $warehouse instanceof Warehouse
            && ($warehouse->type === Warehouse::TYPE_DONOR || $warehouse->name === Warehouse::DONOR_WAREHOUSE_NAME);
    }

    public function isReserved(PartCatalogItem $item): bool
    {
        if ((float) data_get($item->raw_attributes, 'reserved_quantity', 0) > 0) {
            return true;
        }

        return CustomerOrderItem::query()
            ->where('part_catalog_item_id', $item->id)
            ->whereHas('order', fn (Builder $query) => $query->reservable())
            ->exists();
    }

    public function isSold(PartCatalogItem $item): bool
    {
        if ($item->source !== 'nikolacars') {
            return false;
        }

        return app(NikolaCarsInventoryService::class)->isManuallySold($item)
            || data_get($item->raw_attributes, 'storage_status') === Product::STORAGE_STATUS_SOLD
            || $item->sales()
                ->where('source', NikolaCarsInventoryService::SOURCE)
                ->exists();
    }

    protected function donorPreviewUrl(DonorCar $donorCar): ?string
    {
        $photo = collect($donorCar->photos ?? [])->filter()->first();

        return is_string($photo) && $photo !== '' ? PublicStorageUrl::url($photo) : null;
    }

    protected function uniqueProductSku(?DonorCar $donorCar, string $sourceType = 'warehouse'): string
    {
        if ($donorCar) {
            return app(DonorProductSkuService::class)->uniqueManualCode($donorCar);
        }

        $base = match ($sourceType) {
            'purchase' => 'NC-PURCHASE-'.str_pad((string) ((int) Product::query()->where('sku', 'like', 'NC-PURCHASE-%')->count() + 1), 4, '0', STR_PAD_LEFT),
            default => 'NC-MANUAL-'.str_pad((string) ((int) Product::query()->where('sku', 'like', 'NC-MANUAL-%')->count() + 1), 4, '0', STR_PAD_LEFT),
        };
        $sku = $base;
        $counter = 2;

        while (Product::query()->where('sku', $sku)->exists()) {
            $sku = $base.'-'.$counter;
            $counter++;
        }

        return $sku;
    }

    protected function uniqueProductSlug(string $name, string $sku): string
    {
        $base = Str::slug($name) ?: Str::slug($sku) ?: 'nikolacars-part';
        $slug = $base;
        $counter = 2;

        while (Product::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    protected function resolveInitialLocation(Warehouse $warehouse, ?string $floor, ?string $cell): Location
    {
        $floor = $warehouse->hasMultipleFloors() ? $floor : 'floor_1';
        $cell = $warehouse->usesStructuredLocations() ? trim((string) $cell) ?: null : null;

        $query = Location::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('floor', $floor);

        $cell === null ? $query->whereNull('cell') : $query->where('cell', $cell);

        if ($location = $query->first()) {
            return $location;
        }

        return Location::query()->create([
            'warehouse_id' => $warehouse->id,
            'floor' => $floor,
            'cell' => $cell,
            'full_code' => $this->uniqueLocationCode($warehouse, $floor, $cell),
            'is_active' => true,
        ]);
    }

    protected function resolveDonorLocation(Warehouse $warehouse, DonorCar $donorCar): Location
    {
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

    protected function uniqueLocationCode(Warehouse $warehouse, string $floor, ?string $cell): string
    {
        $floorNumber = Str::after($floor, 'floor_') ?: '1';
        $cellCode = $cell ? Str::upper(Str::slug($cell) ?: 'CELL') : 'NO-CELL';
        $base = "WH{$warehouse->id}-F{$floorNumber}-{$cellCode}";
        $code = $base;
        $counter = 2;

        while (Location::query()->where('full_code', $code)->exists()) {
            $code = "{$base}-{$counter}";
            $counter++;
        }

        return $code;
    }

    protected function rawAttributes(PartCatalogItem $item): array
    {
        return PartCatalogRawAttributes::from($item);
    }

    protected function deleteLocalPhoto(string $imageUrl): void
    {
        $path = parse_url($imageUrl, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return;
        }

        $storagePrefix = parse_url(Storage::url(''), PHP_URL_PATH) ?: '/storage/';
        $storagePrefix = rtrim($storagePrefix, '/').'/';

        if (str_starts_with($path, $storagePrefix)) {
            Storage::disk('public')->delete(ltrim(Str::after($path, $storagePrefix), '/'));
        }
    }

    protected function recordManualSale(PartCatalogItem $item, string $displayName): void
    {
        $rawAttributes = $this->rawAttributes($item);
        $quantityBeforeSold = data_get($rawAttributes, 'stock_quantity_before_manual_sold');
        $quantity = $quantityBeforeSold !== null && $quantityBeforeSold !== ''
            ? max(0.001, round((float) $quantityBeforeSold, 3))
            : 1.0;
        $donorCar = $this->manualSaleDonorCar($item);
        $rawDonorVin = trim((string) data_get($rawAttributes, 'donor_vin', ''));
        $donorVinCandidates = collect([
            trim((string) ($donorCar?->vin ?? '')),
            $rawDonorVin,
        ])->filter()->values();
        $donorVin = $donorVinCandidates
            ->first(fn (string $value): bool => mb_strlen($value) <= 17);
        $saleRawAttributes = [
            'manual_cleanup' => true,
            'manual_sold_at' => NikolaCarsInventoryService::MANUAL_SOLD_AT,
            'restorable_from_zapchasti_sold' => true,
        ];

        $originalDonorVin = $donorVinCandidates
            ->first(fn (string $value): bool => $value !== $donorVin && mb_strlen($value) > 17);

        if ($originalDonorVin !== null) {
            $saleRawAttributes['original_donor_vin'] = $originalDonorVin;
        }

        PartSale::query()->updateOrCreate(
            [
                'source' => 'nikolacars',
                'source_row_hash' => $this->manualSaleHash($item),
            ],
            [
                'part_catalog_item_id' => $item->id,
                'donor_car_id' => $donorCar?->id,
                'code' => data_get($rawAttributes, 'code'),
                'part_number' => $item->part_number,
                'name' => $displayName !== '' ? $displayName : ($item->name_ua ?: $item->name_ru ?: $item->name),
                'quantity' => $quantity,
                'unit_price' => $item->priceAmountUsd(),
                'currency' => 'USD',
                'sold_at' => NikolaCarsInventoryService::MANUAL_SOLD_AT,
                'document_number' => 'manual-sold-before-june-2026',
                'counterparty' => 'Cleanup before 01.06.2026',
                'donor_vin' => $donorVin,
                'category_path' => data_get($rawAttributes, 'category_display') ?: data_get($rawAttributes, 'category_path'),
                'raw_attributes' => $saleRawAttributes,
                'source_file' => 'manual-zapchasti-cleanup',
                'source_row_number' => $item->id,
            ],
        );
    }

    protected function manualSaleHash(PartCatalogItem $item): string
    {
        return 'manual-sold-before-june-2026-'.$item->id;
    }

    protected function manualSaleDonorCar(PartCatalogItem $item): ?DonorCar
    {
        $rawAttributes = $this->rawAttributes($item);
        $productId = (int) data_get($rawAttributes, 'product_id');

        if ($productId > 0) {
            $product = Product::query()->with('donorCar')->find($productId);
            if ($product?->donorCar) {
                return $product->donorCar;
            }
        }

        $donorVin = trim((string) data_get($rawAttributes, 'donor_vin'));
        if ($donorVin === '') {
            return null;
        }

        return DonorCar::query()
            ->whereRaw('lower(vin) = ?', [Str::lower($donorVin)])
            ->first();
    }

    protected function inventoryTotalUsd(Collection $items, array $usdRate): float
    {
        return app(NikolaCarsInventoryService::class)->inventoryTotalUsd($items, $usdRate);
    }

    protected function uniqueArticleCount(?Collection $items = null): int
    {
        return app(NikolaCarsCatalogListService::class)->uniqueArticleCount($items);
    }

    protected function itemsCount(?Collection $items = null): int
    {
        return app(NikolaCarsCatalogListService::class)->itemsCount($items);
    }

    protected function addedTodayCount(?Collection $items = null): int
    {
        return app(NikolaCarsCatalogListService::class)->addedTodayCount($items);
    }

    protected function formattedInventoryTotalUsd(): string
    {
        $usdRate = app(ExchangeRateService::class)->displayUsdRate();
        $total = $this->inventoryTotalUsd(
            app(NikolaCarsInventoryService::class)->activeItemsQuery()->get(),
            $usdRate
        );

        return number_format($total, 2, '.', ' ').' USD';
    }

    protected function normalizePartNumber(string $partNumber): string
    {
        $partNumber = Str::upper(str_replace(' ', '', trim($partNumber)));

        if (preg_match('/^(\d{7})([A-Z0-9]{2})([A-Z0-9])$/', $partNumber, $matches) === 1) {
            return "{$matches[1]}-{$matches[2]}-{$matches[3]}";
        }

        return $partNumber;
    }

    protected function availability(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.').' '."\u{0448}\u{0442}";
    }

    protected function withoutPartNumber(string $name, string $partNumber): string
    {
        $partNumber = trim($partNumber);

        if ($name === '' || $partNumber === '') {
            return $name;
        }

        $partNumberPattern = preg_quote($partNumber, '/');
        $partNumberLabelPattern = '(?:\x{0430}\x{0440}\x{0442}\.?|\x{0430}\x{0440}\x{0442}\x{0438}\x{043A}\x{0443}\x{043B}(?:\x{044B})?|part\s*(?:no\.?|number)?|vendor\s*code)\s*[:\x{2116}#-]?\s*';
        $cleaned = (string) preg_replace('/(?:^|[\s,;]+)'.$partNumberLabelPattern.$partNumberPattern.'(?:[\s,;]+|$)/iu', ' ', $name);
        $cleaned = (string) preg_replace('/(?:^|[\s,;]+)'.$partNumberPattern.'(?:[\s,;]+|$)/iu', ' ', $cleaned);

        if ($cleaned === $name) {
            return $name;
        }

        $cleaned = trim((string) preg_replace('/\s{2,}/u', ' ', $cleaned));

        return trim($cleaned, " \t\n\r\0\x0B,;.-");
    }
}
