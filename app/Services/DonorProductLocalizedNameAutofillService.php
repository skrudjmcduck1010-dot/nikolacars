<?php

namespace App\Services;

use App\Models\Product;

class DonorProductLocalizedNameAutofillService
{
    public function fillOnKnownDamageStatus(Product $product, ?string $previousDamageNote, ?string $nextDamageNote): array
    {
        return $this->emptyStats();
    }

    public function fillMissingNames(Product $product): array
    {
        return $this->emptyStats();
    }

    protected function emptyStats(): array
    {
        return [
            'items_seen' => 0,
            'items_updated' => 0,
            'catalog_matches_found' => 0,
            'google_translations_used' => 0,
            'name_ru_updated' => 0,
            'name_ua_updated' => 0,
        ];
    }
}
