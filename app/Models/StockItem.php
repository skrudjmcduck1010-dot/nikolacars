<?php

namespace App\Models;

use App\Models\Concerns\TracksUserStamps;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'product_id',
    'warehouse_id',
    'location_id',
    'quantity',
    'reserved_quantity',
    'available_quantity',
    'testing_status',
    'received_at',
    'created_by',
    'updated_by',
])]
class StockItem extends Model
{
    use HasFactory;
    use TracksUserStamps;

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $stockItem): void {
            $stockItem->syncAvailableQuantity();
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(Movement::class);
    }

    public function syncAvailableQuantity(): void
    {
        $this->available_quantity = max(0, $this->quantity - $this->reserved_quantity);
    }
}
