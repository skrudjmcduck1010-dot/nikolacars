<?php

namespace App\Services;

use App\Models\PartCatalogItem;

class TeslaOfficialLocalizedNameSyncService
{
    protected const SOURCE_ORDER = [
        'tcarservice',
        'teslapartsukraine',
        'erazborka',
        'dkparts',
        'teslawestparts',
        'driveparts',
        'stock-tesla',
        'teslacompany',
        'tsk',
    ];

    public function syncAfterItemSaved(PartCatalogItem $item, bool $created): array
    {
        return $this->emptyStats();
    }

    public function syncOfficialItem(PartCatalogItem $officialItem): array
    {
        return $this->emptyStats();
    }

    public function syncForCompetitorItem(PartCatalogItem $competitorItem): array
    {
        return $this->emptyStats();
    }

    public function isCompetitorSource(string $source): bool
    {
        return in_array($source, self::SOURCE_ORDER, true);
    }

    public function sourceOrder(): array
    {
        return self::SOURCE_ORDER;
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
