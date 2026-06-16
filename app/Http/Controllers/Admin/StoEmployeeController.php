<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CashTransaction;
use App\Models\StoEmployee;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class StoEmployeeController extends Controller
{
    private const REPAIR_MECHANIC_LABELS = ['', '+', '1', '2'];

    private const LEKHA_ALIASES = ['Малой', 'Леха Малой', 'Менеджер Малой', 'Леша'];

    private const LEKHA_NAME = 'Леха';

    private const DONOR_PARTS_SALE_LABELS = [' ', '  '];

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'sort' => ['nullable', Rule::in([
                'last_name',
                'first_name',
                'position',
                'rate',
                'bonus_calculation',
                'is_active',
                'cash_employee_name',
                'salary_uah',
                'salary_usd',
                'transactions_count',
                'latest_operation_date',
                'user',
            ])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'all'])],
        ]);

        $this->syncFromCashbook();

        $sort = in_array($filters['sort'] ?? null, ['last_name', 'first_name'], true)
            ? 'cash_employee_name'
            : ($filters['sort'] ?? 'cash_employee_name');
        $direction = $filters['direction'] ?? 'asc';
        $status = $filters['status'] ?? 'active';
        $payrollQuery = $this->payrollSummaryQuery();
        $payroll = (clone $payrollQuery)->get()->keyBy('employee');

        return view('admin.sto_employees.index', [
            'employees' => StoEmployee::query()
                ->with('user')
                ->leftJoinSub($payrollQuery, 'payroll', function ($join): void {
                    $join->on('sto_employees.cash_employee_name', '=', 'payroll.employee');
                })
                ->leftJoin('users as access_users', 'sto_employees.user_id', '=', 'access_users.id')
                ->select('sto_employees.*')
                ->when($status !== 'all', fn ($query) => $query->where('sto_employees.is_active', $status === 'active'))
                ->orderBy($this->sortColumn($sort), $direction)
                ->orderBy('sto_employees.cash_employee_name')
                ->get(),
            'payroll' => $payroll,
            'sort' => $sort,
            'direction' => $direction,
            'status' => $status,
            'bonusCalculations' => $this->employeeBonusCalculations(),
            'currentMonthBonusPeriod' => $this->currentMonthBonusPeriod(),
        ]);
    }

    public function edit(StoEmployee $stoEmployee): View
    {
        return view('admin.sto_employees.form', [
            'employee' => $stoEmployee,
            'summary' => $this->payrollSummary()->get($stoEmployee->cash_employee_name),
            'bonusOptions' => $this->bonusCalculationOptions(),
            'users' => $this->accessUsers(),
        ]);
    }

    public function create(): View
    {
        return view('admin.sto_employees.form', [
            'employee' => new StoEmployee([
                'is_active' => true,
                'start_date' => now()->toDateString(),
            ]),
            'summary' => null,
            'bonusOptions' => $this->bonusCalculationOptions(),
            'users' => $this->accessUsers(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $employee = StoEmployee::query()->create($this->payload($request));

        return redirect()->route('admin.sto-employees.show', $employee)->with('status', 'Сотрудник добавлен.');
    }

    public function show(StoEmployee $stoEmployee): View
    {
        $stoEmployee->loadMissing('user');

        $transactions = CashTransaction::query()
            ->where('employee', $stoEmployee->cash_employee_name)
            ->whereRaw("TRIM(COALESCE(label, '')) = ?", ['ЗП'])
            ->orderByDesc('operation_date')
            ->orderByDesc('id')
            ->get();

        $monthlyPayroll = $this->buildMonthlyPayroll($transactions, $stoEmployee);

        return view('admin.sto_employees.show', [
            'employee' => $stoEmployee,
            'summary' => $this->payrollSummary()->get($stoEmployee->cash_employee_name),
            'transactions' => $transactions,
            'monthlyPayroll' => $monthlyPayroll,
            'bonusCalculation' => $this->employeeBonusCalculation($stoEmployee),
            'monthlyCompensation' => $this->buildMonthlyCompensation($stoEmployee, $monthlyPayroll),
            'currentMonthBonusPeriod' => $this->currentMonthBonusPeriod(),
        ]);
    }

    public function update(Request $request, StoEmployee $stoEmployee): RedirectResponse
    {
        $payload = $this->payload($request, $stoEmployee);
        $oldCashEmployeeName = $stoEmployee->cash_employee_name;

        DB::transaction(function () use ($stoEmployee, $payload, $oldCashEmployeeName): void {
            $stoEmployee->update($payload);

            if ($oldCashEmployeeName !== $stoEmployee->cash_employee_name) {
                CashTransaction::query()
                    ->where('employee', $oldCashEmployeeName)
                    ->update(['employee' => $stoEmployee->cash_employee_name]);
            }
        });

        return redirect()->route('admin.sto-employees.index')->with('status', 'Сотрудник обновлен.');
    }

    public function updateAccessPassword(Request $request, StoEmployee $stoEmployee): RedirectResponse
    {
        $stoEmployee->loadMissing('user');

        if (! $stoEmployee->user) {
            return back()->withErrors(['password' => 'К этому сотруднику не привязан аккаунт доступа.']);
        }

        $validated = $request->validate([
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $stoEmployee->user->forceFill([
            'password' => Hash::make($validated['password']),
        ])->save();

        return redirect()
            ->route('admin.sto-employees.show', $stoEmployee)
            ->with('status', 'Пароль аккаунта обновлен.');
    }

    public function updateAccessLogin(Request $request, StoEmployee $stoEmployee): RedirectResponse
    {
        $stoEmployee->loadMissing('user');

        if (! $stoEmployee->user) {
            return back()->withErrors(['email' => 'К этому сотруднику не привязан аккаунт доступа.']);
        }

        $validated = $request->validate([
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($stoEmployee->user),
            ],
        ]);

        $stoEmployee->user->forceFill([
            'email' => $validated['email'],
        ])->save();

        return redirect()
            ->route('admin.sto-employees.show', $stoEmployee)
            ->with('status', 'Логин аккаунта обновлен.');
    }

    protected function payload(Request $request, ?StoEmployee $stoEmployee = null): array
    {
        $request->merge([
            'cash_employee_name' => CashTransaction::normalizeEmployeeName($request->input('cash_employee_name')) ?? trim((string) $request->input('cash_employee_name')),
        ]);

        $validated = $request->validate([
            'cash_employee_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('sto_employees', 'cash_employee_name')->ignore($stoEmployee),
            ],
            'position' => ['nullable', 'string', 'max:255'],
            'rate' => ['nullable', 'numeric', 'min:0'],
            'bonus_calculation' => ['nullable', Rule::in(array_keys($this->bonusCalculationOptions()))],
            'start_date' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
            'user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id'),
                Rule::unique('sto_employees', 'user_id')->ignore($stoEmployee),
            ],
        ]);

        return [
            'cash_employee_name' => trim((string) $validated['cash_employee_name']),
            'first_name' => null,
            'last_name' => trim((string) $validated['cash_employee_name']),
            'position' => trim((string) ($validated['position'] ?? '')) ?: null,
            'rate' => array_key_exists('rate', $validated) && $validated['rate'] !== null
                ? round((float) $validated['rate'], 2)
                : null,
            'bonus_calculation' => $validated['bonus_calculation'] ?? null,
            'start_date' => $validated['start_date'] ?? null,
            'is_active' => $request->boolean('is_active'),
            'user_id' => $validated['user_id'] ?? null,
        ];
    }

    protected function accessUsers(): Collection
    {
        return User::query()
            ->orderBy('name')
            ->orderBy('email')
            ->get(['id', 'name', 'email', 'role', 'is_active']);
    }

    protected function syncFromCashbook(): void
    {
        $this->mergeLekhaAliases();

        CashTransaction::query()
            ->select('employee')
            ->whereNotNull('employee')
            ->where('employee', '<>', '')
            ->whereRaw("TRIM(COALESCE(label, '')) = ?", ['ЗП'])
            ->distinct()
            ->orderBy('employee')
            ->pluck('employee')
            ->each(function (string $employee): void {
                $employee = CashTransaction::normalizeEmployeeName($employee) ?? $employee;

                if (trim($employee) === '') {
                    return;
                }

                StoEmployee::query()->firstOrCreate(
                    ['cash_employee_name' => $employee],
                    [
                        'first_name' => null,
                        'last_name' => $employee,
                        'is_active' => true,
                    ],
                );
            });
    }

    protected function mergeLekhaAliases(): void
    {
        if (
            ! CashTransaction::query()->whereIn('employee', self::LEKHA_ALIASES)->exists()
            && ! StoEmployee::query()->whereIn('cash_employee_name', self::LEKHA_ALIASES)->exists()
        ) {
            return;
        }

        DB::transaction(function (): void {
            CashTransaction::query()
                ->whereIn('employee', self::LEKHA_ALIASES)
                ->update(['employee' => self::LEKHA_NAME]);

            $existing = StoEmployee::query()
                ->where('cash_employee_name', self::LEKHA_NAME)
                ->first();

            $source = StoEmployee::query()
                ->whereIn('cash_employee_name', self::LEKHA_ALIASES)
                ->orderByRaw('rate IS NULL')
                ->orderByRaw('bonus_calculation IS NULL')
                ->first();

            if (! $existing) {
                $existing = StoEmployee::query()->create([
                    'cash_employee_name' => self::LEKHA_NAME,
                    'first_name' => null,
                    'last_name' => self::LEKHA_NAME,
                    'position' => $source?->position,
                    'rate' => $source?->rate,
                    'bonus_calculation' => $source?->bonus_calculation,
                    'start_date' => $source?->start_date,
                    'is_active' => $source?->is_active ?? true,
                ]);
            } elseif ($source) {
                $existing->forceFill([
                    'position' => $existing->position ?: $source->position,
                    'rate' => $existing->rate ?? $source->rate,
                    'bonus_calculation' => $existing->bonus_calculation ?: $source->bonus_calculation,
                    'start_date' => $existing->start_date ?: $source->start_date,
                    'is_active' => $existing->is_active || $source->is_active,
                ])->save();
            }

            if (Schema::hasTable('sto_work_order_works')) {
                StoEmployee::query()
                    ->whereIn('cash_employee_name', self::LEKHA_ALIASES)
                    ->pluck('id')
                    ->each(function (int $aliasId) use ($existing): void {
                        DB::table('sto_work_order_works')
                            ->where('sto_employee_id', $aliasId)
                            ->update(['sto_employee_id' => $existing->id]);
                    });
            }

            StoEmployee::query()
                ->whereIn('cash_employee_name', self::LEKHA_ALIASES)
                ->delete();
        });
    }

    protected function payrollSummary(): Collection
    {
        return $this->payrollSummaryQuery()
            ->get()
            ->keyBy('employee');
    }

    protected function payrollSummaryQuery()
    {
        return CashTransaction::query()
            ->select('employee')
            ->selectRaw('COUNT(*) as transactions_count')
            ->selectRaw('MAX(operation_date) as latest_operation_date')
            ->selectRaw('COALESCE(SUM(expense_bank_uah + expense_cash_uah), 0) as salary_uah')
            ->selectRaw('COALESCE(SUM(expense_cash_usd), 0) as salary_usd')
            ->whereNotNull('employee')
            ->where('employee', '<>', '')
            ->whereRaw("TRIM(COALESCE(label, '')) = ?", ['ЗП'])
            ->groupBy('employee');
    }

    protected function sortColumn(string $sort): mixed
    {
        return match ($sort) {
            'position' => 'sto_employees.position',
            'rate' => 'sto_employees.rate',
            'bonus_calculation' => 'sto_employees.bonus_calculation',
            'is_active' => 'sto_employees.is_active',
            'cash_employee_name' => 'sto_employees.cash_employee_name',
            'salary_uah' => DB::raw('COALESCE(payroll.salary_uah, 0)'),
            'salary_usd' => DB::raw('COALESCE(payroll.salary_usd, 0)'),
            'transactions_count' => DB::raw('COALESCE(payroll.transactions_count, 0)'),
            'latest_operation_date' => DB::raw("COALESCE(payroll.latest_operation_date, '0000-00-00')"),
            'user' => DB::raw("COALESCE(access_users.email, '')"),
            default => 'sto_employees.cash_employee_name',
        };
    }

    protected function buildMonthlyPayroll(Collection $transactions, StoEmployee $employee): Collection
    {
        if ($transactions->isEmpty()) {
            return collect();
        }

        $monthlyTotals = $transactions
            ->groupBy(fn (CashTransaction $transaction) => $transaction->operation_date?->format('Y-m'))
            ->map(function (Collection $monthTransactions): array {
                return [
                    'salary_uah' => $monthTransactions->sum(
                        fn (CashTransaction $transaction) => (float) $transaction->expense_bank_uah + (float) $transaction->expense_cash_uah
                    ),
                    'salary_usd' => $monthTransactions->sum(
                        fn (CashTransaction $transaction) => (float) $transaction->expense_cash_usd
                    ),
                ];
            });

        $firstMonth = optional($transactions->min('operation_date'))?->copy()->startOfMonth();
        $lastRecordedMonth = optional($transactions->max('operation_date'))?->copy()->startOfMonth();
        $lastMonth = $employee->is_active
            ? Carbon::now()->startOfMonth()
            : $lastRecordedMonth;

        if (! $firstMonth || ! $lastMonth) {
            return collect();
        }

        $months = collect();
        $cursor = $firstMonth->copy();

        while ($cursor->lte($lastMonth)) {
            $monthKey = $cursor->format('Y-m');
            $totals = $monthlyTotals->get($monthKey, [
                'salary_uah' => 0,
                'salary_usd' => 0,
            ]);

            $months->push([
                'month_key' => $monthKey,
                'month_label' => $cursor->translatedFormat('M Y'),
                'salary_uah' => round((float) $totals['salary_uah'], 2),
                'salary_usd' => round((float) $totals['salary_usd'], 2),
            ]);

            $cursor->addMonth();
        }

        return $months;
    }

    protected function buildMonthlyCompensation(StoEmployee $employee, Collection $monthlyPayroll): Collection
    {
        return $monthlyPayroll
            ->map(function (array $month) use ($employee): array {
                $from = Carbon::createFromFormat('Y-m', $month['month_key'])->startOfMonth();
                $to = $from->copy()->endOfMonth();
                $bonus = $this->bonusCalculationForPeriod($employee, $from, $to);
                $bonusAmount = (float) ($bonus['bonus_amount_uah'] ?? 0);
                $salaryUah = (float) $month['salary_uah'];
                $salaryUsd = (float) $month['salary_usd'];

                return [
                    'month_key' => $month['month_key'],
                    'month_label' => $month['month_label'],
                    'salary_uah' => $salaryUah,
                    'salary_usd' => $salaryUsd,
                    'rate_uah' => round((float) ($employee->rate ?? 0), 2),
                    'bonus_uah' => round($bonusAmount, 2),
                    'total_uah' => round((float) ($employee->rate ?? 0) + $bonusAmount, 2),
                    'bonus_details' => $bonus,
                ];
            })
            ->sortByDesc('month_key')
            ->values();
    }

    protected function bonusCalculationOptions(): array
    {
        return [
            'zinchenko_eugene_profit_7pct' => 'Зинченко Евгений: 7% от (ремонт за месяц + прибыль с запчастей за месяц)',
            'obmanshchikov_excel_e421' => 'Obmanshchikov: =E390/2',
            'zinchenko_anton_excel_d422' => 'Zinchenko Anton: =E379*0.7',
            'razdorin_excel_d423' => 'Razdorin: =(C389+(D389*D1))*0.5',
            'lekha_excel_e424' => 'Lekha: =E392*30%+rate',
            'dima_excel_e425' => 'Dima: =E379*3%+rate',
        ];
    }

    protected function currentMonthBonusPeriod(): array
    {
        $from = Carbon::now()->startOfMonth();
        $to = Carbon::now()->endOfMonth();

        return [
            'from' => $from,
            'to' => $to,
            'label' => $from->translatedFormat('F Y'),
            'usd_rate' => 43.0,
        ];
    }

    protected function employeeBonusCalculations(): Collection
    {
        return StoEmployee::query()
            ->whereNotNull('bonus_calculation')
            ->where('bonus_calculation', '<>', '')
            ->get()
            ->mapWithKeys(fn (StoEmployee $employee) => [$employee->id => $this->employeeBonusCalculation($employee)]);
    }

    protected function employeeBonusCalculation(StoEmployee $employee): ?array
    {
        return $this->bonusCalculationForPeriod(
            $employee,
            Carbon::now()->startOfMonth(),
            Carbon::now()->endOfMonth()
        );
    }

    protected function bonusCalculationForPeriod(StoEmployee $employee, Carbon $from, Carbon $to): ?array
    {
        if (! $employee->bonus_calculation) {
            return null;
        }

        return match ($employee->bonus_calculation) {
            'zinchenko_eugene_profit_7pct' => $this->zinchenkoEugeneBonusCalculation($from, $to),
            'obmanshchikov_excel_e421' => $this->obmanshchikovExcelBonusCalculation($from, $to),
            'zinchenko_anton_excel_d422' => $this->zinchenkoAntonExcelBonusCalculation($from, $to),
            'razdorin_excel_d423' => $this->razdorinExcelBonusCalculation($from, $to),
            'lekha_excel_e424' => $this->lekhaExcelBonusCalculation($from, $to),
            'dima_excel_e425' => $this->dimaExcelBonusCalculation($from, $to),
            default => null,
        };
    }

    protected function zinchenkoEugeneBonusCalculation(Carbon $from, Carbon $to): array
    {
        $rows = CashTransaction::query()
            ->whereDate('operation_date', '>=', $from->toDateString())
            ->whereDate('operation_date', '<=', $to->toDateString())
            ->get();

        $partsUsdRate = 43.0;
        $repairUsdRate = 42.0;
        $partsComponent = $this->zinchenkoExcelPartsComponents($rows);
        $repairComponent = $this->zinchenkoExcelRepairComponents($rows);
        $base = $repairComponent['uah']
            + ($repairComponent['usd'] * $repairUsdRate)
            + $partsComponent['uah']
            + ($partsComponent['usd'] * $partsUsdRate);

        return [
            'scheme' => 'zinchenko_eugene_profit_7pct',
            'label' => '7% от прибыли',
            'description' => '7% x (Прибыль за мес, грн + Общая прибыль с Запчастей, грн + Прибыль за мес, $ x курс + Общая прибыль с Запчастей, $ x курс)',
            'period_label' => $from->translatedFormat('F Y'),
            'usd_rate' => $partsUsdRate,
            'repair_usd_rate' => $repairUsdRate,
            'repair_uah' => round($repairComponent['uah'], 2),
            'repair_usd' => round($repairComponent['usd'], 2),
            'parts_uah' => round($partsComponent['uah'], 2),
            'parts_usd' => round($partsComponent['usd'], 2),
            'base_amount_uah' => round($base, 2),
            'bonus_amount_uah' => round($base * 0.07, 2),
        ];
    }

    protected function obmanshchikovExcelBonusCalculation(Carbon $from, Carbon $to): array
    {
        $rows = CashTransaction::query()
            ->whereDate('operation_date', '>=', $from->toDateString())
            ->whereDate('operation_date', '<=', $to->toDateString())
            ->get();

        $repairM1 = $this->excelRepairM1Total($rows, 42.0);

        return [
            'scheme' => 'obmanshchikov_excel_e421',
            'label' => 'Excel E421',
            'description' => 'E390 / 2',
            'period_label' => $from->translatedFormat('F Y'),
            'base_amount_uah' => round($repairM1, 2),
            'bonus_amount_uah' => round($repairM1 / 2, 2),
        ];
    }

    protected function zinchenkoAntonExcelBonusCalculation(Carbon $from, Carbon $to): array
    {
        $rows = CashTransaction::query()
            ->whereDate('operation_date', '>=', $from->toDateString())
            ->whereDate('operation_date', '<=', $to->toDateString())
            ->get();

        $salesRetail = $this->excelSalesRetailTotal($rows, 41.0);

        return [
            'scheme' => 'zinchenko_anton_excel_d422',
            'label' => 'Excel D422',
            'description' => 'E379 * 0.07',
            'period_label' => $from->translatedFormat('F Y'),
            'base_amount_uah' => round($salesRetail, 2),
            'bonus_amount_uah' => round($salesRetail * 0.07, 2),
        ];
    }

    protected function razdorinExcelBonusCalculation(Carbon $from, Carbon $to): array
    {
        $rows = CashTransaction::query()
            ->whereDate('operation_date', '>=', $from->toDateString())
            ->whereDate('operation_date', '<=', $to->toDateString())
            ->get();

        $repairE = $this->excelRepairETotal($rows, 43.0);

        return [
            'scheme' => 'razdorin_excel_d423',
            'label' => 'Excel D423',
            'description' => '(C389 + D389 * D1) * 0.5',
            'period_label' => $from->translatedFormat('F Y'),
            'base_amount_uah' => round($repairE, 2),
            'bonus_amount_uah' => round($repairE * 0.5, 2),
        ];
    }

    protected function lekhaExcelBonusCalculation(Carbon $from, Carbon $to): array
    {
        $rows = CashTransaction::query()
            ->whereDate('operation_date', '>=', $from->toDateString())
            ->whereDate('operation_date', '<=', $to->toDateString())
            ->get();

        $repairM2 = $this->excelRepairM2Total($rows, 42.0);

        return [
            'scheme' => 'lekha_excel_e424',
            'label' => 'Excel E424',
            'description' => 'E424 - rate',
            'period_label' => $from->translatedFormat('F Y'),
            'base_amount_uah' => round($repairM2, 2),
            'bonus_amount_uah' => round($repairM2 * 0.3, 2),
        ];
    }

    protected function dimaExcelBonusCalculation(Carbon $from, Carbon $to): array
    {
        $rows = CashTransaction::query()
            ->whereDate('operation_date', '>=', $from->toDateString())
            ->whereDate('operation_date', '<=', $to->toDateString())
            ->get();

        $salesRetail = $this->excelSalesRetailTotal($rows, 41.0);

        return [
            'scheme' => 'dima_excel_e425',
            'label' => 'Excel E425',
            'description' => 'E425 - rate',
            'period_label' => $from->translatedFormat('F Y'),
            'base_amount_uah' => round($salesRetail, 2),
            'bonus_amount_uah' => round($salesRetail * 0.03, 2),
        ];
    }

    protected function excelSalesRetailTotal(Collection $rows, float $usdRate): float
    {
        $salesRetail = $rows
            ->filter(fn (CashTransaction $row) => in_array(trim((string) $row->label), self::DONOR_PARTS_SALE_LABELS, true));

        return (float) $salesRetail->sum(fn (CashTransaction $row) => $row->netUah())
            + ((float) $salesRetail->sum(fn (CashTransaction $row) => $row->netUsd()) * $usdRate);
    }

    protected function excelRepairETotal(Collection $rows, float $usdRate): float
    {
        $repairE = $rows
            ->filter(fn (CashTransaction $row) => trim((string) $row->label) === '+');

        return (float) $repairE->sum(fn (CashTransaction $row) => $row->netUah())
            + ((float) $repairE->sum(fn (CashTransaction $row) => $row->netUsd()) * $usdRate);
    }

    protected function excelRepairM2Total(Collection $rows, float $usdRate): float
    {
        $repairM2 = $rows
            ->filter(fn (CashTransaction $row) => in_array(trim((string) $row->label), self::REPAIR_MECHANIC_LABELS, true));

        return (float) $repairM2->sum(fn (CashTransaction $row) => $row->netUah())
            + ((float) $repairM2->sum(fn (CashTransaction $row) => $row->netUsd()) * $usdRate);
    }

    protected function excelRepairM1Total(Collection $rows, float $usdRate): float
    {
        $repairM1 = $rows
            ->filter(fn (CashTransaction $row) => in_array(trim((string) $row->label), self::REPAIR_MECHANIC_LABELS, true));

        return (float) $repairM1->sum(fn (CashTransaction $row) => $row->netUah())
            + ((float) $repairM1->sum(fn (CashTransaction $row) => $row->netUsd()) * $usdRate);
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

        $salesRetail = $sumIncomeMinusExpenseByLabels(self::DONOR_PARTS_SALE_LABELS);
        $returnsRetail = $sumExpenseMinusIncomeByLabels(['Возврат Запчасти и денег']);
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

        return [
            'uah' => $sumNetUahByLabels(['+'])
                + $sumNetUahByLabels(self::REPAIR_MECHANIC_LABELS)
                + $sumNetUahByLabels(['+', '+'])
                + $sumNetUahByLabels(['-']),
            'usd' => $sumNetUsdByLabels(['+'])
                + $sumNetUsdByLabels(self::REPAIR_MECHANIC_LABELS)
                + $sumNetUsdByLabels(['+', '+'])
                + $sumNetUsdByLabels(['-']),
        ];
    }

    protected function zinchenkoExcelPartsComponents(Collection $rows): array
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

        $salesRetail = $sumIncomeMinusExpenseByLabels(self::DONOR_PARTS_SALE_LABELS);
        $returnsRetail = $sumExpenseMinusIncomeByLabels(['Возврат Запчасти и денег']);
        $salesWholesale = $sumIncomeMinusExpenseByLabels(json_decode('["\u041f\u0440\u043e\u0434\u0430\u0436\u0430 \u0417\u0427\u041a"]', true));
        $purchasesWholesale = $sumExpenseMinusIncomeByLabels(json_decode('["\u0417\u0430\u043a\u0443\u043f\u043a\u0430 \u0417\u0427\u041a"]', true));

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

    protected function zinchenkoExcelRepairComponents(Collection $rows): array
    {
        $sumNetUahByLabels = fn (array $labels): float => $rows
            ->filter(fn (CashTransaction $row) => in_array(trim((string) $row->label), $labels, true))
            ->sum(fn (CashTransaction $row) => $row->netUah());

        $sumNetUsdByLabels = fn (array $labels): float => $rows
            ->filter(fn (CashTransaction $row) => in_array(trim((string) $row->label), $labels, true))
            ->sum(fn (CashTransaction $row) => $row->netUsd());

        return [
            'uah' => $sumNetUahByLabels(json_decode('["\u0420\u0435\u043c\u043e\u043d\u0442\u042d+"]', true))
                + $sumNetUahByLabels(self::REPAIR_MECHANIC_LABELS)
                + $sumNetUahByLabels(json_decode('["\u0420\u0435\u043c\u043e\u043d\u0442+","\u0420\u0435\u043c\u043e\u043d\u0442\u0420+"]', true))
                + $sumNetUahByLabels(json_decode('["\u0420\u0435\u043c\u043e\u043d\u0442-"]', true)),
            'usd' => $sumNetUsdByLabels(json_decode('["\u0420\u0435\u043c\u043e\u043d\u0442\u042d+"]', true))
                + $sumNetUsdByLabels(self::REPAIR_MECHANIC_LABELS)
                + $sumNetUsdByLabels(json_decode('["\u0420\u0435\u043c\u043e\u043d\u0442+","\u0420\u0435\u043c\u043e\u043d\u0442\u0420+"]', true))
                + $sumNetUsdByLabels(json_decode('["\u0420\u0435\u043c\u043e\u043d\u0442-"]', true)),
        ];
    }
}
