<?php

namespace App\Models;

use App\Models\Concerns\TracksUserStamps;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'sto_work_order_id',
    'product_id',
    'stock_item_id',
    'name',
    'quantity',
    'unit_price_uah',
    'total_price_uah',
    'note',
    'created_by',
    'updated_by',
])]
class StoWorkOrderPart extends Model
{
    use HasFactory;
    use TracksUserStamps;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price_uah' => 'decimal:2',
            'total_price_uah' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(StoWorkOrder::class, 'sto_work_order_id');
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
