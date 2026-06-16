<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'cash_employee_name',
    'first_name',
    'last_name',
    'position',
    'rate',
    'bonus_calculation',
    'start_date',
    'is_active',
    'user_id',
])]
class StoEmployee extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:2',
            'start_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getFullNameAttribute(): string
    {
        return $this->cash_employee_name;
    }

    public static function splitCashName(string $cashEmployeeName): array
    {
        $cashEmployeeName = CashTransaction::normalizeEmployeeName($cashEmployeeName) ?? $cashEmployeeName;
        $parts = preg_split('/\s+/u', trim($cashEmployeeName), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return [
            'last_name' => $parts[0] ?? $cashEmployeeName,
            'first_name' => implode(' ', array_slice($parts, 1)) ?: null,
        ];
    }
}
