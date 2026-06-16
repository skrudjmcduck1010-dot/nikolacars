<?php

namespace App\Models;

use App\Models\Concerns\HasUppercaseVinAttributes;
use App\Models\Concerns\TracksUserStamps;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'number',
    'status',
    'counterparty_id',
    'client_name',
    'client_phone',
    'car_model',
    'car_year',
    'drive_type',
    'vin',
    'license_plate',
    'mileage',
    'opened_at',
    'work_started_at',
    'appointment_time',
    'planned_finished_at',
    'completed_at',
    'customer_request',
    'work_description',
    'parts_note',
    'sto_comment',
    'paid_cash_uah',
    'paid_cash_usd',
    'paid_bank_uah',
    'paid_amount_uah',
    'payment_confirmed_at',
    'labor_cost_uah',
    'parts_cost_uah',
    'discount_uah',
    'total_cost_uah',
    'created_by',
    'updated_by',
])]
class StoWorkOrder extends Model
{
    use HasFactory;
    use HasUppercaseVinAttributes;
    use SoftDeletes;
    use TracksUserStamps;

    public const STATUS_APPOINTMENT = 'appointment';

    public const STATUS_IN_WORK = 'in_work';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_PAID = 'paid';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [
        self::STATUS_APPOINTMENT,
        self::STATUS_IN_WORK,
        'waiting_parts',
        'paused',
        self::STATUS_COMPLETED,
        self::STATUS_PAID,
        self::STATUS_CANCELLED,
        self::STATUS_ARCHIVED,
    ];

    public const LINE_ITEMS_LOCKED_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_PAID,
        self::STATUS_CANCELLED,
        self::STATUS_ARCHIVED,
    ];

    public const LINE_ITEMS_DELETE_LOCKED_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_PAID,
    ];

    public const STATUS_LABELS = [
        'appointment' => 'Запись',
        'in_work' => 'В работе',
        'waiting_parts' => 'Ожидает запчасти',
        'paused' => 'На паузе',
        self::STATUS_COMPLETED => 'Завершен',
        self::STATUS_PAID => 'Оплачен',
        self::STATUS_CANCELLED => 'Отменен',
        self::STATUS_ARCHIVED => 'Архив',
    ];

    protected function casts(): array
    {
        return [
            'car_year' => 'integer',
            'mileage' => 'integer',
            'opened_at' => 'date',
            'work_started_at' => 'datetime',
            'planned_finished_at' => 'date',
            'completed_at' => 'datetime',
            'payment_confirmed_at' => 'datetime',
            'paid_cash_uah' => 'decimal:2',
            'paid_cash_usd' => 'decimal:2',
            'paid_bank_uah' => 'decimal:2',
            'paid_amount_uah' => 'decimal:2',
            'labor_cost_uah' => 'decimal:2',
            'parts_cost_uah' => 'decimal:2',
            'discount_uah' => 'decimal:2',
            'total_cost_uah' => 'decimal:2',
        ];
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class);
    }

    public function parts(): HasMany
    {
        return $this->hasMany(StoWorkOrderPart::class);
    }

    public function works(): HasMany
    {
        return $this->hasMany(StoWorkOrderWork::class);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function canAddLineItems(): bool
    {
        return ! $this->trashed() && ! in_array($this->status, self::LINE_ITEMS_LOCKED_STATUSES, true);
    }

    public function canDeleteLineItems(): bool
    {
        return ! $this->trashed() && ! in_array($this->status, self::LINE_ITEMS_DELETE_LOCKED_STATUSES, true);
    }

    public function canConfirmPayment(): bool
    {
        return ! $this->trashed() && $this->status === self::STATUS_COMPLETED;
    }

    public function canArchive(): bool
    {
        return ! $this->trashed() && in_array($this->status, [self::STATUS_CANCELLED, self::STATUS_PAID], true);
    }

    public function getCarTitleAttribute(): string
    {
        return collect([
            $this->car_model,
            $this->car_year,
            $this->drive_type ? Counterparty::DRIVE_TYPE_LABELS[$this->drive_type] ?? $this->drive_type : null,
        ])->filter()->join(' · ');
    }
}
