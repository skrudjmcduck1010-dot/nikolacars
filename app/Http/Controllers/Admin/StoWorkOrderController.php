<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CashbookLabel;
use App\Models\CashTransaction;
use App\Models\Counterparty;
use App\Models\Movement;
use App\Models\PartCatalogCategory;
use App\Models\Product;
use App\Models\StockItem;
use App\Models\StoEmployee;
use App\Models\StoWorkOrder;
use App\Models\StoWorkOrderPart;
use App\Models\StoWorkOrderWork;
use App\Services\ExchangeRateService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class StoWorkOrderController extends Controller
{
    private const LABOR_PAYMENT_LABEL = '+';

    private const DONOR_PARTS_SALE_LABEL = '  ';

    private const PURCHASE_PARTS_SALE_LABEL = 'Продажа ЗЧК';

    public function __construct(private readonly ExchangeRateService $exchangeRateService) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in([...StoWorkOrder::STATUSES, 'all'])],
            'statuses' => ['nullable', 'array'],
            'statuses.*' => [Rule::in([...StoWorkOrder::STATUSES, 'all'])],
            'search' => ['nullable', 'string', 'max:255'],
            'week_start' => ['nullable', 'date'],
        ]);

        $defaultStatuses = array_values(array_diff(StoWorkOrder::STATUSES, [StoWorkOrder::STATUS_ARCHIVED]));
        $statuses = $filters['statuses'] ?? null;
        $statuses = $statuses ?: (isset($filters['status']) ? [$filters['status']] : $defaultStatuses);
        $statuses = collect($statuses)->unique()->values()->all();
        $search = trim((string) ($filters['search'] ?? ''));
        $showAll = in_array('all', $statuses, true);
        $selectedStatuses = $showAll
            ? StoWorkOrder::STATUSES
            : array_values(array_intersect($statuses, StoWorkOrder::STATUSES));
        $filterActiveStatuses = ! $showAll;

        $baseQuery = StoWorkOrder::query();
        $calendarStart = isset($filters['week_start'])
            ? Carbon::parse($filters['week_start'])->startOfWeek(Carbon::MONDAY)
            : Carbon::now()->startOfWeek(Carbon::MONDAY);
        $calendarEnd = $calendarStart->copy()->endOfWeek(Carbon::SUNDAY);
        $calendarAppointments = StoWorkOrder::query()
            ->where('status', StoWorkOrder::STATUS_APPOINTMENT)
            ->whereBetween('opened_at', [$calendarStart->toDateString(), $calendarEnd->toDateString()])
            ->orderBy('opened_at')
            ->orderByRaw("COALESCE(appointment_time, '23:59:59') ASC")
            ->orderBy('id')
            ->get()
            ->groupBy(fn (StoWorkOrder $order): string => $order->opened_at->toDateString());

        return view('admin.sto_work_orders.index', [
            'orders' => StoWorkOrder::query()
                ->with('counterparty')
                ->when($filterActiveStatuses, fn (Builder $query) => $query->whereIn('status', $selectedStatuses))
                ->when($search !== '', fn (Builder $query) => $this->applySearch($query, $search))
                ->orderByRaw("CASE WHEN status = '".StoWorkOrder::STATUS_IN_WORK."' THEN 0 WHEN status = 'appointment' THEN 1 ELSE 2 END")
                ->orderByRaw("CASE WHEN status = 'appointment' THEN opened_at END ASC")
                ->orderByRaw("CASE WHEN status = 'appointment' THEN COALESCE(appointment_time, '23:59:59') END ASC")
                ->orderByDesc('opened_at')
                ->orderByDesc('id')
                ->paginate(25)
                ->withQueryString(),
            'statuses' => $statuses,
            'search' => $search,
            'statusCounts' => (clone $baseQuery)
                ->select('status')
                ->selectRaw('COUNT(*) as orders_count')
                ->groupBy('status')
                ->pluck('orders_count', 'status'),
            'totalCount' => (clone $baseQuery)->where('status', '<>', StoWorkOrder::STATUS_ARCHIVED)->count(),
            'archivedCount' => (clone $baseQuery)->where('status', StoWorkOrder::STATUS_ARCHIVED)->count(),
            'calendarStart' => $calendarStart,
            'calendarEnd' => $calendarEnd,
            'calendarPreviousWeekStart' => $calendarStart->copy()->subWeek()->toDateString(),
            'calendarCurrentWeekStart' => Carbon::now()->startOfWeek(Carbon::MONDAY)->toDateString(),
            'calendarNextWeekStart' => $calendarStart->copy()->addWeek()->toDateString(),
            'calendarDays' => collect(range(0, 6))->map(fn (int $day) => $calendarStart->copy()->addDays($day)),
            'calendarAppointments' => $calendarAppointments,
        ]);
    }

    public function create(Request $request): View
    {
        $filters = $request->validate([
            'opened_at' => ['nullable', 'date'],
        ]);

        return view('admin.sto_work_orders.form', $this->formData(new StoWorkOrder([
            'status' => 'appointment',
            'opened_at' => isset($filters['opened_at']) ? Carbon::parse($filters['opened_at']) : now(),
        ]), [
            'calendarAppointmentMode' => isset($filters['opened_at']),
        ]));
    }

    public function clientSearch(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if ($query === '') {
            return response()->json([]);
        }

        $searchTerms = $this->searchTerms($query);
        $searchColumns = ['name', 'phone', 'car_model', 'vin', 'license_plate'];

        return response()->json(
            Counterparty::query()
                ->whereIn('type', ['customer', 'both'])
                ->where(function (Builder $builder) use ($searchTerms, $searchColumns): void {
                    foreach ($searchColumns as $column) {
                        foreach ($searchTerms as $term) {
                            $builder->orWhere($column, 'like', "%{$term}%");
                        }
                    }
                })
                ->orderBy('name')
                ->limit(12)
                ->get([
                    'id',
                    'name',
                    'phone',
                    'car_model',
                    'car_year',
                    'drive_type',
                    'vin',
                    'license_plate',
                ])
                ->map(fn (Counterparty $client): array => [
                    'id' => $client->id,
                    'name' => $client->name,
                    'phone' => $client->phone,
                    'car_model' => $client->car_model,
                    'car_year' => $client->car_year,
                    'drive_type' => $client->drive_type,
                    'drive_type_label' => $client->drive_type_label,
                    'vin' => $client->vin,
                    'license_plate' => $client->license_plate,
                ])
        );
    }

    protected function searchTerms(string $query): array
    {
        $lower = mb_strtolower($query);

        return collect([
            $query,
            $lower,
            mb_strtoupper($query),
            mb_convert_case($lower, MB_CASE_TITLE),
        ])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function partSearch(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if ($query === '') {
            return response()->json([]);
        }

        $likeOperator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $searchTerms = $this->searchTerms($query);
        $searchColumns = ['name', 'sku', 'external_sku', 'model', 'color'];
        $exchangeRate = $this->exchangeRateService->currentUsdRate();

        return response()->json(
            $this->availableWorkOrderPartsQuery()
                ->where(function (Builder $builder) use ($likeOperator, $searchTerms, $searchColumns): void {
                    foreach ($searchColumns as $column) {
                        foreach ($searchTerms as $term) {
                            $builder->orWhere($column, $likeOperator, "%{$term}%");
                        }
                    }
                })
                ->limit(12)
                ->get()
                ->map(fn (Product $product): array => $this->workOrderPartPayload($product, $exchangeRate))
                ->values()
        );
    }

    public function workSearch(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if ($query === '') {
            return response()->json([]);
        }

        $likeOperator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $searchTerms = $this->searchTerms($query);

        $works = StoWorkOrderWork::query()
            ->where(function (Builder $builder) use ($likeOperator, $searchTerms): void {
                foreach ($searchTerms as $term) {
                    $builder->orWhere('name', $likeOperator, "%{$term}%");
                }
            })
            ->orderByDesc('id')
            ->limit(30)
            ->get(['id', 'name', 'price_uah']);

        return response()->json(
            $works
                ->unique(fn (StoWorkOrderWork $work): string => mb_strtolower($work->name))
                ->take(12)
                ->map(fn (StoWorkOrderWork $work): array => [
                    'name' => $work->name,
                    'price_uah' => (float) $work->price_uah,
                ])
                ->values()
        );
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $this->payload($request);

        $order = DB::transaction(function () use ($payload): StoWorkOrder {
            $payload['number'] = $this->nextNumber();

            return StoWorkOrder::query()->create($payload);
        });

        return redirect()
            ->route('admin.sto-work-orders.show', $order)
            ->with('status', 'Заказ-наряд создан.');
    }

    public function show(StoWorkOrder $stoWorkOrder): View
    {
        return view('admin.sto_work_orders.show', [
            'order' => $stoWorkOrder->load(['counterparty', 'parts.product', 'works.employee']),
            'selectedPart' => $this->selectedOldWorkOrderPart(),
            'exchangeRate' => $this->exchangeRateService->currentUsdRate(),
            'activeEmployees' => StoEmployee::query()
                ->where('is_active', true)
                ->orderBy('cash_employee_name')
                ->get(),
        ]);
    }

    public function printOrder(StoWorkOrder $stoWorkOrder): View
    {
        return view('admin.sto_work_orders.print', [
            'order' => $stoWorkOrder->load(['counterparty', 'parts.product', 'works.employee']),
        ]);
    }

    public function storePart(Request $request, StoWorkOrder $stoWorkOrder): RedirectResponse
    {
        $this->ensureLineItemsCanBeAdded($stoWorkOrder);

        $validated = $request->validate([
            'product_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'min:0.001', 'max:999999'],
            'unit_price_uah' => ['required', 'numeric', 'min:0.01'],
            'note' => ['nullable', 'string'],
            'part_search' => ['nullable', 'string', 'max:255'],
        ]);

        $product = $this->availableWorkOrderPartsQuery()->find($validated['product_id']);

        if (! $product) {
            throw ValidationException::withMessages([
                'product_id' => 'Выберите запчасть, которая есть на складе из донора или закупки.',
            ]);
        }

        $quantity = round((float) $validated['quantity'], 3);
        $unitPrice = round((float) $validated['unit_price_uah'], 2);
        $availableQuantity = round((float) ($product->available_stock ?? 0), 3);

        if ($quantity > $availableQuantity) {
            throw ValidationException::withMessages([
                'quantity' => "На складе доступно только {$availableQuantity}.",
            ]);
        }

        DB::transaction(function () use ($stoWorkOrder, $product, $validated, $quantity, $unitPrice): void {
            $stockItem = $this->stockItemForWorkOrderPart($product, $quantity);
            $this->decreaseStockItem($stockItem, $quantity, $stoWorkOrder);

            $stoWorkOrder->parts()->create([
                'product_id' => $product->id,
                'stock_item_id' => $stockItem->id,
                'name' => $product->name,
                'quantity' => $quantity,
                'unit_price_uah' => $unitPrice,
                'total_price_uah' => round($quantity * $unitPrice, 2),
                'note' => trim((string) ($validated['note'] ?? '')) ?: null,
            ]);

            $this->refreshTotals($stoWorkOrder);
        });

        return redirect()
            ->route('admin.sto-work-orders.show', $stoWorkOrder)
            ->with('status', 'Запчасть добавлена в заказ-наряд.');
    }

    public function destroyPart(StoWorkOrder $stoWorkOrder, StoWorkOrderPart $part): RedirectResponse
    {
        abort_unless($part->sto_work_order_id === $stoWorkOrder->id, 404);
        $this->ensureLineItemsCanBeDeleted($stoWorkOrder);

        DB::transaction(function () use ($stoWorkOrder, $part): void {
            $this->returnWorkOrderPartToStock($part);
            $part->delete();
            $this->refreshTotals($stoWorkOrder);
        });

        return redirect()
            ->route('admin.sto-work-orders.show', $stoWorkOrder)
            ->with('status', 'Запчасть удалена из заказ-наряда.');
    }

    public function storeWork(Request $request, StoWorkOrder $stoWorkOrder): RedirectResponse
    {
        $this->ensureLineItemsCanBeAdded($stoWorkOrder);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sto_employee_id' => ['required', Rule::exists('sto_employees', 'id')->where('is_active', true)],
            'price_uah' => ['required', 'numeric', 'min:0.01'],
            'note' => ['nullable', 'string'],
        ]);

        DB::transaction(function () use ($stoWorkOrder, $validated): void {
            $stoWorkOrder->works()->create([
                'sto_employee_id' => $validated['sto_employee_id'],
                'name' => trim($validated['name']),
                'price_uah' => round((float) $validated['price_uah'], 2),
                'note' => trim((string) ($validated['note'] ?? '')) ?: null,
            ]);

            $this->refreshTotals($stoWorkOrder);
        });

        return redirect()
            ->route('admin.sto-work-orders.show', $stoWorkOrder)
            ->with('status', '   -.');
    }

    public function destroyWork(StoWorkOrder $stoWorkOrder, StoWorkOrderWork $work): RedirectResponse
    {
        abort_unless($work->sto_work_order_id === $stoWorkOrder->id, 404);
        $this->ensureLineItemsCanBeDeleted($stoWorkOrder);

        DB::transaction(function () use ($stoWorkOrder, $work): void {
            $work->delete();
            $this->refreshTotals($stoWorkOrder);
        });

        return redirect()
            ->route('admin.sto-work-orders.show', $stoWorkOrder)
            ->with('status', '   -.');
    }

    public function updateStoComment(Request $request, StoWorkOrder $stoWorkOrder): RedirectResponse
    {
        $validated = $request->validate([
            'sto_comment' => ['nullable', 'string'],
        ]);

        $stoWorkOrder->update([
            'sto_comment' => trim((string) ($validated['sto_comment'] ?? '')) ?: null,
        ]);

        return redirect()
            ->route('admin.sto-work-orders.show', $stoWorkOrder)
            ->with('status', 'Комментарий СТО сохранен.');
    }

    public function updateStatus(Request $request, StoWorkOrder $stoWorkOrder): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(StoWorkOrder::STATUSES)],
        ]);

        $status = $validated['status'];

        if (in_array($status, [StoWorkOrder::STATUS_PAID, StoWorkOrder::STATUS_ARCHIVED], true)) {
            throw ValidationException::withMessages([
                'status' => 'Статусы "Оплачен" и "Архив" устанавливаются через отдельные действия.',
            ]);
        }

        if ($stoWorkOrder->status === StoWorkOrder::STATUS_PAID) {
            throw ValidationException::withMessages([
                'status' => 'Оплаченный заказ-наряд можно вернуть только в статус "В работе" или перенести в архив.',
            ]);
        }

        if ($stoWorkOrder->status === StoWorkOrder::STATUS_ARCHIVED) {
            throw ValidationException::withMessages([
                'status' => 'Нельзя изменить статус архивного заказ-наряда.',
            ]);
        }

        if (in_array($status, ['waiting_parts', 'paused'], true) && $stoWorkOrder->status !== StoWorkOrder::STATUS_IN_WORK) {
            throw ValidationException::withMessages([
                'status' => 'Перевести заказ-наряд в статус "Ожидает запчасти" или "На паузе" можно только из статуса "В работе".',
            ]);
        }

        if ($stoWorkOrder->status === StoWorkOrder::STATUS_COMPLETED && in_array($status, ['appointment', StoWorkOrder::STATUS_CANCELLED], true)) {
            throw ValidationException::withMessages([
                'status' => 'Завершенный заказ-наряд нельзя перевести в статус "Запись" или "Отменен".',
            ]);
        }

        $payload = ['status' => $status];
        $now = now();

        if ($status === StoWorkOrder::STATUS_IN_WORK) {
            $payload['opened_at'] = $now->toDateString();

            if (! $stoWorkOrder->work_started_at) {
                $payload['work_started_at'] = $now;
            }
        }

        if ($status === StoWorkOrder::STATUS_COMPLETED) {
            $payload['completed_at'] = $now;
        }

        DB::transaction(function () use ($stoWorkOrder, $payload, $status): void {
            if ($status === StoWorkOrder::STATUS_CANCELLED) {
                $this->clearLineItemsAndReturnParts($stoWorkOrder);
            }

            if ($status === StoWorkOrder::STATUS_COMPLETED) {
                $this->consumeUnallocatedWorkOrderParts($stoWorkOrder);
            }

            $stoWorkOrder->update($payload);
        });

        return redirect()
            ->route('admin.sto-work-orders.show', $stoWorkOrder)
            ->with('status', 'Статус заказ-наряда обновлен.');
    }

    public function confirmPayment(Request $request, StoWorkOrder $stoWorkOrder): RedirectResponse
    {
        abort_unless($stoWorkOrder->canConfirmPayment(), 404);

        $validated = $request->has('payments')
            ? $request->validate([
                'payments' => ['required', 'array', 'min:1', 'max:10'],
                'payments.*.payment_method' => ['required', Rule::in(['cash_uah', 'cash_usd', 'bank_uah'])],
                'payments.*.amount' => ['required', 'numeric', 'min:0.01', 'max:999999999'],
                'return_url' => ['nullable', 'string', 'max:2048'],
            ])
            : $request->validate([
                'payment_method' => ['required', Rule::in(['cash_uah', 'cash_usd', 'bank_uah'])],
                'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999'],
                'return_url' => ['nullable', 'string', 'max:2048'],
            ]);

        $paymentParts = $this->stoPaymentParts($validated);
        $exchangeRate = $this->exchangeRateService->currentUsdRate();
        $rate = (float) ($exchangeRate['rate'] ?? 0);
        $paymentParts = $paymentParts->map(function (array $paymentPart) use ($rate): array {
            $paymentPart['amount_uah'] = $paymentPart['payment_method'] === 'cash_usd'
                ? round($paymentPart['amount'] * $rate, 2)
                : $paymentPart['amount'];

            return $paymentPart;
        });
        $amountUah = round($paymentParts->sum('amount_uah'), 2);

        if ($paymentParts->contains(fn (array $paymentPart): bool => $paymentPart['payment_method'] === 'cash_usd') && $rate <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Не удалось получить курс USD для пересчета оплаты.',
            ]);
        }

        DB::transaction(function () use ($stoWorkOrder, $paymentParts, $amountUah, $rate): void {
            $this->consumeUnallocatedWorkOrderParts($stoWorkOrder);

            $paidCashUah = round((float) $stoWorkOrder->paid_cash_uah + $paymentParts->where('payment_method', 'cash_uah')->sum('amount'), 2);
            $paidCashUsd = round((float) $stoWorkOrder->paid_cash_usd + $paymentParts->where('payment_method', 'cash_usd')->sum('amount'), 2);
            $paidBankUah = round((float) $stoWorkOrder->paid_bank_uah + $paymentParts->where('payment_method', 'bank_uah')->sum('amount'), 2);
            $paidAmountUah = round((float) $stoWorkOrder->paid_amount_uah + $amountUah, 2);
            $isFullyPaid = $paidAmountUah + 0.0001 >= (float) $stoWorkOrder->total_cost_uah;

            foreach ($paymentParts as $paymentPart) {
                $this->createPaymentCashTransactions($stoWorkOrder, $paymentPart['payment_method'], $paymentPart['amount'], $rate);
            }

            $stoWorkOrder->update([
                'paid_cash_uah' => $paidCashUah,
                'paid_cash_usd' => $paidCashUsd,
                'paid_bank_uah' => $paidBankUah,
                'paid_amount_uah' => $paidAmountUah,
                'payment_confirmed_at' => $isFullyPaid ? now() : $stoWorkOrder->payment_confirmed_at,
                'status' => $isFullyPaid ? StoWorkOrder::STATUS_PAID : StoWorkOrder::STATUS_COMPLETED,
            ]);
        });

        $stoWorkOrder->refresh();
        $remaining = max(0, round((float) $stoWorkOrder->total_cost_uah - (float) $stoWorkOrder->paid_amount_uah, 2));
        $message = $stoWorkOrder->status === StoWorkOrder::STATUS_PAID
            ? 'Оплата получена полностью. Заказ-наряд переведен в статус "Оплачен".'
            : 'Оплата сохранена. Остаток к оплате: '.number_format($remaining, 2, ',', ' ').' грн.';

        $redirectUrl = $this->safeReturnUrl($request, $validated['return_url'] ?? null);

        return ($redirectUrl ? redirect()->to($redirectUrl) : redirect()->route('admin.sto-work-orders.show', $stoWorkOrder))
            ->with('status', $message);
    }

    protected function stoPaymentParts(array $validated): Collection
    {
        $payments = $validated['payments'] ?? [[
            'payment_method' => $validated['payment_method'],
            'amount' => $validated['amount'],
        ]];

        return collect($payments)
            ->map(fn (array $payment): array => [
                'payment_method' => $payment['payment_method'],
                'amount' => round((float) $payment['amount'], 2),
            ])
            ->values();
    }

    public function archive(StoWorkOrder $stoWorkOrder): RedirectResponse
    {
        abort_unless($stoWorkOrder->canArchive(), 404);

        $stoWorkOrder->update([
            'status' => StoWorkOrder::STATUS_ARCHIVED,
        ]);

        return redirect()
            ->route('admin.sto-work-orders.index')
            ->with('status', 'Заказ-наряд перенесен в архив.');
    }

    protected function applySearch(Builder $query, string $search): void
    {
        $query->where(function (Builder $query) use ($search): void {
            $query
                ->where('number', 'like', "%{$search}%")
                ->orWhere('client_name', 'like', "%{$search}%")
                ->orWhere('client_phone', 'like', "%{$search}%")
                ->orWhere('car_model', 'like', "%{$search}%")
                ->orWhere('vin', 'like', "%{$search}%")
                ->orWhere('license_plate', 'like', "%{$search}%")
                ->orWhere('customer_request', 'like', "%{$search}%")
                ->orWhere('work_description', 'like', "%{$search}%");
        });
    }

    protected function safeReturnUrl(Request $request, ?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $appHost = $request->getHost();
        $urlHost = parse_url($url, PHP_URL_HOST);

        if ($urlHost !== null && $urlHost !== $appHost) {
            return null;
        }

        return $url;
    }

    protected function resetPaymentConfirmation(StoWorkOrder $order): void
    {
        CashTransaction::query()
            ->where('source', 'sto_work_order_payment')
            ->where('comment', "Оплата заказ-наряда {$order->number}")
            ->delete();

        $order->forceFill([
            'paid_cash_uah' => 0,
            'paid_cash_usd' => 0,
            'paid_bank_uah' => 0,
            'paid_amount_uah' => 0,
            'payment_confirmed_at' => null,
        ]);
    }

    protected function createPaymentCashTransactions(StoWorkOrder $order, string $method, float $amount, float $rate): void
    {
        foreach ($this->paymentAllocations($order, $amount) as $label => $allocatedAmount) {
            if ($allocatedAmount <= 0) {
                continue;
            }

            CashbookLabel::query()->firstOrCreate(
                ['name' => $label],
                ['operation_type' => 'income'],
            );

            CashTransaction::query()->create([
                'operation_date' => now()->toDateString(),
                'income_bank_uah' => $method === 'bank_uah' ? $allocatedAmount : 0,
                'income_cash_uah' => $method === 'cash_uah' ? $allocatedAmount : 0,
                'income_cash_usd' => $method === 'cash_usd' ? $allocatedAmount : 0,
                'expense_bank_uah' => 0,
                'expense_cash_uah' => 0,
                'expense_cash_usd' => 0,
                'label' => $label,
                'vehicle_vin' => $order->vin,
                'comment' => "Оплата заказ-наряда {$order->number}",
                'source' => 'sto_work_order_payment',
                'exchange_rate' => $method === 'cash_usd' ? $rate : null,
            ]);
        }
    }

    protected function paymentAllocations(StoWorkOrder $order, float $amount): array
    {
        $components = $this->paymentComponents($order);
        $total = array_sum($components);

        if ($total <= 0) {
            return [self::LABOR_PAYMENT_LABEL => round($amount, 2)];
        }

        $allocations = [];
        $remainingAmount = round($amount, 2);

        foreach ($components as $label => $componentTotal) {
            $isLast = count($allocations) === count($components) - 1;
            $allocated = $isLast
                ? $remainingAmount
                : round($amount * ($componentTotal / $total), 2);

            $allocations[$label] = max(0, $allocated);
            $remainingAmount = round($remainingAmount - $allocated, 2);
        }

        return $allocations;
    }

    protected function paymentComponents(StoWorkOrder $order): array
    {
        $order->loadMissing('parts.product');

        $components = [
            self::LABOR_PAYMENT_LABEL => round((float) $order->labor_cost_uah, 2),
            self::DONOR_PARTS_SALE_LABEL => 0.0,
            self::PURCHASE_PARTS_SALE_LABEL => 0.0,
        ];

        foreach ($order->parts as $part) {
            $label = $part->product?->donor_car_id
                ? self::DONOR_PARTS_SALE_LABEL
                : self::PURCHASE_PARTS_SALE_LABEL;

            $components[$label] = round($components[$label] + (float) $part->total_price_uah, 2);
        }

        return array_filter($components, fn (float $value): bool => $value > 0);
    }

    protected function payload(Request $request): array
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(StoWorkOrder::STATUSES)],
            'counterparty_id' => ['nullable', 'integer'],
            'client_name' => ['required_without:counterparty_id', 'nullable', 'string', 'max:255'],
            'client_phone' => ['nullable', 'string', 'max:255'],
            'car_model' => ['nullable', 'string', 'max:255', Rule::in(PartCatalogCategory::modelOptions($request->input('car_model')))],
            'car_year' => ['nullable', 'integer', 'min:1990', 'max:'.(date('Y') + 1)],
            'drive_type' => ['nullable', Rule::in(Counterparty::DRIVE_TYPES)],
            'vin' => ['nullable', 'string', 'max:255'],
            'license_plate' => ['nullable', 'string', 'max:255'],
            'mileage' => ['nullable', 'integer', 'min:0', 'max:2000000'],
            'opened_at' => ['required', 'date'],
            'appointment_time' => [
                Rule::requiredIf(fn (): bool => $request->input('status') === StoWorkOrder::STATUS_APPOINTMENT),
                'nullable',
                'date_format:H:i',
                'after_or_equal:09:00',
                'before_or_equal:19:00',
            ],
            'planned_finished_at' => ['nullable', 'date', 'after_or_equal:opened_at'],
            'completed_at' => ['nullable', 'date', 'after_or_equal:opened_at'],
            'customer_request' => ['nullable', 'string'],
            'work_description' => ['nullable', 'string'],
            'parts_note' => ['nullable', 'string'],
            'labor_cost_uah' => ['nullable', 'numeric', 'min:0'],
            'parts_cost_uah' => ['nullable', 'numeric', 'min:0'],
            'discount_uah' => ['nullable', 'numeric', 'min:0'],
            'calendar_appointment' => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('calendar_appointment') && $validated['status'] !== StoWorkOrder::STATUS_APPOINTMENT) {
            throw ValidationException::withMessages([
                'status' => 'Запись из календаря можно создать только со статусом "Запись".',
            ]);
        }

        $client = isset($validated['counterparty_id'])
            ? Counterparty::query()
                ->whereIn('type', ['customer', 'both'])
                ->find($validated['counterparty_id'])
            : null;

        $laborCost = round((float) ($validated['labor_cost_uah'] ?? 0), 2);
        $partsCost = round((float) ($validated['parts_cost_uah'] ?? 0), 2);
        $discount = round((float) ($validated['discount_uah'] ?? 0), 2);
        $isAppointment = $validated['status'] === 'appointment';
        $isInWork = $validated['status'] === StoWorkOrder::STATUS_IN_WORK;

        if ($isAppointment) {
            $appointmentDate = Carbon::parse($validated['opened_at'], 'Europe/Kyiv')->startOfDay();
            $kyivNow = Carbon::now('Europe/Kyiv');
            $today = $kyivNow->copy()->startOfDay();

            if ($appointmentDate->lt($today)) {
                throw ValidationException::withMessages([
                    'opened_at' => 'Нельзя создать запись задним числом.',
                ]);
            }

            if ($appointmentDate->isSameDay($today) && $kyivNow->format('H:i') > '19:00') {
                throw ValidationException::withMessages([
                    'opened_at' => 'Нельзя создать запись на сегодня после окончания рабочего времени СТО.',
                ]);
            }

            if (
                $appointmentDate->isSameDay($today)
                && isset($validated['appointment_time'])
                && $validated['appointment_time'] < $kyivNow->format('H:i')
            ) {
                throw ValidationException::withMessages([
                    'appointment_time' => 'Нельзя выбрать время раньше текущего.',
                ]);
            }
        }

        return [
            'status' => $validated['status'],
            'counterparty_id' => $client?->id,
            'client_name' => trim((string) ($validated['client_name'] ?? $client?->name)),
            'client_phone' => trim((string) ($validated['client_phone'] ?? $client?->phone)) ?: null,
            'car_model' => trim((string) ($validated['car_model'] ?? $client?->car_model)) ?: null,
            'car_year' => $validated['car_year'] ?? $client?->car_year,
            'drive_type' => $isAppointment ? null : ($validated['drive_type'] ?? $client?->drive_type),
            'vin' => $isAppointment ? null : (trim((string) ($validated['vin'] ?? $client?->vin)) ?: null),
            'license_plate' => trim((string) ($validated['license_plate'] ?? $client?->license_plate)) ?: null,
            'mileage' => $isAppointment ? null : ($validated['mileage'] ?? null),
            'opened_at' => $validated['opened_at'],
            'work_started_at' => $isInWork ? now() : null,
            'appointment_time' => $validated['appointment_time'] ?? null,
            'planned_finished_at' => $validated['planned_finished_at'] ?? null,
            'completed_at' => $validated['completed_at'] ?? null,
            'customer_request' => trim((string) ($validated['customer_request'] ?? '')) ?: null,
            'work_description' => trim((string) ($validated['work_description'] ?? '')) ?: null,
            'parts_note' => trim((string) ($validated['parts_note'] ?? '')) ?: null,
            'labor_cost_uah' => $laborCost,
            'parts_cost_uah' => $partsCost,
            'discount_uah' => $discount,
            'total_cost_uah' => max(0, $laborCost + $partsCost - $discount),
        ];
    }

    protected function nextNumber(): string
    {
        $date = Carbon::now();
        $prefix = 'ЗН-'.$date->format('Ymd').'-';
        $monthPrefix = 'ЗН-'.$date->format('Ym');
        $legacyMonthPrefix = $this->legacyMojibakeWorkOrderPrefix().'-'.$date->format('Ym');
        $numberPrefixes = implode('|', array_map(
            fn (string $prefix): string => preg_quote($prefix, '/'),
            ['ЗН', $this->legacyMojibakeWorkOrderPrefix()],
        ));
        $lastSequence = StoWorkOrder::query()
            ->withTrashed()
            ->where(function (Builder $query) use ($monthPrefix, $legacyMonthPrefix): void {
                $query
                    ->where('number', 'like', "{$monthPrefix}%")
                    ->orWhere('number', 'like', "{$legacyMonthPrefix}%");
            })
            ->lockForUpdate()
            ->pluck('number')
            ->reduce(function (int $max, string $number) use ($numberPrefixes): int {
                if (! preg_match('/^(?:'.$numberPrefixes.')-\d{8}-(\d{4,})$/u', $number, $matches)) {
                    return $max;
                }

                return max($max, (int) $matches[1]);
            }, 0);

        return $prefix.str_pad((string) ($lastSequence + 1), 4, '0', STR_PAD_LEFT);
    }

    protected function legacyMojibakeWorkOrderPrefix(): string
    {
        return mb_chr(0x0420).mb_chr(0x2014).mb_chr(0x0420).mb_chr(0x045C);
    }

    protected function refreshTotals(StoWorkOrder $order): void
    {
        $partsCost = round((float) $order->parts()->sum('total_price_uah'), 2);
        $laborCost = round((float) $order->works()->sum('price_uah'), 2);
        $discount = round((float) $order->discount_uah, 2);

        $order->forceFill([
            'parts_cost_uah' => $partsCost,
            'labor_cost_uah' => $laborCost,
            'total_cost_uah' => max(0, $partsCost + $laborCost - $discount),
        ])->save();
    }

    protected function stockItemForWorkOrderPart(Product $product, float $quantity): StockItem
    {
        $stockItem = StockItem::query()
            ->where('product_id', $product->id)
            ->where('available_quantity', '>=', $quantity)
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if (! $stockItem) {
            throw ValidationException::withMessages([
                'quantity' => 'На складе нет одной ячейки с таким количеством выбранной запчасти.',
            ]);
        }

        return $stockItem;
    }

    protected function decreaseStockItem(StockItem $stockItem, float $quantity, StoWorkOrder $order): void
    {
        $stockItem->quantity = max(0, round((float) $stockItem->quantity - $quantity, 3));
        $stockItem->syncAvailableQuantity();
        $stockItem->save();

        $this->logWorkOrderStockMovement($stockItem, $quantity, $order, 'sale');
    }

    protected function returnWorkOrderPartToStock(StoWorkOrderPart $part): void
    {
        if (! $part->stock_item_id) {
            return;
        }

        $stockItem = StockItem::query()
            ->whereKey($part->stock_item_id)
            ->lockForUpdate()
            ->first();

        if (! $stockItem) {
            return;
        }

        $stockItem->quantity = round((float) $stockItem->quantity + (float) $part->quantity, 3);
        $stockItem->syncAvailableQuantity();
        $stockItem->save();

        $this->logWorkOrderStockMovement($stockItem, (float) $part->quantity, $part->order, 'adjustment');
    }

    protected function clearLineItemsAndReturnParts(StoWorkOrder $order): void
    {
        $order->loadMissing('parts');

        foreach ($order->parts as $part) {
            $this->returnWorkOrderPartToStock($part);
            $part->delete();
        }

        $order->works()->delete();
        $this->refreshTotals($order);
    }

    protected function consumeUnallocatedWorkOrderParts(StoWorkOrder $order): void
    {
        $order->loadMissing('parts.product');

        foreach ($order->parts as $part) {
            if ($part->stock_item_id || ! $part->product) {
                continue;
            }

            $quantity = (float) $part->quantity;
            $stockItem = $this->stockItemForWorkOrderPart($part->product, $quantity);

            $this->decreaseStockItem($stockItem, $quantity, $order);
            $part->forceFill(['stock_item_id' => $stockItem->id])->save();
        }
    }

    protected function logWorkOrderStockMovement(StockItem $stockItem, float $quantity, StoWorkOrder $order, string $type): void
    {
        Movement::query()->create([
            'product_id' => $stockItem->product_id,
            'stock_item_id' => $stockItem->id,
            'from_location_id' => $type === 'sale' ? $stockItem->location_id : null,
            'to_location_id' => $type === 'adjustment' ? $stockItem->location_id : null,
            'user_id' => auth()->id(),
            'counterparty_id' => $order->counterparty_id,
            'type' => $type,
            'quantity' => max(1, (int) round($quantity)),
            'reason' => $type === 'adjustment' ? 'sto_work_order_return' : null,
            'document_number' => $order->number,
            'comment' => $type === 'sale'
                ? "Запчасть списана по заказ-наряду {$order->number}"
                : "Запчасть возвращена из заказ-наряда {$order->number}",
            'created_at' => now()->utc(),
        ]);
    }

    protected function ensureLineItemsCanBeAdded(StoWorkOrder $order): void
    {
        if ($order->canAddLineItems()) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => 'Нельзя добавлять запчасти или работы в заказ-наряд со статусом "Завершен", "Оплачен", "Отменен" или "Архив".',
        ]);
    }

    protected function ensureLineItemsCanBeDeleted(StoWorkOrder $order): void
    {
        if ($order->canDeleteLineItems()) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => 'Нельзя удалять запчасти или работы из заказ-наряда со статусом "Завершен" или "Оплачен".',
        ]);
    }

    protected function formData(StoWorkOrder $order, array $extra = []): array
    {
        return array_merge([
            'order' => $order,
            'clients' => Counterparty::query()
                ->whereIn('type', ['customer', 'both'])
                ->orderBy('name')
                ->get(),
            'models' => PartCatalogCategory::modelOptions(),
            'calendarAppointmentMode' => false,
        ], $extra);
    }

    protected function availableWorkOrderPartsQuery(): Builder
    {
        return Product::query()
            ->where('is_active', true)
            ->where(function (Builder $query): void {
                $query
                    ->whereNotNull('donor_car_id')
                    ->orWhereHas('purchaseItems');
            })
            ->whereHas('stockItems', fn (Builder $query) => $query->where('available_quantity', '>', 0))
            ->withSum('stockItems as available_stock', 'available_quantity')
            ->orderBy('name')
            ->orderBy('sku');
    }

    protected function selectedOldWorkOrderPart(): ?array
    {
        $productId = (int) session()->getOldInput('product_id');

        if ($productId <= 0) {
            return null;
        }

        $product = $this->availableWorkOrderPartsQuery()->find($productId);

        return $product ? $this->workOrderPartPayload($product) : null;
    }

    protected function workOrderPartPayload(Product $product, ?array $exchangeRate = null): array
    {
        $exchangeRate ??= $this->exchangeRateService->currentUsdRate();

        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'external_sku' => $product->external_sku,
            'name' => $product->name,
            'model' => $product->model,
            'color' => $product->color,
            'available_stock' => (float) ($product->available_stock ?? 0),
            'source_label' => $product->donor_car_id ? 'Донор' : 'Закупка',
            'selling_price' => (float) $product->selling_price,
            'currency' => $product->currency,
            'unit_price_uah' => $this->exchangeRateService->productSellingPriceUah(
                (float) $product->selling_price,
                $product->currency,
                $exchangeRate,
            ),
            'exchange_rate' => $exchangeRate,
        ];
    }
}
