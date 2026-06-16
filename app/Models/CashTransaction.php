<?php

namespace App\Models;

use App\Models\Concerns\HasUppercaseVinAttributes;
use App\Models\Concerns\TracksUserStamps;
use App\Support\CatalogTextEncoding;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'operation_date',
    'income_bank_uah',
    'income_cash_uah',
    'income_cash_usd',
    'expense_bank_uah',
    'expense_cash_uah',
    'expense_cash_usd',
    'cancelled_amount_uah',
    'cancelled_amount_usd',
    'cancelled_at',
    'label',
    'employee',
    'vehicle_vin',
    'comment',
    'source',
    'source_sheet',
    'exchange_rate',
    'created_by',
    'updated_by',
])]
class CashTransaction extends Model
{
    use HasFactory;
    use HasUppercaseVinAttributes;
    use TracksUserStamps;

    public const SOURCE_STO_WORK_ORDER_PAYMENT = 'sto_work_order_payment';

    protected function casts(): array
    {
        return [
            'operation_date' => 'date',
            'income_bank_uah' => 'decimal:2',
            'income_cash_uah' => 'decimal:2',
            'income_cash_usd' => 'decimal:2',
            'expense_bank_uah' => 'decimal:2',
            'expense_cash_uah' => 'decimal:2',
            'expense_cash_usd' => 'decimal:2',
            'cancelled_amount_uah' => 'decimal:2',
            'cancelled_amount_usd' => 'decimal:2',
            'cancelled_at' => 'datetime',
            'exchange_rate' => 'decimal:2',
        ];
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function canBeDeleted(): bool
    {
        return $this->created_at !== null && $this->created_at->greaterThanOrEqualTo(now()->subDay());
    }

    public function canBeEdited(): bool
    {
        return $this->created_at !== null && $this->created_at->greaterThanOrEqualTo(now()->subDay());
    }

    public static function normalizeEmployeeName(?string $employee): ?string
    {
        $employee = trim(preg_replace('/\s+/u', ' ', (string) $employee));

        if ($employee === '') {
            return null;
        }

        $employee = self::decodeWindows1251Mojibake($employee);
        $comparison = mb_strtolower(str_replace(['.', ','], ' ', $employee));
        $comparison = trim(preg_replace('/\s+/u', ' ', $comparison));

        if (str_starts_with($comparison, 'зинченко антон')) {
            return 'Зинченко Антон';
        }

        if ($comparison === 'зинченко') {
            return 'Зинченко Евгений';
        }

        if (in_array($comparison, ['оверчук', 'оверчук в в', 'оверчук в'], true)) {
            return 'Оверчук Валерий';
        }

        if (in_array($comparison, ['раджепова', 'реджепова', 'роджепова'], true)) {
            return null;
        }

        if (in_array($comparison, ['лисенко', 'лисенко денис', 'лысенко'], true)) {
            return 'Лисенко Денис';
        }

        if (in_array($comparison, ['алена', 'алена учет'], true)) {
            return 'Алена';
        }

        if (in_array($comparison, ['дима', 'дмитрий'], true)) {
            return 'Дима';
        }

        if (in_array($comparison, ['леха', 'леша', 'малой', 'леха малой', 'менеджер малой'], true)) {
            return 'Леха';
        }

        if (str_starts_with($comparison, 'беркела')) {
            return 'Беркела';
        }

        if (str_starts_with($comparison, 'дидик') || str_starts_with($comparison, 'дидык')) {
            return 'Дидык Сергей';
        }

        if ($comparison === 'обманщиков') {
            return 'Обманщиков Евгений';
        }

        if ($comparison === 'раздорин') {
            return 'Раздорин Влад';
        }

        return $employee;
    }

    public static function decodeWindows1251Mojibake(string $value): string
    {
        return CatalogTextEncoding::repair($value) ?? $value;
    }

    public function totalIncomeUah(): float
    {
        return (float) $this->income_bank_uah + (float) $this->income_cash_uah;
    }

    public function incomeUahPaymentLabel(): ?string
    {
        return $this->uahPaymentLabel((float) $this->income_cash_uah, (float) $this->income_bank_uah);
    }

    public function totalExpenseUah(): float
    {
        return (float) $this->expense_bank_uah + (float) $this->expense_cash_uah;
    }

    public function expenseUahPaymentLabel(): ?string
    {
        return $this->uahPaymentLabel((float) $this->expense_cash_uah, (float) $this->expense_bank_uah);
    }

    public function netUah(): float
    {
        return $this->totalIncomeUah() - $this->totalExpenseUah();
    }

    public function netUsd(): float
    {
        return (float) $this->income_cash_usd - (float) $this->expense_cash_usd;
    }

    protected function uahPaymentLabel(float $cashUah, float $bankUah): ?string
    {
        if ($cashUah > 0 && $bankUah > 0) {
            return 'Нал + Безнал';
        }

        if ($cashUah > 0) {
            return 'Нал';
        }

        if ($bankUah > 0) {
            return 'Безнал';
        }

        return null;
    }

    public function isStoWorkOrderPayment(): bool
    {
        return $this->source === self::SOURCE_STO_WORK_ORDER_PAYMENT;
    }

    public function hasConfirmedValeraCashbookTransfer(): bool
    {
        if (! $this->relationLoaded('valeraCashbookTransfer')) {
            $this->load('valeraCashbookTransfer');
        }

        return $this->valeraCashbookTransfer?->status === 'confirmed';
    }

    public function detailsText(): string
    {
        $comment = trim((string) $this->comment);

        if ($comment !== '') {
            return $comment;
        }

        if (! $this->relationLoaded('purchase') || ! $this->purchase) {
            return '';
        }

        return $this->purchase->items
            ->map(function (PurchaseItem $item): string {
                return trim(collect([
                    $item->product?->name,
                    $item->product?->model,
                ])->filter()->join(' - '));
            })
            ->filter()
            ->implode('; ');
    }

    public function purchase(): HasOne
    {
        return $this->hasOne(Purchase::class);
    }

    public function valeraCashbookTransfer(): HasOne
    {
        return $this->hasOne(ValeraCashbookTransfer::class);
    }
}
