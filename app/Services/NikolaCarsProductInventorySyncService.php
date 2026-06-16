<?php

namespace App\Services;

use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Models\Product;
use App\Support\CatalogTextEncoding;
use App\Support\NikolaCarsNomenclatureNameCleaner;
use App\Support\PartCatalogRawAttributes;
use App\Support\ProductPhotoNormalizer;
use App\Support\PublicStorageUrl;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class NikolaCarsProductInventorySyncService
{
    public const SOURCE = 'nikolacars';

    public const BROKEN_DAMAGE_STATUS = "\u{0420}\u{0430}\u{0437}\u{0431}\u{0438}\u{0442}";

    public const NON_LIQUID_DAMAGE_STATUS = "\u{041D}\u{0435}\u{043B}\u{0438}\u{043A}\u{0432}\u{0438}\u{0434}";

    public const CHECKED_DAMAGE_STATUSES = [
        "\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}",
        "\u{041B}\u{0435}\u{0433}\u{043A}\u{0438}\u{0435} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{044F}",
        "\u{0421}\u{0438}\u{043B}\u{044C}\u{043D}\u{044B}\u{0435} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{044F}",
    ];

    public function syncAll(): array
    {
        $stats = ['products_seen' => 0, 'items_saved' => 0, 'items_deleted' => 0];

        Product::query()
            ->with($this->relations())
            ->whereIn('storage_status', [
                Product::STORAGE_STATUS_IN_STOCK,
                Product::STORAGE_STATUS_ON_DONOR,
                Product::STORAGE_STATUS_SOLD,
            ])
            ->orderBy('id')
            ->chunkById(300, function ($products) use (&$stats): void {
                foreach ($products as $product) {
                    $stats['products_seen']++;
                    $result = $this->syncProduct($product);
                    $stats['items_saved'] += $result['saved'] ? 1 : 0;
                    $stats['items_deleted'] += $result['deleted'] ? 1 : 0;
                }
            });

        return $stats;
    }

    public function syncProduct(Product $product): array
    {
        $product->loadMissing($this->relations());

        if (! $this->shouldMirrorProduct($product)) {
            if ($this->hasManuallySoldCatalogItem($product)) {
                return ['saved' => false, 'deleted' => false, 'item' => null];
            }

            $mirrorItems = PartCatalogItem::query()
                ->where('source', self::SOURCE)
                ->whereIn('source_url', $this->sourceUrls($product))
                ->get();
            $restoreOfficialItemId = $this->restorableOfficialSourceItemId($product, $mirrorItems);
            $deleted = $mirrorItems->isNotEmpty()
                && PartCatalogItem::query()->whereKey($mirrorItems->modelKeys())->delete() > 0;

            $this->restoreOfficialGeneratedProductLink($product, $restoreOfficialItemId);

            return ['saved' => false, 'deleted' => $deleted, 'item' => null];
        }

        $sourceUrl = $this->sourceUrl($product);
        $existingItem = PartCatalogItem::query()
            ->where('source', self::SOURCE)
            ->where('source_url', $sourceUrl)
            ->first();
        $existingPartNumber = trim((string) $existingItem?->part_number);
        $existingSourceCatalogItemId = data_get($existingItem?->raw_attributes, 'source_catalog_item_id');
        $staleNikolaCarsItemId = $product->sourcePartCatalogItem?->source === self::SOURCE
            ? (int) $product->sourcePartCatalogItem->id
            : null;
        $category = $this->categoryForProduct($product, $existingItem);
        $payload = $this->payload($product, $category, $existingItem);
        $item = PartCatalogItem::query()->updateOrCreate(
            ['source_url' => $sourceUrl],
            $payload
        );
        $this->linkProductToNikolaCarsItem($product, $item);
        $this->deleteStaleNikolaCarsProductMirrors($product, $item, $staleNikolaCarsItemId);
        $this->fillDonorProductLocalizedNames($product);
        $this->propagateManualLocalizedNamesAfterSourceChange($item->refresh(), $existingPartNumber, $existingSourceCatalogItemId);
        app(NikolaCarsTeslaCategoryResolver::class)->resolveItem($item->fresh() ?? $item);

        return ['saved' => true, 'deleted' => false, 'item' => $item];
    }

    public function markDonorDamageCheckedAt(
        Product $product,
        ?PartCatalogItem $item,
        mixed $previousDamageStatus,
        mixed $currentDamageStatus
    ): void {
        if ($product->donor_car_id === null || ! $item instanceof PartCatalogItem || $item->source !== self::SOURCE) {
            return;
        }

        if (! $this->isUnknownDamageStatus($previousDamageStatus) || ! $this->isCheckedDamageStatus($currentDamageStatus)) {
            return;
        }

        $rawAttributes = PartCatalogRawAttributes::from($item);
        $rawAttributes['donor_damage_checked_at'] = now()->toIso8601String();

        $item->forceFill(['raw_attributes' => $rawAttributes])->save();
    }

    public function damageStatusChangedByForTransition(
        mixed $previousDamageStatus,
        mixed $currentDamageStatus,
        ?int $userId,
        ?int $existingUserId = null
    ): ?int {
        if ($this->isUnknownDamageStatus($currentDamageStatus)) {
            return null;
        }

        if ($this->isUnknownDamageStatus($previousDamageStatus) && $this->isCheckedDamageStatus($currentDamageStatus)) {
            return $userId;
        }

        return $existingUserId;
    }

    public function syncDonorDamageStatusChanger(
        Product $product,
        ?PartCatalogItem $item,
        mixed $previousDamageStatus,
        mixed $currentDamageStatus,
        ?int $userId
    ): void {
        if ($product->donor_car_id === null || ! $item instanceof PartCatalogItem || $item->source !== self::SOURCE) {
            return;
        }

        $rawAttributes = PartCatalogRawAttributes::from($item);

        if ($this->isUnknownDamageStatus($currentDamageStatus)) {
            unset($rawAttributes['donor_damage_status_changed_by']);
        } elseif ($this->isUnknownDamageStatus($previousDamageStatus) && $userId !== null) {
            $rawAttributes['donor_damage_status_changed_by'] = $userId;
        } else {
            return;
        }

        $item->forceFill(['raw_attributes' => $rawAttributes])->save();
    }

    protected function isUnknownDamageStatus(mixed $status): bool
    {
        $status = trim((string) CatalogTextEncoding::repair(is_string($status) ? $status : (string) $status));

        return $status === '' || $status === "\u{041D}\u{0435}\u{0438}\u{0437}\u{0432}\u{0435}\u{0441}\u{0442}\u{043D}\u{043E}";
    }

    protected function isCheckedDamageStatus(mixed $status): bool
    {
        $status = trim((string) CatalogTextEncoding::repair(is_string($status) ? $status : (string) $status));

        return in_array($status, self::CHECKED_DAMAGE_STATUSES, true);
    }

    protected function propagateManualLocalizedNamesAfterSourceChange(
        PartCatalogItem $item,
        string $existingPartNumber,
        mixed $existingSourceCatalogItemId
    ): void {
        $sourceCatalogItemId = data_get($item->raw_attributes, 'source_catalog_item_id');
        $sourceChanged = trim((string) $item->part_number) !== $existingPartNumber
            || (string) $sourceCatalogItemId !== (string) $existingSourceCatalogItemId;

        if (! $sourceChanged) {
            return;
        }

        $manualNames = app(PartCatalogManualNameService::class);
        $updates = [];

        foreach (['name_ru', 'name_ua'] as $column) {
            if ($manualNames->isLocked($item, $column) && trim((string) $item->{$column}) !== '') {
                $updates[$column] = $item->{$column};
            }
        }

        if ($updates !== []) {
            $manualNames->propagateExistingLocks($item, $updates);
            $item->refresh();
        }
    }

    protected function fillDonorProductLocalizedNames(Product $product): void
    {
        if ($product->donor_car_id === null) {
            return;
        }

        app(DonorProductLocalizedNameAutofillService::class)->fillMissingNames($product);
    }

    public function isSellableProduct(Product $product): bool
    {
        if ($product->is_active === false) {
            return false;
        }

        if (! in_array($product->storage_status, [
            Product::STORAGE_STATUS_IN_STOCK,
            Product::STORAGE_STATUS_ON_DONOR,
        ], true)) {
            return false;
        }

        if ($product->donor_car_id !== null) {
            return $this->isCheckedDonorProduct($product);
        }

        return $product->storage_status === Product::STORAGE_STATUS_IN_STOCK;
    }

    public function shouldMirrorProduct(Product $product): bool
    {
        if ($product->storage_status === Product::STORAGE_STATUS_SOLD) {
            return true;
        }

        if (! in_array($product->storage_status, [
            Product::STORAGE_STATUS_IN_STOCK,
            Product::STORAGE_STATUS_ON_DONOR,
        ], true)) {
            return false;
        }

        if ($product->donor_car_id !== null) {
            return $this->isCheckedDonorProduct($product)
                || $this->isBrokenDonorProduct($product);
        }

        return $product->is_active !== false
            && $product->storage_status === Product::STORAGE_STATUS_IN_STOCK;
    }

    public function isCheckedDonorProduct(Product $product): bool
    {
        return $product->donorCar !== null
            && in_array(trim((string) $product->notes), self::CHECKED_DAMAGE_STATUSES, true);
    }

    public function isBrokenDonorProduct(Product $product): bool
    {
        return $product->donorCar !== null
            && (
                trim((string) $product->notes) === self::BROKEN_DAMAGE_STATUS
                || trim((string) $product->notes) === self::NON_LIQUID_DAMAGE_STATUS
            );
    }

    protected function payload(Product $product, PartCatalogCategory $category, ?PartCatalogItem $existingItem = null): array
    {
        $sourceItem = $product->sourcePartCatalogItem;
        $sourceCatalogItem = $this->sourceCatalogItem($product, $sourceItem, $existingItem);
        $partNumber = trim((string) ($product->external_sku ?: $sourceItem?->part_number ?: $product->sku));
        $name = trim((string) ($product->name ?: $sourceItem?->name ?: $partNumber));
        $quantity = $this->catalogQuantity($product);
        $categoryDisplay = $this->categoryDisplay($product, $existingItem);
        $imageUrls = $this->imageUrls($product);
        $donorCar = $product->donorCar;
        $sourceType = $this->sourceType($product);
        $sourceCatalogPrice = $this->sourceCatalogPrice($sourceCatalogItem);
        $existingRawAttributes = $existingItem?->source === self::SOURCE
            ? $this->rawAttributes($existingItem)
            : ($sourceItem?->source === self::SOURCE
                ? $this->rawAttributes($sourceItem)
                : []);
        $manualCreateHasRuName = data_get($existingRawAttributes, 'manual_create_has_ru_name');
        $manualCreateNameRu = trim((string) data_get($existingRawAttributes, 'manual_create_name_ru', ''));
        $nameRu = $manualCreateHasRuName === false
            ? ''
            : ($manualCreateNameRu !== '' ? $manualCreateNameRu : $this->localizedNameCandidate($sourceItem?->name_ru, $product->name));
        $shouldPreserveExistingNameRu = $manualCreateHasRuName !== false;
        $isNomenclatureProduct = $this->isNomenclatureProduct($product, $existingRawAttributes);
        $nameUa = $isNomenclatureProduct
            ? (NikolaCarsNomenclatureNameCleaner::cleanName($product->name, $partNumber) ?: $name)
            : $this->localizedNameCandidate($sourceItem?->name_ua, $product->name);
        $existingRawAttributes = $this->withMirroredLocalizedNameSources($existingRawAttributes, $sourceItem, [
            'name_ru' => $nameRu,
            'name_ua' => $nameUa,
        ]);

        if ($isNomenclatureProduct) {
            $hasDistinctRuName = data_get($existingRawAttributes, 'nikolacars_has_distinct_ru_name');
            $isTranslatedFromUa = data_get($existingRawAttributes, 'nikolacars_ru_translated_from_ua') === true;
            $nameRu = $hasDistinctRuName === false && ! $isTranslatedFromUa
                ? ''
                : (NikolaCarsNomenclatureNameCleaner::cleanName($nameRu, $partNumber) ?: $nameRu);
            $shouldPreserveExistingNameRu = $shouldPreserveExistingNameRu
                && ($hasDistinctRuName !== false || $isTranslatedFromUa);
            $existingRawAttributes = $this->withoutLocalizedNameSource($existingRawAttributes, 'ua');
        }

        if ($shouldPreserveExistingNameRu) {
            $nameRu = $this->preserveExistingLocalizedName($existingItem, 'name_ru', $nameRu);
        }

        $nameUa = $this->preserveExistingLocalizedName($existingItem, 'name_ua', $nameUa);
        $nameRu = $this->preserveManuallyLockedLocalizedName($existingItem, 'name_ru', $nameRu);
        $nameUa = $this->preserveManuallyLockedLocalizedName($existingItem, 'name_ua', $nameUa);
        $existingRawAttributes = $this->withoutEmptyManualNameLocks($existingItem, $existingRawAttributes, [
            'name_ru' => $nameRu,
            'name_ua' => $nameUa,
        ]);
        [$nameRu, $nameUa, $existingRawAttributes, $manualNameLockColumnPayload] = $this->withInheritedManualNameLocks(
            $partNumber,
            $existingItem,
            $nameRu,
            $nameUa,
            $existingRawAttributes
        );

        return [
            'part_catalog_category_id' => $category->id,
            'source' => self::SOURCE,
            'part_number' => $partNumber !== '' ? $partNumber : null,
            'name' => $name !== '' ? $name : $product->sku,
            'name_ru' => $nameRu !== '' ? $nameRu : null,
            'name_ua' => $nameUa !== '' ? $nameUa : null,
            ...$this->emptyManualNameLockColumnPayload($existingItem, [
                'name_ru' => $nameRu,
                'name_ua' => $nameUa,
            ]),
            ...$manualNameLockColumnPayload,
            'price_amount' => $product->selling_price,
            'currency' => $product->currency ?: 'USD',
            'model_label' => $donorCar?->display_model ?: $product->generation ?: $product->model,
            'model_name' => $donorCar?->model ?: $product->model,
            'main_category_name' => $sourceItem?->main_category_name ?: $product->category?->name,
            'subcategory_name' => $sourceItem?->subcategory_name,
            'node_name' => $sourceItem?->node_name ?: $category->name,
            'compatibility_text' => $donorCar?->vin ?: $product->compatibility,
            'condition' => $product->condition_type,
            'quality' => $product->donor_car_id !== null ? trim((string) $product->notes) : null,
            'availability' => app(NikolaCarsInventoryService::class)->availability($quantity),
            'raw_attributes' => array_replace($existingRawAttributes, array_filter([
                'code' => $this->catalogCode($product, $existingRawAttributes),
                'product_id' => $product->id,
                'source_type' => $sourceType,
                'donor_car_id' => $product->donor_car_id,
                'donor_vin' => $donorCar?->vin,
                'donor_label' => $donorCar ? trim(collect([
                    $donorCar->display_model ?: $donorCar->model,
                    $donorCar->vin,
                ])->filter()->implode(' ')) : null,
                'donor_damage_status' => $product->donor_car_id !== null ? trim((string) $product->notes) : null,
                'donor_damage_status_changed_by' => $product->donor_car_id !== null ? $product->donor_damage_status_changed_by : null,
                'category_display' => $categoryDisplay,
                'category_path' => $categoryDisplay,
                'stock_quantity' => $quantity,
                'storage_status' => $product->storage_status,
                'purchase_item_ids' => $product->purchaseItems->pluck('id')->values()->all(),
                'stock_item_ids' => $product->stockItems->pluck('id')->values()->all(),
                'source_catalog_item_id' => $sourceCatalogItem?->id,
                'source_catalog_source' => $sourceCatalogItem?->source,
                'source_catalog_price_amount' => $sourceCatalogPrice['price_amount'] ?? null,
                'source_catalog_currency' => $sourceCatalogPrice['currency'] ?? null,
                'image_urls' => $imageUrls,
            ], fn (mixed $value): bool => $value !== null && $value !== '' && $value !== [])),
            'source_updated_at' => now(),
        ];
    }

    protected function sourceCatalogItem(Product $product, ?PartCatalogItem $sourceItem, ?PartCatalogItem $existingItem): ?PartCatalogItem
    {
        if ($sourceItem instanceof PartCatalogItem && $sourceItem->source !== self::SOURCE) {
            return $sourceItem;
        }

        $rawAttributes = $existingItem?->source === self::SOURCE
            ? $this->rawAttributes($existingItem)
            : ($sourceItem?->source === self::SOURCE ? $this->rawAttributes($sourceItem) : []);

        $sourceCatalogSource = (string) data_get($rawAttributes, 'source_catalog_source');
        $sourceCatalogItemId = (int) data_get($rawAttributes, 'source_catalog_item_id');

        if ($sourceCatalogSource !== '' && $sourceCatalogSource !== self::SOURCE && $sourceCatalogItemId > 0) {
            $catalogItem = PartCatalogItem::query()
                ->whereKey($sourceCatalogItemId)
                ->where('source', $sourceCatalogSource)
                ->first();

            if ($catalogItem instanceof PartCatalogItem) {
                return $catalogItem;
            }
        }

        if (! $product->isTeslaOfficialGenerated() && ! $this->shouldPreserveAutoGeneratedFlag($product)) {
            return null;
        }

        $partNumber = trim((string) ($product->external_sku ?: $sourceItem?->part_number));
        if ($partNumber === '') {
            return null;
        }

        return PartCatalogItem::query()
            ->where('source', 'tesla_official')
            ->where('part_number', $partNumber)
            ->orderByRaw("case when source_url like 'https://parts.tesla.com/%' then 0 else 1 end")
            ->orderBy('id')
            ->first();
    }

    protected function preserveExistingLocalizedName(?PartCatalogItem $existingItem, string $column, string $candidate): string
    {
        $existing = trim((string) $existingItem?->{$column});

        if ($existing === '') {
            return $candidate;
        }

        if ($candidate === '') {
            return $existing;
        }

        return $this->looksLocalizedName($existing) && ! $this->looksLocalizedName($candidate)
            ? $existing
            : $candidate;
    }

    protected function preserveManuallyLockedLocalizedName(?PartCatalogItem $existingItem, string $column, string $candidate): string
    {
        if (! $existingItem instanceof PartCatalogItem) {
            return $candidate;
        }

        if (! app(PartCatalogManualNameService::class)->isLocked($existingItem, $column)) {
            return $candidate;
        }

        $existing = trim((string) $existingItem->{$column});

        return $existing !== '' ? $existing : $candidate;
    }

    protected function withoutEmptyManualNameLocks(?PartCatalogItem $existingItem, array $rawAttributes, array $names): array
    {
        if (! $existingItem instanceof PartCatalogItem) {
            return $rawAttributes;
        }

        foreach (['ru' => 'name_ru', 'ua' => 'name_ua'] as $locale => $column) {
            if (! app(PartCatalogManualNameService::class)->isLocked($existingItem, $column)) {
                continue;
            }

            if (trim((string) $existingItem->{$column}) !== '' || trim((string) ($names[$column] ?? '')) === '') {
                continue;
            }

            unset($rawAttributes['manual_name_locks'][$locale]);
        }

        if (($rawAttributes['manual_name_locks'] ?? null) === []) {
            unset($rawAttributes['manual_name_locks']);
        }

        return $rawAttributes;
    }

    protected function emptyManualNameLockColumnPayload(?PartCatalogItem $existingItem, array $names): array
    {
        if (! $existingItem instanceof PartCatalogItem) {
            return [];
        }

        $payload = [];

        foreach (['name_ru' => 'name_ru_manually_locked_at', 'name_ua' => 'name_ua_manually_locked_at'] as $column => $lockColumn) {
            if (! app(PartCatalogManualNameService::class)->isLocked($existingItem, $column)) {
                continue;
            }

            if (trim((string) $existingItem->{$column}) !== '' || trim((string) ($names[$column] ?? '')) === '') {
                continue;
            }

            if (Schema::hasColumn('part_catalog_items', $lockColumn)) {
                $payload[$lockColumn] = null;
            }
        }

        return $payload;
    }

    protected function withInheritedManualNameLocks(
        string $partNumber,
        ?PartCatalogItem $existingItem,
        string $nameRu,
        string $nameUa,
        array $rawAttributes
    ): array {
        $manualNames = app(PartCatalogManualNameService::class);
        $lockedNames = $manualNames->lockedNameValuesForPartNumber($partNumber, $existingItem?->id);
        $lockColumnPayload = [];

        foreach (['ru' => 'name_ru', 'ua' => 'name_ua'] as $locale => $column) {
            if (! array_key_exists($column, $lockedNames)) {
                continue;
            }

            if ($existingItem instanceof PartCatalogItem
                && $manualNames->isLocked($existingItem, $column)
                && trim((string) $existingItem->{$column}) !== '') {
                continue;
            }

            $value = trim((string) $lockedNames[$column]['value']);
            if ($value === '') {
                continue;
            }

            if ($column === 'name_ru') {
                $nameRu = $value;
            } else {
                $nameUa = $value;
            }

            $lockedAt = $lockedNames[$column]['locked_at'] ?? now();
            $rawAttributes['manual_name_locks'][$locale] = (string) $lockedAt;

            $lockColumn = $column === 'name_ru' ? 'name_ru_manually_locked_at' : 'name_ua_manually_locked_at';
            if (Schema::hasColumn('part_catalog_items', $lockColumn)) {
                $lockColumnPayload[$lockColumn] = $lockedAt;
            }
        }

        return [$nameRu, $nameUa, $rawAttributes, $lockColumnPayload];
    }

    protected function localizedNameCandidate(?string ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);

            if ($candidate !== '' && $this->looksLocalizedName($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    protected function looksLocalizedName(string $name): bool
    {
        return preg_match('/\p{Cyrillic}/u', trim($name)) === 1;
    }

    protected function sourceCatalogPrice(?PartCatalogItem $sourceItem): ?array
    {
        if (! $sourceItem instanceof PartCatalogItem || $sourceItem->source !== 'tesla_official') {
            return null;
        }

        if ($sourceItem->price_amount !== null) {
            return [
                'price_amount' => (float) $sourceItem->price_amount,
                'currency' => $sourceItem->currency ?: 'USD',
            ];
        }

        $contexts = collect((array) data_get($sourceItem->raw_attributes, 'tesla_scheme_annotation_contexts', []))
            ->filter(fn (mixed $context): bool => is_array($context) && is_numeric($context['price'] ?? null));

        if ($contexts->isEmpty()) {
            return null;
        }

        $context = $contexts
            ->first(fn (array $context): bool => strtoupper((string) ($context['currency'] ?? 'USD')) === 'USD')
            ?: $contexts->first();

        return [
            'price_amount' => (float) $context['price'],
            'currency' => strtoupper((string) ($context['currency'] ?? 'USD')) ?: 'USD',
        ];
    }

    protected function isNomenclatureProduct(Product $product, array $rawAttributes): bool
    {
        if ($product->sourcePartCatalogItem?->source !== self::SOURCE) {
            return false;
        }

        return trim((string) data_get($rawAttributes, 'code')) !== ''
            || trim((string) data_get($rawAttributes, 'source_row.code')) !== '';
    }

    protected function withMirroredLocalizedNameSources(array $rawAttributes, ?PartCatalogItem $sourceItem, array $names): array
    {
        if (! $sourceItem instanceof PartCatalogItem) {
            return $rawAttributes;
        }

        $sourceRawAttributes = $this->rawAttributes($sourceItem);

        foreach (['ru' => 'name_ru', 'ua' => 'name_ua'] as $locale => $column) {
            $sourceName = trim((string) $sourceItem->{$column});
            $targetName = trim((string) ($names[$column] ?? ''));

            if ($sourceName === '' || $targetName === '' || $sourceName !== $targetName) {
                continue;
            }

            foreach (['site', 'url', 'item_id', 'type'] as $sourceKey) {
                $key = 'name_source_'.$sourceKey.'_'.$locale;

                if (array_key_exists($key, $sourceRawAttributes) && $sourceRawAttributes[$key] !== null && $sourceRawAttributes[$key] !== '') {
                    $rawAttributes[$key] = $sourceRawAttributes[$key];
                }
            }

            if ($locale === 'ru') {
                foreach (['name_source_site', 'name_source_url'] as $key) {
                    if (array_key_exists($key, $sourceRawAttributes) && $sourceRawAttributes[$key] !== null && $sourceRawAttributes[$key] !== '') {
                        $rawAttributes[$key] = $sourceRawAttributes[$key];
                    }
                }
            } elseif (($rawAttributes['name_source_site'] ?? null) === null
                && ! empty($sourceRawAttributes['name_source_site'])) {
                $rawAttributes['name_source_site'] = $sourceRawAttributes['name_source_site'];
            }
        }

        return $rawAttributes;
    }

    protected function withoutLocalizedNameSource(array $rawAttributes, string $locale): array
    {
        foreach ([
            'name_source_site_'.$locale,
            'name_source_url_'.$locale,
            'name_source_item_id_'.$locale,
            'name_source_type_'.$locale,
        ] as $key) {
            unset($rawAttributes[$key]);
        }

        return $rawAttributes;
    }

    protected function rawAttributes(?PartCatalogItem $item): array
    {
        return PartCatalogRawAttributes::from($item);
    }

    protected function categoryForProduct(Product $product, ?PartCatalogItem $existingItem = null): PartCatalogCategory
    {
        if ($this->hasManualCategory($existingItem)) {
            /** @var PartCatalogCategory $category */
            $category = $existingItem->category()->firstOrFail();

            return $category;
        }

        if ($product->donor_car_id !== null) {
            return $this->donorCategoryForProduct($product, $existingItem);
        }

        $rootName = "\u{0417}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{0438} \u{0432} \u{043F}\u{0440}\u{043E}\u{0434}\u{0430}\u{0436}\u{0435}";

        $root = PartCatalogCategory::query()->firstOrCreate(
            ['source_url' => 'nikolacars://products'],
            [
                'source' => self::SOURCE,
                'parent_id' => null,
                'depth' => 0,
                'name' => $rootName,
                'name_ru' => $rootName,
                'name_ua' => $rootName,
                'model_label' => $rootName,
                'sort_order' => 0,
                'children_scanned_at' => now(),
                'products_scanned_at' => now(),
            ]
        );

        $categoryName = $this->categoryDisplay($product, $existingItem) ?: "\u{0421}\u{043A}\u{043B}\u{0430}\u{0434} \u{0438} \u{0437}\u{0430}\u{043A}\u{0443}\u{043F}\u{043A}\u{0438}";

        return PartCatalogCategory::query()->firstOrCreate(
            ['source_url' => 'nikolacars://products/category/'.md5(Str::lower($categoryName))],
            [
                'source' => self::SOURCE,
                'parent_id' => $root->id,
                'depth' => 1,
                'name' => $categoryName,
                'name_ru' => $categoryName,
                'name_ua' => $categoryName,
                'model_label' => $root->model_label,
                'sort_order' => 0,
                'products_scanned_at' => now(),
            ]
        );
    }

    protected function donorCategoryForProduct(Product $product, ?PartCatalogItem $existingItem = null): PartCatalogCategory
    {
        $donorCar = $product->donorCar;
        $modelLabel = trim((string) ($donorCar?->display_model ?: $donorCar?->model ?: $product->model ?: 'Donor Tesla'));
        $vin = trim((string) $donorCar?->vin);
        $rootName = trim($modelLabel.' '.($vin !== '' ? $vin : 'Donor'));

        $root = PartCatalogCategory::query()->firstOrCreate(
            ['source_url' => 'nikolacars://donor-car/'.(int) $product->donor_car_id],
            [
                'source' => self::SOURCE,
                'parent_id' => null,
                'depth' => 0,
                'code' => $vin !== '' ? $vin : null,
                'name' => $rootName,
                'name_ru' => $rootName,
                'name_ua' => $rootName,
                'model_label' => $modelLabel,
                'model_name' => $donorCar?->model ?: $product->model,
                'sort_order' => 0,
                'children_scanned_at' => now(),
                'products_scanned_at' => now(),
            ]
        );

        $categoryName = $this->categoryDisplay($product, $existingItem) ?: "\u{0414}\u{043E}\u{043D}\u{043E}\u{0440}\u{0441}\u{043A}\u{0438}\u{0435} \u{0437}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{0438}";

        return PartCatalogCategory::query()->firstOrCreate(
            ['source_url' => 'nikolacars://donor-car/'.(int) $product->donor_car_id.'/category/'.md5(Str::lower($categoryName))],
            [
                'source' => self::SOURCE,
                'parent_id' => $root->id,
                'depth' => 1,
                'name' => $categoryName,
                'name_ru' => $categoryName,
                'name_ua' => $categoryName,
                'model_label' => $root->model_label,
                'model_name' => $root->model_name,
                'sort_order' => 0,
                'products_scanned_at' => now(),
            ]
        );
    }

    protected function categoryDisplay(Product $product, ?PartCatalogItem $existingItem = null): string
    {
        if ($this->hasManualCategory($existingItem)) {
            return trim((string) (
                data_get($existingItem->raw_attributes, 'category_display')
                ?: data_get($existingItem->raw_attributes, 'category_path')
                ?: $existingItem->main_category_name
            ));
        }

        $sourceItem = $product->sourcePartCatalogItem;

        return collect([
            $sourceItem?->main_category_name,
            $sourceItem?->subcategory_name,
            $sourceItem?->node_name,
            $product->category?->name,
        ])->filter()->unique()->implode(' / ');
    }

    protected function hasManualCategory(?PartCatalogItem $item): bool
    {
        return $item !== null
            && (bool) data_get($item->raw_attributes, 'manual_category')
            && (int) $item->part_catalog_category_id > 0;
    }

    protected function quantity(Product $product): float
    {
        $availableQuantity = $product->stockItems->sum(function ($stockItem): float {
            return max(0.0, round((float) $stockItem->quantity - (float) $stockItem->reserved_quantity, 3));
        });

        if ($availableQuantity > 0) {
            return round($availableQuantity, 3);
        }

        $stockQuantity = (float) $product->stockItems->sum('quantity');

        if ($stockQuantity > 0) {
            return round($stockQuantity, 3);
        }

        if ($product->sourcePartCatalogItem?->source === self::SOURCE) {
            $sourceStockQuantity = data_get($product->sourcePartCatalogItem->raw_attributes, 'stock_quantity');

            if ($sourceStockQuantity !== null && $sourceStockQuantity !== '') {
                return $this->catalogStockQuantityCappedToSourceRow($product->sourcePartCatalogItem, (float) $sourceStockQuantity);
            }
        }

        return 1.0;
    }

    protected function catalogStockQuantityCappedToSourceRow(PartCatalogItem $catalogItem, float $quantity): float
    {
        $sourceRowStock = $this->numericQuantity(data_get($catalogItem->raw_attributes, 'source_row.stock'));

        if ($sourceRowStock === null) {
            return round($quantity, 3);
        }

        return round(min($quantity, $sourceRowStock), 3);
    }

    protected function numericQuantity(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = str_replace(',', '.', trim((string) $value));

        if (! is_numeric($normalized)) {
            return null;
        }

        return max(0.0, round((float) $normalized, 3));
    }

    protected function catalogQuantity(Product $product): float
    {
        if ($product->storage_status === Product::STORAGE_STATUS_SOLD || $this->isBrokenDonorProduct($product)) {
            return 0.0;
        }

        return $this->quantity($product);
    }

    protected function sourceType(Product $product): string
    {
        if ($product->donor_car_id !== null) {
            return 'donor';
        }

        if (Str::startsWith((string) $product->sku, 'NC-PURCHASE-')) {
            return 'purchase';
        }

        return $product->purchaseItems->isNotEmpty() ? 'purchase' : 'warehouse';
    }

    protected function catalogCode(Product $product, array $existingRawAttributes): string
    {
        $code = trim((string) (
            data_get($existingRawAttributes, 'source_row.code')
            ?: ($existingRawAttributes['code'] ?? $product->sku)
        ));

        if (preg_match('/^NC-(\d+)$/i', $code, $matches) === 1) {
            return $matches[1];
        }

        return $code;
    }

    protected function imageUrls(Product $product): array
    {
        return ProductPhotoNormalizer::productPhotos($product)
            ->map(fn (string $path): string => PublicStorageUrl::url($path) ?? $path)
            ->unique()
            ->values()
            ->all();
    }

    protected function sourceUrl(Product $product): string
    {
        if ($product->donor_car_id !== null) {
            return 'nikolacars://donor-product/'.$product->id;
        }

        if ($product->sourcePartCatalogItem?->source === self::SOURCE
            && Str::startsWith((string) $product->sourcePartCatalogItem->source_url, 'nikolacars://inventory-product/')) {
            return (string) $product->sourcePartCatalogItem->source_url;
        }

        return 'nikolacars://inventory-product/'.$product->id;
    }

    protected function sourceUrls(Product $product): array
    {
        return array_values(array_unique([
            $this->sourceUrl($product),
            'nikolacars://donor-product/'.$product->id,
            'nikolacars://inventory-product/'.$product->id,
        ]));
    }

    protected function hasManuallySoldCatalogItem(Product $product): bool
    {
        return PartCatalogItem::query()
            ->where('source', self::SOURCE)
            ->whereIn('source_url', $this->sourceUrls($product))
            ->get()
            ->contains(fn (PartCatalogItem $item): bool => app(NikolaCarsInventoryService::class)->isManuallySold($item));
    }

    protected function linkProductToNikolaCarsItem(Product $product, PartCatalogItem $item): void
    {
        $isAutoGenerated = $this->shouldPreserveAutoGeneratedFlag($product);

        if ((int) $product->source_part_catalog_item_id === (int) $item->id) {
            if ($isAutoGenerated && ! (bool) $product->is_auto_generated) {
                $product->forceFill(['is_auto_generated' => true])->saveQuietly();
            }

            return;
        }

        $product->forceFill([
            'source_part_catalog_item_id' => $item->id,
            'is_auto_generated' => $isAutoGenerated,
        ])->saveQuietly();
        $product->setRelation('sourcePartCatalogItem', $item);
    }

    protected function deleteStaleNikolaCarsProductMirrors(Product $product, PartCatalogItem $currentItem, ?int $staleItemId = null): void
    {
        PartCatalogItem::query()
            ->where('source', self::SOURCE)
            ->where('id', '!=', $currentItem->id)
            ->where(function ($query) use ($product, $staleItemId): void {
                $query
                    ->whereIn('source_url', $this->sourceUrls($product))
                    ->orWhere('raw_attributes', 'like', '%"product_id":'.$product->id.'%')
                    ->orWhere('raw_attributes', 'like', '%"product_id": '.$product->id.'%');

                if ($staleItemId !== null && $staleItemId > 0) {
                    $query->orWhere('id', $staleItemId);
                }
            })
            ->delete();
    }

    protected function shouldPreserveAutoGeneratedFlag(Product $product): bool
    {
        if ($product->generated_at === null) {
            return false;
        }

        if (preg_match('/^DON\d+-/i', (string) $product->sku) === 1) {
            return true;
        }

        if ($product->sourcePartCatalogItem?->source === 'tesla_official') {
            return true;
        }

        $rawAttributes = $product->sourcePartCatalogItem?->raw_attributes ?? [];
        if (data_get($rawAttributes, 'source_catalog_source') === 'tesla_official') {
            return true;
        }

        $description = CatalogTextEncoding::repair((string) $product->description);

        return Str::contains($description, "\u{0410}\u{0432}\u{0442}\u{043E}\u{043C}\u{0430}\u{0442}\u{0438}\u{0447}\u{0435}\u{0441}\u{043A}\u{0438} \u{0441}\u{0433}\u{0435}\u{043D}\u{0435}\u{0440}\u{0438}\u{0440}\u{043E}\u{0432}\u{0430}\u{043D}\u{043E} \u{0438}\u{0437} \u{043A}\u{0430}\u{0442}\u{0430}\u{043B}\u{043E}\u{0433}\u{0430} \u{0437}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{0435}\u{0439}")
            && Str::contains($description, ['Источник: tesla_official', 'parts.tesla.com']);
    }

    protected function restorableOfficialSourceItemId(Product $product, $mirrorItems): ?int
    {
        if (! $this->shouldPreserveAutoGeneratedFlag($product)) {
            return null;
        }

        if ($product->sourcePartCatalogItem?->source === 'tesla_official') {
            return (int) $product->sourcePartCatalogItem->id;
        }

        $candidateIds = $mirrorItems
            ->map(fn (PartCatalogItem $item): mixed => data_get($item->raw_attributes, 'source_catalog_item_id'))
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($candidateIds->isNotEmpty()) {
            $officialItem = PartCatalogItem::query()
                ->where('source', 'tesla_official')
                ->whereIn('id', $candidateIds)
                ->first(['id']);

            if ($officialItem) {
                return (int) $officialItem->id;
            }
        }

        $description = CatalogTextEncoding::repair((string) $product->description);
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

    protected function restoreOfficialGeneratedProductLink(Product $product, ?int $officialItemId): void
    {
        if (! $this->shouldPreserveAutoGeneratedFlag($product)) {
            return;
        }

        $payload = ['is_auto_generated' => true];

        if ($officialItemId !== null && (int) $product->source_part_catalog_item_id !== $officialItemId) {
            $payload['source_part_catalog_item_id'] = $officialItemId;
        }

        $product->forceFill($payload)->saveQuietly();
    }

    protected function relations(): array
    {
        return [
            'category',
            'donorCar',
            'sourcePartCatalogItem.category.parent.parent.parent.parent',
            'stockItems',
            'purchaseItems',
        ];
    }
}
