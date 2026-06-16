<?php

namespace App\Models;

use App\Support\PartNumberNormalizer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'part_catalog_item_id',
    'product_id',
    'donor_car_id',
    'source',
    'code',
    'part_number',
    'name',
    'quantity',
    'unit_price',
    'currency',
    'sold_at',
    'document_number',
    'counterparty',
    'donor_vin',
    'category_path',
    'raw_attributes',
    'source_file',
    'source_row_number',
    'source_row_hash',
])]
class PartSale extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'sold_at' => 'datetime',
            'raw_attributes' => AsArrayObject::class,
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

    public function donorCar(): BelongsTo
    {
        return $this->belongsTo(DonorCar::class);
    }

    public function setPartNumberAttribute(mixed $value): void
    {
        $this->attributes['part_number'] = PartNumberNormalizer::normalize(is_string($value) ? $value : (string) $value);
    }

    public function getTotalAmountAttribute(): ?float
    {
        if ($this->unit_price === null) {
            return null;
        }

        return round((float) $this->unit_price * (float) $this->quantity, 2);
    }
}
