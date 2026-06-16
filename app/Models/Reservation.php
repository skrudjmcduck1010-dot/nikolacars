<?php

namespace App\Models;

use App\Models\Concerns\TracksUserStamps;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_id',
    'stock_item_id',
    'customer_order_id',
    'quantity',
    'status',
    'expires_at',
    'comment',
    'created_by',
    'updated_by',
])]
class Reservation extends Model
{
    use HasFactory;
    use TracksUserStamps;

    public const STATUSES = ['active', 'released', 'fulfilled', 'cancelled'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }
}
