<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_id',
    'part_catalog_item_id',
    'source',
    'old_price',
    'new_price',
    'currency',
    'changed_at',
])]
class ProductPriceHistory extends Model
{
    protected function casts(): array
    {
        return [
            'old_price' => 'decimal:2',
            'new_price' => 'decimal:2',
            'changed_at' => 'datetime',
        ];
    }

    public function partCatalogItem(): BelongsTo
    {
        return $this->belongsTo(PartCatalogItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
