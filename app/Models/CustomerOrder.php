<?php

namespace App\Models;

use App\Models\Concerns\TracksUserStamps;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Auth;
use LogicException;

#[Fillable([
    'number',
    'status',
    'counterparty_id',
    'client_phone',
    'client_first_name',
    'client_last_name',
    'delivery_method',
    'note',
    'total_amount',
    'currency',
    'payment_type',
    'payment_received_amount',
    'payment_received_amount_uah',
    'paid_cash_uah',
    'paid_cash_usd',
    'paid_bank_tov_uah',
    'paid_bank_fop_uah',
    'paid_prom_uah',
    'paid_amount_uah',
    'payment_confirmed_at',
    'created_by',
    'updated_by',
])]
class CustomerOrder extends Model
{
    use HasFactory;
    use TracksUserStamps;

    public const STATUS_NEW = 'new';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_WAITING_PREPAYMENT = 'waiting_prepayment';

    public const STATUS_ASSEMBLED = 'assembled';

    public const STATUS_SHIPPED = 'shipped';

    public const STATUS_REFUSED = 'refused';

    public const STATUS_PAID = 'paid';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const NOVA_POSHTA_STATUS_RECEIVED = '9';

    public const NOVA_POSHTA_STATUS_ARRIVED_AT_BRANCH = '7';

    public const DELIVERY_METHOD_PICKUP = 'pickup';

    public const DELIVERY_METHOD_NOVA_POSHTA = 'nova_poshta';

    public const DELIVERY_METHOD_STO = 'sto';

    public const PAYMENT_TYPE_CASH_UAH = 'cash_uah';

    public const PAYMENT_TYPE_CASH_USD = 'cash_usd';

    public const PAYMENT_TYPE_BANK_TOV = 'bank_tov';

    public const PAYMENT_TYPE_BANK_FOP = 'bank_fop';

    public const PAYMENT_TYPE_BANK_FOP_AFTERPAYMENT = 'bank_fop_afterpayment';

    public const PAYMENT_TYPE_PROM = 'prom';

    public const PAYMENT_TYPE_LABELS = [
        self::PAYMENT_TYPE_CASH_UAH => 'Нал, грн',
        self::PAYMENT_TYPE_CASH_USD => 'Нал USD',
        self::PAYMENT_TYPE_BANK_TOV => 'БезНал ТОВ',
        self::PAYMENT_TYPE_BANK_FOP => 'БезНал ФОП',
        self::PAYMENT_TYPE_BANK_FOP_AFTERPAYMENT => 'БезНал ФОП (наложка)',
        self::PAYMENT_TYPE_PROM => "\u{0050}\u{0072}\u{006F}\u{006D}-\u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0430}",
    ];

    public const DELIVERY_METHOD_LABELS = [
        self::DELIVERY_METHOD_PICKUP => 'Самовывоз',
        self::DELIVERY_METHOD_NOVA_POSHTA => 'Новая почта',
        self::DELIVERY_METHOD_STO => 'СТО',
    ];

    public const STATUS_LABELS = [
        self::STATUS_NEW => "\u{0421}\u{043E}\u{0431}\u{0438}\u{0440}\u{0430}\u{0435}\u{0442}\u{0441}\u{044F}",
        self::STATUS_PROCESSING => "\u{0421}\u{043E}\u{0431}\u{0438}\u{0440}\u{0430}\u{0435}\u{0442}\u{0441}\u{044F}",
        self::STATUS_WAITING_PREPAYMENT => "\u{0416}\u{0434}\u{0435}\u{043C} \u{043F}\u{0440}\u{0435}\u{0434}\u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0443}",
        self::STATUS_ASSEMBLED => "\u{0421}\u{043E}\u{0431}\u{0440}\u{0430}\u{043D}",
        self::STATUS_SHIPPED => "\u{041E}\u{0442}\u{043F}\u{0440}\u{0430}\u{0432}\u{043B}\u{0435}\u{043D}",
        self::STATUS_REFUSED => "\u{041E}\u{0442}\u{043A}\u{0430}\u{0437}",
        self::STATUS_PAID => 'Оплачено',
        self::STATUS_COMPLETED => "\u{0412}\u{044B}\u{0434}\u{0430}\u{043D}",
        self::STATUS_CANCELLED => 'Отменен',
    ];

    public const OPEN_STATUSES = [
        self::STATUS_NEW,
        self::STATUS_PROCESSING,
    ];

    public const RESERVATION_STATUSES = [
        self::STATUS_NEW,
        self::STATUS_PROCESSING,
        self::STATUS_WAITING_PREPAYMENT,
        self::STATUS_ASSEMBLED,
        self::STATUS_SHIPPED,
        self::STATUS_REFUSED,
        self::STATUS_PAID,
    ];

    protected static function booted(): void
    {
        static::creating(function (CustomerOrder $order): void {
            if (app()->runningUnitTests()) {
                return;
            }

            if (! preg_match('/^ORD-\d{8}-\d{4}$/', (string) $order->number)) {
                throw new LogicException('Customer orders must be created through the admin order workflow.');
            }

            if (! Auth::check()) {
                throw new LogicException('Customer orders must be created by an authenticated admin user.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'payment_received_amount' => 'decimal:2',
            'payment_received_amount_uah' => 'decimal:2',
            'paid_cash_uah' => 'decimal:2',
            'paid_cash_usd' => 'decimal:2',
            'paid_bank_tov_uah' => 'decimal:2',
            'paid_bank_fop_uah' => 'decimal:2',
            'paid_prom_uah' => 'decimal:2',
            'paid_amount_uah' => 'decimal:2',
            'payment_confirmed_at' => 'datetime',
        ];
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CustomerOrderItem::class);
    }

    public function historyEvents(): HasMany
    {
        return $this->hasMany(CustomerOrderHistoryEvent::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(CustomerOrderShipment::class);
    }

    public function novaPoshtaShipment(): HasOne
    {
        return $this->hasOne(CustomerOrderShipment::class)
            ->where('carrier', CustomerOrderShipment::CARRIER_NOVA_POSHTA)
            ->oldest('id');
    }

    public function novaPoshtaShipments(): HasMany
    {
        return $this->hasMany(CustomerOrderShipment::class)
            ->where('carrier', CustomerOrderShipment::CARRIER_NOVA_POSHTA)
            ->oldest('id');
    }

    public function getClientNameAttribute(): string
    {
        return trim(collect([$this->client_first_name, $this->client_last_name])->filter()->implode(' '));
    }

    public function getStatusLabelAttribute(): string
    {
        if ($this->isIssuedToClient()) {
            return self::STATUS_LABELS[self::STATUS_COMPLETED];
        }

        if (
            $this->delivery_method === self::DELIVERY_METHOD_PICKUP
            && $this->status === self::STATUS_PROCESSING
        ) {
            return "\u{0421}\u{043E}\u{0431}\u{0438}\u{0440}\u{0430}\u{0435}\u{0442}\u{0441}\u{044F}";
        }

        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function scopeReservable(Builder $query): Builder
    {
        return $query
            ->whereIn('status', self::RESERVATION_STATUSES)
            ->where(function (Builder $query): void {
                $query
                    ->where('status', '!=', self::STATUS_PAID)
                    ->orWhere('delivery_method', '!=', self::DELIVERY_METHOD_NOVA_POSHTA)
                    ->orWhereNull('delivery_method');
            })
            ->where(function (Builder $query): void {
                $query
                    ->where('delivery_method', '!=', self::DELIVERY_METHOD_NOVA_POSHTA)
                    ->orWhereNull('delivery_method')
                    ->orWhereDoesntHave('novaPoshtaShipment', fn (Builder $query) => $query
                        ->where('np_status_code', self::NOVA_POSHTA_STATUS_RECEIVED));
            })
            ->whereDoesntHave('historyEvents', fn (Builder $query) => $query
                ->where('event_type', 'returned_to_stock'))
            ->where(function (Builder $query): void {
                $query
                    ->where('status', '!=', self::STATUS_REFUSED)
                    ->orWhere('delivery_method', self::DELIVERY_METHOD_NOVA_POSHTA);
            });
    }

    public function scopeIssuedToClient(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->where('status', self::STATUS_COMPLETED)
                ->orWhere(function (Builder $query): void {
                    $query
                        ->where('status', self::STATUS_PAID)
                        ->where('delivery_method', self::DELIVERY_METHOD_NOVA_POSHTA);
                })
                ->orWhere(function (Builder $query): void {
                    $query
                        ->where('status', '!=', self::STATUS_REFUSED)
                        ->where('delivery_method', self::DELIVERY_METHOD_NOVA_POSHTA)
                        ->whereHas('novaPoshtaShipment', fn (Builder $query) => $query
                            ->where('np_status_code', self::NOVA_POSHTA_STATUS_RECEIVED));
                });
        });
    }

    public function isIssuedToClient(): bool
    {
        return $this->status === self::STATUS_COMPLETED
            || ($this->status === self::STATUS_PAID && $this->delivery_method === self::DELIVERY_METHOD_NOVA_POSHTA)
            || ($this->status !== self::STATUS_REFUSED && $this->hasNovaPoshtaReceivedStatus());
    }

    public function canBeMarkedAsAssembled(): bool
    {
        if ($this->delivery_method === self::DELIVERY_METHOD_NOVA_POSHTA) {
            return $this->status === self::STATUS_PROCESSING
                && (float) $this->paid_amount_uah > 0;
        }

        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function canBeMarkedAsShipped(): bool
    {
        return $this->status === self::STATUS_ASSEMBLED
            && $this->delivery_method === self::DELIVERY_METHOD_NOVA_POSHTA;
    }

    public function canBeMarkedAsCompleted(): bool
    {
        if ($this->hasNovaPoshtaReceivedStatus()) {
            return false;
        }

        if ($this->delivery_method === self::DELIVERY_METHOD_STO) {
            return $this->status === self::STATUS_ASSEMBLED;
        }

        if ($this->delivery_method === self::DELIVERY_METHOD_NOVA_POSHTA) {
            return $this->status === self::STATUS_SHIPPED;
        }

        return $this->delivery_method === self::DELIVERY_METHOD_PICKUP
            && in_array($this->status, [self::STATUS_ASSEMBLED, self::STATUS_PAID], true);
    }

    public function hasNovaPoshtaReceivedStatus(): bool
    {
        return $this->delivery_method === self::DELIVERY_METHOD_NOVA_POSHTA
            && $this->novaPoshtaShipment?->np_status_code === self::NOVA_POSHTA_STATUS_RECEIVED;
    }

    public function hasNovaPoshtaReturnReceivedStatus(): bool
    {
        return $this->delivery_method === self::DELIVERY_METHOD_NOVA_POSHTA
            && $this->status === self::STATUS_REFUSED
            && $this->novaPoshtaShipment?->np_return_status_code === self::NOVA_POSHTA_STATUS_RECEIVED;
    }

    public function hasReturnedToStockEvent(): bool
    {
        if ($this->relationLoaded('historyEvents')) {
            return $this->historyEvents->contains(
                fn (CustomerOrderHistoryEvent $event): bool => $event->event_type === 'returned_to_stock'
            );
        }

        return $this->historyEvents()
            ->where('event_type', 'returned_to_stock')
            ->exists();
    }

    public function canBeReturnedToStock(): bool
    {
        return $this->hasNovaPoshtaReturnReceivedStatus()
            && ! $this->hasReturnedToStockEvent();
    }

    public function canUpdateNovaPoshtaTrackingNumber(): bool
    {
        if ($this->delivery_method !== self::DELIVERY_METHOD_NOVA_POSHTA) {
            return false;
        }

        if (! $this->novaPoshtaShipment?->tracking_number) {
            return false;
        }

        if ($this->isIssuedToClient() || in_array($this->status, [self::STATUS_CANCELLED, self::STATUS_REFUSED], true)) {
            return false;
        }

        return ! in_array((string) $this->novaPoshtaShipment?->np_status_code, [
            self::NOVA_POSHTA_STATUS_ARRIVED_AT_BRANCH,
            self::NOVA_POSHTA_STATUS_RECEIVED,
        ], true);
    }

    public function canAddNovaPoshtaTrackingNumber(): bool
    {
        if ($this->delivery_method !== self::DELIVERY_METHOD_NOVA_POSHTA) {
            return false;
        }

        if ($this->hasNovaPoshtaReceivedStatus()) {
            return false;
        }

        return ! $this->isIssuedToClient()
            && ! in_array($this->status, [self::STATUS_CANCELLED, self::STATUS_REFUSED], true);
    }

    public function canBeCancelled(): bool
    {
        return ! in_array($this->status, [
            self::STATUS_CANCELLED,
            self::STATUS_REFUSED,
            self::STATUS_PAID,
            self::STATUS_COMPLETED,
        ], true);
    }

    public function canConfirmPayment(): bool
    {
        if ($this->delivery_method === self::DELIVERY_METHOD_STO) {
            return false;
        }

        return in_array($this->status, [self::STATUS_ASSEMBLED, self::STATUS_SHIPPED], true);
    }

    public function canAcceptPrepayment(): bool
    {
        if ($this->delivery_method === self::DELIVERY_METHOD_STO) {
            return false;
        }

        if (
            $this->delivery_method === self::DELIVERY_METHOD_NOVA_POSHTA
            && ! in_array($this->status, [self::STATUS_WAITING_PREPAYMENT, self::STATUS_PROCESSING], true)
        ) {
            return false;
        }

        return ! in_array($this->status, [self::STATUS_PAID, self::STATUS_COMPLETED, self::STATUS_CANCELLED], true);
    }

    public function canBeEdited(): bool
    {
        return ! in_array($this->status, [self::STATUS_CANCELLED, self::STATUS_REFUSED, self::STATUS_COMPLETED], true);
    }

    public function getDeliveryMethodLabelAttribute(): string
    {
        return self::DELIVERY_METHOD_LABELS[$this->delivery_method] ?? (string) $this->delivery_method;
    }

    public function getPaymentTypeLabelAttribute(): ?string
    {
        if (! $this->payment_type) {
            return null;
        }

        return self::PAYMENT_TYPE_LABELS[$this->payment_type] ?? (string) $this->payment_type;
    }

    public function getTotalAmountUsdHintAttribute(): ?float
    {
        $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();
        $total = $items
            ->pluck('total_price_usd_hint')
            ->filter(fn ($value): bool => $value !== null)
            ->sum(fn ($value): float => (float) $value);

        return $total > 0 ? round($total, 2) : null;
    }
}
