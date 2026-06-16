<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CashbookLabel;
use App\Models\CashTransaction;
use App\Models\Counterparty;
use App\Models\DonorCar;
use App\Models\Location;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StoEmployee;
use App\Models\StoWorkOrder;
use App\Models\ValeraCashbookTransfer;
use App\Models\Warehouse;
use App\Services\StockService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CashTransactionController extends Controller
{
    private const FILTER_SESSION_KEY = 'admin.cashbook.filters';

    private const WITHOUT_LABEL_FILTER = '__without_label__';

    private const HIDDEN_CASHBOOK_LABELS = ['Инкассо Женя', 'Приход из Кассы и работ', 'Дивиденды'];

    private const VALERA_CASHBOOK_TRANSFER_LABEL = 'Инкассо Валера';

    private const PARTS_PURCHASE_LABEL = 'Закупка ЗЧК';

    private const HIDDEN_CASHBOOK_DONOR_CAR_IDS = [64, 61, 60, 59, 57, 56];

    private const DONOR_EXPENSE_FIELDS = DonorCar::DONOR_EXPENSE_FIELDS;

    private const DONOR_PARTS_SALE_LABELS = [' ', '  '];

    private const OLD_REPAIR_MECHANIC_LABELS = ['+', '1', '2'];

    private const REPAIR_MECHANIC_LABELS = ['', '+', '1', '2'];

    private const PURCHASE_PRODUCT_OPTION_LIMIT = 500;

    public function __construct(private readonly StockService $stockService) {}

    public function index(Request $request): View
    {
        return view('admin.cashbook.index', $this->buildCashbookDashboardData($request));
    }

    protected function buildCashbookDashboardData(Request $request, bool $includeTransactions = true): array
    {
        $this->mergeRepairMechanicLabels();

        $supportsSourceSheetFilter = $this->supportsSourceSheetFilter($request);

        if ($request->boolean('clear_filters')) {
            $request->session()->forget(self::FILTER_SESSION_KEY);
        } elseif (! $this->hasCashbookFilterInput($request) && $request->session()->has(self::FILTER_SESSION_KEY)) {
            $storedFilters = $request->session()->get(self::FILTER_SESSION_KEY, []);

            if (! $supportsSourceSheetFilter) {
                unset($storedFilters['source_sheet']);
            }

            $request->merge($storedFilters);
        }

        if ($request->filled('label') && ! is_array($request->input('label'))) {
            $request->merge(['label' => [$request->input('label')]]);
        }

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'label' => ['nullable', 'array'],
            'label.*' => ['string', 'max:255'],
            'operation_type' => ['nullable', Rule::in(['income', 'expense', 'exchange'])],
            'employee' => ['nullable', 'string', 'max:255'],
            'source_sheet' => ['nullable', 'string', 'max:255'],
            'search' => ['nullable', 'string', 'max:255'],
            'show_all' => ['nullable', 'boolean'],
            'per_page' => ['nullable', Rule::in(['25', '50', '100', '500', 'all'])],
            'usd_rate' => ['nullable', 'numeric', 'min:0'],
            'sort' => ['nullable', Rule::in(['operation_date', 'income', 'expense', 'label', 'employee', 'details'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        if (! $supportsSourceSheetFilter) {
            unset($filters['source_sheet']);
        }

        if (! $request->boolean('clear_filters') && $this->hasCashbookFilterInput($request)) {
            $request->session()->put(self::FILTER_SESSION_KEY, $this->cashbookFilterState($request, $filters));
        }

        if (! $request->hasAny(['from', 'to']) && ! ($filters['from'] ?? null) && ! ($filters['to'] ?? null)) {
            $filters['from'] = now()->startOfMonth()->toDateString();
            $filters['to'] = now()->toDateString();
        }

        if ($supportsSourceSheetFilter && ! $request->hasAny(['from', 'to', 'source_sheet']) && ! ($filters['source_sheet'] ?? null)) {
            $filters['source_sheet'] = $this->defaultSourceSheetForPeriod($filters['from'], $filters['to']);
        }

        $query = CashTransaction::query()
            ->with(['purchase.items.product', 'valeraCashbookTransfer'])
            ->where(function (Builder $query): void {
                $query
                    ->whereNotIn('label', ['Инкассо Женя', 'Приход из Кассы и работ'])
                    ->orWhereNull('label');
            })
            ->when($filters['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('operation_date', '>=', $date))
            ->when($filters['to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('operation_date', '<=', $date))
            ->when($filters['label'] ?? null, function (Builder $query, array $labels): void {
                $withoutLabel = in_array(self::WITHOUT_LABEL_FILTER, $labels, true);
                $labels = array_values(array_diff($labels, [self::WITHOUT_LABEL_FILTER]));
                $labels = $this->expandCashbookLabelsWithChildren($labels);

                $query->where(function (Builder $query) use ($labels, $withoutLabel): void {
                    if ($labels !== []) {
                        $query->whereIn('label', $labels);
                    }

                    if ($withoutLabel) {
                        $method = $labels === [] ? 'where' : 'orWhere';

                        $query->{$method}(function (Builder $query): void {
                            $query
                                ->whereNull('label')
                                ->orWhereRaw("TRIM(COALESCE(label, '')) = ''");
                        });
                    }
                });
            })
            ->when($filters['operation_type'] ?? null, function (Builder $query, string $type): void {
                $exchangeLabels = $this->cashbookLabelsByOperationType('exchange');

                $query->where(function (Builder $query) use ($type): void {
                    if ($type === 'exchange') {
                        $query->whereIn('label', $this->cashbookLabelsByOperationType('exchange'));

                        return;
                    }

                    if ($type === 'income') {
                        $query
                            ->where('income_bank_uah', '>', 0)
                            ->orWhere('income_cash_uah', '>', 0)
                            ->orWhere('income_cash_usd', '>', 0);

                        return;
                    }

                    $query
                        ->where('expense_bank_uah', '>', 0)
                        ->orWhere('expense_cash_uah', '>', 0)
                        ->orWhere('expense_cash_usd', '>', 0);
                });

                if ($type !== 'exchange' && $exchangeLabels->isNotEmpty()) {
                    $query->where(function (Builder $query) use ($exchangeLabels): void {
                        $query
                            ->whereNotIn('label', $exchangeLabels)
                            ->orWhereNull('label')
                            ->orWhereRaw("TRIM(COALESCE(label, '')) = ''");
                    });
                }
            })
            ->when($filters['employee'] ?? null, fn (Builder $query, string $employee) => $this->applyCashbookEmployeeFilter($query, $employee))
            ->when($filters['source_sheet'] ?? null, fn (Builder $query, string $sheet) => $query->where('source_sheet', $sheet))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('comment', 'like', "%{$search}%")
                        ->orWhere('vehicle_vin', 'like', "%{$search}%")
                        ->orWhere('label', 'like', "%{$search}%")
                        ->orWhere('employee', 'like', "%{$search}%");
                });
            });

        $summary = (clone $query)->selectRaw('
            COALESCE(SUM(income_bank_uah), 0) as income_bank_uah,
            COALESCE(SUM(income_cash_uah), 0) as income_cash_uah,
            COALESCE(SUM(income_cash_usd), 0) as income_cash_usd,
            COALESCE(SUM(expense_bank_uah), 0) as expense_bank_uah,
            COALESCE(SUM(expense_cash_uah), 0) as expense_cash_uah,
            COALESCE(SUM(expense_cash_usd), 0) as expense_cash_usd
        ')->first();

        $labels = $this->cashbookLabels();

        $employeeGroups = $this->cashbookFilterEmployeeGroups();
        $employees = $employeeGroups['active']->merge($employeeGroups['inactive']);

        $sourceSheets = CashTransaction::query()
            ->whereNotNull('source_sheet')
            ->where('source_sheet', '<>', '')
            ->select('source_sheet')
            ->selectRaw('MAX(operation_date) as latest_operation_date')
            ->groupBy('source_sheet')
            ->orderByDesc('latest_operation_date')
            ->pluck('source_sheet');

        $sourceSheetPeriods = CashTransaction::query()
            ->whereNotNull('source_sheet')
            ->where('source_sheet', '<>', '')
            ->select('source_sheet')
            ->selectRaw('MIN(operation_date) as first_operation_date')
            ->selectRaw('MAX(operation_date) as latest_operation_date')
            ->groupBy('source_sheet')
            ->get()
            ->keyBy('source_sheet');

        $stoExpenseLabels = [
            'Аренда',
            'Коммунальные',
            'Ремонт',
            '',
            'Связь',
            'Инструмент',
            '',
            'Продукты',
            ' ',
            'Налоги',
            'Прочие',
        ];

        $labelSummary = (clone $query)
            ->select('label')
            ->selectRaw('COALESCE(SUM(income_bank_uah + income_cash_uah), 0) as income_uah')
            ->selectRaw('COALESCE(SUM(expense_bank_uah + expense_cash_uah), 0) as expense_uah')
            ->selectRaw('COALESCE(SUM(income_cash_usd - expense_cash_usd), 0) as net_usd')
            ->whereNotNull('label')
            ->whereIn('label', $stoExpenseLabels)
            ->groupBy('label')
            ->orderByRaw("CASE TRIM(label)
                WHEN 'Аренда' THEN 1
                WHEN 'Коммунальные' THEN 2
                WHEN '' THEN 3
                WHEN '' THEN 4
                WHEN 'Связь' THEN 5
                WHEN 'Инструмент' THEN 6
                WHEN '' THEN 7
                WHEN 'Продукты' THEN 8
                WHEN ' ' THEN 9
                WHEN 'Налоги' THEN 10
                WHEN 'Прочие' THEN 11
                ELSE 999
            END")
            ->get();

        $employeeSummary = (clone $query)
            ->select('employee')
            ->selectRaw('COALESCE(SUM(expense_bank_uah + expense_cash_uah), 0) as salary_uah')
            ->selectRaw('COALESCE(SUM(expense_cash_usd), 0) as salary_usd')
            ->whereNotNull('employee')
            ->whereRaw("TRIM(COALESCE(label, '')) = ?", ['ЗП'])
            ->groupBy('employee')
            ->orderBy('employee')
            ->get();

        $periodRows = (clone $query)->get();
        $stoEmployees = StoEmployee::query()
            ->whereIn('cash_employee_name', $employeeSummary->pluck('employee')->filter()->values())
            ->get()
            ->keyBy('cash_employee_name');

        $employeeSummary = $employeeSummary->map(function ($row) use ($stoEmployees, $periodRows): object {
            $employee = $stoEmployees->get($row->employee);
            $bonus = $employee ? $this->bonusCalculationForRows($employee, $periodRows) : null;

            return (object) [
                'employee' => $row->employee,
                'sto_employee_id' => $employee?->id,
                'rate_uah' => round((float) ($employee->rate ?? 0), 2),
                'bonus_uah' => round((float) ($bonus['bonus_amount_uah'] ?? 0), 2),
            ];
        });

        $dividendsSummary = (clone $query)
            ->selectRaw('COALESCE(SUM(expense_bank_uah + expense_cash_uah - income_bank_uah - income_cash_uah), 0) as uah')
            ->selectRaw('COALESCE(SUM(expense_cash_usd - income_cash_usd), 0) as usd')
            ->whereRaw("TRIM(COALESCE(label, '')) = ?", ['Дивиденды'])
            ->first();

        $repairProfitTable = $this->repairProfitTableSnapshot(clone $query, (float) ($filters['usd_rate'] ?? 43));
        $partsProfitTable = $this->partsProfitTableSnapshot(clone $query, (float) ($filters['usd_rate'] ?? 43));
        $profit = $this->profitSnapshot(
            clone $query,
            $partsProfitTable['total']['uah_total'],
            $repairProfitTable['total_full_uah'],
            (float) $labelSummary->sum('expense_uah'),
        );
        $perPage = (string) ($filters['per_page'] ?? '100');

        if (($filters['show_all'] ?? false) && ! $request->filled('per_page')) {
            $perPage = 'all';
        }

        $filters['per_page'] = $perPage;
        $filters['sort'] = $filters['sort'] ?? 'operation_date';
        $filters['direction'] = $filters['direction'] ?? 'desc';
        $showAll = $perPage === 'all';
        $orderedQuery = $this->applyCashbookSort($query, $filters['sort'], $filters['direction']);

        $data = [
            'summary' => $summary,
            'labels' => $labels,
            'labelTypes' => $this->cashbookLabelTypes(),
            'labelParents' => $this->cashbookLabelParents(),
            'parentLabels' => $this->cashbookParentLabels(),
            'donorCars' => $this->cashbookDonorCars(),
            ...$this->partsPurchaseFormOptions(),
            'employees' => $employees,
            'inactiveEmployees' => $employeeGroups['inactive'],
            'activeEmployees' => $this->cashbookActiveEmployees(),
            'activeMechanicEmployees' => $this->cashbookActiveMechanicEmployees(),
            'sourceSheets' => $sourceSheets,
            'sourceSheetPeriods' => $sourceSheetPeriods,
            'labelSummary' => $labelSummary,
            'employeeSummary' => $employeeSummary,
            'dividendsSummary' => $dividendsSummary,
            'profit' => $profit,
            'partsProfitTable' => $partsProfitTable,
            'repairProfitTable' => $repairProfitTable,
            'filters' => $filters,
            'showAll' => $showAll,
            'newTransaction' => new CashTransaction([
                'operation_date' => now(),
                'source' => 'manual',
                'source_sheet' => $filters['source_sheet'] ?? null,
                'exchange_rate' => $filters['usd_rate'] ?? 43,
            ]),
        ];

        if ($includeTransactions) {
            $data['transactions'] = $showAll
                ? $orderedQuery->get()
                : $orderedQuery->paginate((int) $perPage)->withQueryString();

            $data['workOrdersByNumber'] = $this->workOrdersByNumberForCashbookRows($data['transactions']);
            $data['workOrderEmployeesByCashTransaction'] = $this->workOrderEmployeesByCashTransaction(
                $data['transactions'],
                $data['workOrdersByNumber'],
            );
        }

        return $data;
    }

    protected function workOrdersByNumberForCashbookRows(iterable $transactions): Collection
    {
        if (method_exists($transactions, 'getCollection')) {
            $transactions = $transactions->getCollection();
        }

        $numbers = collect($transactions)
            ->flatMap(fn (CashTransaction $transaction): array => $this->extractWorkOrderNumbers($transaction->detailsText()))
            ->unique()
            ->values();

        if ($numbers->isEmpty()) {
            return collect();
        }

        $lookupNumbers = $numbers
            ->flatMap(fn (string $number): array => $this->workOrderNumberVariants($number))
            ->unique()
            ->values();

        return StoWorkOrder::query()
            ->with('works.employee')
            ->whereIn('number', $lookupNumbers)
            ->get()
            ->flatMap(function (StoWorkOrder $order): array {
                return collect($this->workOrderNumberVariants($order->number))
                    ->mapWithKeys(fn (string $number): array => [$number => $order])
                    ->all();
            });
    }

    protected function applyCashbookEmployeeFilter(Builder $query, string $employee): void
    {
        $workOrderNumbers = StoWorkOrder::query()
            ->whereHas('works.employee', fn (Builder $query): Builder => $query->where('cash_employee_name', $employee))
            ->pluck('number')
            ->flatMap(fn (string $number): array => $this->workOrderNumberVariants($number))
            ->unique()
            ->values();

        $query->where(function (Builder $query) use ($employee, $workOrderNumbers): void {
            $query->where('employee', $employee);

            if ($workOrderNumbers->isEmpty()) {
                return;
            }

            $query->orWhere(function (Builder $query) use ($workOrderNumbers): void {
                $query
                    ->where('label', '+')
                    ->where(function (Builder $query) use ($workOrderNumbers): void {
                        foreach ($workOrderNumbers as $number) {
                            $query->orWhere('comment', 'like', "%{$number}%");
                        }
                    });
            });
        });
    }

    protected function workOrderEmployeesByCashTransaction(iterable $transactions, Collection $workOrdersByNumber): Collection
    {
        if (method_exists($transactions, 'getCollection')) {
            $transactions = $transactions->getCollection();
        }

        return collect($transactions)
            ->mapWithKeys(function (CashTransaction $transaction) use ($workOrdersByNumber): array {
                if (trim((string) $transaction->label) !== '+') {
                    return [];
                }

                $employees = collect($this->extractWorkOrderNumbers($transaction->detailsText()))
                    ->map(function (string $number) use ($workOrdersByNumber): ?StoWorkOrder {
                        return $workOrdersByNumber->get($number);
                    })
                    ->filter()
                    ->unique('id')
                    ->flatMap(fn (StoWorkOrder $order) => $order->works)
                    ->map(fn ($work): ?string => $work->employee?->cash_employee_name)
                    ->filter()
                    ->unique()
                    ->values()
                    ->implode(', ');

                return $employees !== '' ? [$transaction->id => $employees] : [];
            });
    }

    protected function extractWorkOrderNumbers(string $text): array
    {
        $prefixes = implode('|', array_map(fn (string $prefix): string => preg_quote($prefix, '/'), $this->workOrderNumberPrefixes()));
        preg_match_all('/(?:'.$prefixes.')-\d{8}-\d{4}/u', $text, $matches);

        return $matches[0] ?? [];
    }

    protected function workOrderNumberVariants(string $number): array
    {
        $prefixes = implode('|', array_map(fn (string $prefix): string => preg_quote($prefix, '/'), $this->workOrderNumberPrefixes()));

        if (! preg_match('/^(?:'.$prefixes.')-(\d{8}-\d{4})$/u', $number, $matches)) {
            return [$number];
        }

        return collect($this->workOrderNumberPrefixes())
            ->map(fn (string $prefix): string => $prefix.'-'.$matches[1])
            ->all();
    }

    protected function workOrderNumberPrefixes(): array
    {
        return ['ЗН', $this->legacyMojibakeWorkOrderPrefix(), 'ZN'];
    }

    protected function legacyMojibakeWorkOrderPrefix(): string
    {
        return mb_chr(0x0420).mb_chr(0x2014).mb_chr(0x0420).mb_chr(0x045C);
    }

    protected function alternateWorkOrderNumberPrefix(string $number): ?string
    {
        $variants = $this->workOrderNumberVariants($number);

        return collect($variants)->first(fn (string $variant): bool => $variant !== $number);
    }

    public function create(): View
    {
        $this->mergeRepairMechanicLabels();

        return view('admin.cashbook.form', [
            'labels' => $this->cashbookLabels(),
            'labelTypes' => $this->cashbookLabelTypes(),
            'labelParents' => $this->cashbookLabelParents(),
            'parentLabels' => $this->cashbookParentLabels(),
            'donorCars' => $this->cashbookDonorCars(),
            ...$this->partsPurchaseFormOptions(),
            'activeEmployees' => $this->cashbookActiveEmployees(),
            'activeMechanicEmployees' => $this->cashbookActiveMechanicEmployees(),
            'transaction' => new CashTransaction([
                'operation_date' => now(),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (trim((string) $request->input('label')) === self::PARTS_PURCHASE_LABEL && trim((string) $request->input('comment')) === '') {
            $request->merge(['comment' => self::PARTS_PURCHASE_LABEL]);
        }

        $payload = $this->payload($request);
        $donorExpenseType = $this->validatedDonorExpenseType($request, $payload);
        $purchaseData = $this->shouldCreatePartsPurchase($request, $payload)
            ? $this->validatedPartsPurchaseData($request)
            : null;

        if ($purchaseData !== null) {
            $payload['comment'] = $this->partsPurchaseItemsDetails($purchaseData['purchase_items']) ?: $payload['comment'];
        }

        DB::transaction(function () use ($payload, $purchaseData, $donorExpenseType): void {
            $transaction = CashTransaction::query()->create($payload);

            if ($purchaseData !== null) {
                $this->createPartsPurchase($transaction, $purchaseData);
            }

            $this->syncDonorExpense($transaction, $donorExpenseType);
            $this->syncValeraCashbookTransfer($transaction);
        });

        return redirect()->route('admin.cashbook.index')->with('status', 'Операция добавлена.');
    }

    public function show(CashTransaction $cashbook): View
    {
        $cashbook->load('purchase.items.product', 'valeraCashbookTransfer');

        return view('admin.cashbook.show', [
            'transaction' => $cashbook,
            'workOrdersByNumber' => $this->workOrdersByNumberForCashbookRows(collect([$cashbook])),
        ]);
    }

    public function edit(CashTransaction $cashbook): View|RedirectResponse
    {
        abort_if($cashbook->isStoWorkOrderPayment(), 404);
        abort_if($cashbook->isCancelled(), 404);
        abort_if($cashbook->hasConfirmedValeraCashbookTransfer(), 404);

        if (! $cashbook->canBeEdited()) {
            return redirect()
                ->route('admin.cashbook.index')
                ->with('status', 'Операцию старше 1 суток нельзя редактировать.');
        }

        return view('admin.cashbook.form', [
            'labels' => $this->cashbookLabels(),
            'labelTypes' => $this->cashbookLabelTypes(),
            'labelParents' => $this->cashbookLabelParents(),
            'parentLabels' => $this->cashbookParentLabels(),
            'donorCars' => $this->cashbookDonorCars(),
            ...$this->partsPurchaseFormOptions(),
            'activeEmployees' => $this->cashbookActiveEmployees(),
            'activeMechanicEmployees' => $this->cashbookActiveMechanicEmployees(),
            'transaction' => $cashbook,
        ]);
    }

    public function update(Request $request, CashTransaction $cashbook): RedirectResponse
    {
        if ($cashbook->isCancelled()) {
            return redirect()
                ->route('admin.cashbook.index')
                ->with('status', 'Отмененную операцию нельзя редактировать.');
        }

        if ($cashbook->isStoWorkOrderPayment()) {
            return redirect()
                ->route('admin.cashbook.index')
                ->with('status', 'Оплату заказ-наряда нельзя редактировать из кассы.');
        }

        if ($cashbook->hasConfirmedValeraCashbookTransfer()) {
            return redirect()
                ->route('admin.cashbook.index')
                ->with('status', 'Подтвержденную инкассацию Валера нельзя редактировать.');
        }

        if (! $cashbook->canBeEdited()) {
            return redirect()
                ->route('admin.cashbook.index')
                ->with('status', 'Операцию старше 1 суток нельзя редактировать.');
        }

        $payload = $this->payload($request, $cashbook);
        $donorExpenseType = $this->validatedDonorExpenseType($request, $payload);

        DB::transaction(function () use ($cashbook, $payload, $donorExpenseType): void {
            $cashbook->update($payload);
            $this->syncDonorExpense($cashbook, $donorExpenseType);
            $this->syncValeraCashbookTransfer($cashbook);
        });

        return redirect()->route('admin.cashbook.index')->with('status', 'Операция обновлена.');
    }

    public function destroy(CashTransaction $cashbook): RedirectResponse
    {
        $cashbook->loadMissing('valeraCashbookTransfer');

        if ($cashbook->isStoWorkOrderPayment()) {
            return redirect()
                ->route('admin.cashbook.index')
                ->with('status', 'Оплату заказ-наряда нельзя удалить из кассы.');
        }

        if ($cashbook->isCancelled()) {
            return redirect()
                ->route('admin.cashbook.index')
                ->with('status', 'Отмененную операцию нельзя удалить.');
        }

        if (! $cashbook->canBeDeleted()) {
            $status = $cashbook->hasConfirmedValeraCashbookTransfer()
                ? 'Подтвержденную инкассацию Валера нельзя удалить.'
                : 'Операцию старше 1 суток нельзя удалить.';

            return redirect()
                ->route('admin.cashbook.index')
                ->with('status', $status);
        }

        if ($cashbook->hasConfirmedValeraCashbookTransfer()) {
            return redirect()
                ->route('admin.cashbook.index')
                ->with('status', 'Подтвержденную инкассацию Валера нельзя удалить.');
        }

        DB::transaction(function () use ($cashbook): void {
            $this->clearSyncedDonorExpense($cashbook);
            $cashbook->delete();
        });

        return redirect()->route('admin.cashbook.index')->with('status', 'Операция удалена.');
    }

    protected function payload(Request $request, ?CashTransaction $existingTransaction = null): array
    {
        if ($existingTransaction !== null) {
            $request->merge(['label' => $existingTransaction->label]);
        }

        if (in_array(trim((string) $request->input('label')), self::OLD_REPAIR_MECHANIC_LABELS, true)) {
            $request->merge(['label' => '']);
        }

        $validated = $request->validate([
            'operation_date' => ['required', 'date'],
            'income_uah' => ['nullable', 'numeric', 'min:0'],
            'income_payment_method' => ['nullable', Rule::in(['cash', 'bank'])],
            'income_bank_uah' => ['nullable', 'numeric', 'min:0'],
            'income_cash_uah' => ['nullable', 'numeric', 'min:0'],
            'income_cash_usd' => ['nullable', 'numeric', 'min:0'],
            'expense_uah' => ['nullable', 'numeric', 'min:0'],
            'expense_payment_method' => ['nullable', Rule::in(['cash', 'bank'])],
            'expense_bank_uah' => ['nullable', 'numeric', 'min:0'],
            'expense_cash_uah' => ['nullable', 'numeric', 'min:0'],
            'expense_cash_usd' => ['nullable', 'numeric', 'min:0'],
            'label' => [$existingTransaction === null ? 'required' : 'nullable', 'string', 'max:255'],
            'employee' => ['nullable', 'string', 'max:255'],
            'vehicle_vin' => ['nullable', 'string', 'max:255'],
            'comment' => ['required', 'string'],
            'source' => ['nullable', Rule::in(['manual', 'csv', 'xlsx'])],
            'source_sheet' => ['nullable', 'string', 'max:255'],
            'exchange_rate' => ['nullable', 'numeric', 'min:0'],
            'transaction_type' => ['nullable', Rule::in(['income', 'expense', 'exchange'])],
        ]);

        if (array_key_exists('income_uah', $validated)) {
            $incomeUah = (float) ($validated['income_uah'] ?? 0);
            $incomePaymentMethod = $validated['income_payment_method'] ?? 'cash';

            $validated['income_bank_uah'] = $incomePaymentMethod === 'bank' ? $incomeUah : 0;
            $validated['income_cash_uah'] = $incomePaymentMethod === 'cash' ? $incomeUah : 0;
        }

        if (array_key_exists('expense_uah', $validated)) {
            $expenseUah = (float) ($validated['expense_uah'] ?? 0);
            $expensePaymentMethod = $validated['expense_payment_method'] ?? 'cash';

            $validated['expense_bank_uah'] = $expensePaymentMethod === 'bank' ? $expenseUah : 0;
            $validated['expense_cash_uah'] = $expensePaymentMethod === 'cash' ? $expenseUah : 0;
        }

        foreach ([
            'income_bank_uah',
            'income_cash_uah',
            'income_cash_usd',
            'expense_bank_uah',
            'expense_cash_uah',
            'expense_cash_usd',
        ] as $moneyField) {
            $validated[$moneyField] = (float) ($validated[$moneyField] ?? 0);
        }

        if (trim((string) ($validated['label'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'label' => 'Метка обязательна.',
            ]);
        }

        if (($validated['transaction_type'] ?? null) === 'income' && $this->hasNoIncomeAmount($validated)) {
            throw ValidationException::withMessages([
                'income_bank_uah' => 'Для нового прихода заполните хотя бы одно поле прихода.',
            ]);
        }

        if (($validated['transaction_type'] ?? null) === 'expense' && $this->hasNoExpenseAmount($validated)) {
            throw ValidationException::withMessages([
                'expense_bank_uah' => 'Для нового расхода заполните хотя бы одно поле расхода.',
            ]);
        }

        if ($this->isDonorExpense($validated)) {
            $donorVin = trim((string) ($validated['vehicle_vin'] ?? ''));

            if ($donorVin === '') {
                throw ValidationException::withMessages([
                    'vehicle_vin' => 'Для расхода с меткой Донор выберите VIN донора.',
                ]);
            }

            if (! DonorCar::query()->where('vin', $donorVin)->exists()) {
                throw ValidationException::withMessages([
                    'vehicle_vin' => 'Выберите VIN из списка донорских автомобилей.',
                ]);
            }
        }

        if (($validated['transaction_type'] ?? null) === 'exchange' && $this->hasNoIncomeAmount($validated)) {
            throw ValidationException::withMessages([
                'income_uah' => 'Для обмена заполните хотя бы одно поле прихода.',
            ]);
        }

        if (($validated['transaction_type'] ?? null) === 'exchange' && $this->hasNoExpenseAmount($validated)) {
            throw ValidationException::withMessages([
                'expense_uah' => 'Для обмена заполните хотя бы одно поле расхода.',
            ]);
        }

        unset(
            $validated['transaction_type'],
            $validated['income_uah'],
            $validated['income_payment_method'],
            $validated['expense_uah'],
            $validated['expense_payment_method'],
        );

        return [
            ...$validated,
            'operation_date' => Carbon::parse($validated['operation_date'])->toDateString(),
            'label' => trim((string) ($validated['label'] ?? '')) ?: null,
            'employee' => CashTransaction::normalizeEmployeeName($validated['employee'] ?? null),
            'vehicle_vin' => trim((string) ($validated['vehicle_vin'] ?? '')) ?: null,
            'comment' => trim((string) ($validated['comment'] ?? '')) ?: null,
            'source' => $validated['source'] ?? 'manual',
            'source_sheet' => trim((string) ($validated['source_sheet'] ?? '')) ?: null,
            'exchange_rate' => $validated['exchange_rate'] ?? null,
        ];
    }

    protected function hasNoIncomeAmount(array $validated): bool
    {
        return (float) ($validated['income_bank_uah'] ?? 0) <= 0
            && (float) ($validated['income_cash_uah'] ?? 0) <= 0
            && (float) ($validated['income_cash_usd'] ?? 0) <= 0;
    }

    protected function hasNoExpenseAmount(array $validated): bool
    {
        return (float) ($validated['expense_bank_uah'] ?? 0) <= 0
            && (float) ($validated['expense_cash_uah'] ?? 0) <= 0
            && (float) ($validated['expense_cash_usd'] ?? 0) <= 0;
    }

    protected function isDonorExpense(array $validated): bool
    {
        if (trim((string) ($validated['label'] ?? '')) !== 'Донор') {
            return false;
        }

        if (($validated['transaction_type'] ?? null) === 'expense') {
            return true;
        }

        return ! $this->hasNoExpenseAmount($validated);
    }

    protected function validatedDonorExpenseType(Request $request, array $payload): ?string
    {
        if (trim((string) ($payload['label'] ?? '')) !== 'Донор') {
            return null;
        }

        $hasExpense = (float) ($payload['expense_bank_uah'] ?? 0) > 0
            || (float) ($payload['expense_cash_uah'] ?? 0) > 0
            || (float) ($payload['expense_cash_usd'] ?? 0) > 0;

        if (! $hasExpense) {
            return null;
        }

        $validated = $request->validate([
            'donor_expense_type' => ['required', Rule::in(array_keys(self::DONOR_EXPENSE_FIELDS))],
        ]);

        $this->donorExpenseAmountUsd($payload);
        $donorVin = trim((string) ($payload['vehicle_vin'] ?? ''));
        $donorExpenseField = self::DONOR_EXPENSE_FIELDS[$validated['donor_expense_type']];
        $donorCar = DonorCar::query()
            ->where('vin', $donorVin)
            ->first(['id', $donorExpenseField]);

        if ($donorCar && $donorCar->{$donorExpenseField} !== null) {
            throw ValidationException::withMessages([
                'donor_expense_type' => 'Эта статья расхода донора уже заполнена.',
            ]);
        }

        return $validated['donor_expense_type'];
    }

    protected function syncDonorExpense(CashTransaction $transaction, ?string $donorExpenseType): void
    {
        if ($donorExpenseType === null || ! array_key_exists($donorExpenseType, self::DONOR_EXPENSE_FIELDS)) {
            return;
        }

        $donorVin = trim((string) $transaction->vehicle_vin);

        if ($donorVin === '') {
            return;
        }

        $amountUsd = $this->donorExpenseAmountUsd([
            'expense_cash_usd' => $transaction->expense_cash_usd,
            'expense_bank_uah' => $transaction->expense_bank_uah,
            'expense_cash_uah' => $transaction->expense_cash_uah,
            'exchange_rate' => $transaction->exchange_rate,
        ]);

        $donorCar = DonorCar::query()
            ->where('vin', $donorVin)
            ->first();

        if (! $donorCar) {
            return;
        }

        $donorExpenseField = self::DONOR_EXPENSE_FIELDS[$donorExpenseType];
        $donorCar->{$donorExpenseField} = $amountUsd;
        $donorCar->setDonorExpenseSource($donorExpenseField, DonorCar::DONOR_EXPENSE_SOURCE_CASHBOOK);
        $donorCar->save();
    }

    protected function clearSyncedDonorExpense(CashTransaction $transaction): void
    {
        if (trim((string) $transaction->label) !== 'Донор') {
            return;
        }

        $donorVin = trim((string) $transaction->vehicle_vin);

        if ($donorVin === '') {
            return;
        }

        $amountUsd = $this->donorExpenseAmountUsd([
            'expense_cash_usd' => $transaction->expense_cash_usd,
            'expense_bank_uah' => $transaction->expense_bank_uah,
            'expense_cash_uah' => $transaction->expense_cash_uah,
            'exchange_rate' => $transaction->exchange_rate,
        ]);

        if ($amountUsd <= 0) {
            return;
        }

        $donorCar = DonorCar::query()
            ->where('vin', $donorVin)
            ->first();

        if (! $donorCar) {
            return;
        }

        foreach (self::DONOR_EXPENSE_FIELDS as $field) {
            if ($donorCar->donorExpenseSourceFor($field) !== DonorCar::DONOR_EXPENSE_SOURCE_CASHBOOK) {
                continue;
            }

            if (round((float) $donorCar->{$field}, 2) !== $amountUsd) {
                continue;
            }

            $donorCar->{$field} = null;
            $donorCar->unsetDonorExpenseSource($field);
        }

        if ($donorCar->isDirty()) {
            $donorCar->save();
        }
    }

    protected function donorExpenseAmountUsd(array $payload): float
    {
        $expenseUsd = (float) ($payload['expense_cash_usd'] ?? 0);

        if ($expenseUsd > 0) {
            return round($expenseUsd, 2);
        }

        $expenseUah = (float) ($payload['expense_bank_uah'] ?? 0) + (float) ($payload['expense_cash_uah'] ?? 0);

        if ($expenseUah <= 0) {
            return 0.0;
        }

        $exchangeRate = (float) ($payload['exchange_rate'] ?? 0);

        if ($exchangeRate <= 0) {
            throw ValidationException::withMessages([
                'exchange_rate' => 'Для расхода донора в гривне укажите курс доллара.',
            ]);
        }

        return round($expenseUah / $exchangeRate, 2);
    }

    protected function shouldCreatePartsPurchase(Request $request, array $payload): bool
    {
        if (trim((string) ($payload['label'] ?? '')) !== self::PARTS_PURCHASE_LABEL) {
            return false;
        }

        if (($request->input('transaction_type') ?? null) === 'expense') {
            return true;
        }

        return (float) ($payload['expense_bank_uah'] ?? 0) > 0
            || (float) ($payload['expense_cash_uah'] ?? 0) > 0
            || (float) ($payload['expense_cash_usd'] ?? 0) > 0;
    }

    protected function syncValeraCashbookTransfer(CashTransaction $transaction): void
    {
        $transaction->loadMissing('valeraCashbookTransfer');

        $shouldExist = trim((string) $transaction->label) === self::VALERA_CASHBOOK_TRANSFER_LABEL
            && ((float) $transaction->expense_cash_usd > 0
                || (float) $transaction->expense_bank_uah > 0
                || (float) $transaction->expense_cash_uah > 0);

        $transfer = $transaction->valeraCashbookTransfer;

        if ($shouldExist) {
            if (! $transfer) {
                ValeraCashbookTransfer::query()->create([
                    'cash_transaction_id' => $transaction->id,
                    'status' => 'pending',
                ]);
            }

            return;
        }

        if ($transfer && $transfer->status === 'pending') {
            $transfer->delete();
        }
    }

    protected function validatedPartsPurchaseData(Request $request): array
    {
        return $request->validate([
            'purchase_counterparty_id' => ['required', 'exists:counterparties,id'],
            'purchase_document_number' => ['nullable', 'string', 'max:255'],
            'purchase_items' => ['required', 'array', 'min:1'],
            'purchase_items.*.product_id' => ['required', 'exists:products,id'],
            'purchase_items.*.warehouse_id' => ['required', 'exists:warehouses,id'],
            'purchase_items.*.location_id' => ['required', 'exists:locations,id'],
            'purchase_items.*.quantity' => ['required', 'integer', 'min:1'],
            'purchase_items.*.purchase_price' => ['nullable', 'numeric', 'min:0'],
            'purchase_items.*.selling_price' => ['nullable', 'numeric', 'min:0'],
            'purchase_items.*.currency' => ['required', 'string', 'size:3'],
            'purchase_items.*.comment' => ['nullable', 'string'],
        ]);
    }

    protected function createPartsPurchase(CashTransaction $transaction, array $data): Purchase
    {
        $items = collect($data['purchase_items']);
        $firstItem = $items->first();
        $currency = (string) ($firstItem['currency'] ?? (((float) $transaction->expense_cash_usd > 0) ? 'USD' : 'UAH'));
        $totalAmount = (float) $transaction->expense_cash_usd > 0
            ? (float) $transaction->expense_cash_usd
            : $transaction->totalExpenseUah();

        $purchase = Purchase::query()->create([
            'cash_transaction_id' => $transaction->id,
            'counterparty_id' => $data['purchase_counterparty_id'],
            'warehouse_id' => $firstItem['warehouse_id'] ?? null,
            'purchase_date' => $transaction->operation_date,
            'document_number' => trim((string) ($data['purchase_document_number'] ?? '')) ?: null,
            'status' => 'posted',
            'currency' => strtoupper($currency),
            'total_amount' => $totalAmount,
            'comment' => $transaction->comment,
        ]);

        foreach ($items as $item) {
            $product = Product::query()->findOrFail($item['product_id']);
            $purchasePrice = (float) ($item['purchase_price'] ?? 0);
            $sellingPrice = (float) ($item['selling_price'] ?? 0);
            $itemCurrency = strtoupper((string) ($item['currency'] ?? $currency));

            $stockItem = $this->stockService->intake([
                'product_id' => $product->id,
                'warehouse_id' => $item['warehouse_id'],
                'location_id' => $item['location_id'],
                'quantity' => $item['quantity'],
                'counterparty_id' => $data['purchase_counterparty_id'],
                'document_number' => $data['purchase_document_number'] ?? null,
                'comment' => trim((string) ($item['comment'] ?? '')) ?: $transaction->comment,
            ]);

            $purchase->items()->create([
                'product_id' => $product->id,
                'stock_item_id' => $stockItem->id,
                'warehouse_id' => $item['warehouse_id'],
                'location_id' => $item['location_id'],
                'quantity' => $item['quantity'],
                'purchase_price' => $purchasePrice,
                'selling_price' => $sellingPrice,
                'currency' => $itemCurrency,
                'comment' => trim((string) ($item['comment'] ?? '')) ?: null,
            ]);

            $updates = [];

            if ($purchasePrice > 0) {
                $updates['purchase_price'] = $purchasePrice;
                $updates['currency'] = $itemCurrency;
            }

            if ($sellingPrice > 0) {
                $updates['selling_price'] = $sellingPrice;
                $updates['currency'] = $itemCurrency;
            }

            if ($updates !== []) {
                $product->update($updates);
            }
        }

        return $purchase;
    }

    protected function partsPurchaseItemsDetails(array $items): string
    {
        $products = Product::query()
            ->whereIn('id', collect($items)->pluck('product_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        return collect($items)
            ->map(function (array $item) use ($products): string {
                $product = $products->get((int) ($item['product_id'] ?? 0));

                if (! $product) {
                    return '';
                }

                return trim(collect([
                    $product->name,
                    $product->model,
                ])->filter()->join(' - '));
            })
            ->filter()
            ->implode('; ');
    }

    protected function partsPurchaseFormOptions(): array
    {
        $warehouses = Warehouse::query()->orderBy('name')->get();
        $locations = Location::query()->with('warehouse')->orderBy('full_code')->get();

        return [
            'purchaseProducts' => Product::query()
                ->select(['id', 'sku', 'name'])
                ->where('is_active', true)
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->limit(self::PURCHASE_PRODUCT_OPTION_LIMIT)
                ->get(),
            'purchaseWarehouses' => $warehouses,
            'purchaseLocations' => $locations,
            'purchaseWarehouseOptions' => $warehouses
                ->map(fn (Warehouse $warehouse): array => [
                    'id' => $warehouse->id,
                    'name' => $warehouse->name,
                    'floor_count' => $warehouse->floor_count ?? 1,
                    'floors' => collect($warehouse->availableFloors())
                        ->map(fn (string $label, string $value): array => [
                            'value' => $value,
                            'label' => $label,
                        ])
                        ->values(),
                ])
                ->values(),
            'purchaseLocationOptions' => $locations
                ->map(fn (Location $location): array => [
                    'id' => $location->id,
                    'warehouse_id' => $location->warehouse_id,
                    'floor' => $location->floor,
                    'full_code' => $location->full_code,
                ])
                ->values(),
            'purchaseCounterparties' => Counterparty::query()
                ->whereIn('type', ['supplier', 'both'])
                ->orderBy('name')
                ->get(),
            'partsPurchaseLabel' => self::PARTS_PURCHASE_LABEL,
        ];
    }

    protected function cashbookDonorCars(): Collection
    {
        return DonorCar::query()
            ->havingOpenDonorExpenses()
            ->whereNotIn('id', self::HIDDEN_CASHBOOK_DONOR_CAR_IDS)
            ->orderByRaw('purchase_date IS NULL')
            ->orderByDesc('purchase_date')
            ->orderBy('vin')
            ->get([
                'id',
                'vin',
                'brand',
                'model',
                'purchase_date',
                ...array_values(self::DONOR_EXPENSE_FIELDS),
            ]);
    }

    protected function cashbookLabels(): Collection
    {
        $this->mergeRepairMechanicLabels();

        return CashbookLabel::query()
            ->whereNotIn('name', [...self::OLD_REPAIR_MECHANIC_LABELS, ...self::HIDDEN_CASHBOOK_LABELS])
            ->orderBy('name')
            ->pluck('name');
    }

    protected function cashbookLabelTypes(): Collection
    {
        $this->mergeRepairMechanicLabels();

        $types = CashbookLabel::query()
            ->whereNotIn('name', [...self::OLD_REPAIR_MECHANIC_LABELS, ...self::HIDDEN_CASHBOOK_LABELS])
            ->pluck('operation_type', 'name');

        return $types->put('', $types->get('', 'income'));
    }

    protected function cashbookLabelParents(): Collection
    {
        return CashbookLabel::query()
            ->leftJoin('cashbook_labels as parents', 'cashbook_labels.parent_id', '=', 'parents.id')
            ->whereNotIn('cashbook_labels.name', [...self::OLD_REPAIR_MECHANIC_LABELS, ...self::HIDDEN_CASHBOOK_LABELS])
            ->whereNotNull('parents.name')
            ->orderBy('cashbook_labels.name')
            ->get([
                'cashbook_labels.name as label_name',
                'parents.name as parent_name',
            ])
            ->pluck('parent_name', 'label_name');
    }

    protected function cashbookParentLabels(): Collection
    {
        return CashbookLabel::query()
            ->whereHas('children')
            ->whereNotIn('name', [...self::OLD_REPAIR_MECHANIC_LABELS, ...self::HIDDEN_CASHBOOK_LABELS])
            ->orderBy('name')
            ->pluck('name');
    }

    protected function expandCashbookLabelsWithChildren(array $labels): array
    {
        $labels = collect($labels)
            ->filter(fn ($label) => trim((string) $label) !== '')
            ->values();

        if ($labels->isEmpty()) {
            return [];
        }

        $children = CashbookLabel::query()
            ->join('cashbook_labels as parents', 'cashbook_labels.parent_id', '=', 'parents.id')
            ->whereIn('parents.name', $labels)
            ->pluck('cashbook_labels.name');

        return $labels
            ->merge($children)
            ->unique()
            ->values()
            ->all();
    }

    protected function cashbookLabelsByOperationType(string $operationType): Collection
    {
        $this->mergeRepairMechanicLabels();

        return CashbookLabel::query()
            ->where('operation_type', $operationType)
            ->whereNotIn('name', [...self::OLD_REPAIR_MECHANIC_LABELS, ...self::HIDDEN_CASHBOOK_LABELS])
            ->pluck('name');
    }

    protected function mergeRepairMechanicLabels(): void
    {
        if (! CashbookLabel::query()->whereIn('name', self::OLD_REPAIR_MECHANIC_LABELS)->exists()) {
            return;
        }

        DB::transaction(function (): void {
            $operationType = CashbookLabel::query()
                ->whereIn('name', self::OLD_REPAIR_MECHANIC_LABELS)
                ->value('operation_type') ?? 'income';

            CashbookLabel::query()->firstOrCreate(
                ['name' => ''],
                ['operation_type' => $operationType],
            );

            CashTransaction::query()
                ->whereIn('label', self::OLD_REPAIR_MECHANIC_LABELS)
                ->update(['label' => '']);

            CashbookLabel::query()
                ->whereIn('name', self::OLD_REPAIR_MECHANIC_LABELS)
                ->delete();
        });
    }

    protected function cashbookActiveEmployees(): Collection
    {
        return StoEmployee::query()
            ->orderByDesc('is_active')
            ->orderBy('cash_employee_name')
            ->pluck('cash_employee_name');
    }

    protected function cashbookFilterEmployeeGroups(): array
    {
        $employees = CashTransaction::query()
            ->whereNotNull('employee')
            ->where('employee', '<>', '')
            ->distinct()
            ->orderBy('employee')
            ->pluck('employee');

        $inactiveEmployees = StoEmployee::query()
            ->whereIn('cash_employee_name', $employees)
            ->where('is_active', false)
            ->pluck('cash_employee_name')
            ->flip();

        [$inactive, $active] = $employees->partition(
            fn (string $employee): bool => $inactiveEmployees->has($employee),
        );

        return [
            'active' => $active->values(),
            'inactive' => $inactive->values(),
        ];
    }

    protected function cashbookActiveMechanicEmployees(): Collection
    {
        return StoEmployee::query()
            ->where('is_active', true)
            ->where(function (Builder $query): void {
                $query
                    ->whereRaw("TRIM(COALESCE(position, '')) = ?", ['Механик'])
                    ->orWhereRaw("TRIM(COALESCE(position, '')) = ?", ['механик']);
            })
            ->orderBy('cash_employee_name')
            ->pluck('cash_employee_name');
    }

    protected function defaultSourceSheetForPeriod(string $from, string $to): ?string
    {
        return CashTransaction::query()
            ->whereBetween('operation_date', [$from, $to])
            ->whereNotNull('source_sheet')
            ->where('source_sheet', '<>', '')
            ->select('source_sheet')
            ->selectRaw('MAX(operation_date) as latest_operation_date')
            ->groupBy('source_sheet')
            ->orderByDesc('latest_operation_date')
            ->value('source_sheet');
    }

    protected function hasCashbookFilterInput(Request $request): bool
    {
        return $request->hasAny([
            'from',
            'to',
            'label',
            'operation_type',
            'employee',
            'source_sheet',
            'search',
            'show_all',
            'per_page',
            'usd_rate',
            'sort',
            'direction',
        ]);
    }

    protected function cashbookFilterState(Request $request, array $filters): array
    {
        $state = [];

        foreach (['from', 'to', 'operation_type', 'employee', 'source_sheet', 'search', 'per_page', 'usd_rate', 'sort', 'direction'] as $key) {
            if ($request->has($key)) {
                $state[$key] = $filters[$key] ?? null;
            }
        }

        if ($request->has('label')) {
            $state['label'] = $filters['label'] ?? [];
        }

        if ($request->has('show_all')) {
            $state['show_all'] = (bool) ($filters['show_all'] ?? false);
        }

        return $state;
    }

    protected function applyCashbookSort(Builder $query, string $sort, string $direction): Builder
    {
        $direction = $direction === 'asc' ? 'asc' : 'desc';

        match ($sort) {
            'income' => $query
                ->orderByRaw("(COALESCE(income_bank_uah, 0) + COALESCE(income_cash_uah, 0)) {$direction}")
                ->orderBy('income_cash_usd', $direction),
            'expense' => $query
                ->orderByRaw("(COALESCE(expense_bank_uah, 0) + COALESCE(expense_cash_uah, 0)) {$direction}")
                ->orderBy('expense_cash_usd', $direction),
            'label' => $query->orderByRaw("COALESCE(label, '') {$direction}"),
            'employee' => $query->orderByRaw("COALESCE(employee, '') {$direction}"),
            'details' => $query
                ->orderByRaw("COALESCE(vehicle_vin, '') {$direction}")
                ->orderByRaw("COALESCE(comment, '') {$direction}"),
            default => $query->orderBy('operation_date', $direction),
        };

        return $query->orderBy('id', $direction);
    }

    protected function supportsSourceSheetFilter(Request $request): bool
    {
        return ! $request->routeIs('admin.cashbook.index');
    }

    protected function profitSnapshot(
        Builder $query,
        ?float $partsProfitOverride = null,
        ?float $repairProfitOverride = null,
        ?float $stoExpensesOverride = null,
    ): array {
        $rows = $query->get();

        $partsProfit = $partsProfitOverride ?? $rows
            ->filter(fn (CashTransaction $row) => str_contains((string) $row->label, 'Продажа ЗЧ'))
            ->sum(fn (CashTransaction $row) => $row->totalIncomeUah() - $row->totalExpenseUah());

        $repairProfit = $repairProfitOverride ?? $rows
            ->filter(fn (CashTransaction $row) => str_contains((string) $row->label, ''))
            ->sum(fn (CashTransaction $row) => $row->totalIncomeUah() - $row->totalExpenseUah());

        $otherIncome = $rows
            ->filter(fn (CashTransaction $row) => trim((string) $row->label) === 'Субаренда')
            ->sum(fn (CashTransaction $row) => $row->totalIncomeUah() - $row->totalExpenseUah());

        $stoExpenses = $stoExpensesOverride ?? $rows
            ->filter(fn (CashTransaction $row) => in_array(trim((string) $row->label), [
                'Аренда',
                'Коммунальные',
                '',
                'Связь',
                'Инструмент',
                '',
                'Продукты',
                'Налоги',
                'Прочие',
            ], true))
            ->sum(fn (CashTransaction $row) => $row->totalExpenseUah());

        $payroll = $rows
            ->filter(fn (CashTransaction $row) => trim((string) $row->label) === 'ЗП')
            ->sum(fn (CashTransaction $row) => $row->totalExpenseUah());

        return [
            'parts_profit' => $partsProfit,
            'repair_profit' => $repairProfit,
            'other_income' => $otherIncome,
            'sto_expenses' => $stoExpenses,
            'payroll' => $payroll,
            'net' => $partsProfit + $repairProfit + $otherIncome - $stoExpenses - $payroll,
        ];
    }

    protected function partsProfitTableSnapshot(Builder $query, float $usdRate = 43): array
    {
        $rows = $query->get();
        $usdRate = $usdRate > 0 ? $usdRate : 43.0;

        $sumIncomeMinusExpenseByLabels = function (array $labels) use ($rows): array {
            $matchedRows = $rows->filter(function (CashTransaction $row) use ($labels): bool {
                return in_array(trim((string) $row->label), $labels, true);
            });

            return [
                'uah' => $matchedRows->sum(fn (CashTransaction $row) => $row->netUah()),
                'usd' => $matchedRows->sum(fn (CashTransaction $row) => $row->netUsd()),
            ];
        };

        $sumExpenseMinusIncomeByLabels = function (array $labels) use ($rows): array {
            $matchedRows = $rows->filter(function (CashTransaction $row) use ($labels): bool {
                return in_array(trim((string) $row->label), $labels, true);
            });

            return [
                'uah' => $matchedRows->sum(fn (CashTransaction $row) => $row->totalExpenseUah() - $row->totalIncomeUah()),
                'usd' => $matchedRows->sum(fn (CashTransaction $row) => (float) $row->expense_cash_usd - (float) $row->income_cash_usd),
            ];
        };

        $salesRetail = $sumIncomeMinusExpenseByLabels(self::DONOR_PARTS_SALE_LABELS);
        $returnsRetail = $sumExpenseMinusIncomeByLabels(['Возврат Запчасти и денег']);
        $salesWholesale = $sumIncomeMinusExpenseByLabels(['Продажа ЗЧК']);
        $purchasesWholesale = $sumExpenseMinusIncomeByLabels(['Закупка ЗЧК']);
        $transport = $sumExpenseMinusIncomeByLabels(['Транспортные ЗЧ']);

        $totalUah = ($salesRetail['uah'] * 0.35)
            + $salesWholesale['uah']
            - $purchasesWholesale['uah']
            - $transport['uah']
            - $returnsRetail['uah'];

        $totalUsd = ($salesRetail['usd'] * 0.35)
            + $salesWholesale['usd']
            - $purchasesWholesale['usd']
            - $transport['usd']
            - $returnsRetail['usd'];

        return [
            'usd_rate' => $usdRate,
            'rows' => [
                'sales_retail' => [
                    'label' => '  ( )',
                    'uah' => $salesRetail['uah'],
                    'usd' => $salesRetail['usd'],
                ],
                'returns_retail' => [
                    'label' => 'Возврат Запчасти и денег',
                    'uah' => $returnsRetail['uah'],
                    'usd' => $returnsRetail['usd'],
                ],
                'sales_wholesale' => [
                    'label' => 'Продажа ЗЧК',
                    'uah' => $salesWholesale['uah'],
                    'usd' => $salesWholesale['usd'],
                ],
                'purchases_wholesale' => [
                    'label' => 'Закупка ЗЧК',
                    'uah' => $purchasesWholesale['uah'],
                    'usd' => $purchasesWholesale['usd'],
                ],
                'transport' => [
                    'label' => 'Транспортные ЗЧ',
                    'uah' => $transport['uah'],
                    'usd' => $transport['usd'],
                    'present' => abs($transport['uah']) > 0.0001 || abs($transport['usd']) > 0.0001,
                ],
            ],
            'total' => [
                'uah_component' => $totalUah,
                'usd_component' => $totalUsd,
                'uah_total' => $totalUah + ($totalUsd * $usdRate),
            ],
        ];
    }

    protected function repairProfitTableSnapshot(Builder $query, float $usdRate = 43): array
    {
        $rows = $query->get();
        $usdRate = $usdRate > 0 ? $usdRate : 43.0;

        $sumNetUahByLabels = function (array $labels) use ($rows): float {
            return $rows
                ->filter(fn (CashTransaction $row) => in_array(trim((string) $row->label), $labels, true))
                ->sum(fn (CashTransaction $row) => $row->netUah());
        };

        $sumNetUsdByLabels = function (array $labels) use ($rows): float {
            return $rows
                ->filter(fn (CashTransaction $row) => in_array(trim((string) $row->label), $labels, true))
                ->sum(fn (CashTransaction $row) => $row->netUsd());
        };

        $repairRows = [
            'repair_e' => [
                'label' => '+',
                'uah' => $sumNetUahByLabels(['+']),
                'usd' => $sumNetUsdByLabels(['+']),
            ],
            'repair_mechanic' => [
                'label' => '',
                'uah' => $sumNetUahByLabels(self::REPAIR_MECHANIC_LABELS),
                'usd' => $sumNetUsdByLabels(self::REPAIR_MECHANIC_LABELS),
            ],
            'repair_plus' => [
                'label' => '+',
                'uah' => $sumNetUahByLabels(['+', '+']),
                'usd' => $sumNetUsdByLabels(['+', '+']),
            ],
            'repair_minus' => [
                'label' => '-',
                'uah' => $sumNetUahByLabels(['-']),
                'usd' => $sumNetUsdByLabels(['-']),
            ],
        ];

        return [
            'usd_rate' => $usdRate,
            'rows' => $repairRows,
            'total_uah' => collect($repairRows)->sum('uah'),
            'total_usd' => collect($repairRows)->sum('usd'),
            'total_full_uah' => collect($repairRows)->sum('uah') + (collect($repairRows)->sum('usd') * $usdRate),
        ];
    }

    protected function bonusCalculationForRows(StoEmployee $employee, Collection $rows): ?array
    {
        if (! $employee->bonus_calculation) {
            return null;
        }

        return match ($employee->bonus_calculation) {
            'zinchenko_eugene_profit_7pct' => $this->zinchenkoEugeneBonusCalculationForRows($rows),
            'obmanshchikov_excel_e421' => $this->obmanshchikovExcelBonusCalculationForRows($rows),
            'zinchenko_anton_excel_d422' => $this->zinchenkoAntonExcelBonusCalculationForRows($rows),
            'razdorin_excel_d423' => $this->razdorinExcelBonusCalculationForRows($rows),
            'lekha_excel_e424' => $this->lekhaExcelBonusCalculationForRows($rows),
            'dima_excel_e425' => $this->dimaExcelBonusCalculationForRows($rows),
            default => null,
        };
    }

    protected function zinchenkoEugeneBonusCalculationForRows(Collection $rows): array
    {
        $partsUsdRate = 43.0;
        $repairUsdRate = 42.0;
        $partsComponent = $this->partsProfitComponentsFixed($rows);
        $repairComponent = $this->repairProfitComponentsFixed($rows);
        $base = $repairComponent['uah']
            + ($repairComponent['usd'] * $repairUsdRate)
            + $partsComponent['uah']
            + ($partsComponent['usd'] * $partsUsdRate);

        return [
            'bonus_amount_uah' => round($base * 0.07, 2),
        ];
    }

    protected function obmanshchikovExcelBonusCalculationForRows(Collection $rows): array
    {
        $repairM1 = $this->excelRepairM1TotalForRows($rows, 42.0);

        return [
            'bonus_amount_uah' => round($repairM1 / 2, 2),
        ];
    }

    protected function zinchenkoAntonExcelBonusCalculationForRows(Collection $rows): array
    {
        $salesRetail = $this->excelSalesRetailTotalForRows($rows, 41.0);

        return [
            'bonus_amount_uah' => round($salesRetail * 0.07, 2),
        ];
    }

    protected function razdorinExcelBonusCalculationForRows(Collection $rows): array
    {
        $repairE = $this->excelRepairETotalForRows($rows, 43.0);

        return [
            'bonus_amount_uah' => round($repairE * 0.5, 2),
        ];
    }

    protected function lekhaExcelBonusCalculationForRows(Collection $rows): array
    {
        $repairM2 = $this->excelRepairM2TotalForRows($rows, 42.0);

        return [
            'bonus_amount_uah' => round($repairM2 * 0.3, 2),
        ];
    }

    protected function dimaExcelBonusCalculationForRows(Collection $rows): array
    {
        $salesRetail = $this->excelSalesRetailTotalForRows($rows, 41.0);

        return [
            'bonus_amount_uah' => round($salesRetail * 0.03, 2),
        ];
    }

    protected function excelSalesRetailTotalForRows(Collection $rows, float $usdRate): float
    {
        $salesRetail = $rows
            ->filter(fn (CashTransaction $row) => in_array(trim((string) $row->label), self::DONOR_PARTS_SALE_LABELS, true));

        return (float) $salesRetail->sum(fn (CashTransaction $row) => $row->netUah())
            + ((float) $salesRetail->sum(fn (CashTransaction $row) => $row->netUsd()) * $usdRate);
    }

    protected function excelRepairETotalForRows(Collection $rows, float $usdRate): float
    {
        $repairE = $rows
            ->filter(fn (CashTransaction $row) => trim((string) $row->label) === '+');

        return (float) $repairE->sum(fn (CashTransaction $row) => $row->netUah())
            + ((float) $repairE->sum(fn (CashTransaction $row) => $row->netUsd()) * $usdRate);
    }

    protected function excelRepairM2TotalForRows(Collection $rows, float $usdRate): float
    {
        $repairM2 = $rows
            ->filter(fn (CashTransaction $row) => in_array(trim((string) $row->label), self::REPAIR_MECHANIC_LABELS, true));

        return (float) $repairM2->sum(fn (CashTransaction $row) => $row->netUah())
            + ((float) $repairM2->sum(fn (CashTransaction $row) => $row->netUsd()) * $usdRate);
    }

    protected function excelRepairM1TotalForRows(Collection $rows, float $usdRate): float
    {
        $repairM1 = $rows
            ->filter(fn (CashTransaction $row) => in_array(trim((string) $row->label), self::REPAIR_MECHANIC_LABELS, true));

        return (float) $repairM1->sum(fn (CashTransaction $row) => $row->netUah())
            + ((float) $repairM1->sum(fn (CashTransaction $row) => $row->netUsd()) * $usdRate);
    }

    protected function partsProfitComponentsFixed(Collection $rows): array
    {
        $salesRetailLabels = self::DONOR_PARTS_SALE_LABELS;
        $returnsRetailLabels = ['Возврат Запчасти и денег'];
        $salesWholesaleLabels = json_decode('["\u041f\u0440\u043e\u0434\u0430\u0436\u0430 \u0417\u0427\u041a"]', true);
        $purchasesWholesaleLabels = json_decode('["\u0417\u0430\u043a\u0443\u043f\u043a\u0430 \u0417\u0427\u041a"]', true);
        $transportLabels = json_decode('["\u0422\u0440\u0430\u043d\u0441\u043f\u043e\u0440\u0442\u043d\u044b\u0435 \u0417\u0427"]', true);

        $sumIncomeMinusExpenseByLabels = function (array $labels) use ($rows): array {
            $matchedRows = $rows->filter(fn (CashTransaction $row) => in_array(trim((string) $row->label), $labels, true));

            return [
                'uah' => $matchedRows->sum(fn (CashTransaction $row) => $row->netUah()),
                'usd' => $matchedRows->sum(fn (CashTransaction $row) => $row->netUsd()),
            ];
        };

        $sumExpenseMinusIncomeByLabels = function (array $labels) use ($rows): array {
            $matchedRows = $rows->filter(fn (CashTransaction $row) => in_array(trim((string) $row->label), $labels, true));

            return [
                'uah' => $matchedRows->sum(fn (CashTransaction $row) => $row->totalExpenseUah() - $row->totalIncomeUah()),
                'usd' => $matchedRows->sum(fn (CashTransaction $row) => (float) $row->expense_cash_usd - (float) $row->income_cash_usd),
            ];
        };

        $salesRetail = $sumIncomeMinusExpenseByLabels($salesRetailLabels);
        $returnsRetail = $sumExpenseMinusIncomeByLabels($returnsRetailLabels);
        $salesWholesale = $sumIncomeMinusExpenseByLabels($salesWholesaleLabels);
        $purchasesWholesale = $sumExpenseMinusIncomeByLabels($purchasesWholesaleLabels);
        $transport = $sumExpenseMinusIncomeByLabels($transportLabels);

        return [
            'uah' => $salesRetail['uah']
                - $returnsRetail['uah']
                + $salesWholesale['uah']
                - $purchasesWholesale['uah'],
            'usd' => $salesRetail['usd']
                - $returnsRetail['usd']
                + $salesWholesale['usd']
                - $purchasesWholesale['usd'],
        ];
    }

    protected function repairProfitComponentsFixed(Collection $rows): array
    {
        $repairELabels = json_decode('["\u0420\u0435\u043c\u043e\u043d\u0442\u042d+"]', true);
        $repairPlusLabels = json_decode('["\u0420\u0435\u043c\u043e\u043d\u0442+","\u0420\u0435\u043c\u043e\u043d\u0442\u0420+"]', true);
        $repairMinusLabels = json_decode('["\u0420\u0435\u043c\u043e\u043d\u0442-"]', true);

        $sumNetUahByLabels = fn (array $labels): float => $rows
            ->filter(fn (CashTransaction $row) => in_array(trim((string) $row->label), $labels, true))
            ->sum(fn (CashTransaction $row) => $row->netUah());

        $sumNetUsdByLabels = fn (array $labels): float => $rows
            ->filter(fn (CashTransaction $row) => in_array(trim((string) $row->label), $labels, true))
            ->sum(fn (CashTransaction $row) => $row->netUsd());

        return [
            'uah' => $sumNetUahByLabels($repairELabels)
                + $sumNetUahByLabels(self::REPAIR_MECHANIC_LABELS)
                + $sumNetUahByLabels($repairPlusLabels)
                + $sumNetUahByLabels($repairMinusLabels),
            'usd' => $sumNetUsdByLabels($repairELabels)
                + $sumNetUsdByLabels(self::REPAIR_MECHANIC_LABELS)
                + $sumNetUsdByLabels($repairPlusLabels)
                + $sumNetUsdByLabels($repairMinusLabels),
        ];
    }

    protected function partsProfitComponents(Collection $rows): array
    {
        $sumIncomeMinusExpenseByLabels = function (array $labels) use ($rows): array {
            $matchedRows = $rows->filter(fn (CashTransaction $row) => in_array(trim((string) $row->label), $labels, true));

            return [
                'uah' => $matchedRows->sum(fn (CashTransaction $row) => $row->netUah()),
                'usd' => $matchedRows->sum(fn (CashTransaction $row) => $row->netUsd()),
            ];
        };

        $sumExpenseMinusIncomeByLabels = function (array $labels) use ($rows): array {
            $matchedRows = $rows->filter(fn (CashTransaction $row) => in_array(trim((string) $row->label), $labels, true));

            return [
                'uah' => $matchedRows->sum(fn (CashTransaction $row) => $row->totalExpenseUah() - $row->totalIncomeUah()),
                'usd' => $matchedRows->sum(fn (CashTransaction $row) => (float) $row->expense_cash_usd - (float) $row->income_cash_usd),
            ];
        };

        $salesRetail = $sumIncomeMinusExpenseByLabels([' ']);
        $returnsRetail = $sumExpenseMinusIncomeByLabels([' ', ' ']);
        $salesWholesale = $sumIncomeMinusExpenseByLabels(['Продажа ЗЧК']);
        $purchasesWholesale = $sumExpenseMinusIncomeByLabels(['Закупка ЗЧК']);
        $transport = $sumExpenseMinusIncomeByLabels(['Транспортные ЗЧ']);

        return [
            'uah' => ($salesRetail['uah'] * 0.35)
                + $salesWholesale['uah']
                - $purchasesWholesale['uah']
                - $transport['uah']
                - $returnsRetail['uah'],
            'usd' => ($salesRetail['usd'] * 0.35)
                + $salesWholesale['usd']
                - $purchasesWholesale['usd']
                - $transport['usd']
                - $returnsRetail['usd'],
        ];
    }

    protected function repairProfitComponents(Collection $rows): array
    {
        $sumNetUahByLabels = fn (array $labels): float => $rows
            ->filter(fn (CashTransaction $row) => in_array(trim((string) $row->label), $labels, true))
            ->sum(fn (CashTransaction $row) => $row->netUah());

        $sumNetUsdByLabels = fn (array $labels): float => $rows
            ->filter(fn (CashTransaction $row) => in_array(trim((string) $row->label), $labels, true))
            ->sum(fn (CashTransaction $row) => $row->netUsd());

        $repairM1Rows = $rows->filter(function (CashTransaction $row): bool {
            $label = trim((string) $row->label);
            $employee = trim((string) $row->employee);

            if ($label === '1') {
                return true;
            }

            return $label === '+' && $employee === '';
        });

        return [
            'uah' => $sumNetUahByLabels(['+'])
                + $repairM1Rows->sum(fn (CashTransaction $row) => $row->netUah())
                + $sumNetUahByLabels(['+', '+'])
                + $sumNetUahByLabels(['2'])
                + $sumNetUahByLabels(['-']),
            'usd' => $sumNetUsdByLabels(['+'])
                + $repairM1Rows->sum(fn (CashTransaction $row) => $row->netUsd())
                + $sumNetUsdByLabels(['+', '+'])
                + $sumNetUsdByLabels(['2'])
                + $sumNetUsdByLabels(['-']),
        ];
    }
}
