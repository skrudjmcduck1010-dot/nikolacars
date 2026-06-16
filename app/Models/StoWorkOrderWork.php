<?php

namespace App\Models;

use App\Models\Concerns\TracksUserStamps;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'sto_work_order_id',
    'sto_employee_id',
    'name',
    'price_uah',
    'note',
    'created_by',
    'updated_by',
])]
class StoWorkOrderWork extends Model
{
    use HasFactory;
    use TracksUserStamps;

    protected function casts(): array
    {
        return [
            'price_uah' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(StoWorkOrder::class, 'sto_work_order_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(StoEmployee::class, 'sto_employee_id');
    }
}
