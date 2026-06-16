<?php

namespace App\Models;

use App\Models\Concerns\HasUppercaseVinAttributes;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'counterparty_id',
    'car_model',
    'car_year',
    'drive_type',
    'vin',
    'license_plate',
])]
class CounterpartyVehicle extends Model
{
    use HasFactory;
    use HasUppercaseVinAttributes;

    protected function casts(): array
    {
        return [
            'car_year' => 'integer',
        ];
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class);
    }

    public function getDriveTypeLabelAttribute(): string
    {
        return Counterparty::DRIVE_TYPE_LABELS[$this->drive_type] ?? $this->drive_type ?? '';
    }
}
