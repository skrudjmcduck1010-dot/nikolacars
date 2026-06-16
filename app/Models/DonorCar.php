<?php

namespace App\Models;

use App\Models\Concerns\HasUppercaseVinAttributes;
use App\Support\CatalogTextEncoding;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['vin', 'status', 'brand', 'model', 'drive_type', 'battery_type', 'is_performance', 'year', 'color', 'paint_code', 'mileage', 'purchase_date', 'warehouse_arrival_date', 'estimated_cost_usd', 'usa_delivery_price_usd', 'klaipeda_ukraine_delivery_price_usd', 'customs_clearance_price_usd', 'donor_expense_sources', 'notes', 'photos'])]
class DonorCar extends Model
{
    use HasFactory;
    use HasUppercaseVinAttributes;

    public const STATUS_IN_TRANSIT = 'in_transit';

    public const STATUS_AT_STO = 'at_sto';

    public const STATUS_DISMANTLING = 'dismantling';

    public const STATUS_DISMANTLED = 'dismantled';

    public const ARRIVED_STATUSES = [
        self::STATUS_DISMANTLING,
        self::STATUS_DISMANTLED,
    ];

    public const STATUSES = [
        self::STATUS_IN_TRANSIT => 'В пути',
        self::STATUS_DISMANTLING => 'В разборке',
        self::STATUS_DISMANTLED => "\u{0420}\u{0430}\u{0437}\u{043E}\u{0431}\u{0440}\u{0430}\u{043D}",
    ];

    public const LEGACY_STATUSES = [
        self::STATUS_AT_STO => 'На СТО',
    ];

    public const BRANDS = ['Tesla'];

    public const MODELS = ['Model S', 'Model X', 'Model Y', 'Model 3'];

    public const DRIVE_TYPE_REAR = 'rear';

    public const DRIVE_TYPE_ALL = 'all';

    public const BATTERY_TYPE_STANDARD_RANGE = 'standard_range';

    public const BATTERY_TYPE_LONG_RANGE = 'long_range';

    public const BATTERY_TYPE_PERFORMANCE = 'performance';

    public const DRIVE_TYPES = [
        self::DRIVE_TYPE_REAR => 'Задний привод',
        self::DRIVE_TYPE_ALL => 'Полный привод',
    ];

    public const BATTERY_TYPES = [
        self::BATTERY_TYPE_STANDARD_RANGE => 'Standard Range',
        self::BATTERY_TYPE_LONG_RANGE => 'Long Range',
        self::BATTERY_TYPE_PERFORMANCE => 'Performance',
    ];

    public const PERFORMANCE_OPTIONS = [
        '0' => 'Нет',
        '1' => 'Да',
    ];

    public const PHOTO_LIMIT = 30;

    public const DONOR_EXPENSE_FIELDS = [
        'purchase_with_fees' => 'estimated_cost_usd',
        'usa_delivery' => 'usa_delivery_price_usd',
        'klaipeda_ukraine_delivery' => 'klaipeda_ukraine_delivery_price_usd',
        'customs_clearance' => 'customs_clearance_price_usd',
    ];

    public const DONOR_EXPENSE_SOURCE_CASHBOOK = 'cashbook';

    public const DONOR_EXPENSE_SOURCE_VALERA_CASHBOOK = 'valera_cashbook';

    public const DONOR_EXPENSE_SOURCE_LABELS = [
        self::DONOR_EXPENSE_SOURCE_CASHBOOK => 'Касса и работы',
        self::DONOR_EXPENSE_SOURCE_VALERA_CASHBOOK => 'Касса Валера',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $donorCar): void {
            if (blank($donorCar->status)) {
                $donorCar->status = self::STATUS_IN_TRANSIT;
            }

            $donorCar->applyAutomaticStoStatus();
        });
    }

    public function applyAutomaticStoStatus(): void
    {
        if ($this->status !== self::STATUS_IN_TRANSIT) {
            return;
        }

        if (! $this->has_complete_sto_arrival_details) {
            return;
        }

        $this->status = self::STATUS_AT_STO;
    }

    public function getHasCompleteStoArrivalDetailsAttribute(): bool
    {
        return $this->purchase_date !== null
            && $this->warehouse_arrival_date !== null
            && collect(self::DONOR_EXPENSE_FIELDS)
                ->every(fn (string $field): bool => $this->{$field} !== null);
    }

    public function getStatusLabelAttribute(): string
    {
        return CatalogTextEncoding::repair(self::allStatusLabels()[$this->status] ?? (string) $this->status);
    }

    public function getNotesAttribute(?string $value): ?string
    {
        return CatalogTextEncoding::repair($value);
    }

    public function getColorAttribute(?string $value): ?string
    {
        return CatalogTextEncoding::repair($value);
    }

    public function getPaintCodeAttribute(?string $value): ?string
    {
        return CatalogTextEncoding::repair($value);
    }

    public function canTransitionTo(string $status): bool
    {
        if ($this->status === $status) {
            return true;
        }

        return ! ($this->status === self::STATUS_DISMANTLING && $status !== self::STATUS_DISMANTLED);
    }

    public function availableStatusOptions(): array
    {
        if ($this->status === self::STATUS_AT_STO) {
            return array_intersect_key(self::allStatusLabels(), array_flip([
                self::STATUS_AT_STO,
                self::STATUS_DISMANTLING,
                self::STATUS_DISMANTLED,
            ]));
        }

        if ($this->status !== self::STATUS_DISMANTLING) {
            return self::STATUSES;
        }

        return array_intersect_key(self::STATUSES, array_flip([
            self::STATUS_DISMANTLING,
            self::STATUS_DISMANTLED,
        ]));
    }

    public function getStatusClassAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_AT_STO => 'donor-status--sto',
            self::STATUS_DISMANTLING => 'donor-status--dismantling',
            self::STATUS_DISMANTLED => 'donor-status--dismantled',
            default => 'donor-status--transit',
        };
    }

    public function allowedStatusValues(): array
    {
        $statuses = array_keys(self::STATUSES);

        if ($this->exists && $this->status === self::STATUS_AT_STO) {
            $statuses[] = self::STATUS_AT_STO;
        }

        return $statuses;
    }

    public static function allStatusLabels(): array
    {
        return self::STATUSES + self::LEGACY_STATUSES;
    }

    public function getTotalCostUsdAttribute(): ?float
    {
        $costs = [
            $this->estimated_cost_usd,
            $this->usa_delivery_price_usd,
            $this->klaipeda_ukraine_delivery_price_usd,
            $this->customs_clearance_price_usd,
        ];

        if (collect($costs)->every(fn ($cost): bool => $cost === null)) {
            return null;
        }

        return collect($costs)->sum(fn ($cost): float => (float) $cost);
    }

    public function getHasIncompleteCostAttribute(): bool
    {
        $requiredCosts = [
            $this->estimated_cost_usd,
            $this->usa_delivery_price_usd,
            $this->klaipeda_ukraine_delivery_price_usd,
        ];

        if ($this->purchase_date?->year >= 2026) {
            $requiredCosts[] = $this->customs_clearance_price_usd;
        }

        return collect($requiredCosts)->contains(fn ($cost): bool => $cost === null);
    }

    public function getDisplayModelAttribute(): string
    {
        $model = trim((string) CatalogTextEncoding::repair($this->model));
        $model = preg_replace('/\s+\d{2}\.\d{4}\s*(?:-\s*(?:\d{2}\.\d{4})?)?$/u', '', $model);
        $model = preg_replace('/\s+\d{4}\s*-\s*(?:\d{4})?$/u', '', (string) $model);

        return trim((string) $model);
    }

    public function getDisplayVinAttribute(): string
    {
        return CatalogTextEncoding::repair($this->vin) ?? (string) $this->vin;
    }

    public function scopeHavingOpenDonorExpenses(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->whereNull('estimated_cost_usd')
                ->orWhereNull('usa_delivery_price_usd')
                ->orWhereNull('klaipeda_ukraine_delivery_price_usd')
                ->orWhere(function (Builder $query): void {
                    $query
                        ->whereYear('purchase_date', '>=', 2026)
                        ->whereNull('customs_clearance_price_usd');
                });
        });
    }

    public function scopeRealVinOnly(Builder $query): Builder
    {
        return $query
            ->whereRaw('LENGTH(vin) = 17')
            ->where('vin', 'not like', '% %');
    }

    public function getDriveTypeLabelAttribute(): ?string
    {
        return CatalogTextEncoding::repair(self::DRIVE_TYPES[$this->drive_type] ?? null);
    }

    public function getBatteryTypeLabelAttribute(): ?string
    {
        return self::batteryTypeOptionsForModel($this->model)[$this->battery_type] ?? null;
    }

    public static function batteryTypeOptionsForModel(?string $model): array
    {
        $model = strtolower(trim((string) $model));

        if (str_contains($model, 'model 3 highland')) {
            return [
                self::BATTERY_TYPE_STANDARD_RANGE => 'Highland RWD / Standard Range',
                self::BATTERY_TYPE_LONG_RANGE => 'Highland Long Range / AWD / Dual Motor',
                self::BATTERY_TYPE_PERFORMANCE => 'Highland Performance',
            ];
        }

        if (str_contains($model, 'model 3')) {
            return [
                self::BATTERY_TYPE_STANDARD_RANGE => 'Model 3 RWD / Standard Range',
                self::BATTERY_TYPE_LONG_RANGE => 'Model 3 Long Range / AWD / Dual Motor',
                self::BATTERY_TYPE_PERFORMANCE => 'Model 3 Performance',
            ];
        }

        if (str_contains($model, 'model y')) {
            return [
                self::BATTERY_TYPE_STANDARD_RANGE => 'Model Y RWD / Standard Range',
                self::BATTERY_TYPE_LONG_RANGE => 'Model Y Long Range / AWD / Dual Motor',
                self::BATTERY_TYPE_PERFORMANCE => 'Model Y Performance',
            ];
        }

        if (str_contains($model, 'model s')) {
            return [
                self::BATTERY_TYPE_STANDARD_RANGE => 'Model S Base / Standard / 60-75',
                self::BATTERY_TYPE_LONG_RANGE => 'Model S Long Range / 85-100',
                self::BATTERY_TYPE_PERFORMANCE => 'Model S Performance / Ludicrous / Plaid',
            ];
        }

        if (str_contains($model, 'model x')) {
            return [
                self::BATTERY_TYPE_STANDARD_RANGE => 'Model X Base / Standard / 60-75',
                self::BATTERY_TYPE_LONG_RANGE => 'Model X Long Range / 90-100',
                self::BATTERY_TYPE_PERFORMANCE => 'Model X Performance / Ludicrous / Plaid',
            ];
        }

        if (str_contains($model, 'cybertruck')) {
            return [
                self::BATTERY_TYPE_STANDARD_RANGE => 'Standard / RWD / BD00-BD02',
                self::BATTERY_TYPE_LONG_RANGE => 'Premium / AWD / BD01',
                self::BATTERY_TYPE_PERFORMANCE => 'Cyberbeast / Performance',
            ];
        }

        return self::BATTERY_TYPES;
    }

    public function getPerformanceLabelAttribute(): ?string
    {
        if ($this->is_performance === null) {
            return null;
        }

        return $this->is_performance ? 'Да' : 'Нет';
    }

    public function canBeDeleted(): bool
    {
        return $this->created_at !== null && $this->created_at->greaterThanOrEqualTo(now()->subDay());
    }

    public function donorExpenseSourceFor(string $field): ?string
    {
        $source = $this->donor_expense_sources[$field] ?? null;

        return array_key_exists((string) $source, self::DONOR_EXPENSE_SOURCE_LABELS) ? (string) $source : null;
    }

    public function donorExpenseSourceLabelFor(string $field): ?string
    {
        $source = $this->donorExpenseSourceFor($field);

        return $source ? CatalogTextEncoding::repair(self::DONOR_EXPENSE_SOURCE_LABELS[$source]) : null;
    }

    public function isDonorExpenseFieldLocked(string $field): bool
    {
        return $this->donorExpenseSourceFor($field) !== null;
    }

    public function setDonorExpenseSource(string $field, string $source): void
    {
        $sources = $this->donor_expense_sources ?? [];
        $sources[$field] = $source;
        $this->donor_expense_sources = $sources;
    }

    public function unsetDonorExpenseSource(string $field): void
    {
        $sources = $this->donor_expense_sources ?? [];
        unset($sources[$field]);
        $this->donor_expense_sources = $sources ?: null;
    }

    protected function casts(): array
    {
        return [
            'photos' => 'array',
            'donor_expense_sources' => 'array',
            'is_performance' => 'boolean',
            'mileage' => 'integer',
            'purchase_date' => 'date',
            'warehouse_arrival_date' => 'date',
            'estimated_cost_usd' => 'decimal:2',
            'usa_delivery_price_usd' => 'decimal:2',
            'klaipeda_ukraine_delivery_price_usd' => 'decimal:2',
            'customs_clearance_price_usd' => 'decimal:2',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function partSales(): HasMany
    {
        return $this->hasMany(PartSale::class);
    }
}
