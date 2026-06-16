<?php

namespace App\Services;

use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Support\PartCatalogRawAttributes;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PartCatalogTranslationBackfiller
{
    protected const SOURCE_LOCALE_POLICY = [
        'teslapartsukraine' => ['ua'],
        'tcarservice' => ['ru', 'ua'],
        'tsk' => ['mixed'],
        'stock-tesla' => ['ru', 'ua'],
        'driveparts' => ['ru', 'ua'],
        'dkparts' => ['ru', 'ua'],
        'erazborka' => ['ru', 'ua'],
        'toprazborka' => ['ua'],
        'teslawestparts' => ['ua'],
        'teslacompany' => ['ru'],
    ];

    protected const UA_SOURCES = ['teslapartsukraine', 'tcarservice', 'stock-tesla', 'driveparts', 'dkparts', 'erazborka', 'toprazborka', 'teslawestparts', 'tsk'];

    protected const RU_SOURCES = ['teslacompany', 'tcarservice', 'stock-tesla', 'driveparts', 'dkparts', 'erazborka', 'tsk'];

    public function refresh(array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $onlyMissing = (bool) ($options['only_missing'] ?? true);
        $skipCategoryNames = (bool) ($options['skip_category_names'] ?? false);
        $limit = max(0, (int) ($options['limit'] ?? 0));
        $progress = $options['progress'] ?? null;
        $requestedPartNumbers = collect((array) ($options['part_numbers'] ?? []))
            ->map(fn (mixed $value): string => $this->normalizePartNumber((string) $value))
            ->filter()
            ->unique()
            ->values();

        $stats = [
            'official_items_seen' => 0,
            'official_items_updated' => 0,
            'name_ua_updated' => 0,
            'name_ru_updated' => 0,
            'category_name_ua_updated' => 0,
            'category_name_ru_updated' => 0,
            'matched_competitor_items' => 0,
        ];

        $query = PartCatalogItem::query()
            ->with('category')
            ->where('source', 'tesla_official')
            ->whereNotNull('part_number')
            ->where('part_number', '!=', '')
            ->when($requestedPartNumbers->isNotEmpty(), fn ($query) => $query->whereIn(\DB::raw('upper(trim(part_number))'), $requestedPartNumbers->all()))
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $query->chunkById(500, function (Collection $officialItems) use (&$stats, $dryRun, $onlyMissing, $skipCategoryNames, $progress): void {
            $stats['official_items_seen'] += $officialItems->count();

            $partNumbers = $officialItems
                ->pluck('part_number')
                ->map(fn (?string $value): string => $this->normalizePartNumber($value))
                ->filter()
                ->unique()
                ->values();
            $partNumberRoots = $officialItems
                ->pluck('part_number')
                ->map(fn (?string $value): ?string => $this->partNumberRootSearchKey($value))
                ->filter()
                ->unique()
                ->values();

            if ($partNumbers->isEmpty()) {
                return;
            }

            $competitors = PartCatalogItem::query()
                ->whereIn('source', array_merge(self::UA_SOURCES, self::RU_SOURCES))
                ->whereNotNull('part_number')
                ->where(function ($query) use ($partNumbers, $partNumberRoots): void {
                    $query->whereIn(\DB::raw('upper(trim(part_number))'), $partNumbers->all());

                    if ($partNumberRoots->isNotEmpty()) {
                        $query->orWhereIn(\DB::raw('substr(upper(trim(part_number)), 1, 7)'), $partNumberRoots->all());
                    }
                })
                ->get([
                    'id',
                    'source',
                    'part_number',
                    'name',
                    'name_ua',
                    'name_ru',
                    'main_category_name',
                    'subcategory_name',
                    'node_name',
                    'source_url',
                    'raw_attributes',
                ]);

            $byPartNumber = $this->groupByPartNumberSearchKey($competitors);
            $stats['matched_competitor_items'] += $competitors->count();

            $manualNames = app(PartCatalogManualNameService::class);

            foreach ($officialItems as $officialItem) {
                $manualPayload = $manualNames->lockedPayloadForPartNumber($officialItem);
                if ($manualPayload !== []) {
                    $nameUpdates = collect($manualPayload)
                        ->only(['name_ru', 'name_ua'])
                        ->filter(fn (mixed $value, string $column): bool => $officialItem->{$column} !== $value)
                        ->all();

                    if ($nameUpdates !== []) {
                        $officialItem->forceFill($manualPayload);

                        if (! $dryRun) {
                            $officialItem->save();
                            $officialItem->refresh();
                        }

                        $stats['official_items_updated']++;
                        $stats['name_ua_updated'] += array_key_exists('name_ua', $nameUpdates) ? 1 : 0;
                        $stats['name_ru_updated'] += array_key_exists('name_ru', $nameUpdates) ? 1 : 0;
                    }
                }

                $matchGroups = $this->matchGroupsForPartNumber($byPartNumber, $officialItem->part_number);
                if ($this->matchGroupsAreEmpty($matchGroups)) {
                    continue;
                }

                $updates = [];
                $rawAttributes = $this->rawAttributes($officialItem);

                if (! $manualNames->isLocked($officialItem, 'name_ua')
                    && $this->shouldFillCatalogName($officialItem->name_ua)) {
                    $nameUaItem = $this->preferredNameItemFromGroups($matchGroups, self::UA_SOURCES, 'ua');
                    $nameUa = $nameUaItem === null ? null : $this->localizedName($nameUaItem, 'ua');
                    if ($nameUa !== null && $nameUa !== $officialItem->name_ua) {
                        $updates['name_ua'] = $nameUa;
                        $rawAttributes = $this->withNameSource($rawAttributes, $nameUaItem, 'ua');
                    }
                }

                if (! $manualNames->isLocked($officialItem, 'name_ru')
                    && $this->shouldFillCatalogName($officialItem->name_ru)) {
                    $nameRuItem = $this->preferredNameItemFromGroups($matchGroups, self::RU_SOURCES, 'ru');
                    $nameRu = $nameRuItem === null ? null : $this->localizedName($nameRuItem, 'ru');
                    if ($nameRu !== null && $nameRu !== $officialItem->name_ru) {
                        $updates['name_ru'] = $nameRu;
                        $rawAttributes = $this->withNameSource($rawAttributes, $nameRuItem, 'ru');
                    }
                }

                if ($updates !== []) {
                    $updates['raw_attributes'] = $rawAttributes;

                    if (! $dryRun) {
                        $officialItem->forceFill($updates)->save();
                    }

                    $stats['official_items_updated']++;
                    $stats['name_ua_updated'] += array_key_exists('name_ua', $updates) ? 1 : 0;
                    $stats['name_ru_updated'] += array_key_exists('name_ru', $updates) ? 1 : 0;

                    if ($progress !== null) {
                        $progress("#{$officialItem->id} {$officialItem->part_number}: ".implode(', ', array_keys($updates)));
                    }
                }

                if (! $skipCategoryNames) {
                    $this->fillCategoryNames($officialItem, $this->mergeMatchGroups($matchGroups), $stats, $dryRun, $onlyMissing);
                }
            }
        });

        return $stats;
    }

    public function refreshNameSources(array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $limit = max(0, (int) ($options['limit'] ?? 0));
        $progress = $options['progress'] ?? null;
        $requestedPartNumbers = collect((array) ($options['part_numbers'] ?? []))
            ->map(fn (mixed $value): string => $this->normalizePartNumber((string) $value))
            ->filter()
            ->unique()
            ->values();

        $stats = [
            'official_items_seen' => 0,
            'official_items_updated' => 0,
            'name_ua_source_updated' => 0,
            'name_ru_source_updated' => 0,
            'matched_competitor_items' => 0,
        ];

        $query = PartCatalogItem::query()
            ->where('source', 'tesla_official')
            ->whereNotNull('part_number')
            ->where('part_number', '!=', '')
            ->when($requestedPartNumbers->isNotEmpty(), fn ($query) => $query->whereIn(\DB::raw('upper(trim(part_number))'), $requestedPartNumbers->all()))
            ->where(function ($query): void {
                $query
                    ->where(fn ($builder) => $builder->whereNotNull('name_ua')->where('name_ua', '!=', ''))
                    ->orWhere(fn ($builder) => $builder->whereNotNull('name_ru')->where('name_ru', '!=', ''));
            })
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $query->chunkById(500, function (Collection $officialItems) use (&$stats, $dryRun, $progress): void {
            $stats['official_items_seen'] += $officialItems->count();

            $partNumbers = $officialItems
                ->pluck('part_number')
                ->map(fn (?string $value): string => $this->normalizePartNumber($value))
                ->filter()
                ->unique()
                ->values();
            $partNumberRoots = $officialItems
                ->pluck('part_number')
                ->map(fn (?string $value): ?string => $this->partNumberRootSearchKey($value))
                ->filter()
                ->unique()
                ->values();

            if ($partNumbers->isEmpty()) {
                return;
            }

            $competitors = PartCatalogItem::query()
                ->whereIn('source', array_merge(self::UA_SOURCES, self::RU_SOURCES))
                ->whereNotNull('part_number')
                ->where(function ($query) use ($partNumbers, $partNumberRoots): void {
                    $query->whereIn(\DB::raw('upper(trim(part_number))'), $partNumbers->all());

                    if ($partNumberRoots->isNotEmpty()) {
                        $query->orWhereIn(\DB::raw('substr(upper(trim(part_number)), 1, 7)'), $partNumberRoots->all());
                    }
                })
                ->get(['id', 'source', 'part_number', 'name', 'name_ua', 'name_ru', 'source_url', 'raw_attributes']);

            $byPartNumber = $this->groupByPartNumberSearchKey($competitors);
            $stats['matched_competitor_items'] += $competitors->count();

            foreach ($officialItems as $officialItem) {
                $matchGroups = $this->matchGroupsForPartNumber($byPartNumber, $officialItem->part_number);
                if ($this->matchGroupsAreEmpty($matchGroups)) {
                    continue;
                }

                $rawAttributes = $this->rawAttributes($officialItem);
                $updates = [];

                if ($this->filled($officialItem->name_ua) && ! $this->filled($rawAttributes['name_source_url_ua'] ?? null)) {
                    $sourceItem = $this->matchingNameSourceItemFromGroups($matchGroups, self::UA_SOURCES, 'ua', $officialItem->name_ua);
                    if ($sourceItem !== null) {
                        $rawAttributes = $this->withNameSource($rawAttributes, $sourceItem, 'ua');
                        $updates['name_ua_source'] = true;
                    }
                }

                if ($this->filled($officialItem->name_ru) && ! $this->filled($rawAttributes['name_source_url_ru'] ?? null)) {
                    $sourceItem = $this->matchingNameSourceItemFromGroups($matchGroups, self::RU_SOURCES, 'ru', $officialItem->name_ru);
                    if ($sourceItem !== null) {
                        $rawAttributes = $this->withNameSource($rawAttributes, $sourceItem, 'ru');
                        $updates['name_ru_source'] = true;
                    }
                }

                if ($updates === []) {
                    continue;
                }

                if (! $dryRun) {
                    $officialItem->forceFill(['raw_attributes' => $rawAttributes])->save();
                }

                $stats['official_items_updated']++;
                $stats['name_ua_source_updated'] += array_key_exists('name_ua_source', $updates) ? 1 : 0;
                $stats['name_ru_source_updated'] += array_key_exists('name_ru_source', $updates) ? 1 : 0;

                if ($progress !== null) {
                    $progress("#{$officialItem->id} {$officialItem->part_number}: ".implode(', ', array_keys($updates)));
                }
            }
        });

        return $stats;
    }

    protected function preferredName(Collection $matches, array $sources, string $locale): ?string
    {
        $item = $this->preferredNameItem($matches, $sources, $locale);

        return $item === null ? null : $this->localizedName($item, $locale);
    }

    protected function shouldFillCatalogName(?string $currentName): bool
    {
        return ! $this->filled($currentName)
            || ! $this->looksCyrillic((string) $currentName);
    }

    protected function preferredNameItemFromGroups(array $matchGroups, array $sources, string $locale): ?PartCatalogItem
    {
        foreach ($matchGroups as $matches) {
            $item = $this->preferredNameItem($matches, $sources, $locale);

            if ($item !== null) {
                return $item;
            }
        }

        return null;
    }

    protected function preferredNameItem(Collection $matches, array $sources, string $locale): ?PartCatalogItem
    {
        foreach ($sources as $source) {
            $candidate = $matches
                ->where('source', $source)
                ->filter(fn (PartCatalogItem $item): bool => $this->isUsableName($this->localizedName($item, $locale)))
                ->sortBy(fn (PartCatalogItem $item): string => collect([
                    $this->localizedNameCompletenessRank($this->localizedName($item, $locale)),
                    str_pad((string) $item->id, 10, '0', STR_PAD_LEFT),
                ])->implode('|'))
                ->first();

            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    protected function localizedName(PartCatalogItem $item, string $locale): ?string
    {
        if (! $this->sourceSupportsLocale((string) $item->source, $locale)) {
            return null;
        }

        if ($locale === 'ua') {
            if ($this->filled($item->name_ua)) {
                $name = $item->name_ua;
            } elseif ($this->sourceIsMixedLanguage((string) $item->source)) {
                $name = $this->mixedSourceLocalizedName($item, 'ua');
            } else {
                return null;
            }

            if ($this->isRussianFallbackUkrainianName($item, (string) $name)) {
                return null;
            }
        } else {
            $name = $this->filled($item->name_ru) ? $item->name_ru : ($this->sourceIsMixedLanguage((string) $item->source) ? $item->name : null);

            if ($this->sourceIsMixedLanguage((string) $item->source) && $this->looksUkrainian((string) $name)) {
                return null;
            }
        }

        return $this->isUsableName($name) ? Str::limit((string) $name, 255, '') : null;
    }

    protected function isRussianFallbackUkrainianName(PartCatalogItem $item, string $name): bool
    {
        $normalizedName = $this->withoutAutoTranslatedSuffix($name);

        return $normalizedName !== $this->normalizeNameForCompare($name)
            && collect([$item->name_ru, $item->name])
                ->map(fn (mixed $value): string => $this->normalizeNameForCompare((string) $value))
                ->filter()
                ->contains($normalizedName);
    }

    protected function withoutAutoTranslatedSuffix(string $name): string
    {
        $normalizedName = $this->normalizeNameForCompare($name);
        $autoTranslatedSuffix = pack('H*', '2028d0b0d0b2d182d0bed0bfd0b5d180d0b5d0b2d0bed0b429');

        return Str::endsWith($normalizedName, $autoTranslatedSuffix)
            ? trim(Str::beforeLast($normalizedName, $autoTranslatedSuffix))
            : $normalizedName;
    }

    protected function normalizeNameForCompare(string $name): string
    {
        return Str::lower(trim($name));
    }

    protected function sourceSupportsLocale(string $source, string $locale): bool
    {
        $policy = self::SOURCE_LOCALE_POLICY[$source] ?? [];

        return in_array($locale, $policy, true) || in_array('mixed', $policy, true);
    }

    protected function sourceIsMixedLanguage(string $source): bool
    {
        return in_array('mixed', self::SOURCE_LOCALE_POLICY[$source] ?? [], true);
    }

    protected function mixedSourceLocalizedName(PartCatalogItem $item, string $locale): ?string
    {
        $name = $this->filled($item->name_ua)
            ? $item->name_ua
            : ($this->filled($item->name) ? $item->name : $item->name_ru);

        if ($locale === 'ua') {
            return $this->looksUkrainian((string) $name) ? $name : null;
        }

        return $this->looksUkrainian((string) $name) ? null : $name;
    }

    protected function looksUkrainian(string $name): bool
    {
        $name = Str::lower($name);

        return preg_match('/[іїєґ]/u', $name) === 1
            || preg_match('/\b(передн[іія]|задн[іія]|лів[иі]й|прав[иі]й|кр[іи]плення|важел[ья]|п[іи]дв[іи]ск|скло|двер[еі]й|гальм|керм|дзеркал|датчик рівня|накладка)\b/u', $name) === 1;
    }

    protected function looksCyrillic(string $name): bool
    {
        return preg_match('/\p{Cyrillic}/u', $name) === 1;
    }

    protected function localizedNameCompletenessRank(?string $name): string
    {
        $name = trim((string) $name);

        return str_pad((string) max(0, 1000 - mb_strlen($name)), 4, '0', STR_PAD_LEFT);
    }

    protected function withNameSource(array $rawAttributes, ?PartCatalogItem $sourceItem, string $locale): array
    {
        if ($sourceItem === null) {
            return $rawAttributes;
        }

        $sourceUrl = $this->displayableSourceUrl($sourceItem);

        if (! $this->filled($sourceUrl)) {
            return $rawAttributes;
        }

        $host = parse_url((string) $sourceUrl, PHP_URL_HOST);
        $site = is_string($host) && $host !== ''
            ? preg_replace('/^www\./', '', $host)
            : $sourceItem->source;

        $rawAttributes['name_source_site'] = $site;
        $rawAttributes['name_source_site_'.$locale] = $site;
        $rawAttributes['name_source_url'] = $sourceUrl;
        $rawAttributes['name_source_url_'.$locale] = $sourceUrl;
        $rawAttributes['name_source_item_id_'.$locale] = $sourceItem->id;

        return $rawAttributes;
    }

    protected function displayableSourceUrl(PartCatalogItem $sourceItem): ?string
    {
        $sourceUrl = (string) ($sourceItem->source_url ?? '');

        if (Str::startsWith($sourceUrl, ['http://', 'https://'])) {
            return $sourceUrl;
        }

        $pageUrl = data_get($sourceItem->raw_attributes, 'teslashop_url')
            ?: data_get($sourceItem->raw_attributes, 'product_url')
            ?: data_get($sourceItem->raw_attributes, 'teslahelp_page_url')
            ?: data_get($sourceItem->raw_attributes, 'competitor_raw_attributes.product_url')
            ?: data_get($sourceItem->raw_attributes, 'competitor_raw_attributes.page_url')
            ?: data_get($sourceItem->raw_attributes, 'page_url')
            ?: $this->teslaShopUrlFromPartNumber($sourceItem)
            ?: data_get($sourceItem->raw_attributes, 'schematic_source_url')
            ?: data_get($sourceItem->raw_attributes, 'category_source_url');

        return is_string($pageUrl) && Str::startsWith($pageUrl, ['http://', 'https://'])
            ? $pageUrl
            : null;
    }

    protected function teslaShopUrlFromPartNumber(PartCatalogItem $sourceItem): ?string
    {
        if ($sourceItem->source !== 'teslahelp') {
            return null;
        }

        $partNumber = data_get($sourceItem->raw_attributes, 'base_part_number') ?: $sourceItem->part_number;
        $partNumber = is_string($partNumber) ? trim($partNumber) : '';

        if ($partNumber === '') {
            return null;
        }

        $basePartNumber = preg_replace('/-[A-Z0-9]{2,}(?:-[A-Z0-9]+)?$/i', '', $partNumber);

        return 'https://teslashop.ru/auto-parts/mark_tesla?number='.rawurlencode($basePartNumber ?: $partNumber);
    }

    protected function matchingNameSourceItemFromGroups(array $matchGroups, array $sources, string $locale, ?string $name): ?PartCatalogItem
    {
        foreach ($matchGroups as $matches) {
            $item = $this->matchingNameSourceItem($matches, $sources, $locale, $name);

            if ($item !== null) {
                return $item;
            }
        }

        return null;
    }

    protected function matchingNameSourceItem(Collection $matches, array $sources, string $locale, ?string $name): ?PartCatalogItem
    {
        $needle = $this->normalizeName($name);
        if ($needle === '') {
            return null;
        }

        foreach ($sources as $source) {
            $candidate = $matches
                ->where('source', $source)
                ->first(fn (PartCatalogItem $item): bool => $this->normalizeName($this->localizedName($item, $locale)) === $needle);

            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    protected function normalizeName(?string $name): string
    {
        return Str::lower(trim((string) preg_replace('/\s+/u', ' ', (string) $name)));
    }

    protected function rawAttributes(PartCatalogItem $item): array
    {
        return PartCatalogRawAttributes::from($item);
    }

    protected function fillCategoryNames(PartCatalogItem $officialItem, Collection $matches, array &$stats, bool $dryRun, bool $onlyMissing): void
    {
        if ($officialItem->category === null) {
            return;
        }

        foreach ($this->categoryTrail($officialItem->category) as $category) {
            $depth = (int) $category->depth;

            if ($depth <= 0) {
                continue;
            }

            $updates = [];

            if (! $onlyMissing || ! $this->filled($category->name_ua)) {
                $nameUa = $this->preferredCategoryName($matches, self::UA_SOURCES, $depth, 'ua');
                if ($nameUa !== null && $nameUa !== $category->name_ua) {
                    $updates['name_ua'] = $nameUa;
                }
            }

            if (! $onlyMissing || ! $this->filled($category->name_ru)) {
                $nameRu = $this->preferredCategoryName($matches, self::RU_SOURCES, $depth, 'ru');
                if ($nameRu !== null && $nameRu !== $category->name_ru) {
                    $updates['name_ru'] = $nameRu;
                }
            }

            if ($updates === []) {
                continue;
            }

            if (! $dryRun) {
                $category->forceFill($updates)->save();
            }

            $stats['category_name_ua_updated'] += array_key_exists('name_ua', $updates) ? 1 : 0;
            $stats['category_name_ru_updated'] += array_key_exists('name_ru', $updates) ? 1 : 0;
        }
    }

    protected function preferredCategoryName(Collection $matches, array $sources, int $depth, string $locale): ?string
    {
        $column = match ($depth) {
            1 => 'main_category_name',
            2 => 'subcategory_name',
            default => 'node_name',
        };

        foreach ($sources as $source) {
            $candidate = $matches
                ->where('source', $source)
                ->map(fn (PartCatalogItem $item): ?string => $item->{$column})
                ->filter(fn (?string $name) => $this->isUsableLocalizedNameForSource($name, $source, $locale))
                ->sortBy(fn (string $name): int => mb_strlen($name))
                ->first();

            if ($candidate !== null) {
                return Str::limit($candidate, 255, '');
            }
        }

        return null;
    }

    protected function isUsableLocalizedNameForSource(?string $name, string $source, string $locale): bool
    {
        if (! $this->sourceSupportsLocale($source, $locale) || ! $this->isUsableName($name)) {
            return false;
        }

        if (! $this->sourceIsMixedLanguage($source)) {
            return true;
        }

        return $locale === 'ua'
            ? $this->looksUkrainian((string) $name)
            : ! $this->looksUkrainian((string) $name);
    }

    protected function categoryTrail(PartCatalogCategory $category): Collection
    {
        $trail = collect();

        while ($category) {
            $trail->prepend($category);

            $category = $category->parent_id
                ? PartCatalogCategory::query()->find($category->parent_id)
                : null;
        }

        return $trail->values();
    }

    protected function isUsableName(?string $name): bool
    {
        $name = trim((string) $name);

        if ($name === ''
            || $this->isGenericCatalogName($name)
            || preg_match('/^[A-Z0-9.\-]+$/i', $name) === 1
            || preg_match('/^\d+$/', $name) === 1) {
            return false;
        }

        if (in_array(Str::lower($name), [
            'цена (по убыванию)',
            'цена (по возрастанию)',
            'топ продаж',
            'новинки',
        ], true)) {
            return false;
        }

        return preg_match('/[А-Яа-яІіЇїЄєҐґ]/u', $name) === 1;
    }

    protected function isGenericCatalogName(string $name): bool
    {
        $normalizedName = $this->withoutAutoTranslatedSuffix($name);

        if (in_array($normalizedName, [
            pack('H*', 'd0bad183d0b7d0bed0b2'),
            pack('H*', 'd180d0b0d0bcd0b0'),
            pack('H*', 'd0bfd180d0bed0b2d0bed0b4d0bad0b0'),
            pack('H*', 'd0bad183d0b7d0bed0b2d0bdd18bd0b520d0b7d0b0d0bfd187d0b0d181d182d0b8'),
            pack('H*', 'd0bad183d0b7d0bed0b2d0bdd19620d0b7d0b0d0bfd187d0b0d181d182d0b8d0bdd0b8'),
            pack('H*', 'd181d0b0d0bbd0bed0bd'),
            pack('H*', 'd18dd0bbd0b5d0bad182d180d0b8d0bad0b0'),
            pack('H*', 'd0b5d0bbd0b5d0bad182d180d0b8d0bad0b0'),
            pack('H*', 'd185d0bed0b4d0bed0b2d0b0d18f'),
            pack('H*', 'd0bfd196d0b4d0b2d196d181d0bad0b0'),
            pack('H*', 'd0bfd0bed0b4d0b2d0b5d181d0bad0b0'),
        ], true)) {
            return true;
        }

        return in_array($normalizedName, [
            'кузов',
            'кузовные запчасти',
            'кузовні запчастини',
            'салон',
            'электрика',
            'електрика',
            'ходовая',
            'підвіска',
            'подвеска',
        ], true);
    }

    protected function filled(?string $value): bool
    {
        return trim((string) $value) !== '';
    }

    protected function normalizePartNumber(?string $value): string
    {
        return Str::upper(trim((string) $value));
    }

    protected function partNumberSearchKeys(?string $value): array
    {
        $partNumber = $this->normalizePartNumber($value);

        if ($partNumber === '') {
            return [];
        }

        return collect([
            $partNumber,
            $this->partNumberBaseSearchKey($partNumber),
            $this->partNumberRootSearchKey($partNumber),
        ])->filter()->unique()->values()->all();
    }

    protected function partNumberBaseSearchKey(?string $value): ?string
    {
        $partNumber = $this->normalizePartNumber($value);

        if (preg_match('/^(.+)-[A-Z0-9]$/', $partNumber, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    protected function partNumberRootSearchKey(?string $value): ?string
    {
        $partNumber = $this->normalizePartNumber($value);

        if (preg_match('/^([0-9]{7})/', $partNumber, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    protected function groupByPartNumberSearchKey(Collection $items): array
    {
        $groups = [];

        foreach ($items as $item) {
            foreach ($this->partNumberSearchKeys($item->part_number) as $key) {
                $groups[$key] ??= collect();
                $groups[$key]->push($item);
            }
        }

        return $groups;
    }

    protected function matchGroupsForPartNumber(array $byPartNumber, ?string $partNumber): array
    {
        return collect($this->partNumberSearchKeys($partNumber))
            ->map(fn (string $key): Collection => $byPartNumber[$key] ?? collect())
            ->values()
            ->all();
    }

    protected function matchGroupsAreEmpty(array $matchGroups): bool
    {
        return collect($matchGroups)->every(fn (Collection $matches): bool => $matches->isEmpty());
    }

    protected function mergeMatchGroups(array $matchGroups): Collection
    {
        return collect($matchGroups)
            ->flatMap(fn (Collection $matches): array => $matches->all())
            ->unique('id')
            ->values();
    }
}
