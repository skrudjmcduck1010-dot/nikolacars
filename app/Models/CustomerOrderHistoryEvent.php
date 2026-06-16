<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'customer_order_id',
    'customer_order_item_id',
    'user_id',
    'event_type',
    'title',
    'description',
    'old_values',
    'new_values',
])]
class CustomerOrderHistoryEvent extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(CustomerOrder::class, 'customer_order_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(CustomerOrderItem::class, 'customer_order_item_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
