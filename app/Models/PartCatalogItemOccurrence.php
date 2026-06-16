<?php

namespace App\Models;

use App\Support\PartNumberNormalizer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'part_catalog_item_id',
    'part_catalog_category_id',
    'source',
    'occurrence_key',
    'page_url',
    'product_url',
    'part_number',
    'name',
    'scheme_number',
    'quantity',
    'raw_attributes',
])]
class PartCatalogItemOccurrence extends Model
{
    protected function casts(): array
    {
        return [
            'raw_attributes' => AsArrayObject::class,
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(PartCatalogItem::class, 'part_catalog_item_id');
    }

    public function setPartNumberAttribute(mixed $value): void
    {
        $this->attributes['part_number'] = PartNumberNormalizer::normalize(is_string($value) ? $value : (string) $value);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(PartCatalogCategory::class, 'part_catalog_category_id');
    }
}
