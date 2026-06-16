<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'operation_date',
    'operation_type',
    'amount_usd',
    'amount_uah',
    'income_usd',
    'income_uah',
    'expense_usd',
    'expense_uah',
    'cancelled_amount_usd',
    'cancelled_amount_uah',
    'cancelled_at',
    'purpose',
    'vehicle_vin',
    'project',
    'category',
    'label',
    'operation',
    'person',
    'comment',
    'balance_usd',
    'balance_uah',
    'source',
    'source_sheet',
    'source_row',
])]
class ValeraCashTransaction extends Model
{
    use HasFactory;

    public const SOURCE_CASHBOOK_TRANSFER_DELETED = 'cashbook_transfer_deleted';

    protected static function booted(): void
    {
        static::saving(function (self $transaction): void {
            $signedUsd = (float) $transaction->amount_usd;
            $signedUah = (float) $transaction->amount_uah;

            if ((float) $transaction->income_usd === 0.0 && (float) $transaction->expense_usd === 0.0 && $signedUsd !== 0.0) {
                $transaction->income_usd = max($signedUsd, 0);
                $transaction->expense_usd = abs(min($signedUsd, 0));
            }

            if ((float) $transaction->income_uah === 0.0 && (float) $transaction->expense_uah === 0.0 && $signedUah !== 0.0) {
                $transaction->income_uah = max($signedUah, 0);
                $transaction->expense_uah = abs(min($signedUah, 0));
            }

            if ($signedUsd === 0.0 && ((float) $transaction->income_usd !== 0.0 || (float) $transaction->expense_usd !== 0.0)) {
                $transaction->amount_usd = (float) $transaction->income_usd - (float) $transaction->expense_usd;
            }

            if ($signedUah === 0.0 && ((float) $transaction->income_uah !== 0.0 || (float) $transaction->expense_uah !== 0.0)) {
                $transaction->amount_uah = (float) $transaction->income_uah - (float) $transaction->expense_uah;
            }

            if (blank($transaction->comment)) {
                $comment = collect([
                    trim((string) $transaction->purpose),
                    trim((string) $transaction->project),
                ])->filter()->implode(' - ');

                $transaction->comment = $comment !== '' ? $comment : null;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'operation_date' => 'date',
            'amount_usd' => 'decimal:2',
            'amount_uah' => 'decimal:2',
            'income_usd' => 'decimal:2',
            'income_uah' => 'decimal:2',
            'expense_usd' => 'decimal:2',
            'expense_uah' => 'decimal:2',
            'cancelled_amount_usd' => 'decimal:2',
            'cancelled_amount_uah' => 'decimal:2',
            'cancelled_at' => 'datetime',
            'balance_usd' => 'decimal:2',
            'balance_uah' => 'decimal:2',
            'source_row' => 'integer',
        ];
    }

    public function isIncome(): bool
    {
        $type = mb_strtolower(trim((string) $this->operation_type));

        if ($type === 'приход') {
            return true;
        }

        if ($type === 'расход') {
            return false;
        }

        return $this->signedTotal() > 0;
    }

    public function isExpense(): bool
    {
        $type = mb_strtolower(trim((string) $this->operation_type));

        if ($type === 'расход') {
            return true;
        }

        if ($type === 'приход') {
            return false;
        }

        return $this->signedTotal() < 0;
    }

    public function signedTotal(): float
    {
        return (float) $this->income_uah + (float) $this->income_usd
            - (float) $this->expense_uah - (float) $this->expense_usd;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function canBeDeleted(): bool
    {
        return $this->created_at !== null && $this->created_at->greaterThanOrEqualTo(now()->subDay());
    }

    public function isDeletedFromCashbook(): bool
    {
        return $this->source === self::SOURCE_CASHBOOK_TRANSFER_DELETED;
    }

    public function confirmedTransfer(): HasOne
    {
        return $this->hasOne(ValeraCashbookTransfer::class, 'confirmed_valera_cash_transaction_id');
    }

    public function detailsText(): string
    {
        $comment = trim((string) $this->comment);
        $vehicleVin = trim((string) $this->vehicle_vin);

        if ($vehicleVin !== '') {
            return collect([$vehicleVin, $comment])->filter()->implode(' - ');
        }

        if ($comment !== '') {
            return $comment;
        }

        return collect([
            trim((string) $this->purpose),
            trim((string) $this->project),
        ])->filter()->implode(' - ');
    }
}
