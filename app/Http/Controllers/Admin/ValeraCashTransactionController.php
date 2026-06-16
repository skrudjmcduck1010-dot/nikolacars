<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CashbookLabel;
use App\Models\DonorCar;
use App\Models\ValeraCashbookTransfer;
use App\Models\ValeraCashTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ValeraCashTransactionController extends Controller
{
    private const DEFAULT_FROM_DATE = '2023-05-01';

    private const DONOR_EXPENSE_LABEL = 'Донор';

    private const DONOR_EXPENSE_FIELDS = DonorCar::DONOR_EXPENSE_FIELDS;

    private const OPERATION_TYPE_INCOME = 'Приход';

    private const OPERATION_TYPE_EXPENSE = '';

    private const OPERATION_TYPE_EXCHANGE = 'Обмен';

    private const TRANSFER_INCOME_LABEL = 'Приход из Кассы и работ';

    private const TRANSFER_LEGACY_INCOME_LABELS = ['Инкассо Женя', 'Приход Из Кассы Женя'];

    private const TRANSFER_FALLBACK_COMMENT = 'Инкассо Валера';

    private const TRANSFER_CANCELLED_LABEL = 'Отменена инкассация Валера';

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'operation_type' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'label' => ['nullable', 'string', 'max:255'],
            'person' => ['nullable', 'string', 'max:255'],
            'project' => ['nullable', 'string', 'max:255'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', Rule::in(['25', '50', '100', '500', 'all'])],
            'sort' => ['nullable', Rule::in(['operation_date', 'income', 'expense', 'label', 'details', 'amount', 'type', 'person', 'project'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        if (! $request->hasAny(['from', 'to']) && ! ($filters['from'] ?? null) && ! ($filters['to'] ?? null)) {
            $filters['from'] = self::DEFAULT_FROM_DATE;
            $filters['to'] = now()->toDateString();
        }

        $pendingTransfers = ValeraCashbookTransfer::query()
            ->with('cashTransaction')
            ->where('status', 'pending')
            ->whereHas('cashTransaction')
            ->latest()
            ->get();
        $pendingTransferValeraIds = $this->pendingTransferValeraTransactionIds($pendingTransfers);

        $query = ValeraCashTransaction::query()
            ->with('confirmedTransfer')
            ->when($filters['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('operation_date', '>=', $date))
            ->when($filters['to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('operation_date', '<=', $date))
            ->when($filters['operation_type'] ?? null, fn (Builder $query, string $type) => $query->where('operation_type', $type))
            ->when($filters['category'] ?? null, fn (Builder $query, string $category) => $query->where('category', $category))
            ->when($filters['label'] ?? null, fn (Builder $query, string $label) => $query->where('label', $label))
            ->when($filters['person'] ?? null, fn (Builder $query, string $person) => $query->where('person', $person))
            ->when($filters['project'] ?? null, fn (Builder $query, string $project) => $query->where('project', $project))
            ->when($pendingTransferValeraIds !== [], fn (Builder $query) => $query->whereNotIn('id', $pendingTransferValeraIds))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('comment', 'like', "%{$search}%")
                        ->orWhere('vehicle_vin', 'like', "%{$search}%")
                        ->orWhere('purpose', 'like', "%{$search}%")
                        ->orWhere('project', 'like', "%{$search}%")
                        ->orWhere('category', 'like', "%{$search}%")
                        ->orWhere('label', 'like', "%{$search}%")
                        ->orWhere('operation', 'like', "%{$search}%")
                        ->orWhere('person', 'like', "%{$search}%");
                });
            });

        $summary = (clone $query)->selectRaw('
            COALESCE(SUM(income_uah), 0) as income_uah,
            COALESCE(SUM(expense_uah), 0) as expense_uah,
            COALESCE(SUM(income_uah - expense_uah), 0) as net_uah,
            COALESCE(SUM(income_usd), 0) as income_usd,
            COALESCE(SUM(expense_usd), 0) as expense_usd,
            COALESCE(SUM(income_usd - expense_usd), 0) as net_usd
        ')->first();

        $latestBalance = (clone $query)
            ->where(function (Builder $query): void {
                $query->whereNotNull('balance_usd')->orWhereNotNull('balance_uah');
            })
            ->orderByDesc('operation_date')
            ->orderByRaw('source_row IS NULL DESC')
            ->orderByDesc('source_row')
            ->orderByDesc('id')
            ->first();

        if ($latestBalance) {
            $latestBalance = $this->balanceForLatestConfirmedTransaction($latestBalance, $pendingTransferValeraIds);
        }

        $filters['per_page'] = (string) ($filters['per_page'] ?? '100');
        $filters['sort'] = $filters['sort'] ?? 'operation_date';
        $filters['direction'] = $filters['direction'] ?? 'desc';

        $orderedQuery = $this->applySort($query, $filters['sort'], $filters['direction']);
        $showAll = $filters['per_page'] === 'all';
        $labelTypes = CashbookLabel::query()
            ->orderBy('name')
            ->pluck('operation_type', 'name');

        return view('admin.valera_cashbook.index', [
            'transactions' => $showAll ? $orderedQuery->get() : $orderedQuery->paginate((int) $filters['per_page'])->withQueryString(),
            'pendingTransfers' => $pendingTransfers,
            'summary' => $summary,
            'latestBalance' => $latestBalance,
            'filters' => $filters,
            'operationTypes' => $this->distinctValues('operation_type'),
            'categories' => $this->distinctValues('category'),
            'labels' => $labelTypes->keys(),
            'labelTypes' => $labelTypes,
            'donorCars' => $this->donorCars(),
            'selectedLabels' => $this->distinctValues('label'),
            'people' => $this->distinctValues('person'),
            'projects' => $this->distinctValues('project'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $this->payload($request);
        $donorExpenseType = $payload['donor_expense_type'] ?? null;
        unset($payload['donor_expense_type']);

        DB::transaction(function () use ($payload, $donorExpenseType): void {
            $transaction = ValeraCashTransaction::query()->create($payload);
            $this->syncDonorExpense($transaction, $donorExpenseType);
            $this->recalculateBalances();
        });

        return redirect()
            ->route('admin.valera-cashbook.index')
            ->with('status', 'Операция добавлена в кассу Валеры.');
    }

    public function confirmTransfer(ValeraCashbookTransfer $transfer): RedirectResponse
    {
        if ($transfer->status !== 'pending') {
            return back()->with('status', 'Инкассация уже подтверждена.');
        }

        $transfer->load('cashTransaction');
        $cashTransaction = $transfer->cashTransaction;

        abort_if(! $cashTransaction, 404);

        DB::transaction(function () use ($transfer, $cashTransaction): void {
            CashbookLabel::query()->updateOrCreate(
                ['name' => self::TRANSFER_INCOME_LABEL],
                ['operation_type' => 'income'],
            );

            $transaction = $this->matchingTransferIncome($cashTransaction);

            if ($transaction) {
                $transaction->forceFill([
                    'operation_type' => self::OPERATION_TYPE_INCOME,
                    'label' => self::TRANSFER_INCOME_LABEL,
                ])->save();
            } else {
                $transaction = ValeraCashTransaction::query()->create([
                    'operation_date' => $cashTransaction->operation_date?->toDateString(),
                    'operation_type' => self::OPERATION_TYPE_INCOME,
                    'amount_usd' => (float) $cashTransaction->expense_cash_usd,
                    'amount_uah' => (float) $cashTransaction->expense_bank_uah + (float) $cashTransaction->expense_cash_uah,
                    'income_usd' => (float) $cashTransaction->expense_cash_usd,
                    'income_uah' => (float) $cashTransaction->expense_bank_uah + (float) $cashTransaction->expense_cash_uah,
                    'expense_usd' => 0,
                    'expense_uah' => 0,
                    'purpose' => $this->transferPurpose($cashTransaction),
                    'comment' => $this->transferPurpose($cashTransaction),
                    'label' => self::TRANSFER_INCOME_LABEL,
                    'source' => 'cashbook_transfer',
                ]);
            }

            $transfer->update([
                'status' => 'confirmed',
                'confirmed_valera_cash_transaction_id' => $transaction->id,
                'confirmed_at' => now(),
            ]);

            $this->recalculateBalances();
        });

        return redirect()
            ->route('admin.valera-cashbook.index')
            ->with('status', 'Инкассация подтверждена и добавлена в кассу Валеры.');
    }

    public function cancelTransfer(ValeraCashbookTransfer $transfer): RedirectResponse
    {
        if ($transfer->status !== 'pending') {
            return back()->with('status', 'Инкассация уже обработана.');
        }

        $transfer->load('cashTransaction');
        $cashTransaction = $transfer->cashTransaction;

        abort_if(! $cashTransaction, 404);

        DB::transaction(function () use ($transfer, $cashTransaction): void {
            $cancelledAmountUsd = (float) $cashTransaction->expense_cash_usd;
            $cancelledAmountUah = (float) $cashTransaction->expense_bank_uah + (float) $cashTransaction->expense_cash_uah;
            $comment = trim((string) $cashTransaction->comment) ?: 'Отменена инкассация Валера';

            CashbookLabel::query()->updateOrCreate(
                ['name' => self::TRANSFER_CANCELLED_LABEL],
                ['operation_type' => 'exchange'],
            );

            $cashTransaction->forceFill([
                'income_bank_uah' => 0,
                'income_cash_uah' => 0,
                'income_cash_usd' => 0,
                'expense_bank_uah' => 0,
                'expense_cash_uah' => 0,
                'expense_cash_usd' => 0,
                'cancelled_amount_uah' => $cancelledAmountUah,
                'cancelled_amount_usd' => $cancelledAmountUsd,
                'cancelled_at' => now(),
            ])->save();

            $transaction = ValeraCashTransaction::query()->create([
                'operation_date' => $cashTransaction->operation_date?->toDateString(),
                'operation_type' => 'Отменена',
                'amount_usd' => 0,
                'amount_uah' => 0,
                'income_usd' => 0,
                'income_uah' => 0,
                'expense_usd' => 0,
                'expense_uah' => 0,
                'cancelled_amount_usd' => $cancelledAmountUsd,
                'cancelled_amount_uah' => $cancelledAmountUah,
                'cancelled_at' => now(),
                'purpose' => $comment,
                'comment' => $comment,
                'label' => self::TRANSFER_CANCELLED_LABEL,
                'source' => 'cashbook_transfer_cancelled',
            ]);

            $transfer->update([
                'status' => 'cancelled',
                'confirmed_valera_cash_transaction_id' => $transaction->id,
                'cancelled_at' => now(),
            ]);

            $this->recalculateBalances();
        });

        return redirect()
            ->route('admin.valera-cashbook.index')
            ->with('status', 'Инкассация отменена. Приход и расход обнулены, сумма сохранена как отмена.');
    }

    public function destroy(ValeraCashTransaction $valeraCashbook): RedirectResponse
    {
        if ($valeraCashbook->isDeletedFromCashbook()) {
            return back()->with('status', 'Эта строка оставлена как след удаленной связанной операции.');
        }

        if (! $valeraCashbook->canBeDeleted()) {
            return back()->with('status', 'Операцию старше 1 суток нельзя удалить.');
        }

        $transfer = ValeraCashbookTransfer::query()
            ->with('cashTransaction')
            ->where('confirmed_valera_cash_transaction_id', $valeraCashbook->id)
            ->where('status', 'confirmed')
            ->first();

        if ($transfer) {
            DB::transaction(function () use ($valeraCashbook, $transfer): void {
                $transfer->cashTransaction?->delete();

                $valeraCashbook->forceFill([
                    'source' => ValeraCashTransaction::SOURCE_CASHBOOK_TRANSFER_DELETED,
                ])->save();

                $this->recalculateBalances();
            });

            return back()->with('status', 'Связанная операция удалена из кассы, строка в кассе Валеры оставлена с пометкой.');
        }

        DB::transaction(function () use ($valeraCashbook): void {
            $valeraCashbook->delete();
            $this->recalculateBalances();
        });

        return back()->with('status', 'Операция удалена.');
    }

    protected function payload(Request $request): array
    {
        $validated = $request->validate([
            'operation_date' => ['required', 'date'],
            'transaction_type' => ['required', Rule::in(['expense', 'exchange'])],
            'label' => ['required', 'string', 'max:255', Rule::exists('cashbook_labels', 'name')],
            'income_uah' => ['nullable', 'numeric', 'min:0'],
            'income_usd' => ['nullable', 'numeric', 'min:0'],
            'expense_uah' => ['nullable', 'numeric', 'min:0'],
            'expense_usd' => ['nullable', 'numeric', 'min:0'],
            'purpose' => ['required', 'string', 'max:1000'],
            'vehicle_vin' => ['nullable', 'string', 'max:255'],
            'donor_expense_type' => ['nullable', Rule::in(array_keys(self::DONOR_EXPENSE_FIELDS))],
            'project' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'operation' => ['nullable', 'string', 'max:255'],
            'person' => ['nullable', 'string', 'max:255'],
        ]);

        $labelType = CashbookLabel::query()
            ->where('name', $validated['label'])
            ->value('operation_type') ?? 'income';

        if ($labelType !== $validated['transaction_type']) {
            throw ValidationException::withMessages([
                'label' => 'Выберите метку из соответствующего типа операции.',
            ]);
        }

        $incomeUah = (float) ($validated['income_uah'] ?? 0);
        $incomeUsd = (float) ($validated['income_usd'] ?? 0);
        $expenseUah = (float) ($validated['expense_uah'] ?? 0);
        $expenseUsd = (float) ($validated['expense_usd'] ?? 0);
        if ($validated['transaction_type'] === 'expense') {
            $incomeUah = 0;
            $incomeUsd = 0;

            if ($expenseUah <= 0 && $expenseUsd <= 0) {
                throw ValidationException::withMessages([
                    'expense_uah' => 'Для расхода заполните сумму.',
                ]);
            }
        }

        $donorExpenseType = null;
        $vehicleVin = trim((string) ($validated['vehicle_vin'] ?? ''));

        if ($validated['transaction_type'] === 'expense' && trim((string) $validated['label']) === self::DONOR_EXPENSE_LABEL) {
            if ($vehicleVin === '') {
                throw ValidationException::withMessages([
                    'vehicle_vin' => 'Для расхода с меткой Донор выберите VIN донора.',
                ]);
            }

            $donorExpenseType = $validated['donor_expense_type'] ?? null;

            if ($donorExpenseType === null) {
                throw ValidationException::withMessages([
                    'donor_expense_type' => 'Выберите статью расхода донора.',
                ]);
            }

            $donorExpenseField = self::DONOR_EXPENSE_FIELDS[$donorExpenseType];
            $donorCar = DonorCar::query()
                ->where('vin', $vehicleVin)
                ->first(['id', 'vin', $donorExpenseField]);

            if (! $donorCar) {
                throw ValidationException::withMessages([
                    'vehicle_vin' => 'Выберите VIN из списка донорских автомобилей.',
                ]);
            }

            if ($donorCar->{$donorExpenseField} !== null) {
                throw ValidationException::withMessages([
                    'donor_expense_type' => 'Эта статья расхода донора уже заполнена.',
                ]);
            }
        } else {
            $vehicleVin = '';
        }

        if ($validated['transaction_type'] === 'exchange'
            && (($incomeUah <= 0 && $incomeUsd <= 0) || ($expenseUah <= 0 && $expenseUsd <= 0))) {
            throw ValidationException::withMessages([
                'income_uah' => 'Для обмена заполните приход и расход.',
            ]);
        }

        $operationType = match ($validated['transaction_type']) {
            'expense' => self::OPERATION_TYPE_EXPENSE,
            'exchange' => self::OPERATION_TYPE_EXCHANGE,
        };
        $purpose = trim((string) $validated['purpose']);
        $person = trim((string) ($validated['person'] ?? '')) ?: null;

        return [
            'operation_date' => $validated['operation_date'],
            'operation_type' => $operationType,
            'amount_usd' => round($incomeUsd - $expenseUsd, 2),
            'amount_uah' => round($incomeUah - $expenseUah, 2),
            'income_usd' => round($incomeUsd, 2),
            'income_uah' => round($incomeUah, 2),
            'expense_usd' => round($expenseUsd, 2),
            'expense_uah' => round($expenseUah, 2),
            'purpose' => $purpose,
            'vehicle_vin' => $vehicleVin !== '' ? $vehicleVin : null,
            'project' => trim((string) ($validated['project'] ?? '')) ?: null,
            'category' => trim((string) ($validated['category'] ?? '')) ?: null,
            'operation' => trim((string) ($validated['operation'] ?? '')) ?: null,
            'person' => $person,
            'comment' => $purpose,
            'label' => $validated['label'],
            'source' => 'manual',
            'donor_expense_type' => $donorExpenseType,
        ];
    }

    protected function syncDonorExpense(ValeraCashTransaction $transaction, ?string $donorExpenseType): void
    {
        if ($donorExpenseType === null || ! array_key_exists($donorExpenseType, self::DONOR_EXPENSE_FIELDS)) {
            return;
        }

        $donorVin = trim((string) $transaction->vehicle_vin);

        if ($donorVin === '') {
            return;
        }

        $amountUsd = (float) $transaction->expense_usd;

        if ($amountUsd <= 0 && (float) $transaction->expense_uah > 0) {
            $amountUsd = round((float) $transaction->expense_uah / 43, 2);
        }

        if ($amountUsd <= 0) {
            return;
        }

        $donorCar = DonorCar::query()
            ->where('vin', $donorVin)
            ->first();

        if (! $donorCar) {
            return;
        }

        $donorExpenseField = self::DONOR_EXPENSE_FIELDS[$donorExpenseType];
        $donorCar->{$donorExpenseField} = $amountUsd;
        $donorCar->setDonorExpenseSource($donorExpenseField, DonorCar::DONOR_EXPENSE_SOURCE_VALERA_CASHBOOK);
        $donorCar->save();
    }

    protected function applySort(Builder $query, string $sort, string $direction): Builder
    {
        $direction = $direction === 'asc' ? 'asc' : 'desc';

        return match ($sort) {
            'income' => $query->orderByRaw("(COALESCE(income_uah, 0) + COALESCE(income_usd, 0)) {$direction}")->orderByDesc('source_row'),
            'expense' => $query->orderByRaw("(COALESCE(expense_uah, 0) + COALESCE(expense_usd, 0)) {$direction}")->orderByDesc('source_row'),
            'label' => $query->orderByRaw("COALESCE(label, '') {$direction}")->orderByDesc('operation_date')->orderByDesc('source_row'),
            'details' => $query->orderBy('comment', $direction)->orderByDesc('operation_date')->orderByDesc('source_row'),
            'amount' => $query->orderByRaw("amount_uah {$direction}, amount_usd {$direction}")->orderByDesc('source_row'),
            'type' => $query->orderBy('operation_type', $direction)->orderByDesc('operation_date')->orderByDesc('source_row'),
            'person' => $query->orderBy('person', $direction)->orderByDesc('operation_date')->orderByDesc('source_row'),
            'project' => $query->orderBy('project', $direction)->orderByDesc('operation_date')->orderByDesc('source_row'),
            default => $this->orderByValeraCashbookSequence($query->orderBy('operation_date', $direction), $direction),
        };
    }

    protected function orderByValeraCashbookSequence(Builder $query, string $direction = 'asc'): Builder
    {
        $direction = $direction === 'desc' ? 'desc' : 'asc';
        $nullDirection = $direction === 'desc' ? 'DESC' : 'ASC';

        return $query
            ->orderByRaw("source_row IS NULL {$nullDirection}")
            ->orderBy('source_row', $direction)
            ->orderBy('id', $direction);
    }

    protected function pendingTransferValeraTransactionIds($pendingTransfers): array
    {
        $ids = [];

        foreach ($pendingTransfers as $transfer) {
            $cashTransaction = $transfer->cashTransaction;

            if (! $cashTransaction) {
                continue;
            }

            $matchingTransaction = $this->matchingTransferIncome($cashTransaction);

            if ($matchingTransaction) {
                $ids[] = $matchingTransaction->id;
            }
        }

        return array_values(array_unique($ids));
    }

    protected function distinctValues(string $column)
    {
        return ValeraCashTransaction::query()
            ->whereNotNull($column)
            ->where($column, '<>', '')
            ->distinct()
            ->orderBy($column)
            ->pluck($column);
    }

    protected function donorCars()
    {
        return DonorCar::query()
            ->realVinOnly()
            ->havingOpenDonorExpenses()
            ->orderByDesc('purchase_date')
            ->orderBy('brand')
            ->orderBy('model')
            ->orderBy('vin')
            ->get(['vin', 'brand', 'model', 'purchase_date', ...array_values(self::DONOR_EXPENSE_FIELDS)]);
    }

    protected function matchingTransferIncome($cashTransaction): ?ValeraCashTransaction
    {
        $date = $cashTransaction->operation_date?->toDateString();

        if (! $date) {
            return null;
        }

        $amountUsd = round((float) $cashTransaction->expense_cash_usd, 2);
        $amountUah = round((float) $cashTransaction->expense_bank_uah + (float) $cashTransaction->expense_cash_uah, 2);
        $purpose = $this->transferPurpose($cashTransaction);
        $labels = [self::TRANSFER_INCOME_LABEL, ...self::TRANSFER_LEGACY_INCOME_LABELS];

        $matches = ValeraCashTransaction::query()
            ->whereDoesntHave('confirmedTransfer')
            ->whereDate('operation_date', $date)
            ->where(function (Builder $query) use ($labels): void {
                $query
                    ->whereIn('label', $labels)
                    ->orWhereNull('label')
                    ->orWhereRaw("TRIM(COALESCE(label, '')) = ''");
            })
            ->orderByDesc('source_row')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (ValeraCashTransaction $transaction): bool => (
                (
                    round((float) $transaction->income_usd, 2) === $amountUsd
                    && round((float) $transaction->income_uah, 2) === $amountUah
                ) || (
                    round((float) $transaction->amount_usd, 2) === $amountUsd
                    && round((float) $transaction->amount_uah, 2) === $amountUah
                )
            ));

        return $matches->first(fn (ValeraCashTransaction $transaction): bool => trim((string) $transaction->comment) === $purpose
            || trim((string) $transaction->purpose) === $purpose)
            ?? $matches->first(fn (ValeraCashTransaction $transaction): bool => in_array($transaction->label, $labels, true));
    }

    protected function transferPurpose($cashTransaction): string
    {
        return trim((string) $cashTransaction->comment) ?: self::TRANSFER_FALLBACK_COMMENT;
    }

    protected function balanceForLatestConfirmedTransaction(ValeraCashTransaction $latestTransaction, array $pendingTransactionIds): object
    {
        $latestSourceRow = $latestTransaction->source_row ?? PHP_INT_MAX;

        $totals = ValeraCashTransaction::query()
            ->when($pendingTransactionIds !== [], fn (Builder $query) => $query->whereNotIn('id', $pendingTransactionIds))
            ->where(function (Builder $query) use ($latestTransaction, $latestSourceRow): void {
                $query
                    ->whereDate('operation_date', '<', $latestTransaction->operation_date)
                    ->orWhere(function (Builder $query) use ($latestTransaction, $latestSourceRow): void {
                        $query
                            ->whereDate('operation_date', $latestTransaction->operation_date)
                            ->where(function (Builder $query) use ($latestTransaction, $latestSourceRow): void {
                                $query
                                    ->where('source_row', '<', $latestSourceRow)
                                    ->orWhere(function (Builder $query) use ($latestTransaction, $latestSourceRow): void {
                                        $query
                                            ->where(function (Builder $query) use ($latestSourceRow): void {
                                                if ($latestSourceRow === PHP_INT_MAX) {
                                                    $query->whereNull('source_row');
                                                } else {
                                                    $query->where('source_row', $latestSourceRow);
                                                }
                                            })
                                            ->where('id', '<=', $latestTransaction->id);
                                    });
                            });
                    });
            })
            ->selectRaw('
                COALESCE(SUM(income_uah - expense_uah), 0) as balance_uah,
                COALESCE(SUM(income_usd - expense_usd), 0) as balance_usd
            ')
            ->first();

        return (object) [
            'balance_uah' => round((float) $totals->balance_uah, 2),
            'balance_usd' => round((float) $totals->balance_usd, 2),
        ];
    }

    protected function recalculateBalances(): void
    {
        $balanceUsd = 0.0;
        $balanceUah = 0.0;

        ValeraCashTransaction::query()
            ->orderBy('operation_date')
            ->orderByRaw('source_row IS NULL ASC')
            ->orderBy('source_row')
            ->orderBy('id')
            ->get()
            ->each(function (ValeraCashTransaction $transaction) use (&$balanceUsd, &$balanceUah): void {
                $balanceUsd += (float) $transaction->income_usd - (float) $transaction->expense_usd;
                $balanceUah += (float) $transaction->income_uah - (float) $transaction->expense_uah;

                $transaction->forceFill([
                    'balance_usd' => round($balanceUsd, 2),
                    'balance_uah' => round($balanceUah, 2),
                ])->save();
            });
    }
}
