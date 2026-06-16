<?php

namespace App\Models;

use App\Models\Concerns\TracksUserStamps;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    public const STATUS_ASSEMBLED = 'assembled';

    public const STATUS_SHIPPED = 'shipped';

    public const STATUS_PAID = 'paid';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const DELIVERY_METHOD_PICKUP = 'pickup';

    public const DELIVERY_METHOD_NOVA_POSHTA = 'nova_poshta';

    public const DELIVERY_METHOD_STO = 'sto';

    public const PAYMENT_TYPE_CASH_UAH = 'cash_uah';

    public const PAYMENT_TYPE_CASH_USD = 'cash_usd';

    public const PAYMENT_TYPE_BANK_TOV = 'bank_tov';

    public const PAYMENT_TYPE_BANK_FOP = 'bank_fop';

    public const PAYMENT_TYPE_LABELS = [
        self::PAYMENT_TYPE_CASH_UAH => 'Нал, грн',
        self::PAYMENT_TYPE_CASH_USD => 'Нал USD',
        self::PAYMENT_TYPE_BANK_TOV => 'БезНал ТОВ',
        self::PAYMENT_TYPE_BANK_FOP => 'БезНал ФОП',
    ];

    public const DELIVERY_METHOD_LABELS = [
        self::DELIVERY_METHOD_PICKUP => 'Самовывоз',
        self::DELIVERY_METHOD_NOVA_POSHTA => 'Новая почта',
        self::DELIVERY_METHOD_STO => 'СТО',
    ];

    public const STATUS_LABELS = [
        self::STATUS_NEW => 'Обрабатывается',
        self::STATUS_PROCESSING => 'Обрабатывается',
        self::STATUS_ASSEMBLED => "\u{0421}\u{043E}\u{0431}\u{0440}\u{0430}\u{043D}",
        self::STATUS_SHIPPED => "\u{041E}\u{0442}\u{043F}\u{0440}\u{0430}\u{0432}\u{043B}\u{0435}\u{043D}",
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
        self::STATUS_ASSEMBLED,
        self::STATUS_SHIPPED,
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

    public function getClientNameAttribute(): string
    {
        return trim(collect([$this->client_first_name, $this->client_last_name])->filter()->implode(' '));
    }

    public function getStatusLabelAttribute(): string
    {
        if ($this->isIssuedToClient()) {
            return self::STATUS_LABELS[self::STATUS_COMPLETED];
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
                });
        });
    }

    public function isIssuedToClient(): bool
    {
        return $this->status === self::STATUS_COMPLETED
            || ($this->status === self::STATUS_PAID && $this->delivery_method === self::DELIVERY_METHOD_NOVA_POSHTA);
    }

    public function canBeMarkedAsAssembled(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function canBeMarkedAsShipped(): bool
    {
        return $this->status === self::STATUS_ASSEMBLED
            && $this->delivery_method === self::DELIVERY_METHOD_NOVA_POSHTA;
    }

    public function canBeMarkedAsCompleted(): bool
    {
        if ($this->delivery_method === self::DELIVERY_METHOD_STO) {
            return $this->status === self::STATUS_ASSEMBLED;
        }

        return in_array($this->status, [self::STATUS_ASSEMBLED, self::STATUS_PAID], true)
            && $this->delivery_method === self::DELIVERY_METHOD_PICKUP;
    }

    public function canBeCancelled(): bool
    {
        return ! in_array($this->status, [
            self::STATUS_CANCELLED,
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

        return ! in_array($this->status, [self::STATUS_PAID, self::STATUS_COMPLETED, self::STATUS_CANCELLED], true);
    }

    public function canBeEdited(): bool
    {
        return ! in_array($this->status, [self::STATUS_CANCELLED, self::STATUS_COMPLETED], true);
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
