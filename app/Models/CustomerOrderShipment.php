<?php

namespace App\Models;

use App\Models\Concerns\TracksUserStamps;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'customer_order_id',
    'carrier',
    'status',
    'recipient_city_name',
    'recipient_warehouse_name',
    'recipient_warehouse_ref',
    'recipient_name',
    'recipient_phone',
    'payer_type',
    'payment_method',
    'seats_amount',
    'weight',
    'length_cm',
    'width_cm',
    'height_cm',
    'declared_cost',
    'afterpayment_amount',
    'cargo_description',
    'np_ref',
    'tracking_number',
    'np_status_code',
    'np_status',
    'np_status_detail',
    'np_return_tracking_number',
    'np_return_document_type',
    'np_return_created_at',
    'np_return_status_code',
    'np_return_status',
    'np_return_status_detail',
    'np_return_status_checked_at',
    'np_status_checked_at',
    'label_url',
    'raw_response',
    'error_message',
    'created_by',
    'updated_by',
])]
class CustomerOrderShipment extends Model
{
    use TracksUserStamps;

    public const CARRIER_NOVA_POSHTA = 'nova_poshta';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_CREATED = 'created';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'seats_amount' => 'integer',
            'weight' => 'decimal:3',
            'length_cm' => 'integer',
            'width_cm' => 'integer',
            'height_cm' => 'integer',
            'declared_cost' => 'decimal:2',
            'afterpayment_amount' => 'decimal:2',
            'np_status_checked_at' => 'datetime',
            'np_return_created_at' => 'datetime',
            'np_return_status_checked_at' => 'datetime',
            'raw_response' => 'array',
        ];
    }

    public function customerOrder(): BelongsTo
    {
        return $this->belongsTo(CustomerOrder::class);
    }

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(CustomerOrderItem::class, 'customer_order_shipment_items')
            ->withTimestamps();
    }

    public function getTrackingUrlAttribute(): ?string
    {
        if (! $this->tracking_number) {
            return null;
        }

        return 'https://novaposhta.ua/tracking/'.rawurlencode($this->tracking_number);
    }

    public function getReturnTrackingUrlAttribute(): ?string
    {
        if (! $this->np_return_tracking_number) {
            return null;
        }

        return 'https://novaposhta.ua/tracking/'.rawurlencode($this->np_return_tracking_number);
    }
}
