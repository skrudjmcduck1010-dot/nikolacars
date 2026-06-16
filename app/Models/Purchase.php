<?php

namespace App\Models;

use App\Models\Concerns\TracksUserStamps;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'cash_transaction_id',
    'counterparty_id',
    'warehouse_id',
    'purchase_date',
    'document_number',
    'status',
    'currency',
    'total_amount',
    'comment',
    'created_by',
    'updated_by',
])]
class Purchase extends Model
{
    use HasFactory;
    use TracksUserStamps;

    public const STATUSES = ['posted', 'cancelled'];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'total_amount' => 'decimal:2',
        ];
    }

    public function cashTransaction(): BelongsTo
    {
        return $this->belongsTo(CashTransaction::class);
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }
}
