<?php

namespace App\View\Admin\PartCatalog;

use App\Models\PartCatalogItem;
use App\Services\NikolaCarsInventoryService;
use App\Services\NikolaCarsTeslaCategoryResolver;
use App\Support\PartCatalogLanguageMarkerConflict;
use App\Support\PartCatalogLanguageMarkers;
use App\Support\PartCatalogRawAttributes;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PartCatalogIndexPresenter
{
    protected ?Collection $activeLanguageMarkers = null;

    public function __construct(
        protected PartCatalogImagePresenter $imagePresenter,
        protected NikolaCarsInventoryService $nikolaCarsInventoryService,
    ) {}

    public function refreshSourceLabel(array $catalog): string
    {
        $labels = [
            'tcarservice' => 'TCARS',
            'teslapartsukraine' => 'TeslaPartsUkraine',
            'tsk' => 'TSK',
            'stock-tesla' => 'Stock Tesla',
            'teslahelp' => 'TeslaHelp',
            'driveparts' => 'DriveParts',
            'dkparts' => 'DK-Parts',
            'erazborka' => 'Erazborka',
            'teslawestparts' => 'Tesla West Parts',
            'teslacompany' => 'TeslaCompany',
        ];

        return $labels[$catalog['source']] ?? $catalog['source'];
    }

    public function canManageCompetitorRefresh(): bool
    {
        return auth()->user()?->hasPermission('competitor_refresh.manage') ?? false;
    }

    public function competitorRefreshStartUrl(array $catalog): string
    {
        return route('admin.part-catalog.source-competitor-refresh.start', ['source' => $catalog['source']]);
    }

    public function scriptConfig(): array
    {
        return [
            'csrfToken' => csrf_token(),
            'clientSearchUrl' => route('admin.customer-orders.clients.search'),
            'novaPoshtaCitiesUrl' => route('admin.customer-orders.nova-poshta.cities'),
            'novaPoshtaWarehousesUrl' => route('admin.customer-orders.nova-poshta.warehouses'),
            'createOrderUrl' => route('admin.customer-orders.store'),
        ];
    }

    public function imageUrls(PartCatalogItem $item): Collection
    {
        return $this->imagePresenter->urlsFor($item);
    }

    public function isInvalidNikolaCarsPartNumber(?string $partNumber): bool
    {
        return trim((string) $partNumber) !== ''
            && ! $this->nikolaCarsInventoryService->isTeslaPartNumberShape((string) $partNumber);
    }

    public function isDrivePartsSharedPlaceholderImageUrl(string $url): bool
    {
        return $this->imagePresenter->isDrivePartsSharedPlaceholderImageUrl($url);
    }

    public function nikolaCarsUndeterminedCategory(): string
    {
        return NikolaCarsTeslaCategoryResolver::UNDETERMINED;
    }

    public function nikolaCarsPartsCount(Collection $itemGroups): int
    {
        return $itemGroups->sum(fn (array $group): int => (int) ($group['count'] ?? 0));
    }

    public function nameBadge(?string $value): array
    {
        return [
            'text' => trim((string) $value),
            'is_auto' => false,
        ];
    }

    public function nameBadgeForItem(PartCatalogItem $item, ?string $value): array
    {
        $badge = $this->nameBadge($value);

        if ($item->source === 'teslapartsukraine') {
            $badge['text'] = $this->stripTeslaPartsUkraineMarkers($item, $badge['text']);
        }

        return $badge;
    }

    public function englishName(PartCatalogItem $item): string
    {
        $nameEn = trim((string) $item->name_en);

        if ($nameEn !== '') {
            return $nameEn;
        }

        $name = trim((string) $item->name);

        return preg_match('/\p{Cyrillic}/u', $name) === 1 ? '' : $name;
    }

    public function tskUndeterminedName(PartCatalogItem $item): string
    {
        if ($item->source !== 'tsk') {
            return '';
        }

        $name = trim((string) $item->name);

        if (
            $name === ''
            || preg_match('/\p{Cyrillic}/u', $name) !== 1
            || trim((string) $item->name_ru) !== ''
            || trim((string) $item->name_ua) !== ''
        ) {
            return '';
        }

        return $name;
    }

    public function localizedNameBadges(PartCatalogItem $item): array
    {
        return [
            'ru' => $this->nameBadgeForItem($item, $item->name_ru),
            'ua' => $this->nameBadgeForItem($item, $item->name_ua),
            'undetermined' => $this->nameBadgeForItem($item, $this->tskUndeterminedName($item)),
        ];
    }

    public function localizedNameManualLocks(PartCatalogItem $item): array
    {
        $rawAttributes = PartCatalogRawAttributes::from($item);

        return [
            'ru' => (bool) (data_get($item, 'name_ru_manually_locked_at') || data_get($rawAttributes, 'manual_name_locks.ru')),
            'ua' => (bool) (data_get($item, 'name_ua_manually_locked_at') || data_get($rawAttributes, 'manual_name_locks.ua')),
        ];
    }

    public function drivePartsIdentifiers(PartCatalogItem $item): array
    {
        if ($item->source !== 'driveparts') {
            return [
                'tesla_part_number' => '',
                'sku' => '',
            ];
        }

        $rawAttributes = PartCatalogRawAttributes::from($item);

        return [
            'tesla_part_number' => trim((string) data_get($rawAttributes, 'tesla_actual_part_number')),
            'sku' => trim((string) data_get($rawAttributes, 'driveparts_sku')),
        ];
    }

    public function teslaStatusBadges(PartCatalogItem $item): array
    {
        $rawAttributes = PartCatalogRawAttributes::from($item);
        $presence = (string) data_get($rawAttributes, 'official_presence');
        $matchStatus = (string) data_get($rawAttributes, 'official_part_match_status');
        $badges = [];

        if ($presence === 'part_search_auth_required') {
            $badges[] = 'Tesla auth required';
        } elseif ($presence === 'part_search_security_blocked') {
            $badges[] = 'Tesla security blocked';
        } elseif ($presence === 'part_search_api_error') {
            $badges[] = 'Tesla API error';
        } elseif (filled(data_get($rawAttributes, 'tesla_part_search_checked_at'))) {
            $badges[] = 'Tesla checked';
        }

        if (in_array($presence, ['part_search_exact', 'official_catalog_exact'], true)) {
            $badges[] = 'Tesla exact';
        }

        if ($matchStatus === 'similar') {
            $badges[] = 'Tesla similar';
        }

        if ($matchStatus === 'not_found') {
            $badges[] = 'Tesla not found';
        }

        return array_values(array_unique($badges));
    }

    public function priceSummary(PartCatalogItem $item, array $usdRate, ?callable $priceSource = null): array
    {
        $source = $priceSource ? $priceSource($item) : [];
        $sourceUrl = $source['url'] ?? null;

        return [
            'has_price' => $item->price_amount !== null,
            'amount_usd' => $item->price_amount !== null ? $item->priceAmountUsd($usdRate) : null,
            'amount_uah' => $item->price_amount !== null ? $item->priceAmountUah($usdRate) : null,
            'source_url' => is_string($sourceUrl) && trim($sourceUrl) !== '' ? $sourceUrl : null,
            'source_label' => (string) ($source['label'] ?? ''),
            'rate_label' => (string) ($usdRate['label'] ?? ''),
        ];
    }

    public function partOriginLabel(PartCatalogItem $item): string
    {
        return trim((string) data_get(PartCatalogRawAttributes::from($item), 'part_origin_label'));
    }

    public function isManuallySold(PartCatalogItem $item): bool
    {
        return (bool) data_get(PartCatalogRawAttributes::from($item), 'manual_sold_at');
    }

    public function cartProductId(PartCatalogItem $item): int
    {
        return (int) data_get(PartCatalogRawAttributes::from($item), 'product_id') ?: $item->id;
    }

    public function promDescriptionSource(PartCatalogItem $item, mixed $groupDescriptionUk = null): string
    {
        return (string) ($groupDescriptionUk ?? ($item->notes_ua ?: data_get(PartCatalogRawAttributes::from($item), 'prom_description', '')));
    }

    public function nameSource(PartCatalogItem $item): array
    {
        $rawAttributes = PartCatalogRawAttributes::from($item);
        $url = data_get($rawAttributes, 'name_source_url');

        return [
            'url' => is_string($url) && Str::startsWith($url, ['http://', 'https://']) ? $url : null,
            'site' => trim((string) data_get($rawAttributes, 'name_source_site')) ?: 'Source',
        ];
    }

    public function localizedNameConflictText(PartCatalogItem $item, string $locale): string
    {
        $rawAttributes = PartCatalogRawAttributes::from($item);
        if (data_get($rawAttributes, 'manual_name_locks.'.$locale)
            || data_get($item, $locale === 'ru' ? 'name_ru_manually_locked_at' : 'name_ua_manually_locked_at')) {
            return '';
        }

        $conflict = data_get($rawAttributes, 'name_language_marker_conflict_'.$locale);
        $count = (int) data_get($conflict, 'count', 0);
        $conflictLocale = data_get($conflict, 'locale');

        if ($count <= 0 || ! in_array($conflictLocale, ['ru', 'ua'], true)) {
            return '';
        }

        $markers = PartCatalogLanguageMarkerConflict::activeMarkers($conflict, $this->activeLanguageMarkers());
        $count = $markers->count();
        if ($count <= 0) {
            return '';
        }
        $label = $conflictLocale === 'ua' ? 'укр' : 'ру';
        $word = ($count % 10 === 1 && $count % 100 !== 11) ? 'слово' : (($count % 10 >= 2 && $count % 10 <= 4 && ! in_array($count % 100, [12, 13, 14], true)) ? 'слова' : 'слов');
        $markerText = ': '.$markers->implode(', ');

        return "Конфликт ({$count} {$label} {$word} из маркера языка{$markerText})";
    }

    protected function stripTeslaPartsUkraineMarkers(PartCatalogItem $item, string $value): string
    {
        $patterns = ['/(?<![\pL\pN])(?:аналог|Оригінал|Оригинал)(?![\pL\pN])/iu'];
        $replacements = [''];
        $patterns[] = '/(?<![\pL\pN])(?:бв|б\s*\/?\s*у)(?![\pL\pN])/iu';
        $replacements[] = '';

        if (Str::contains(Str::lower((string) ($item->model_name ?: $item->model_label)), 'model 3')) {
            $patterns[] = '/(?<![\pL\pN])(?:tesla\s+)?model\s*3(?![\pL\pN])/iu';
            $replacements[] = '';
        }

        return trim(preg_replace('/\s+/u', ' ', (string) preg_replace([
            ...$patterns,
            '/\s+([,.;:)])/u',
            '/([(])\s+/u',
        ], [
            ...$replacements,
            '$1',
            '$1',
        ], $value)));
    }

    protected function activeLanguageMarkers(): Collection
    {
        return $this->activeLanguageMarkers ??= PartCatalogLanguageMarkers::activeNormalized();
    }
}
