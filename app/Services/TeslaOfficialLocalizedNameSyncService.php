<?php

namespace App\Services;

use App\Models\PartCatalogItem;
use App\Support\PartCatalogRawAttributes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class TeslaOfficialLocalizedNameSyncService
{
    protected const OFFICIAL_SOURCE = 'tesla_official';

    protected const SOURCE_ORDER = [
        'tcarservice' => [
            'label' => 'TCARS',
            'site' => 'tcarservice.com',
            'locales' => ['ru', 'ua'],
        ],
        'teslapartsukraine' => [
            'label' => 'TeslaPartsUkraine',
            'site' => 'teslapartsukraine.com',
            'locales' => ['ru', 'ua'],
        ],
        'erazborka' => [
            'label' => 'Erazborka',
            'site' => 'erazborka.com',
            'locales' => ['ru', 'ua'],
        ],
        'dkparts' => [
            'label' => 'DK-Parts',
            'site' => 'dk-parts.com.ua',
            'locales' => ['ru', 'ua'],
        ],
        'teslawestparts' => [
            'label' => 'Tesla West Parts',
            'site' => 'teslawestparts.com.ua',
            'locales' => ['ru', 'ua'],
        ],
        'driveparts' => [
            'label' => 'DriveParts',
            'site' => 'drive-parts.com.ua',
            'locales' => ['ru', 'ua'],
        ],
        'stock-tesla' => [
            'label' => 'Stock Tesla',
            'site' => 'stock-tesla.com',
            'locales' => ['ru', 'ua'],
        ],
        'teslacompany' => [
            'label' => 'TeslaCompany',
            'site' => 'teslacompany.com.ua',
            'locales' => ['ru'],
        ],
        'tsk' => [
            'label' => 'TSK',
            'site' => 'tsk.ua',
            'locales' => ['ru', 'ua'],
        ],
    ];

    public function __construct(protected PartCatalogManualNameService $manualNames) {}

    public function syncAfterItemSaved(PartCatalogItem $item, bool $created): array
    {
        if ($item->source === self::OFFICIAL_SOURCE) {
            if (! $created) {
                return $this->emptyStats();
            }

            return $this->syncOfficialItem($item);
        }

        return $this->emptyStats();
    }

    public function syncOfficialItem(PartCatalogItem $officialItem): array
    {
        $stats = $this->emptyStats();
        $partNumber = $this->normalizedPartNumber($officialItem->part_number);

        if ($officialItem->source !== self::OFFICIAL_SOURCE || $partNumber === '') {
            return $stats;
        }

        $updates = [];
        $rawAttributes = $this->rawAttributes($officialItem);
        $localizedNameUpdates = [];

        foreach (['name_ru' => 'ru', 'name_ua' => 'ua'] as $column => $locale) {
            if ($this->manualNames->isLocked($officialItem, $column)) {
                $stats['manual_locked_skipped']++;

                continue;
            }

            if (! $this->canReplaceName($officialItem, $column, $locale)) {
                continue;
            }

            $sourceItem = $this->preferredSourceItem($partNumber, $locale);
            if (! $sourceItem instanceof PartCatalogItem) {
                continue;
            }

            $name = $this->localizedName($sourceItem, $locale);
            if ($name === null || $name === (string) $officialItem->{$column}) {
                continue;
            }

            $updates[$column] = $name;
            $localizedNameUpdates[$column] = $name;
            $rawAttributes = $this->withNameSource($rawAttributes, $sourceItem, $locale);
            $stats[$column.'_updated']++;
        }

        foreach (['name_ru' => 'ru', 'name_ua' => 'ua'] as $column => $locale) {
            if (array_key_exists($column, $updates)
                || $this->manualNames->isLocked($officialItem, $column)
                || ! $this->canReplaceName($officialItem, $column, $locale)) {
                continue;
            }

            [$sourceText, $sourceLocale, $sourceMeta] = $this->translationSource($officialItem, $locale, $updates + $localizedNameUpdates);
            $name = $this->googleTranslate($sourceText, $locale, $sourceLocale);
            if ($name === null || $name === (string) $officialItem->{$column}) {
                continue;
            }

            $updates[$column] = $name;
            $rawAttributes = $this->withGoogleNameSource($rawAttributes, $locale, $sourceMeta);
            $stats[$column.'_updated']++;
        }

        if ($updates === []) {
            return $stats;
        }

        $officialItem->forceFill($updates + [
            'raw_attributes' => $rawAttributes,
        ])->saveQuietly();

        $stats['official_items_updated'] = 1;

        return $stats;
    }

    public function syncForCompetitorItem(PartCatalogItem $competitorItem): array
    {
        $stats = $this->emptyStats();
        $baseNumber = $this->basePartNumber($competitorItem->part_number);

        if (! $this->isCompetitorSource((string) $competitorItem->source) || $baseNumber === '') {
            return $stats;
        }

        $officialItems = PartCatalogItem::query()
            ->where('source', self::OFFICIAL_SOURCE)
            ->whereNotNull('part_number')
            ->whereRaw($this->basePartNumberSql('part_number').' = ?', [$baseNumber])
            ->get($this->officialSelectColumns());

        foreach ($officialItems as $officialItem) {
            $itemStats = $this->syncOfficialItem($officialItem);

            foreach ($stats as $key => $value) {
                $stats[$key] = $value + ($itemStats[$key] ?? 0);
            }
        }

        return $stats;
    }

    public function isCompetitorSource(string $source): bool
    {
        return isset(self::SOURCE_ORDER[$source]);
    }

    public function sourceOrder(): array
    {
        return array_keys(self::SOURCE_ORDER);
    }

    protected function preferredSourceItem(string $partNumber, string $locale): ?PartCatalogItem
    {
        $matchQueries = [$partNumber];
        $baseNumber = $this->basePartNumber($partNumber);

        if ($this->isTeslaPartNumber($partNumber) && $baseNumber !== '' && $baseNumber !== $partNumber) {
            $matchQueries[] = $baseNumber;
        }

        foreach ($matchQueries as $index => $matchQuery) {
            $item = $this->preferredSourceItemForMatch($matchQuery, $locale, $index > 0);
            if ($item instanceof PartCatalogItem) {
                return $item;
            }
        }

        return null;
    }

    protected function preferredSourceItemForMatch(string $matchQuery, string $locale, bool $baseMatch): ?PartCatalogItem
    {
        foreach (self::SOURCE_ORDER as $source => $config) {
            if (! in_array($locale, $config['locales'], true)) {
                continue;
            }

            $item = PartCatalogItem::query()
                ->where('source', $source)
                ->whereNotNull('part_number')
                ->whereRaw($baseMatch ? $this->basePartNumberSql('part_number').' = ?' : 'upper(trim(part_number)) = ?', [$matchQuery])
                ->where(fn (Builder $query): Builder => $this->whereUsableLocalizedName($query, $locale === 'ru' ? 'name_ru' : 'name_ua'))
                ->orderBy('id')
                ->get(['id', 'source', 'source_url', 'part_number', 'name', 'name_en', 'name_ru', 'name_ua', 'raw_attributes'])
                ->first(fn (PartCatalogItem $item): bool => $this->localizedName($item, $locale) !== null);

            if ($item instanceof PartCatalogItem) {
                return $item;
            }
        }

        return null;
    }

    protected function whereUsableLocalizedName(Builder $query, string $column): Builder
    {
        return $query
            ->whereNotNull($column)
            ->where($column, '!=', '');
    }

    protected function localizedName(PartCatalogItem $item, string $locale): ?string
    {
        $config = self::SOURCE_ORDER[$item->source] ?? null;
        if ($config === null || ! in_array($locale, $config['locales'], true)) {
            return null;
        }

        $column = $locale === 'ru' ? 'name_ru' : 'name_ua';
        $name = $item->{$column};

        if ($locale === 'ua' && $this->isRussianFallbackUkrainianName($item, (string) $name)) {
            return null;
        }

        if ($locale === 'ru' && $item->source === 'tsk' && $this->looksUkrainian((string) $name)) {
            return null;
        }

        return $this->isUsableLocalizedName($name) ? Str::limit((string) $name, 255, '') : null;
    }

    protected function canReplaceName(PartCatalogItem $officialItem, string $column, string $locale): bool
    {
        if (trim((string) $officialItem->{$column}) === '') {
            return true;
        }

        $rawAttributes = $this->rawAttributes($officialItem);
        $sourceType = data_get($rawAttributes, 'name_source_type_'.$locale);

        return is_string($sourceType) && in_array($sourceType, $this->autoSourceTypes(), true);
    }

    protected function withNameSource(array $rawAttributes, PartCatalogItem $sourceItem, string $locale): array
    {
        $sourceUrl = $this->displayableSourceUrl($sourceItem, $locale);
        $config = self::SOURCE_ORDER[$sourceItem->source];

        $rawAttributes['name_source_site'] = $config['site'];
        $rawAttributes['name_source_site_'.$locale] = $config['site'];
        $rawAttributes['name_source_url'] = $sourceUrl;
        $rawAttributes['name_source_url_'.$locale] = $sourceUrl;
        $rawAttributes['name_source_item_id_'.$locale] = $sourceItem->id;
        $rawAttributes['name_source_type_'.$locale] = $this->sourceType((string) $sourceItem->source);

        return $rawAttributes;
    }

    protected function displayableSourceUrl(PartCatalogItem $item, string $locale): ?string
    {
        $localizedUrl = data_get($item->raw_attributes, 'source_url_'.$locale);
        if (is_string($localizedUrl) && Str::startsWith($localizedUrl, ['http://', 'https://'])) {
            return $localizedUrl;
        }

        $sourceUrl = (string) ($item->source_url ?? '');
        if (Str::startsWith($sourceUrl, ['http://', 'https://'])) {
            return $sourceUrl;
        }

        $pageUrl = data_get($item->raw_attributes, 'product_url')
            ?: data_get($item->raw_attributes, 'page_url');

        return is_string($pageUrl) && Str::startsWith($pageUrl, ['http://', 'https://'])
            ? $pageUrl
            : null;
    }

    protected function sourceType(string $source): string
    {
        return match ($source) {
            'tcarservice' => 'tesla_official_tcars_base_part_match',
            'stock-tesla' => 'tesla_official_stock_tesla_base_part_match',
            default => 'tesla_official_'.$source.'_base_part_match',
        };
    }

    protected function autoSourceTypes(): array
    {
        $types = array_map(
            fn (string $source): string => $this->sourceType($source),
            array_keys(self::SOURCE_ORDER)
        );

        $types[] = 'tesla_official_toprazborka_base_part_match';

        return $types;
    }

    protected function officialSelectColumns(): array
    {
        return array_values(array_filter([
            'id',
            'source',
            'source_url',
            'part_number',
            'name_ru',
            'name_ua',
            'raw_attributes',
            Schema::hasColumn('part_catalog_items', 'name_ru_manually_locked_at') ? 'name_ru_manually_locked_at' : null,
            Schema::hasColumn('part_catalog_items', 'name_ua_manually_locked_at') ? 'name_ua_manually_locked_at' : null,
        ]));
    }

    protected function basePartNumber(?string $partNumber): string
    {
        $partNumber = $this->normalizedPartNumber($partNumber);

        return trim(Str::before($partNumber, '-'));
    }

    protected function normalizedPartNumber(?string $partNumber): string
    {
        $partNumber = Str::upper(trim((string) $partNumber));

        return preg_replace('/\s+/', '', $partNumber) ?? '';
    }

    protected function isTeslaPartNumber(?string $partNumber): bool
    {
        return preg_match('/^\d{7}-[A-Z0-9]{2}-[A-Z0-9]$/i', $this->normalizedPartNumber($partNumber)) === 1;
    }

    protected function translationSource(PartCatalogItem $officialItem, string $targetLocale, array $localizedNameUpdates): array
    {
        $oppositeColumn = $targetLocale === 'ru' ? 'name_ua' : 'name_ru';
        $oppositeLocale = $targetLocale === 'ru' ? 'ua' : 'ru';

        $oppositeName = trim((string) ($localizedNameUpdates[$oppositeColumn] ?? $officialItem->{$oppositeColumn}));
        if ($this->isUsableLocalizedName($oppositeName)
            && ! $this->isGoogleTranslatedName($officialItem, $oppositeLocale)
            && ! $this->hasBadLocalizedNameSource($officialItem, $oppositeLocale)) {
            return [$oppositeName, $oppositeLocale, [
                'locale' => $oppositeLocale,
                'site' => data_get($this->rawAttributes($officialItem), 'name_source_site_'.$oppositeLocale),
                'url' => data_get($this->rawAttributes($officialItem), 'name_source_url_'.$oppositeLocale),
                'item_id' => data_get($this->rawAttributes($officialItem), 'name_source_item_id_'.$oppositeLocale),
            ]];
        }

        $sourceText = collect([$officialItem->name_en, $officialItem->name])
            ->map(fn (mixed $value): string => trim((string) $value))
            ->first(fn (string $value): bool => $this->isUsableMachineTranslationSource($value));

        return [$sourceText, $this->looksEnglishSourceText($sourceText) ? 'en' : null, [
            'locale' => $this->looksEnglishSourceText($sourceText) ? 'en' : null,
            'site' => 'Tesla official',
            'url' => $officialItem->source_url,
            'item_id' => $officialItem->id,
        ]];
    }

    protected function googleTranslate(?string $text, string $locale, ?string $sourceLocale = null): ?string
    {
        $text = trim((string) $text);
        $key = trim((string) config('services.google_translate.key'));

        if ($text === '' || $key === '') {
            return null;
        }

        if (app()->environment('testing') && ! (bool) config('services.google_translate.allow_in_testing', false)) {
            return null;
        }

        try {
            $payload = [
                'key' => $key,
                'q' => $text,
                'target' => $locale === 'ua' ? 'uk' : $locale,
                'format' => 'text',
            ];

            if ($sourceLocale !== null) {
                $payload['source'] = $sourceLocale === 'ua' ? 'uk' : $sourceLocale;
            }

            $response = Http::timeout((int) config('services.google_translate.timeout', 5))
                ->asForm()
                ->post('https://translation.googleapis.com/language/translate/v2', $payload);

            if (! $response->successful()) {
                return null;
            }

            $translated = data_get($response->json(), 'data.translations.0.translatedText');
        } catch (Throwable) {
            return null;
        }

        if (! is_string($translated)) {
            return null;
        }

        $translated = html_entity_decode(trim($translated), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $this->isUsableLocalizedName($translated) ? Str::limit($translated, 255, '') : null;
    }

    protected function withGoogleNameSource(array $rawAttributes, string $locale, array $sourceMeta): array
    {
        unset($rawAttributes['name_source_item_id_'.$locale], $rawAttributes['name_source_marker_'.$locale]);

        $rawAttributes['name_source_site_'.$locale] = 'Google Translate';
        $rawAttributes['name_source_url_'.$locale] = 'https://cloud.google.com/translate';
        $rawAttributes['name_source_type_'.$locale] = 'tesla_official_google_translate';
        $rawAttributes['google_translation_source_locale_'.$locale] = $sourceMeta['locale'] ?? null;
        $rawAttributes['google_translation_source_site_'.$locale] = $sourceMeta['site'] ?? null;
        $rawAttributes['google_translation_source_url_'.$locale] = $sourceMeta['url'] ?? null;
        $rawAttributes['google_translation_source_item_id_'.$locale] = $sourceMeta['item_id'] ?? null;

        if ($locale === 'ru') {
            $rawAttributes['name_source_site'] = 'Google Translate';
            $rawAttributes['name_source_url'] = 'https://cloud.google.com/translate';
        }

        return array_filter($rawAttributes, fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    protected function isGoogleTranslatedName(PartCatalogItem $item, string $locale): bool
    {
        $rawAttributes = $this->rawAttributes($item);
        $site = data_get($rawAttributes, 'name_source_site_'.$locale)
            ?: ($locale === 'ru' ? data_get($rawAttributes, 'name_source_site') : null);
        $type = data_get($rawAttributes, 'name_source_type_'.$locale);

        return Str::lower(trim((string) $site)) === 'google translate'
            || str_contains(Str::lower(trim((string) $type)), 'google_translate');
    }

    protected function hasBadLocalizedNameSource(PartCatalogItem $item, string $locale): bool
    {
        $rawAttributes = $this->rawAttributes($item);
        $site = Str::lower(trim((string) data_get($rawAttributes, 'name_source_site_'.$locale)));
        $url = (string) data_get($rawAttributes, 'name_source_url_'.$locale);
        $host = Str::lower((string) parse_url($url, PHP_URL_HOST));

        return $site === 'teslahelp'
            || str_contains($site, 'teslashop')
            || in_array($host, ['teslahelp.ru', 'www.teslahelp.ru', 'teslashop.ru', 'www.teslashop.ru'], true);
    }

    protected function looksEnglishSourceText(?string $sourceText): bool
    {
        $sourceText = trim((string) $sourceText);

        return $sourceText !== ''
            && preg_match('/[A-Za-z]/', $sourceText) === 1
            && preg_match('/\p{Cyrillic}/u', $sourceText) !== 1;
    }

    protected function isUsableMachineTranslationSource(?string $text): bool
    {
        $text = trim((string) $text);

        return $text !== ''
            && preg_match('/^\d+$/', $text) !== 1
            && preg_match('/^\d{6,8}(?:[-\s]?[A-Z0-9]{1,3}){0,3}$/i', $text) !== 1;
    }

    protected function basePartNumberSql(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "case when instr(upper(trim({$column})), '-') > 0 then substr(upper(trim({$column})), 1, instr(upper(trim({$column})), '-') - 1) else upper(trim({$column})) end",
            'pgsql' => "split_part(upper(trim({$column})), '-', 1)",
            default => "substring_index(upper(trim({$column})), '-', 1)",
        };
    }

    protected function isUsableLocalizedName(?string $name): bool
    {
        $name = trim((string) $name);

        if ($name === ''
            || $this->hasAutoTranslatedSuffix($name)
            || $this->isGenericCatalogName($name)
            || $this->isSortingLabel($name)) {
            return false;
        }

        return $name !== ''
            && preg_match('/\p{Cyrillic}/u', $name) === 1
            && preg_match('/^[A-Z0-9.\-\s]+$/i', $name) !== 1;
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

    protected function hasAutoTranslatedSuffix(string $name): bool
    {
        return $this->withoutAutoTranslatedSuffix($name) !== $this->normalizeNameForCompare($name);
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

    protected function isGenericCatalogName(string $name): bool
    {
        $normalizedName = $this->withoutAutoTranslatedSuffix($name);

        return in_array($normalizedName, [
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
        ], true);
    }

    protected function isSortingLabel(string $name): bool
    {
        return in_array($this->normalizeNameForCompare($name), [
            pack('H*', 'd186d0b5d0bdd0b02028d0bfd0be20d183d0b1d18bd0b2d0b0d0bdd0b8d18e29'),
            pack('H*', 'd186d0b5d0bdd0b02028d0bfd0be20d0b2d0bed0b7d180d0b0d181d182d0b0d0bdd0b8d18e29'),
            pack('H*', 'd182d0bed0bf20d0bfd180d0bed0b4d0b0d0b6'),
            pack('H*', 'd0bdd0bed0b2d0b8d0bdd0bad0b8'),
        ], true);
    }

    protected function looksUkrainian(string $name): bool
    {
        $name = Str::lower($name);

        return preg_match('/[\x{0456}\x{0457}\x{0454}\x{0491}]/u', $name) === 1;
    }

    protected function rawAttributes(PartCatalogItem $item): array
    {
        return PartCatalogRawAttributes::from($item);
    }

    protected function emptyStats(): array
    {
        return [
            'official_items_updated' => 0,
            'name_ru_updated' => 0,
            'name_ua_updated' => 0,
            'manual_locked_skipped' => 0,
        ];
    }
}
