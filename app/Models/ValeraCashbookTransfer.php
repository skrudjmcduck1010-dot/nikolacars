<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'cash_transaction_id',
    'status',
    'confirmed_valera_cash_transaction_id',
    'confirmed_at',
    'cancelled_at',
])]
class ValeraCashbookTransfer extends Model
{
    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function cashTransaction(): BelongsTo
    {
        return $this->belongsTo(CashTransaction::class);
    }

    public function confirmedValeraCashTransaction(): BelongsTo
    {
        return $this->belongsTo(ValeraCashTransaction::class, 'confirmed_valera_cash_transaction_id');
    }
}
