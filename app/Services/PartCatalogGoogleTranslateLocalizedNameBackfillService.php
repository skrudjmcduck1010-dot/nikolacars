<?php

namespace App\Services;

class PartCatalogGoogleTranslateLocalizedNameBackfillService
{
    public function backfill(array $options = []): array
    {
        return $this->emptyStats();
    }

    protected function emptyStats(): array
    {
        return [
            'items_seen' => 0,
            'items_changed' => 0,
            'items_skipped' => 0,
            'google_translations_used' => 0,
            'name_ru_updated' => 0,
            'name_ua_updated' => 0,
        ];
    }
}
