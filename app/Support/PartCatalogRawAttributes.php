<?php

namespace App\Support;

use App\Models\PartCatalogItem;
use ArrayObject;

class PartCatalogRawAttributes
{
    public static function from(?PartCatalogItem $item): array
    {
        return $item instanceof PartCatalogItem
            ? self::fromValue($item->raw_attributes)
            : [];
    }

    public static function fromValue(mixed $value): array
    {
        if ($value instanceof ArrayObject) {
            return $value->getArrayCopy();
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($value) ? $value : [];
    }
}
