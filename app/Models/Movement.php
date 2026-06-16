<?php

namespace App\Models;

use App\Models\Concerns\TracksUserStamps;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'product_id',
    'stock_item_id',
    'from_location_id',
    'to_location_id',
    'user_id',
    'counterparty_id',
    'type',
    'quantity',
    'reason',
    'document_number',
    'comment',
    'created_by',
    'updated_by',
    'created_at',
])]
class Movement extends Model
{
    use HasFactory;
    use TracksUserStamps;

    public const TYPES = ['intake', 'move', 'reserve', 'unreserve', 'sale', 'writeoff', 'adjustment'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (): void {
            throw new LogicException('Movement history cannot be deleted.');
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class);
    }
}
