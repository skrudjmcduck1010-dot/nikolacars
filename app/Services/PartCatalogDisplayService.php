<?php

namespace App\Services;

use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Support\CatalogTextEncoding;
use App\Support\PartCatalogRawAttributes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PartCatalogDisplayService
{
    protected const COMPETITOR_CATALOG_SOURCES = [
        'tcarservice',
        'teslapartsukraine',
        'tsk',
        'stock-tesla',
        'teslahelp',
        'driveparts',
        'dkparts',
        'erazborka',
        'toprazborka',
        'teslawestparts',
        'teslacompany',
    ];

    protected array $officialCategoryNameCache = [];

    public function displayCategoryName(PartCatalogCategory $category): string
    {
        if ($this->isCompetitorCatalogSource((string) $category->source)) {
            return $this->officialCategoryName($category)
                ?: $category->name_en
                ?: $category->name
                ?: $category->name_ru
                ?: $category->name_ua
                ?: '';
        }

        return $category->name_ru ?: $category->name_ua ?: $category->name_en ?: $category->name ?: '';
    }

    public function isCompetitorCatalogSource(string $source): bool
    {
        return in_array($source, self::COMPETITOR_CATALOG_SOURCES, true);
    }

    public function officialCategoryName(PartCatalogCategory $category): ?string
    {
        $modelLabel = trim((string) $category->model_label);
        $code = trim((string) $category->code);

        if ($modelLabel === '' || $code === '' || (int) $category->depth > 2) {
            return null;
        }

        $cacheKey = implode('|', [
            $modelLabel,
            (int) $category->depth,
            $code,
        ]);

        if (array_key_exists($cacheKey, $this->officialCategoryNameCache)) {
            return $this->officialCategoryNameCache[$cacheKey];
        }

        $officialCategory = PartCatalogCategory::query()
            ->where('source', 'tesla_official')
            ->where('model_label', $modelLabel)
            ->where('depth', (int) $category->depth)
            ->when(
                $code !== '',
                fn (Builder $builder) => $builder->where('code', $code),
                fn (Builder $builder) => $builder->whereNull('code')
            )
            ->orderBy('id')
            ->first(['name_en', 'name']);

        return $this->officialCategoryNameCache[$cacheKey] = $officialCategory !== null
            ? (string) ($officialCategory->name_en ?: $officialCategory->name ?: '')
            : null;
    }

    public function displayItemName(PartCatalogItem $item): string
    {
        $name = $item->source === 'nikolacars'
            ? ($item->name_ua ?: $item->name_ru ?: $item->name_en ?: $item->name ?: '')
            : ($item->name_ru ?: $item->name_ua ?: $item->name_en ?: $item->name ?: '');

        $name = $item->source === 'nikolacars'
            ? $this->withoutNikolaCarsPartNumber($name, (string) $item->part_number)
            : ($item->source === 'teslapartsukraine'
                ? $this->withoutTeslaPartsUkraineNameMarkers($name, $item)
                : $name);

        return $item->source === 'tesla_official'
            ? $this->teslaOfficialNameWithAnnotation($name, $item)
            : $name;
    }

    public function teslaOfficialNameWithAnnotation(string $name, PartCatalogItem $item): string
    {
        $name = trim($name);
        $annotation = trim((string) data_get($item->raw_attributes, 'annotation'));

        if ($name === '' || $annotation === '' || ! ctype_digit($annotation)) {
            return $name;
        }

        if (preg_match('/^'.preg_quote($annotation, '/').'(?:\s*\.|\s+)/u', $name) === 1) {
            return trim((string) preg_replace(
                '/^'.preg_quote($annotation, '/').'(?:\s*\.|\s+)/u',
                "{$annotation}. ",
                $name,
                1
            ));
        }

        return "{$annotation}. {$name}";
    }

    public function withoutTeslaPartsUkraineNameMarkers(string $name, PartCatalogItem $item): string
    {
        $name = preg_replace('/(?<![\pL\pN])(?:аналог|Оригінал|Оригинал)(?![\pL\pN])/iu', '', $name);
        $name = preg_replace('/(?<![\pL\pN])(?:БВ|Б\/У|Б\s*\/?\s*У)(?![\pL\pN])/iu', '', (string) $name);

        if (str_contains(Str::lower((string) ($item->model_name ?: $item->model_label)), 'model 3')) {
            $name = preg_replace('/(?<![\pL\pN])(?:tesla\s+)?model\s*3(?![\pL\pN])/iu', '', (string) $name);
        }

        $name = preg_replace('/\s+([,.;:)])/u', '$1', (string) $name);
        $name = preg_replace('/([(])\s+/u', '$1', (string) $name);

        return trim(preg_replace('/\s+/u', ' ', (string) $name));
    }

    public function displayModelLabel(mixed $value): string
    {
        $label = $value instanceof PartCatalogItem
            ? (string) ($value->model_label ?: $value->model_name ?: '')
            : ($value instanceof PartCatalogCategory
                ? (string) ($value->model_label ?: $value->model_name ?: '')
                : (string) $value);

        $label = CatalogTextEncoding::repair($label) ?? $label;

        return match ($label) {
            'Model S до 2016' => 'Model S до 2016',
            'Model S після 2016' => 'Model S після 2016',
            default => $label,
        };
    }

    public function teslaPartsUkrainePartOriginLabel(PartCatalogItem $item): ?string
    {
        if ($item->source !== 'teslapartsukraine') {
            return null;
        }

        $name = (string) ($item->name_ru ?: $item->name_ua ?: $item->name_en ?: $item->name ?: '');

        return match (true) {
            preg_match('/(?<![\pL\pN])аналог(?![\pL\pN])/iu', $name) === 1 => 'Аналог',
            preg_match('/(?<![\pL\pN])(?:Оригінал|Оригинал)(?![\pL\pN])/iu', $name) === 1 => 'Оригинал',
            default => null,
        };
    }

    public function teslaPartsUkraineConditionLabel(PartCatalogItem $item): ?string
    {
        if ($item->source !== 'teslapartsukraine') {
            return null;
        }

        $name = (string) ($item->name_ru ?: $item->name_ua ?: $item->name_en ?: $item->name ?: '');

        return preg_match('/(?<![\pL\pN])(?:БВ|Б\/У|Б\s*\/?\s*У)(?![\pL\pN])/iu', $name) === 1 ? 'Б/У' : null;
    }

    public function displayItemCondition(PartCatalogItem $item): ?string
    {
        return $item->condition ?: $this->teslaPartsUkraineConditionLabel($item);
    }

    public function displayItemPartType(PartCatalogItem $item): ?string
    {
        $value = data_get($item->raw_attributes, 'part_origin_label')
            ?: $this->teslaPartsUkrainePartOriginLabel($item);

        return match (mb_strtolower(trim((string) $value))) {
            'оригінал', 'оригинал' => 'Оригинал',
            'аналог' => 'Аналог',
            default => $value ?: null,
        };
    }

    public function displayNikolaCarsDescription(PartCatalogItem $item, ?string $description): string
    {
        return $this->withoutNikolaCarsPartNumber(trim((string) $description), (string) $item->part_number);
    }

    public function withoutNikolaCarsPartNumber(string $name, string $partNumber): string
    {
        $partNumber = trim($partNumber);

        if ($name === '' || $partNumber === '') {
            return $name;
        }

        $partNumberPattern = preg_quote($partNumber, '/');
        $partNumberLabelPattern = '(?:арт\.?|артикул(?:ы)?|part\s*(?:no\.?|number)?|vendor\s*code)\s*[:№#-]?\s*';
        $cleaned = (string) preg_replace('/(?:^|[\s,;]+)'.$partNumberLabelPattern.$partNumberPattern.'(?:[\s,;]+|$)/iu', ' ', $name);
        $cleaned = (string) preg_replace('/(?:^|[\s,;]+)'.$partNumberPattern.'(?:[\s,;]+|$)/iu', ' ', $cleaned);

        if ($cleaned === $name) {
            return $name;
        }

        $cleaned = trim((string) preg_replace('/\s{2,}/u', ' ', $cleaned));

        return trim($cleaned, " \t\n\r\0\x0B,;.-");
    }

    public function localizedNameSources(PartCatalogItem $item): array
    {
        return [
            'ru' => $this->localizedNameSource($item, 'ru'),
            'ua' => $this->localizedNameSource($item, 'ua'),
        ];
    }

    public function localizedNameSource(PartCatalogItem $item, string $locale): array
    {
        $localizedName = $locale === 'ru' ? $item->name_ru : $item->name_ua;

        if (! filled($localizedName)
            || app(PartCatalogManualNameService::class)->isLocked($item, $locale === 'ru' ? 'name_ru' : 'name_ua')) {
            return [
                'site' => null,
                'url' => null,
                'is_auto' => false,
            ];
        }

        $url = data_get($item->raw_attributes, 'name_source_url_'.$locale)
            ?: ($locale === 'ru' ? data_get($item->raw_attributes, 'name_source_url') : null);
        $site = data_get($item->raw_attributes, 'name_source_site_'.$locale)
            ?: ($locale === 'ru' ? data_get($item->raw_attributes, 'name_source_site') : null);
        $referencedSourceItem = $this->localizedNameSourceItemFromReference($item, $locale);
        $referencedUrl = $referencedSourceItem instanceof PartCatalogItem
            ? $this->displayableSourceUrl($referencedSourceItem, $locale)
            : null;
        $isAuto = $this->isAutoTranslatedFromSource($item, $localizedName, $referencedSourceItem);
        $competitorUrl = $this->competitorLocalizedNameUrl($item, $locale);

        if ($competitorUrl !== null) {
            $url = $competitorUrl;
            $site = $this->competitorSourceLabel((string) $item->source);
        }

        if ($referencedUrl !== null) {
            $url = $referencedUrl;
            $site = $this->siteFromUrl($referencedUrl) ?: $site;
        }

        if (! is_string($url) || ! str_starts_with($url, 'http')) {
            $url = $this->localizedNameSourceUrlFromItemReference($item, $locale);
        }

        if (! is_string($site) || $site === '') {
            $site = is_string($url) && $url !== ''
                ? preg_replace('/^www\./', '', (string) parse_url($url, PHP_URL_HOST))
                : '';
        }

        return [
            'site' => is_string($site) && $site !== '' ? $site : null,
            'url' => is_string($url) && $url !== '' ? $url : null,
            'is_auto' => $isAuto,
        ];
    }

    public function competitorLocalizedNameUrl(PartCatalogItem $item, string $locale): ?string
    {
        if (! in_array($item->source, [
            'dkparts',
            'driveparts',
            'erazborka',
            'stock-tesla',
            'tcarservice',
            'teslacompany',
            'teslahelp',
            'teslapartsukraine',
            'toprazborka',
            'teslawestparts',
            'tsk',
        ], true)) {
            return null;
        }

        $rawAttributes = PartCatalogRawAttributes::from($item);

        $explicitUrl = data_get($rawAttributes, 'source_url_'.$locale)
            ?: data_get($rawAttributes, 'url_'.$locale);

        if (is_string($explicitUrl) && Str::startsWith($explicitUrl, ['http://', 'https://'])) {
            return $explicitUrl;
        }

        $baseUrl = data_get($rawAttributes, 'teslashop_url')
            ?: data_get($rawAttributes, 'product_url')
            ?: data_get($rawAttributes, 'page_url')
            ?: data_get($rawAttributes, 'buy_url')
            ?: data_get($rawAttributes, 'teslahelp_page_url')
            ?: $item->source_url;

        if (! is_string($baseUrl) || ! Str::startsWith($baseUrl, ['http://', 'https://'])) {
            return null;
        }

        if ($item->source === 'teslapartsukraine') {
            return $locale === 'ua' ? $baseUrl : null;
        }

        if ($item->source === 'teslahelp') {
            return $locale === 'ru' ? $baseUrl : null;
        }

        if ($item->source === 'toprazborka') {
            return $locale === 'ua' ? $baseUrl : null;
        }

        if (in_array($item->source, ['teslacompany', 'teslawestparts'], true)) {
            return $locale === 'ua'
                ? $this->withPathLocale($baseUrl, 'ua')
                : $this->withoutPathLocale($baseUrl, 'ua');
        }

        if ($item->source === 'erazborka') {
            return $locale === 'ua'
                ? $this->withPathLocale($baseUrl, 'ua')
                : $this->withoutPathLocale($baseUrl, 'ua');
        }

        if (in_array($item->source, ['dkparts', 'driveparts', 'stock-tesla', 'tcarservice', 'tsk'], true)) {
            return $locale === 'ru'
                ? $this->withPathLocale($baseUrl, 'ru')
                : $this->withoutPathLocale($baseUrl, 'ru');
        }

        return $baseUrl;
    }

    public function withPathLocale(string $url, string $locale): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (str_starts_with($path, '/'.$locale.'/')) {
            return $url;
        }

        return preg_replace('#://([^/]+)/(?!'.$locale.'/)#', '://$1/'.$locale.'/', $url, 1) ?: $url;
    }

    public function withoutPathLocale(string $url, string $locale): string
    {
        return preg_replace('#://([^/]+)/'.$locale.'/#', '://$1/', $url, 1) ?: $url;
    }

    public function competitorSourceLabel(string $source): string
    {
        return match ($source) {
            'dkparts' => 'Источник DK-Parts',
            'driveparts' => 'Источник DriveParts',
            'erazborka' => 'Источник Erazborka',
            'stock-tesla' => 'Источник Stock Tesla',
            'tcarservice' => 'Источник TCARS',
            'teslacompany' => 'Источник TeslaCompany',
            'teslahelp' => 'Источник TeslaHelp',
            'teslapartsukraine' => 'Источник TeslaPartsUkraine',
            'teslawestparts' => 'Источник Tesla West Parts',
            'tsk' => 'Источник TSK',
            default => 'Источник',
        };
    }

    public function localizedNameSourceUrlFromItemReference(PartCatalogItem $item, string $locale): ?string
    {
        $sourceItem = $this->localizedNameSourceItemFromReference($item, $locale);

        return $sourceItem instanceof PartCatalogItem
            ? $this->displayableSourceUrl($sourceItem, $locale)
            : null;
    }

    public function localizedNameSourceItemFromReference(PartCatalogItem $item, string $locale): ?PartCatalogItem
    {
        $sourceItemId = data_get($item->raw_attributes, 'name_source_item_id_'.$locale);

        if (! is_numeric($sourceItemId)) {
            return null;
        }

        $sourceItem = PartCatalogItem::query()->find((int) $sourceItemId);

        return $sourceItem instanceof PartCatalogItem && $sourceItem->source !== 'teslahelp'
            ? $sourceItem
            : null;
    }

    public function inventoryLocalizedNameSourceUrlFromItemReference(PartCatalogItem $item, string $locale, bool $useLocalizedUrl = true): ?string
    {
        $sourceItem = $this->localizedNameSourceItemFromReference($item, $locale);

        return $sourceItem instanceof PartCatalogItem
            ? $this->inventoryDisplayableSourceUrl($sourceItem, $useLocalizedUrl ? $locale : null)
            : null;
    }

    public function inventoryLocalizedNameSourcesForItems(Collection $items): Collection
    {
        if ($items->isEmpty()) {
            return collect();
        }

        $manualNameService = app(PartCatalogManualNameService::class);
        $referencedItemIds = $items
            ->flatMap(fn (PartCatalogItem $item): array => [
                data_get(PartCatalogRawAttributes::from($item), 'name_source_item_id_ru'),
                data_get(PartCatalogRawAttributes::from($item), 'name_source_item_id_ua'),
            ])
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $referencedItems = $referencedItemIds->isEmpty()
            ? collect()
            : PartCatalogItem::query()->whereKey($referencedItemIds)->get()->keyBy('id');

        $sourceRows = collect();
        $candidatePartNumbers = [];

        foreach ($items as $item) {
            foreach (['ru', 'ua'] as $locale) {
                $localizedName = $locale === 'ru' ? $item->name_ru : $item->name_ua;

                if (! filled($localizedName) || $manualNameService->isLocked($item, $locale === 'ru' ? 'name_ru' : 'name_ua')) {
                    $sourceRows->put($item->id.'.'.$locale, ['site' => null, 'url' => null]);

                    continue;
                }

                $source = $this->initialInventoryLocalizedNameSource($item, $locale, $referencedItems);

                if ((! is_string($source['site']) || $source['site'] === '') && filled($item->part_number)) {
                    $candidatePartNumbers[] = (string) $item->part_number;
                }

                $sourceRows->put($item->id.'.'.$locale, [
                    ...$source,
                    'item' => $item,
                    'locale' => $locale,
                    'localized_name' => (string) $localizedName,
                ]);
            }
        }

        $uniquePartNumbers = collect($candidatePartNumbers)->filter()->unique()->values();
        $candidatesByPartNumber = $uniquePartNumbers->isEmpty()
            ? collect()
            : PartCatalogItem::query()
                ->whereIn('part_number', $uniquePartNumbers)
                ->where(fn (Builder $query) => $query
                    ->where(fn (Builder $query) => $query->where('name_ru', '!=', '')->whereNotNull('name_ru'))
                    ->orWhere(fn (Builder $query) => $query->where('name_ua', '!=', '')->whereNotNull('name_ua')))
                ->orderByRaw("case when source <> 'tesla_official' then 0 else 1 end")
                ->orderBy('id')
                ->get()
                ->groupBy('part_number');

        return $sourceRows
            ->map(function (array $source) use ($candidatesByPartNumber): array {
                $item = $source['item'] ?? null;
                $locale = $source['locale'] ?? null;
                $localizedName = $source['localized_name'] ?? null;

                if ($item instanceof PartCatalogItem
                    && is_string($locale)
                    && is_string($localizedName)
                    && (! is_string($source['site']) || $source['site'] === '')
                    && filled($item->part_number)) {
                    $sourceItem = $this->matchingLocalizedNameSourceItemFromCandidates(
                        $item,
                        $locale,
                        $localizedName,
                        $candidatesByPartNumber->get((string) $item->part_number, collect())
                    );

                    if ($sourceItem instanceof PartCatalogItem) {
                        $url = $this->inventoryDisplayableSourceUrl($sourceItem);
                        $source['url'] = $url;
                        $source['site'] = is_string($url) && $url !== ''
                            ? $this->siteFromUrl($url)
                            : $sourceItem->source;
                    }
                }

                return [
                    'site' => is_string($source['site'] ?? null) && $source['site'] !== '' ? $source['site'] : null,
                    'url' => is_string($source['url'] ?? null) && $source['url'] !== '' ? $source['url'] : null,
                ];
            })
            ->mapToGroups(function (array $source, string $key): array {
                [$itemId, $locale] = explode('.', $key, 2);

                return [(int) $itemId => [$locale => $source]];
            })
            ->map(fn (Collection $sources): array => $sources->toBase()->collapse()->all());
    }

    protected function initialInventoryLocalizedNameSource(PartCatalogItem $item, string $locale, Collection $referencedItems): array
    {
        $rawAttributes = PartCatalogRawAttributes::from($item);
        $url = data_get($rawAttributes, 'name_source_url_'.$locale)
            ?: ($locale === 'ru' ? data_get($rawAttributes, 'name_source_url') : null);
        $site = data_get($rawAttributes, 'name_source_site_'.$locale)
            ?: ($locale === 'ru' ? data_get($rawAttributes, 'name_source_site') : null);
        $referencedUrl = $this->inventoryLocalizedNameSourceUrlFromLoadedItemReference($item, $locale, $referencedItems);

        if ($referencedUrl !== null) {
            $url = $referencedUrl;
            $site = $this->siteFromUrl($referencedUrl) ?: $site;
        }

        if (! is_string($url) || ! Str::startsWith($url, ['http://', 'https://'])) {
            $url = $referencedUrl;
        }

        if ((! is_string($site) || $site === '') && is_string($url) && $url !== '') {
            $site = $this->siteFromUrl($url);
        }

        return [
            'site' => is_string($site) && $site !== '' ? $site : null,
            'url' => is_string($url) && $url !== '' ? $url : null,
        ];
    }

    protected function inventoryLocalizedNameSourceUrlFromLoadedItemReference(PartCatalogItem $item, string $locale, Collection $referencedItems): ?string
    {
        $sourceItemId = data_get(PartCatalogRawAttributes::from($item), 'name_source_item_id_'.$locale);

        if (! is_numeric($sourceItemId)) {
            return null;
        }

        $sourceItem = $referencedItems->get((int) $sourceItemId);

        return $sourceItem instanceof PartCatalogItem
            ? $this->inventoryDisplayableSourceUrl($sourceItem)
            : null;
    }

    public function matchingLocalizedNameSourceItem(PartCatalogItem $item, string $locale, string $localizedName): ?PartCatalogItem
    {
        $column = $locale === 'ru' ? 'name_ru' : 'name_ua';
        $name = $localizedName;

        return PartCatalogItem::query()
            ->where('part_number', $item->part_number)
            ->whereKeyNot($item->id)
            ->where('source', '!=', 'teslahelp')
            ->where($column, '!=', '')
            ->whereNotNull($column)
            ->orderByRaw("case when source <> 'tesla_official' then 0 else 1 end")
            ->orderBy('id')
            ->get()
            ->sortBy(function (PartCatalogItem $candidate) use ($name): string {
                $candidateName = mb_strtolower((string) $candidate->name);
                $localizedName = mb_strtolower($name);

                return ($candidateName === $localizedName ? '0' : '1').'|'.str_pad((string) $candidate->id, 12, '0', STR_PAD_LEFT);
            })
            ->first(function (PartCatalogItem $candidate) use ($column, $name): bool {
                $candidateName = (string) $candidate->{$column};

                return $candidateName !== '' && mb_strtolower($candidateName) === mb_strtolower($name);
            });
    }

    public function matchingLocalizedNameSourceItemFromCandidates(PartCatalogItem $item, string $locale, string $localizedName, Collection $candidates): ?PartCatalogItem
    {
        $column = $locale === 'ru' ? 'name_ru' : 'name_ua';
        $name = $localizedName;

        return $candidates
            ->reject(fn (PartCatalogItem $candidate): bool => (int) $candidate->id === (int) $item->id)
            ->reject(fn (PartCatalogItem $candidate): bool => $candidate->source === 'teslahelp')
            ->filter(fn (PartCatalogItem $candidate): bool => trim((string) $candidate->{$column}) !== '')
            ->sortBy(function (PartCatalogItem $candidate) use ($name): string {
                $candidateName = mb_strtolower((string) $candidate->name);
                $localizedName = mb_strtolower($name);

                return ($candidateName === $localizedName ? '0' : '1').'|'.str_pad((string) $candidate->id, 12, '0', STR_PAD_LEFT);
            })
            ->first(function (PartCatalogItem $candidate) use ($column, $name): bool {
                $candidateName = (string) $candidate->{$column};

                return $candidateName !== '' && mb_strtolower($candidateName) === mb_strtolower($name);
            });
    }

    public function isAutoTranslatedFromSource(
        PartCatalogItem $item,
        ?string $localizedName,
        ?PartCatalogItem $sourceItem
    ): bool {
        if (! $sourceItem instanceof PartCatalogItem) {
            return false;
        }

        if ($sourceItem->id === $item->id) {
            return false;
        }

        $localizedName = $this->normalizeSourceNameForCompare((string) $localizedName);
        $sourceNames = collect([
            $sourceItem->name,
            data_get($sourceItem->raw_attributes, 'jsonld_name'),
        ])
            ->map(fn (mixed $value): string => $this->normalizeSourceNameForCompare((string) $value))
            ->filter();

        return $localizedName !== ''
            && $sourceNames->isNotEmpty()
            && ! $sourceNames->contains($localizedName);
    }

    public function normalizeSourceNameForCompare(string $value): string
    {
        $value = Str::lower(trim($value));
        $value = preg_replace('/\{[^}]*\}/u', '', $value) ?: '';
        $value = preg_replace('/\s+/u', ' ', $value) ?: '';

        return trim($value);
    }

    public function displayableSourceUrl(PartCatalogItem $item, ?string $locale = null): ?string
    {
        if ($locale !== null) {
            $localizedUrl = $this->competitorLocalizedNameUrl($item, $locale);

            if ($localizedUrl !== null) {
                return $localizedUrl;
            }
        }

        if ($item->source === 'teslacompany') {
            $sourceUrl = (string) $item->source_url;

            return Str::startsWith((string) parse_url($sourceUrl, PHP_URL_PATH), '/goods/')
                ? $sourceUrl
                : null;
        }

        $rawUrl = data_get($item->raw_attributes, 'teslashop_url')
            ?: data_get($item->raw_attributes, 'buy_url')
            ?: data_get($item->raw_attributes, 'product_url')
            ?: data_get($item->raw_attributes, 'teslahelp_page_url')
            ?: data_get($item->raw_attributes, 'competitor_raw_attributes.product_url')
            ?: data_get($item->raw_attributes, 'competitor_raw_attributes.page_url')
            ?: data_get($item->raw_attributes, 'page_url')
            ?: $this->teslaShopUrlFromPartNumber($item)
            ?: data_get($item->raw_attributes, 'schematic_source_url')
            ?: $item->source_url
            ?: data_get($item->raw_attributes, 'category_source_url');

        return is_string($rawUrl) && Str::startsWith($rawUrl, ['http://', 'https://'])
            ? $rawUrl
            : null;
    }

    public function inventoryDisplayableSourceUrl(PartCatalogItem $item, ?string $locale = null): ?string
    {
        if ($locale !== null) {
            $localizedUrl = $this->inventoryCompetitorLocalizedNameUrl($item, $locale);

            if ($localizedUrl !== null) {
                return $localizedUrl;
            }
        }

        return collect(data_get($item->raw_attributes, 'source_urls', []))
            ->merge(data_get($item->raw_attributes, 'product_source_urls', []))
            ->merge([
                data_get($item->raw_attributes, 'product_url'),
                data_get($item->raw_attributes, 'buy_url'),
                data_get($item->raw_attributes, 'teslashop_url'),
                data_get($item->raw_attributes, 'teslahelp_page_url'),
                data_get($item->raw_attributes, 'competitor_source_url'),
                data_get($item->raw_attributes, 'competitor_raw_attributes.product_url'),
                data_get($item->raw_attributes, 'competitor_raw_attributes.buy_url'),
                data_get($item->raw_attributes, 'competitor_raw_attributes.teslashop_url'),
                data_get($item->raw_attributes, 'competitor_raw_attributes.teslahelp_page_url'),
                data_get($item->raw_attributes, 'competitor_raw_attributes.page_url'),
                data_get($item->raw_attributes, 'competitor_raw_attributes.category_source_url'),
                $item->source_url,
            ])
            ->first(fn (mixed $rawUrl): bool => is_string($rawUrl) && Str::startsWith($rawUrl, ['http://', 'https://']));
    }

    public function inventoryCompetitorLocalizedNameUrl(PartCatalogItem $item, string $locale): ?string
    {
        if (! in_array($item->source, [
            'dkparts',
            'driveparts',
            'erazborka',
            'stock-tesla',
            'tcarservice',
            'tsk',
        ], true)) {
            return null;
        }

        $explicitUrl = data_get($item->raw_attributes, 'source_url_'.$locale)
            ?: data_get($item->raw_attributes, 'url_'.$locale);

        if (is_string($explicitUrl) && Str::startsWith($explicitUrl, ['http://', 'https://'])) {
            return $explicitUrl;
        }

        $baseUrl = data_get($item->raw_attributes, 'product_url')
            ?: data_get($item->raw_attributes, 'page_url')
            ?: data_get($item->raw_attributes, 'buy_url')
            ?: $item->source_url;

        if (! is_string($baseUrl) || ! Str::startsWith($baseUrl, ['http://', 'https://'])) {
            return null;
        }

        if (in_array($item->source, ['dkparts', 'driveparts', 'stock-tesla', 'tcarservice', 'tsk'], true)) {
            return $locale === 'ru'
                ? $this->withPathLocale($baseUrl, 'ru')
                : $this->withoutPathLocale($baseUrl, 'ru');
        }

        if ($item->source === 'erazborka') {
            return $locale === 'ua'
                ? $this->withPathLocale($baseUrl, 'ua')
                : $this->withoutPathLocale($baseUrl, 'ua');
        }

        return $baseUrl;
    }

    public function siteFromUrl(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== ''
            ? preg_replace('/^www\./', '', $host)
            : null;
    }

    public function teslaShopUrlFromPartNumber(PartCatalogItem $item): ?string
    {
        if ($item->source !== 'teslahelp') {
            return null;
        }

        $partNumber = data_get($item->raw_attributes, 'base_part_number') ?: $item->part_number;
        $partNumber = is_string($partNumber) ? trim($partNumber) : '';

        if ($partNumber === '') {
            return null;
        }

        $basePartNumber = preg_replace('/-[A-Z0-9]{2,}(?:-[A-Z0-9]+)?$/i', '', $partNumber);

        return 'https://teslashop.ru/auto-parts/mark_tesla?number='.rawurlencode($basePartNumber ?: $partNumber);
    }
}
