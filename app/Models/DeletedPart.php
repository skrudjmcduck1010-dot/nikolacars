<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'source',
    'product_id',
    'part_catalog_item_id',
    'donor_car_id',
    'donor_vin',
    'sku',
    'part_number',
    'name',
    'product_snapshot',
    'part_catalog_item_snapshot',
    'related_product_snapshots',
    'deleted_by',
    'deleted_at',
])]
class DeletedPart extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'product_snapshot' => 'array',
            'part_catalog_item_snapshot' => 'array',
            'related_product_snapshots' => 'array',
            'deleted_at' => 'datetime',
        ];
    }

    public function donorCar(): BelongsTo
    {
        return $this->belongsTo(DonorCar::class);
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }
}
