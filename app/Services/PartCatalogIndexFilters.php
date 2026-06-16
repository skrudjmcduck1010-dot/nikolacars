<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class PartCatalogIndexFilters
{
    private const CYBERTRUCK_MODEL_LABEL = 'Cybertruck';

    public function __construct(
        public readonly string $query,
        public readonly bool $modelFilterSubmitted,
        public readonly array $requestedModels,
        public readonly array $selectedModels,
        public readonly array $filterModels,
        public readonly array $urlModels,
        public readonly string $model,
        public readonly bool $includeCybertruck,
        public readonly array $missingNames,
        public readonly array $productFilters,
        public readonly ?string $catalogItemsPriceSort,
        public readonly ?string $competitorSort,
        public readonly string $competitorSortDirection,
        public readonly string $catalogImageFilter,
        public readonly string $competitorNameFilter,
        public readonly string $teslaCheckFilter,
        public readonly string $teslaVisualFilter,
        public readonly string $nikolaCarsSort,
        public readonly string $nikolaCarsSortDirection,
        public readonly string $nikolaCarsVin,
        public readonly array $nikolaCarsVins,
        public readonly array $nikolaCarsTopCategories,
        public readonly bool $hideNikolaCarsSold,
        public readonly string $nameSource,
        public readonly bool $showCatalogItems,
        public readonly bool $hasSourceCatalogItemRequest,
    ) {}

    public static function fromRequest(
        Request $request,
        array $allowedModelLabels,
    ): self {
        $query = trim((string) $request->query('q', ''));
        $legacyModel = trim((string) $request->query('model', ''));
        $modelFilterSubmitted = $request->hasAny(['model_filter', 'models', 'model', 'include_cybertruck']);
        $requestedModels = collect((array) $request->query('models', []))
            ->when($legacyModel !== '', fn (Collection $models) => $models->push($legacyModel))
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter(fn (string $value): bool => in_array($value, $allowedModelLabels, true))
            ->unique()
            ->values()
            ->all();
        $includeCybertruck = $request->boolean('include_cybertruck')
            || in_array(self::CYBERTRUCK_MODEL_LABEL, $requestedModels, true);
        $missingNames = self::arrayOptions($request, 'missing_names', ['ru', 'ua']);
        $productFilters = self::arrayOptions($request, 'product_filters', ['errors']);
        $catalogItemsPriceSort = self::nullableOption($request, 'catalog_items_price_sort', ['asc', 'desc']);
        $competitorSort = self::nullableOption($request, 'competitor_sort', ['id', 'part_number', 'name', 'category', 'price', 'availability', 'created_at']);
        $competitorSortDirection = $request->query('competitor_direction') === 'desc' ? 'desc' : 'asc';
        $catalogImageFilter = self::stringOption($request, 'catalog_image_filter', ['with', 'without']);
        $competitorNameFilter = self::stringOption($request, 'competitor_name_filter', ['conflict', 'missing_ru', 'missing_ua']);
        $teslaCheckFilter = self::stringOption($request, 'tesla_check_filter', ['checked', 'unchecked', 'exact', 'similar', 'not_found', 'api_error']);
        $teslaVisualFilter = self::stringOption($request, 'tesla_visual_filter', ['part_photo', 'scheme', 'part_photo_and_scheme']);
        $nikolaCarsSort = self::stringOption($request, 'sort', ['created_at', 'category', 'stock', 'name', 'part_number', 'vin', 'price', 'quantity'], 'created_at');
        $nikolaCarsSortDirection = $request->has('direction')
            ? ($request->query('direction') === 'desc' ? 'desc' : 'asc')
            : ($nikolaCarsSort === 'created_at' ? 'desc' : 'asc');
        $legacyNikolaCarsVin = trim((string) $request->query('vin', ''));
        $nikolaCarsVins = self::stringArray($request, 'vins');
        if ($legacyNikolaCarsVin !== '' && ! in_array($legacyNikolaCarsVin, $nikolaCarsVins, true)) {
            $nikolaCarsVins[] = $legacyNikolaCarsVin;
        }
        $nikolaCarsVin = $nikolaCarsVins[0] ?? '';
        $nikolaCarsTopCategories = self::stringArray($request, 'top_categories');
        $hideNikolaCarsSold = $request->has('hide_sold') ? $request->boolean('hide_sold') : true;
        $nameSource = self::stringOption($request, 'name_source', ['aleto', 'teslashop']);
        $selectedModels = $modelFilterSubmitted
            ? array_values(array_filter($requestedModels, fn (string $model): bool => $model !== self::CYBERTRUCK_MODEL_LABEL))
            : [];
        $filterModels = $modelFilterSubmitted ? $selectedModels : [];
        if ($includeCybertruck && ! in_array(self::CYBERTRUCK_MODEL_LABEL, $filterModels, true)) {
            $filterModels[] = self::CYBERTRUCK_MODEL_LABEL;
        }
        $urlModels = $modelFilterSubmitted ? $selectedModels : [];
        $model = count($selectedModels) === 1 ? $selectedModels[0] : '';
        $showCatalogItems = false;
        $hasSourceCatalogItemRequest = $competitorSort !== null
            || $request->has('competitor_items_page')
            || $catalogImageFilter !== ''
            || $competitorNameFilter !== ''
            || $teslaCheckFilter !== ''
            || $teslaVisualFilter !== '';

        return new self(
            query: $query,
            modelFilterSubmitted: $modelFilterSubmitted,
            requestedModels: $requestedModels,
            selectedModels: $selectedModels,
            filterModels: $filterModels,
            urlModels: $urlModels,
            model: $model,
            includeCybertruck: $includeCybertruck,
            missingNames: $missingNames,
            productFilters: $productFilters,
            catalogItemsPriceSort: $catalogItemsPriceSort,
            competitorSort: $competitorSort,
            competitorSortDirection: $competitorSortDirection,
            catalogImageFilter: $catalogImageFilter,
            competitorNameFilter: $competitorNameFilter,
            teslaCheckFilter: $teslaCheckFilter,
            teslaVisualFilter: $teslaVisualFilter,
            nikolaCarsSort: $nikolaCarsSort,
            nikolaCarsSortDirection: $nikolaCarsSortDirection,
            nikolaCarsVin: $nikolaCarsVin,
            nikolaCarsVins: $nikolaCarsVins,
            nikolaCarsTopCategories: $nikolaCarsTopCategories,
            hideNikolaCarsSold: $hideNikolaCarsSold,
            nameSource: $nameSource,
            showCatalogItems: $showCatalogItems,
            hasSourceCatalogItemRequest: $hasSourceCatalogItemRequest,
        );
    }

    private static function arrayOptions(Request $request, string $key, array $allowed): array
    {
        return collect((array) $request->query($key, []))
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter(fn (string $value): bool => in_array($value, $allowed, true))
            ->values()
            ->all();
    }

    private static function nullableOption(Request $request, string $key, array $allowed): ?string
    {
        $value = $request->query($key);

        return in_array($value, $allowed, true) ? (string) $value : null;
    }

    private static function stringOption(Request $request, string $key, array $allowed, string $default = ''): string
    {
        $value = $request->query($key);

        return in_array($value, $allowed, true) ? (string) $value : $default;
    }

    private static function stringArray(Request $request, string $key): array
    {
        return collect((array) $request->query($key, []))
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();
    }
}
