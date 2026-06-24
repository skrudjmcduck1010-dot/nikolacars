<?php

namespace App\View\Admin\DonorCars;

use App\Models\DonorCar;
use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Models\PartSale;
use App\Models\Product;
use App\Models\StoWorkOrder;
use App\Services\NikolaCarsInventoryService;
use App\Services\NikolaCarsProductInventorySyncService;
use App\Support\CatalogTextEncoding;
use App\Support\PartCatalogRawAttributes;
use App\Support\ProductPhotoNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DonorPartDisplayPresenter
{
    public function __construct(
        protected NikolaCarsInventoryService $nikolaCarsInventoryService,
    ) {}

    public function money(mixed $amount, ?string $currency = 'USD'): string
    {
        return number_format((float) $amount, 2, '.', ' ').' '.($currency ?: 'USD');
    }

    public function quantity(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
    }

    public function readableCategoryText(?string $value, bool $stripNumericPrefix = false, bool $normalizeAcronyms = false): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        if ($value === '') {
            return '';
        }

        if ($stripNumericPrefix) {
            $value = trim((string) preg_replace('/^\d+\s*(?:[-\x{2013}\x{2014}:.]\s*)?/u', '', $value));
        }

        if ($value === '') {
            return '';
        }

        $uppercaseCount = (int) preg_match_all('/\p{Lu}/u', $value);
        $hasLowercase = preg_match('/\p{Ll}/u', $value) === 1;

        if (! $hasLowercase || ($normalizeAcronyms && $uppercaseCount > 1)) {
            $value = mb_strtolower($value, 'UTF-8');
        }

        return mb_strtoupper(mb_substr($value, 0, 1, 'UTF-8'), 'UTF-8')
            .mb_substr($value, 1, null, 'UTF-8');
    }

    public function readableCategoryPath(?string $value, bool $stripNumericPrefixes = false, bool $normalizeAcronyms = false): string
    {
        return collect(preg_split('/\s*(?:\/|>|\\\\)\s*/u', (string) $value) ?: [])
            ->map(fn (string $part): string => $this->readableCategoryText($part, $stripNumericPrefixes, $normalizeAcronyms))
            ->filter()
            ->implode(' / ');
    }

    public function categoryTrail(?PartCatalogCategory $category): Collection
    {
        $trail = collect();
        $current = $category;

        while ($current instanceof PartCatalogCategory) {
            $trail->prepend($current);
            $current = $current->parent;
        }

        return $trail
            ->filter(fn (PartCatalogCategory $trailCategory): bool => (int) $trailCategory->depth > 0)
            ->values();
    }

    public function categoryLabel(
        ?PartCatalogCategory $category,
        string $locale = 'preferred',
        bool $includeNumericCode = false,
        bool $stripTeslaCode = false,
        bool $normalizeAcronyms = false,
    ): string {
        if (! $category instanceof PartCatalogCategory) {
            return '';
        }

        $code = trim((string) $category->code);
        $name = trim((string) match ($locale) {
            'ru' => $category->name_ru,
            'ua' => $category->name_ua,
            'original' => $category->name_en ?: $category->name,
            default => $category->name_ru ?: $category->name_ua ?: $category->name_en ?: $category->name,
        });

        if ($stripTeslaCode) {
            $name = $this->nikolaCarsInventoryService->withoutTeslaCategoryCode($name);
        }

        $displayName = $this->readableCategoryText($name, false, $normalizeAcronyms);

        if (! $includeNumericCode) {
            return $displayName;
        }

        $displayCode = ctype_digit($code)
            ? $code
            : $this->readableCategoryText($code, false, $normalizeAcronyms);

        if ($displayCode !== '' && $displayName !== '') {
            $displayName = trim((string) preg_replace('/^'.preg_quote($displayCode, '/').'\s*[-\x{2013}\x{2014}:]\s*/u', '', $displayName));
        }

        if ($displayCode !== '' && $displayName !== '' && ctype_digit($code)) {
            return $displayCode.' - '.$displayName;
        }

        return $displayName !== '' ? $displayName : $displayCode;
    }

    public function categoryPath(
        ?PartCatalogCategory $category,
        string $locale = 'preferred',
        bool $includeNumericCode = false,
        bool $stripTeslaCode = false,
        bool $normalizeAcronyms = false,
    ): string {
        return $this->categoryTrail($category)
            ->map(fn (PartCatalogCategory $trailCategory): string => $this->categoryLabel(
                $trailCategory,
                $locale,
                $includeNumericCode,
                $stripTeslaCode,
                $normalizeAcronyms,
            ))
            ->filter()
            ->implode(' / ');
    }

    public function categoryMatchesDonor(DonorCar $donorCar, ?PartCatalogCategory $category): bool
    {
        if (! $category instanceof PartCatalogCategory) {
            return false;
        }

        $donorModelLabel = mb_strtolower(trim((string) $donorCar->model));
        $donorBaseModel = mb_strtolower(trim((string) ($donorCar->display_model ?: $donorCar->model)));
        $donorYear = $donorCar->year ? (int) $donorCar->year : null;
        $donorModelHasDateRange = preg_match('/\d{2}\.\d{4}|\d{4}\s*-/u', (string) $donorCar->model) === 1;
        $modelLabel = mb_strtolower(trim((string) $category->model_label));
        $modelName = mb_strtolower(trim((string) $category->model_name));

        if ($donorModelLabel !== '' && $modelLabel === $donorModelLabel) {
            return true;
        }

        if ($donorModelHasDateRange && $modelLabel !== '') {
            return false;
        }

        if ($donorBaseModel !== '' && $modelName !== '' && $modelName !== $donorBaseModel) {
            return false;
        }

        if ($donorYear !== null) {
            if ($category->year_from !== null && (int) $category->year_from > $donorYear) {
                return false;
            }

            if ($category->year_to !== null && (int) $category->year_to < $donorYear) {
                return false;
            }
        }

        return $donorBaseModel !== '' && ($modelName === $donorBaseModel || ($modelLabel !== '' && str_contains($modelLabel, $donorBaseModel)));
    }

    public function categoryForDonor(DonorCar $donorCar, mixed $catalogItem = null, bool $useOccurrences = true): ?PartCatalogCategory
    {
        if (! $catalogItem instanceof PartCatalogItem) {
            return null;
        }

        $occurrences = $useOccurrences || $catalogItem->relationLoaded('occurrences')
            ? $catalogItem->occurrences
            : collect();

        $occurrenceCategory = $occurrences
            ->pluck('category')
            ->filter(fn ($category): bool => $this->categoryMatchesDonor($donorCar, $category))
            ->first();

        if ($occurrenceCategory instanceof PartCatalogCategory) {
            return $occurrenceCategory;
        }

        return $catalogItem->category instanceof PartCatalogCategory
            ? $catalogItem->category
            : null;
    }

    public function catalogRawCategoryPath(mixed $catalogItem = null, bool $normalizeAcronyms = false): string
    {
        if (! $catalogItem instanceof PartCatalogItem) {
            return '';
        }

        $rawAttributes = PartCatalogRawAttributes::from($catalogItem);
        $categoryPath = data_get($rawAttributes, 'category_display') ?: data_get($rawAttributes, 'category_path');

        return $this->readableCategoryPath($categoryPath, false, $normalizeAcronyms);
    }

    public function desktopCategoryOption(
        DonorCar $donorCar,
        mixed $catalogItem = null,
        ?string $categoryPath = null,
        ?string $fallbackText = null,
    ): array {
        $label = $this->firstPathLabel($categoryPath);
        if ($label !== '') {
            return $this->categoryOptionFromLabel($label);
        }

        $label = $catalogItem instanceof PartCatalogItem ? $this->firstPathLabel($this->catalogRawCategoryPath($catalogItem)) : '';
        if ($label !== '') {
            return $this->categoryOptionFromLabel($label);
        }

        $category = $this->categoryForDonor($donorCar, $catalogItem);

        if ($category instanceof PartCatalogCategory) {
            $topCategory = $this->categoryTrail($category)
                ->filter(fn (PartCatalogCategory $trailCategory): bool => $this->categoryLabel($trailCategory, 'preferred', true) !== '')
                ->first();

            if ($topCategory instanceof PartCatalogCategory) {
                return $this->categoryOptionFromLabel($this->categoryLabel($topCategory, 'preferred', true));
            }
        }

        return $this->categoryOptionFromLabel($this->undefinedCategoryLabel());
    }

    public function mobileCategoryOption(DonorCar $donorCar, mixed $catalogItem = null, ?string $categoryPath = null): array
    {
        $rawCategoryPath = $catalogItem instanceof PartCatalogItem
            ? $this->catalogRawCategoryPath($catalogItem, true)
            : '';
        $label = $this->firstPathLabel($rawCategoryPath);
        if ($label !== '') {
            return $this->categoryOptionFromLabel($label, true);
        }

        $category = $this->categoryForDonor($donorCar, $catalogItem, false);

        if ($category instanceof PartCatalogCategory) {
            $topCategory = $this->categoryTrail($category)
                ->filter(fn (PartCatalogCategory $trailCategory): bool => $this->categoryLabel($trailCategory, 'preferred', false, true, true) !== '')
                ->first();

            if ($topCategory instanceof PartCatalogCategory) {
                return $this->categoryOptionFromLabel($this->categoryLabel($topCategory, 'preferred', false, true, true), true);
            }
        }

        $label = $this->firstPathLabel($categoryPath);
        if ($label !== '' && ! $this->looksLikeLegacyCategoryFallback($label)) {
            return $this->categoryOptionFromLabel($label, true);
        }

        return $this->categoryOptionFromLabel($this->undefinedCategoryLabel(), true);
    }

    public function desktopProductCategoryOption(DonorCar $donorCar, Product $product): array
    {
        return $this->desktopCategoryOption($donorCar, $product->sourcePartCatalogItem, $product->category?->name, $product->name);
    }

    public function desktopSaleCategoryOption(DonorCar $donorCar, PartSale $sale): array
    {
        return $this->desktopCategoryOption(
            $donorCar,
            $sale->partCatalogItem,
            $sale->partCatalogItem ? null : $sale->category_path,
            $sale->name,
        );
    }

    public function mobileProductCategoryOption(DonorCar $donorCar, Product $product): array
    {
        return $this->mobileCategoryOption($donorCar, $product->sourcePartCatalogItem, $product->category?->name);
    }

    public function mobileSaleCategoryOption(DonorCar $donorCar, PartSale $sale): array
    {
        return $this->mobileCategoryOption($donorCar, $sale->partCatalogItem, $sale->partCatalogItem ? null : $sale->category_path);
    }

    public function mobileProductCategoryLabel(DonorCar $donorCar, Product $product): string
    {
        $catalogPath = $this->categoryPath($this->categoryForDonor($donorCar, $product->sourcePartCatalogItem, false), 'preferred', false, true, true);

        return $catalogPath !== ''
            ? $catalogPath
            : $this->readableCategoryPath($product->category?->name ?: '', false, true);
    }

    public function mobileSaleCategoryLabel(DonorCar $donorCar, PartSale $sale): string
    {
        $catalogPath = $this->categoryPath($this->categoryForDonor($donorCar, $sale->partCatalogItem, false), 'preferred', false, true, true);

        return $catalogPath !== ''
            ? $catalogPath
            : $this->readableCategoryPath($sale->category_path ?: '', false, true);
    }

    public function catalogNameManualLocks(?PartCatalogItem $catalogItem): array
    {
        $rawAttributes = PartCatalogRawAttributes::from($catalogItem);

        return [
            'ru' => (bool) (trim((string) $catalogItem?->name_ru) !== ''
                && (data_get($catalogItem, 'name_ru_manually_locked_at') || data_get($rawAttributes, 'manual_name_locks.ru'))),
            'ua' => (bool) (trim((string) $catalogItem?->name_ua) !== ''
                && (data_get($catalogItem, 'name_ua_manually_locked_at') || data_get($rawAttributes, 'manual_name_locks.ua'))),
        ];
    }

    public function productOriginBadge(Product $product, bool $officialGeneratedOnly = false): array
    {
        $isAutoGenerated = $product->isProtectedAutoGeneratedDonorProduct()
            && (! $officialGeneratedOnly || ($product->generated_at || $product->sourcePartCatalogItem?->source === 'tesla_official'));

        return $isAutoGenerated
            ? ['letter' => 'A', 'label' => "\u{0410}\u{0432}\u{0442}\u{043E}\u{043C}\u{0430}\u{0442}\u{0438}\u{0447}\u{0435}\u{0441}\u{043A}\u{0438} \u{0441}\u{0433}\u{0435}\u{043D}\u{0435}\u{0440}\u{0438}\u{0440}\u{043E}\u{0432}\u{0430}\u{043D}\u{043E}"]
            : ['letter' => "\u{0420}", 'label' => "\u{0414}\u{043E}\u{0431}\u{0430}\u{0432}\u{043B}\u{0435}\u{043D}\u{043E} \u{0432}\u{0440}\u{0443}\u{0447}\u{043D}\u{0443}"];
    }

    public function damageNote(Product $product): string
    {
        return trim((string) ($product->notes ?? ''));
    }

    public function normalizedDamageNote(Product $product): string
    {
        return trim((string) CatalogTextEncoding::repair($this->damageNote($product)));
    }

    public function mobileProductStatus(Product $product): array
    {
        $workOrder = $product->stoWorkOrderParts->first()?->order;
        $noteKey = mb_strtolower($this->normalizedDamageNote($product), 'UTF-8');
        $isBrokenNote = Str::startsWith($noteKey, "\u{0440}\u{0430}\u{0437}\u{0431}\u{0438}\u{0442}");
        $isNonLiquidNote = $noteKey === mb_strtolower(NikolaCarsProductInventorySyncService::NON_LIQUID_DAMAGE_STATUS, 'UTF-8');
        $isSuccessNote = collect(NikolaCarsProductInventorySyncService::CHECKED_DAMAGE_STATUSES)
            ->map(fn (string $status): string => mb_strtolower($status, 'UTF-8'))
            ->contains($noteKey)
            || Str::contains($noteKey, [
                "\u{0431}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}",
                "\u{043B}\u{0435}\u{0433}\u{043A}",
                "\u{043B}\u{0451}\u{0433}\u{043A}",
                "\u{0441}\u{0438}\u{043B}\u{044C}\u{043D}",
            ]);

        if ($workOrder?->status === StoWorkOrder::STATUS_PAID || $product->storage_status === Product::STORAGE_STATUS_SOLD) {
            return ['key' => 'sold', 'label' => "\u{041F}\u{0440}\u{043E}\u{0434}\u{0430}\u{043D}\u{0430}", 'class' => 'tag-paid', 'tone' => ''];
        }

        if (in_array($workOrder?->status, [StoWorkOrder::STATUS_IN_WORK, StoWorkOrder::STATUS_COMPLETED], true)) {
            return ['key' => 'reserved', 'label' => "\u{0412} \u{0440}\u{0435}\u{0437}\u{0435}\u{0440}\u{0432}\u{0435}", 'class' => 'tag-warning', 'tone' => ''];
        }

        if ($isBrokenNote || $isNonLiquidNote) {
            return ['key' => 'broken', 'label' => $isNonLiquidNote ? NikolaCarsProductInventorySyncService::NON_LIQUID_DAMAGE_STATUS : "\u{0420}\u{0430}\u{0437}\u{0431}\u{0438}\u{0442}\u{0430}", 'class' => 'tag-danger', 'tone' => 'danger'];
        }

        if ($noteKey === '' || $noteKey === "\u{043D}\u{0435}\u{0438}\u{0437}\u{0432}\u{0435}\u{0441}\u{0442}\u{043D}\u{043E}") {
            return ['key' => 'unchecked', 'label' => "\u{041D}\u{0435} \u{043F}\u{0440}\u{043E}\u{0432}\u{0435}\u{0440}\u{0435}\u{043D}\u{0430}", 'class' => 'tag-warning', 'tone' => ''];
        }

        return [
            'key' => 'checked',
            'label' => "\u{041F}\u{0440}\u{043E}\u{0432}\u{0435}\u{0440}\u{0435}\u{043D}\u{0430}",
            'class' => 'tag-paid',
            'tone' => $isSuccessNote ? 'success' : '',
            'origin' => $this->productOriginBadge($product, true),
        ];
    }

    public function productImages(Product $product): Collection
    {
        return ProductPhotoNormalizer::productPhotos($product);
    }

    public function isTeslaDownloadedImage(?string $path): bool
    {
        return str_contains((string) $path, 'tesla-official/part-images/');
    }

    public function catalogSchemeImages(?PartCatalogItem $catalogItem): Collection
    {
        $schemeImages = collect((array) data_get(PartCatalogRawAttributes::from($catalogItem), 'system_group_image_urls', []))
            ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
            ->unique()
            ->values();

        if ($schemeImages->isEmpty() && $catalogItem?->category?->preview_image_url) {
            return collect([$catalogItem->category->preview_image_url]);
        }

        return $schemeImages;
    }

    public function catalogSchemePosition(?PartCatalogItem $catalogItem): string
    {
        return trim((string) ($catalogItem?->scheme_number ?: data_get(PartCatalogRawAttributes::from($catalogItem), 'annotation')));
    }

    public function saleProductIdCandidates(PartSale $sale): array
    {
        $productId = (int) $sale->product_id;
        if ($productId > 0) {
            return [$productId];
        }

        $rawProductId = (int) data_get(PartCatalogRawAttributes::fromValue($sale->raw_attributes), 'product_id');
        if ($rawProductId > 0) {
            return [$rawProductId];
        }

        return collect([(int) data_get(PartCatalogRawAttributes::from($sale->partCatalogItem), 'product_id')])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function resolveSaleProduct(PartSale $sale, Collection $productsById, Collection $productsByCatalogItem): ?Product
    {
        $productIdCandidates = $this->saleProductIdCandidates($sale);

        foreach ($productIdCandidates as $productId) {
            $product = $productsById->get((int) $productId);

            if ($product instanceof Product) {
                return $product;
            }
        }

        if ($productIdCandidates !== []) {
            return null;
        }

        $product = $productsByCatalogItem->get((int) $sale->part_catalog_item_id);

        return $product instanceof Product ? $product : null;
    }

    public function originalPartNumber(PartSale $sale): string
    {
        return trim((string) (data_get(PartCatalogRawAttributes::fromValue($sale->raw_attributes), 'original_part_number') ?: $sale->part_number));
    }

    public function undefinedCategoryLabel(): string
    {
        return "\u{041D}\u{0435} \u{043E}\u{043F}\u{0440}\u{0435}\u{0434}\u{0435}\u{043B}\u{0435}\u{043D}\u{043E}";
    }

    protected function categoryOptionFromLabel(string $label, bool $normalizeAcronyms = false): array
    {
        $label = $this->readableCategoryText($label, true, $normalizeAcronyms);

        return [
            'key' => $label !== '' ? 'label:'.md5(mb_strtolower($label, 'UTF-8')) : '',
            'label' => $label,
        ];
    }

    protected function firstPathLabel(?string $path): string
    {
        return (string) collect(preg_split('/\s*(?:\/|>|\\\\)\s*/u', (string) $path) ?: [])
            ->map(fn (string $part): string => trim($part))
            ->filter()
            ->first();
    }

    protected function looksLikeLegacyCategoryFallback(?string $label): bool
    {
        $label = trim((string) $label);

        return $label !== ''
            && (preg_match('/^tesla\s*;/iu', $label) === 1
                || preg_match('/[A-HJ-NPR-Z0-9]{17}/i', $label) === 1);
    }
}
