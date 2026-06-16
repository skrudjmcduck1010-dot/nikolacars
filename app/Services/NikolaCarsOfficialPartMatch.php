<?php

namespace App\Services;

use App\Models\PartCatalogItem;

class NikolaCarsOfficialPartMatch
{
    public const TYPE_EXACT = 'exact';

    public const TYPE_SEVEN_DIGIT_PREFIX = 'seven_digit_prefix';

    public const TYPE_NONE = 'none';

    public function __construct(
        public readonly ?PartCatalogItem $officialItem,
        public readonly string $matchType,
        public readonly string $normalizedPartNumber,
        public readonly ?string $partPrefix,
    ) {}

    public function matched(): bool
    {
        return $this->officialItem instanceof PartCatalogItem;
    }
}
