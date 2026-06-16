<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'customer_order_id',
    'part_catalog_item_id',
    'product_id',
    'name',
    'part_number',
    'code',
    'donor_vin',
    'category',
    'quantity',
    'unit_price',
    'total_price',
    'currency',
    'unit_price_usd_hint',
    'total_price_usd_hint',
    'usd_exchange_rate',
    'catalog_original_price_amount',
    'catalog_original_currency',
    'catalog_price_snapshot_taken',
    'source_url',
    'image_url',
])]
class CustomerOrderItem extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'total_price' => 'decimal:2',
            'unit_price_usd_hint' => 'decimal:2',
            'total_price_usd_hint' => 'decimal:2',
            'usd_exchange_rate' => 'decimal:6',
            'catalog_original_price_amount' => 'decimal:2',
            'catalog_price_snapshot_taken' => 'boolean',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(CustomerOrder::class, 'customer_order_id');
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
