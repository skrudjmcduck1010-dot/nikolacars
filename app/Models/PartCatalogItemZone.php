<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'part_catalog_item_id',
    'zone',
    'confidence',
    'matched_rule',
])]
class PartCatalogItemZone extends Model
{
    use HasFactory;

    public function item(): BelongsTo
    {
        return $this->belongsTo(PartCatalogItem::class, 'part_catalog_item_id');
    }
}
