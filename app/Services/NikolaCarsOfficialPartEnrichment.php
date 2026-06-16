<?php

namespace App\Services;

use App\Models\PartCatalogItem;

class NikolaCarsOfficialPartEnrichment
{
    public function __construct(
        public readonly NikolaCarsOfficialPartMatch $match,
        public readonly ?PartCatalogItem $officialItem,
        public readonly string $requestedPartNumber,
        public readonly ?string $officialPartNumber,
        public readonly ?string $officialUrl,
        public readonly ?string $officialName,
        public readonly array $categoryParts,
        public readonly ?string $categoryPath,
        public readonly array $compatibilityModels,
        public readonly array $occurrences,
        public readonly ?int $schemeNumber,
        public readonly array $partImageUrls,
        public readonly array $schemeImageUrls,
        public readonly array $imageUrls,
    ) {}

    public function matched(): bool
    {
        return $this->officialItem instanceof PartCatalogItem;
    }

    public function matchType(): string
    {
        return $this->match->matchType;
    }
}
