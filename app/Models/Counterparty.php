<?php

namespace App\Models;

use App\Models\Concerns\HasUppercaseVinAttributes;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'type',
    'name',
    'phone',
    'email',
    'address',
    'notes',
    'car_model',
    'car_year',
    'drive_type',
    'vin',
    'license_plate',
    'is_active',
])]
class Counterparty extends Model
{
    use HasFactory;
    use HasUppercaseVinAttributes;

    public const ANONYMOUS_ID = 1;

    public const ANONYMOUS_NAME = "\u{041D}\u{0435}\u{0438}\u{0437}\u{0432}\u{0435}\u{0441}\u{0442}\u{043D}\u{044B}\u{0439} \u{0410}\u{043D}\u{043E}\u{043D}\u{0438}\u{043C}\u{0443}\u{0441}";

    public const ANONYMOUS_PHONE = '+380000000000';

    public const STO_NIKOLACARS_NAME = "\u{0421}\u{0422}\u{041E} NikolaCars";

    public const TYPE_SUPPLIER = 'supplier';

    public const TYPE_STO_CUSTOMER = 'customer';

    public const TYPE_BOTH = 'both';

    public const TYPE_PARTS = 'parts';

    public const TYPES = [
        self::TYPE_SUPPLIER,
        self::TYPE_STO_CUSTOMER,
        self::TYPE_PARTS,
        self::TYPE_BOTH,
    ];

    public const TYPE_LABELS = [
        self::TYPE_SUPPLIER => 'Поставщик',
        self::TYPE_STO_CUSTOMER => 'Клиент СТО',
        self::TYPE_PARTS => 'Запчасти',
        self::TYPE_BOTH => 'Поставщик и клиент СТО',
    ];

    public const DRIVE_TYPES = ['rear', 'all'];

    public const DRIVE_TYPE_LABELS = [
        'rear' => 'Задний',
        'all' => 'Полный',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'car_year' => 'integer',
        ];
    }

    public function movements(): HasMany
    {
        return $this->hasMany(Movement::class);
    }

    public function stoWorkOrders(): HasMany
    {
        return $this->hasMany(StoWorkOrder::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(CounterpartyVehicle::class);
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }

    public function getDriveTypeLabelAttribute(): string
    {
        return self::DRIVE_TYPE_LABELS[$this->drive_type] ?? $this->drive_type ?? '';
    }
}
