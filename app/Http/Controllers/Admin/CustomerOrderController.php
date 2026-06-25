<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Counterparty;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderHistoryEvent;
use App\Models\CustomerOrderItem;
use App\Models\CustomerOrderShipment;
use App\Models\Location;
use App\Models\PartCatalogItem;
use App\Models\Product;
use App\Models\StockItem;
use App\Models\Warehouse;
use App\Services\CustomerOrderIssuedSaleService;
use App\Services\CustomerOrderNovaPoshtaStatusSyncService;
use App\Services\CustomerOrderReservationProjectionService;
use App\Services\ExchangeRateService;
use App\Services\NikolaCarsInventoryService;
use App\Services\NikolaCarsProductInventorySyncService;
use App\Services\NovaPoshtaDirectoryService;
use App\Services\NovaPoshtaInternetDocumentService;
use App\Services\StockService;
use App\Support\CatalogTextEncoding;
use App\Support\PartCatalogRawAttributes;
use App\Support\ProductPhotoNormalizer;
use App\Support\PublicStorageUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

class CustomerOrderController extends Controller
{
    protected const NOVA_POSHTA_AFTERPAYMENT_COMMISSION_RATE = 0.005;

    public function index(Request $request, ExchangeRateService $exchangeRateService): View
    {
        $query = trim((string) $request->query('q', ''));
        $tab = $request->query('tab') === 'cancelled' ? 'cancelled' : 'active';
        $paymentUsdRate = $exchangeRateService->currentUsdRate();
        $ordersQuery = CustomerOrder::query()
            ->with(['counterparty', 'creator.stoEmployee', 'novaPoshtaShipment', 'novaPoshtaShipments.items', 'items.partCatalogItem', 'items.product.sourcePartCatalogItem', 'historyEvents'])
            ->when($query !== '', fn (Builder $builder) => $this->applySearch($builder, $query));
        $orders = (clone $ordersQuery)
            ->when(
                $tab === 'cancelled',
                fn (Builder $builder) => $builder->whereIn('status', [
                    CustomerOrder::STATUS_CANCELLED,
                ]),
                fn (Builder $builder) => $builder->whereNotIn('status', [
                    CustomerOrder::STATUS_CANCELLED,
                    CustomerOrder::STATUS_REFUSED,
                    CustomerOrder::STATUS_COMPLETED,
                ])->where(function (Builder $builder): void {
                    $builder
                        ->where('status', '!=', CustomerOrder::STATUS_PAID)
                        ->orWhere('delivery_method', '!=', CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA)
                        ->orWhereNull('delivery_method');
                })->where(function (Builder $builder): void {
                    $builder
                        ->where('status', '!=', CustomerOrder::STATUS_SHIPPED)
                        ->orWhere('delivery_method', '!=', CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA)
                        ->orWhereNull('delivery_method');
                })->where(function (Builder $builder): void {
                    $builder->whereNot(function (Builder $builder): void {
                        $builder
                            ->where('status', CustomerOrder::STATUS_ASSEMBLED)
                            ->where('delivery_method', CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA)
                            ->whereHas('novaPoshtaShipment', fn (Builder $builder) => $this->whereNovaPoshtaShipmentMeansShipped($builder));
                    });
                }),
            )
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();
        $shippedNovaPoshtaOrders = $tab === 'active'
            ? (clone $ordersQuery)
                ->where('delivery_method', CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA)
                ->where(function (Builder $builder): void {
                    $builder
                        ->where('status', CustomerOrder::STATUS_SHIPPED)
                        ->orWhere(function (Builder $builder): void {
                            $builder
                                ->where('status', CustomerOrder::STATUS_ASSEMBLED)
                                ->whereHas('novaPoshtaShipment', fn (Builder $builder) => $this->whereNovaPoshtaShipmentMeansShipped($builder));
                        });
                })
                ->whereDoesntHave('novaPoshtaShipment', fn (Builder $builder) => $builder
                    ->where('np_status_code', CustomerOrder::NOVA_POSHTA_STATUS_RECEIVED))
                ->orderByDesc('id')
                ->limit(30)
                ->get()
            : collect();
        $refusedNovaPoshtaOrders = $tab === 'active'
            ? (clone $ordersQuery)
                ->where('status', CustomerOrder::STATUS_REFUSED)
                ->where('delivery_method', CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA)
                ->orderByDesc('id')
                ->limit(30)
                ->get()
            : collect();
        $returnToStockWarehouses = $this->activeReturnToStockWarehouses();
        $returnToStockLocations = $this->activeReturnToStockLocations();
        $completedOrders = $tab === 'active'
            ? (clone $ordersQuery)
                ->where(function (Builder $builder): void {
                    $builder
                        ->issuedToClient()
                        ->orWhere(function (Builder $builder): void {
                            $builder
                                ->where('delivery_method', CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA)
                                ->whereHas('novaPoshtaShipment', fn (Builder $builder) => $builder
                                    ->where('np_status_code', CustomerOrder::NOVA_POSHTA_STATUS_RECEIVED));
                        });
                })
                ->orderByDesc('id')
                ->limit(30)
                ->get()
            : collect();
        $ordersForTotals = $orders->getCollection()
            ->concat($shippedNovaPoshtaOrders)
            ->concat($refusedNovaPoshtaOrders)
            ->concat($completedOrders);
        $orderUsdRates = $ordersForTotals
            ->mapWithKeys(fn (CustomerOrder $order): array => [
                $order->id => $this->customerOrderUsdRate($order, $exchangeRateService),
            ]);

        return view('admin.customer_orders.index', [
            'orders' => $orders,
            'shippedNovaPoshtaOrders' => $shippedNovaPoshtaOrders,
            'refusedNovaPoshtaOrders' => $refusedNovaPoshtaOrders,
            'completedOrders' => $completedOrders,
            'usdRate' => $paymentUsdRate,
            'customerOrderCashSummary' => $this->customerOrderCashSummary(),
            'orderTotalAmountUah' => $ordersForTotals
                ->mapWithKeys(fn (CustomerOrder $order): array => [
                    $order->id => $this->customerOrderTotalAmountUah($order, $exchangeRateService, $orderUsdRates->get($order->id)),
                ]),
            'itemProductUrls' => $this->customerOrderItemProductUrls($ordersForTotals->pluck('items')->flatten()),
            'itemDisplayCodes' => $this->customerOrderItemDisplayCodes($ordersForTotals->pluck('items')->flatten()),
            'itemDisplayPartNumbers' => $this->customerOrderItemDisplayPartNumbers($ordersForTotals->pluck('items')->flatten()),
            'itemDisplayNames' => $this->customerOrderItemDisplayNames($ordersForTotals->pluck('items')->flatten()),
            'returnToStockWarehouseOptions' => $this->returnToStockWarehouseOptions($returnToStockWarehouses),
            'returnToStockLocationOptions' => $this->returnToStockLocationOptions($returnToStockLocations),
            'returnToStockDefaults' => $this->returnToStockDefaults($ordersForTotals, $returnToStockLocations),
            'query' => $query,
            'tab' => $tab,
        ]);
    }

    protected function whereNovaPoshtaShipmentMeansShipped(Builder $builder): void
    {
        $builder->where(function (Builder $builder): void {
            $builder
                ->whereIn('np_status_code', ['5', '6', '7', '8', '41', '101'])
                ->orWhere(function (Builder $builder): void {
                    $builder
                        ->where('np_status', 'like', '%Відправлення у м.%')
                        ->where('np_status', 'not like', '%Очікує відправлення%')
                        ->where('np_status', 'not like', '%Ожидает отправ%');
                })
                ->orWhere(function (Builder $builder): void {
                    $builder
                        ->where('np_status', 'like', '%Відправлення у місті%')
                        ->where('np_status', 'not like', '%Очікує відправлення%')
                        ->where('np_status', 'not like', '%Ожидает отправ%');
                });
        });
    }

    public function show(CustomerOrder $customerOrder, ExchangeRateService $exchangeRateService): View
    {
        $order = $customerOrder->load(['counterparty', 'creator.stoEmployee', 'novaPoshtaShipment', 'novaPoshtaShipments.items', 'items.partCatalogItem', 'items.product.sourcePartCatalogItem', 'historyEvents.user']);
        $orderUsdRate = $this->customerOrderUsdRate($order, $exchangeRateService);
        $paymentUsdRate = $exchangeRateService->currentUsdRate();

        return view('admin.customer_orders.show', [
            'order' => $order,
            'usdRate' => $orderUsdRate,
            'paymentUsdRate' => $paymentUsdRate,
            'itemUnitPriceUah' => $order->items
                ->mapWithKeys(fn (CustomerOrderItem $item): array => [
                    $item->id => $this->customerOrderItemUnitPriceUah($item, $exchangeRateService, $orderUsdRate),
                ]),
            'itemTotalPriceUah' => $order->items
                ->mapWithKeys(fn (CustomerOrderItem $item): array => [
                    $item->id => $this->customerOrderItemTotalPriceUah($item, $exchangeRateService, $orderUsdRate),
                ]),
            'orderTotalAmountUah' => $this->customerOrderTotalAmountUah($order, $exchangeRateService, $orderUsdRate),
            'orderHistoryEvents' => $this->customerOrderHistoryEvents($order),
            'itemProductUrls' => $this->customerOrderItemProductUrls($order->items),
            'itemDisplayCodes' => $this->customerOrderItemDisplayCodes($order->items),
            'itemDisplayPartNumbers' => $this->customerOrderItemDisplayPartNumbers($order->items),
            'itemDisplayNames' => $this->customerOrderItemDisplayNames($order->items),
            'itemImageUrls' => $this->customerOrderItemImageUrls($order->items),
        ]);
    }

    public function updateDeliveryMethod(Request $request, CustomerOrder $customerOrder): RedirectResponse
    {
        $this->ensureCustomerOrderCanBeEdited($customerOrder);

        if ($customerOrder->status === CustomerOrder::STATUS_SHIPPED) {
            throw ValidationException::withMessages([
                'delivery_method' => "\u{0421}\u{043F}\u{043E}\u{0441}\u{043E}\u{0431} \u{043F}\u{043E}\u{043B}\u{0443}\u{0447}\u{0435}\u{043D}\u{0438}\u{044F} \u{043D}\u{0435}\u{043B}\u{044C}\u{0437}\u{044F} \u{0438}\u{0437}\u{043C}\u{0435}\u{043D}\u{0438}\u{0442}\u{044C}: \u{0437}\u{0430}\u{043A}\u{0430}\u{0437} \u{0443}\u{0436}\u{0435} \u{043E}\u{0442}\u{043F}\u{0440}\u{0430}\u{0432}\u{043B}\u{0435}\u{043D}.",
            ]);
        }

        $validated = $request->validate([
            'delivery_method' => ['required', Rule::in(array_keys(CustomerOrder::DELIVERY_METHOD_LABELS))],
            'nova_poshta_city' => ['nullable', 'string', 'max:255'],
            'nova_poshta_warehouse' => ['nullable', 'string', 'max:255'],
            'nova_poshta_warehouse_ref' => ['nullable', 'string', 'max:255'],
        ]);

        $oldDeliveryMethod = $customerOrder->delivery_method;
        $oldStatus = $customerOrder->status;
        $novaPoshtaShipmentPayload = [];

        if ($validated['delivery_method'] === CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA) {
            $this->ensureNovaPoshtaOrderDetails(
                (string) $customerOrder->client_phone,
                $validated,
                (string) $customerOrder->client_first_name,
                (string) $customerOrder->client_last_name,
            );
            $novaPoshtaShipmentPayload = $this->novaPoshtaShipmentPayload(
                $validated,
                (string) $customerOrder->client_first_name,
                (string) $customerOrder->client_last_name,
                (string) $customerOrder->client_phone,
            );
        }

        if ($oldDeliveryMethod !== $validated['delivery_method'] || $validated['delivery_method'] === CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA) {
            $payload = [
                'delivery_method' => $validated['delivery_method'],
            ];

            if ($validated['delivery_method'] === CustomerOrder::DELIVERY_METHOD_STO) {
                $payload += [
                    'counterparty_id' => $this->stoNikolaCarsCounterparty()->id,
                    'client_phone' => null,
                    'client_first_name' => "\u{0421}\u{0422}\u{041E}",
                    'client_last_name' => null,
                ];
            }

            if (
                $validated['delivery_method'] === CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA
                && in_array($customerOrder->status, [CustomerOrder::STATUS_NEW, CustomerOrder::STATUS_PROCESSING], true)
                && (float) $customerOrder->paid_amount_uah <= 0
            ) {
                $payload['status'] = CustomerOrder::STATUS_WAITING_PREPAYMENT;
            }

            if (
                $oldDeliveryMethod === CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA
                && $validated['delivery_method'] !== CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA
                && $customerOrder->status === CustomerOrder::STATUS_WAITING_PREPAYMENT
            ) {
                $payload['status'] = CustomerOrder::STATUS_PROCESSING;
            }

            $customerOrder->forceFill($payload)->save();

            if ($validated['delivery_method'] === CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA) {
                $customerOrder->novaPoshtaShipment()->updateOrCreate(
                    ['carrier' => CustomerOrderShipment::CARRIER_NOVA_POSHTA],
                    $novaPoshtaShipmentPayload + [
                        'status' => CustomerOrderShipment::STATUS_DRAFT,
                        'declared_cost' => (float) $customerOrder->total_amount,
                        'error_message' => null,
                    ],
                );
            }

            if ($oldDeliveryMethod !== $validated['delivery_method']) {
                $this->recordCustomerOrderHistoryEvent(
                    $customerOrder,
                    'delivery_method_changed',
                    'Способ получения изменен',
                    sprintf(
                        '%s -> %s',
                        $this->customerOrderDeliveryMethodLabel($oldDeliveryMethod),
                        $this->customerOrderDeliveryMethodLabel($validated['delivery_method']),
                    ),
                    ['delivery_method' => $oldDeliveryMethod],
                    ['delivery_method' => $validated['delivery_method']],
                );
            }

            if ($oldStatus !== $customerOrder->status) {
                $this->recordCustomerOrderHistoryEvent(
                    $customerOrder,
                    'status_changed',
                    'Статус изменен',
                    sprintf(
                        '%s -> %s',
                        $this->customerOrderStatusLabel($oldStatus),
                        $this->customerOrderStatusLabel($customerOrder->status),
                    ),
                    ['status' => $oldStatus],
                    ['status' => $customerOrder->status],
                );
            }
        }

        return redirect()
            ->route('admin.customer-orders.show', $customerOrder)
            ->with('status', "\u{0421}\u{043F}\u{043E}\u{0441}\u{043E}\u{0431} \u{043F}\u{043E}\u{043B}\u{0443}\u{0447}\u{0435}\u{043D}\u{0438}\u{044F} \u{043E}\u{0431}\u{043D}\u{043E}\u{0432}\u{043B}\u{0435}\u{043D}.");
    }

    public function updateNote(Request $request, CustomerOrder $customerOrder): RedirectResponse
    {
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:10000'],
        ]);

        $oldNote = $customerOrder->note;
        $newNote = trim((string) ($validated['note'] ?? ''));
        $newNote = $newNote === '' ? null : $newNote;

        if ($oldNote !== $newNote) {
            $customerOrder->forceFill([
                'note' => $newNote,
            ])->save();

            $this->recordCustomerOrderHistoryEvent(
                $customerOrder,
                'note_updated',
                "\u{041F}\u{0440}\u{0438}\u{043C}\u{0435}\u{0447}\u{0430}\u{043D}\u{0438}\u{0435} \u{043E}\u{0431}\u{043D}\u{043E}\u{0432}\u{043B}\u{0435}\u{043D}\u{043E}",
                $newNote === null
                    ? "\u{041F}\u{0440}\u{0438}\u{043C}\u{0435}\u{0447}\u{0430}\u{043D}\u{0438}\u{0435} \u{043E}\u{0447}\u{0438}\u{0449}\u{0435}\u{043D}\u{043E}"
                    : "\u{041D}\u{043E}\u{0432}\u{043E}\u{0435} \u{043F}\u{0440}\u{0438}\u{043C}\u{0435}\u{0447}\u{0430}\u{043D}\u{0438}\u{0435}: ".Str::limit($newNote, 180),
                ['note' => $oldNote],
                ['note' => $newNote],
            );
        }

        return redirect()
            ->route('admin.customer-orders.show', $customerOrder)
            ->with('status', "\u{041F}\u{0440}\u{0438}\u{043C}\u{0435}\u{0447}\u{0430}\u{043D}\u{0438}\u{0435} \u{0441}\u{043E}\u{0445}\u{0440}\u{0430}\u{043D}\u{0435}\u{043D}\u{043E}.");
    }

    public function updateNovaPoshtaTrackingNumber(
        Request $request,
        CustomerOrder $customerOrder,
        CustomerOrderNovaPoshtaStatusSyncService $syncService,
    ): JsonResponse {
        if ($customerOrder->delivery_method !== CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA) {
            throw ValidationException::withMessages([
                'tracking_number' => "\u{0422}\u{0422}\u{041D} \u{043C}\u{043E}\u{0436}\u{043D}\u{043E} \u{043C}\u{0435}\u{043D}\u{044F}\u{0442}\u{044C} \u{0442}\u{043E}\u{043B}\u{044C}\u{043A}\u{043E} \u{0434}\u{043B}\u{044F} \u{0437}\u{0430}\u{043A}\u{0430}\u{0437}\u{043E}\u{0432} \u{041D}\u{043E}\u{0432}\u{043E}\u{0439} \u{043F}\u{043E}\u{0447}\u{0442}\u{044B}.",
            ]);
        }

        $customerOrder->loadMissing('novaPoshtaShipment');

        if (! $customerOrder->canUpdateNovaPoshtaTrackingNumber()) {
            throw ValidationException::withMessages([
                'tracking_number' => "\u{0422}\u{0422}\u{041D} \u{043D}\u{0435}\u{043B}\u{044C}\u{0437}\u{044F} \u{0438}\u{0437}\u{043C}\u{0435}\u{043D}\u{0438}\u{0442}\u{044C} \u{0434}\u{043B}\u{044F} \u{044D}\u{0442}\u{043E}\u{0433}\u{043E} \u{0441}\u{0442}\u{0430}\u{0442}\u{0443}\u{0441}\u{0430} \u{0437}\u{0430}\u{043A}\u{0430}\u{0437}\u{0430}.",
            ]);
        }

        $validated = $request->validate([
            'tracking_number' => ['required', 'string', 'max:64', 'regex:/^[0-9A-Za-z\-\s]+$/u'],
        ]);

        $shipment = $customerOrder->novaPoshtaShipment;

        if (! $shipment instanceof CustomerOrderShipment || ! $shipment->tracking_number) {
            throw ValidationException::withMessages([
                'tracking_number' => "\u{0423} \u{0437}\u{0430}\u{043A}\u{0430}\u{0437}\u{0430} \u{0435}\u{0449}\u{0451} \u{043D}\u{0435}\u{0442} \u{0422}\u{0422}\u{041D} \u{041D}\u{043E}\u{0432}\u{043E}\u{0439} \u{043F}\u{043E}\u{0447}\u{0442}\u{044B}.",
            ]);
        }

        $oldTrackingNumber = (string) $shipment->tracking_number;
        $newTrackingNumber = preg_replace('/\s+/', '', trim((string) $validated['tracking_number'])) ?: '';

        if ($newTrackingNumber === '') {
            throw ValidationException::withMessages([
                'tracking_number' => "\u{0423}\u{043A}\u{0430}\u{0436}\u{0438}\u{0442}\u{0435} \u{043D}\u{043E}\u{043C}\u{0435}\u{0440} \u{0422}\u{0422}\u{041D}.",
            ]);
        }

        if ($oldTrackingNumber !== $newTrackingNumber) {
            $shipment->forceFill([
                'tracking_number' => $newTrackingNumber,
                'np_ref' => null,
                'np_status_code' => null,
                'np_status' => null,
                'np_status_detail' => null,
                'np_status_checked_at' => null,
                'label_url' => null,
                'error_message' => null,
            ])->save();

            $this->recordCustomerOrderHistoryEvent(
                $customerOrder,
                'nova_poshta_ttn_updated',
                "\u{0422}\u{0422}\u{041D} \u{041D}\u{043E}\u{0432}\u{043E}\u{0439} \u{043F}\u{043E}\u{0447}\u{0442}\u{044B} \u{0438}\u{0437}\u{043C}\u{0435}\u{043D}\u{0435}\u{043D}\u{0430}",
                $oldTrackingNumber.' -> '.$newTrackingNumber,
                ['tracking_number' => $oldTrackingNumber],
                ['tracking_number' => $newTrackingNumber],
            );

            $syncService->syncOrder($customerOrder);
        }

        $customerOrder->refresh()->load(['novaPoshtaShipment', 'novaPoshtaShipments']);
        $shipment = $customerOrder->novaPoshtaShipment;
        $orderAfterpaymentAmount = $this->customerOrderNovaPoshtaAfterpaymentAmount($customerOrder);

        return response()->json([
            'shipment_id' => $shipment->id,
            'tracking_number' => $shipment->tracking_number,
            'tracking_url' => $shipment->tracking_url,
            'label_url' => route('admin.customer-orders.nova-poshta.label', [$customerOrder, 'shipment' => $shipment->id]),
            'np_status_code' => $shipment->np_status_code,
            'np_status' => $shipment->np_status,
            'afterpayment_amount' => (float) $shipment->afterpayment_amount,
            'afterpayment_text' => $this->formatCustomerOrderMoney((float) $shipment->afterpayment_amount, 'UAH'),
            'afterpayment_warning' => $orderAfterpaymentAmount > 0
                && round($orderAfterpaymentAmount + (float) $customerOrder->paid_amount_uah, 2) < round((float) $customerOrder->total_amount, 2),
            'order_status' => $customerOrder->status,
            'display_status' => $this->customerOrderDisplayStatusLabel($customerOrder),
            'display_status_class' => $this->customerOrderDisplayStatusClass($customerOrder),
            'message' => "\u{0422}\u{0422}\u{041D} \u{0441}\u{043E}\u{0445}\u{0440}\u{0430}\u{043D}\u{0435}\u{043D}\u{0430}.",
        ]);
    }

    public function novaPoshtaTrackingNumberSuggestions(
        Request $request,
        CustomerOrder $customerOrder,
        NovaPoshtaInternetDocumentService $novaPoshtaService,
    ): JsonResponse {
        if ($customerOrder->delivery_method !== CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA) {
            return response()->json([]);
        }

        return $this->novaPoshtaAvailableTrackingNumberSuggestions($request, $novaPoshtaService, $customerOrder);
    }

    public function novaPoshtaAvailableTrackingNumberSuggestions(
        Request $request,
        NovaPoshtaInternetDocumentService $novaPoshtaService,
        ?CustomerOrder $customerOrder = null,
    ): JsonResponse {
        try {
            $usedTrackingNumbers = CustomerOrderShipment::query()
                ->where('carrier', CustomerOrderShipment::CARRIER_NOVA_POSHTA)
                ->when($customerOrder instanceof CustomerOrder, fn (Builder $builder) => $builder
                    ->where('customer_order_id', '!=', $customerOrder->id))
                ->whereNotNull('tracking_number')
                ->pluck('tracking_number')
                ->map(fn (?string $trackingNumber): string => preg_replace('/\s+/', '', trim((string) $trackingNumber)) ?: '')
                ->filter()
                ->flip();

            $blockedStatuses = [
                "\u{043E}\u{0442}\u{0440}\u{0438}\u{043C}\u{0430}\u{043D}\u{043E}",
                "\u{0432}\u{0456}\u{0434}\u{043C}\u{043E}\u{0432}\u{0430} \u{043E}\u{0434}\u{0435}\u{0440}\u{0436}\u{0443}\u{0432}\u{0430}\u{0447}\u{0430}",
            ];

            $suggestions = collect($novaPoshtaService->documentSuggestions(
                (string) $request->query('query', ''),
                20,
            ))
                ->reject(fn (array $suggestion): bool => $usedTrackingNumbers->has((string) ($suggestion['tracking_number'] ?? '')))
                ->reject(function (array $suggestion) use ($blockedStatuses): bool {
                    $status = mb_strtolower(trim((string) ($suggestion['status'] ?? '')));

                    return $status !== ''
                        && collect($blockedStatuses)->contains(fn (string $blockedStatus): bool => str_contains($status, $blockedStatus));
                })
                ->values();

            return response()->json($suggestions);
        } catch (RuntimeException $exception) {
            Log::warning('Nova Poshta TTN suggestions request failed.', [
                'customer_order_id' => $customerOrder?->id,
                'query' => (string) $request->query('query', ''),
                'message' => $exception->getMessage(),
            ]);

            return response()->json(['message' => $exception->getMessage()], 503);
        }
    }

    public function storeNovaPoshtaTrackingNumber(
        Request $request,
        CustomerOrder $customerOrder,
        NovaPoshtaInternetDocumentService $novaPoshtaService,
    ): JsonResponse {
        if ($customerOrder->delivery_method !== CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA) {
            throw ValidationException::withMessages([
                'tracking_number' => "\u{0422}\u{0422}\u{041D} \u{043C}\u{043E}\u{0436}\u{043D}\u{043E} \u{0434}\u{043E}\u{0431}\u{0430}\u{0432}\u{043B}\u{044F}\u{0442}\u{044C} \u{0442}\u{043E}\u{043B}\u{044C}\u{043A}\u{043E} \u{0434}\u{043B}\u{044F} \u{0437}\u{0430}\u{043A}\u{0430}\u{0437}\u{043E}\u{0432} \u{041D}\u{043E}\u{0432}\u{043E}\u{0439} \u{043F}\u{043E}\u{0447}\u{0442}\u{044B}.",
            ]);
        }

        $customerOrder->loadMissing('novaPoshtaShipment');

        if (! $customerOrder->canAddNovaPoshtaTrackingNumber()) {
            throw ValidationException::withMessages([
                'tracking_number' => "\u{0422}\u{0422}\u{041D} \u{043D}\u{0435}\u{043B}\u{044C}\u{0437}\u{044F} \u{0434}\u{043E}\u{0431}\u{0430}\u{0432}\u{0438}\u{0442}\u{044C} \u{0434}\u{043B}\u{044F} \u{044D}\u{0442}\u{043E}\u{0433}\u{043E} \u{0441}\u{0442}\u{0430}\u{0442}\u{0443}\u{0441}\u{0430} \u{0437}\u{0430}\u{043A}\u{0430}\u{0437}\u{0430}.",
            ]);
        }

        $validated = $request->validate([
            'tracking_number' => ['required', 'string', 'max:64', 'regex:/^[0-9A-Za-z\-\s]+$/u'],
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['integer'],
        ]);

        $trackingNumber = preg_replace('/\s+/', '', trim((string) $validated['tracking_number'])) ?: '';

        if ($trackingNumber === '') {
            throw ValidationException::withMessages([
                'tracking_number' => "\u{0423}\u{043A}\u{0430}\u{0436}\u{0438}\u{0442}\u{0435} \u{043D}\u{043E}\u{043C}\u{0435}\u{0440} \u{0422}\u{0422}\u{041D}.",
            ]);
        }

        if ($customerOrder->novaPoshtaShipments()->where('tracking_number', $trackingNumber)->exists()) {
            throw ValidationException::withMessages([
                'tracking_number' => "\u{042D}\u{0442}\u{0430} \u{0422}\u{0422}\u{041D} \u{0443}\u{0436}\u{0435} \u{0435}\u{0441}\u{0442}\u{044C} \u{0432} \u{0437}\u{0430}\u{043A}\u{0430}\u{0437}\u{0435}.",
            ]);
        }

        $itemIds = collect($validated['item_ids'])
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        $validItemIds = $customerOrder->items()
            ->whereIn('id', $itemIds)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values();

        if ($itemIds->isEmpty() || $validItemIds->count() !== $itemIds->count()) {
            throw ValidationException::withMessages([
                'item_ids' => "\u{0412}\u{044B}\u{0431}\u{0435}\u{0440}\u{0438}\u{0442}\u{0435} \u{0437}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{0438} \u{044D}\u{0442}\u{043E}\u{0433}\u{043E} \u{0437}\u{0430}\u{043A}\u{0430}\u{0437}\u{0430}.",
            ]);
        }

        $sourceShipment = $customerOrder->novaPoshtaShipment()->first();

        $shipment = $customerOrder->shipments()->create([
            'carrier' => CustomerOrderShipment::CARRIER_NOVA_POSHTA,
            'status' => CustomerOrderShipment::STATUS_CREATED,
            'recipient_city_name' => $sourceShipment?->recipient_city_name,
            'recipient_warehouse_name' => $sourceShipment?->recipient_warehouse_name,
            'recipient_warehouse_ref' => $sourceShipment?->recipient_warehouse_ref,
            'recipient_name' => $sourceShipment?->recipient_name ?: $customerOrder->client_name,
            'recipient_phone' => $sourceShipment?->recipient_phone ?: $customerOrder->client_phone,
            'payer_type' => $sourceShipment?->payer_type ?: 'Recipient',
            'payment_method' => $sourceShipment?->payment_method ?: 'Cash',
            'seats_amount' => $sourceShipment?->seats_amount ?: 1,
            'weight' => $sourceShipment?->weight ?: 1,
            'length_cm' => $sourceShipment?->length_cm,
            'width_cm' => $sourceShipment?->width_cm,
            'height_cm' => $sourceShipment?->height_cm,
            'declared_cost' => 0,
            'afterpayment_amount' => 0,
            'cargo_description' => $sourceShipment?->cargo_description,
            'tracking_number' => $trackingNumber,
        ]);

        try {
            $status = $novaPoshtaService->trackingStatusNumber($trackingNumber, $shipment->recipient_phone);

            $shipment->forceFill([
                'np_status_code' => $status['status_code'] !== '' ? $status['status_code'] : null,
                'np_status' => $status['status'] !== '' ? $status['status'] : null,
                'np_status_detail' => ($status['status_detail'] ?? '') !== '' ? $status['status_detail'] : null,
                'afterpayment_amount' => $status['afterpayment_amount'] ?? 0,
                'np_status_checked_at' => now(),
                'raw_response' => $status['raw_response'],
                'error_message' => null,
            ])->save();
        } catch (RuntimeException $exception) {
            $shipment->forceFill([
                'error_message' => $exception->getMessage(),
            ])->save();
        }

        $this->syncCustomerOrderShipmentItems($customerOrder, $shipment, $validItemIds);

        $this->recordCustomerOrderHistoryEvent(
            $customerOrder,
            'nova_poshta_ttn_added',
            "\u{0414}\u{043E}\u{0431}\u{0430}\u{0432}\u{043B}\u{0435}\u{043D}\u{0430} \u{0422}\u{0422}\u{041D} \u{041D}\u{043E}\u{0432}\u{043E}\u{0439} \u{043F}\u{043E}\u{0447}\u{0442}\u{044B}",
            $trackingNumber,
            [],
            [
                'tracking_number' => $trackingNumber,
                'item_ids' => $validItemIds->all(),
            ],
        );

        return response()->json([
            'message' => "\u{0422}\u{0422}\u{041D} \u{0434}\u{043E}\u{0431}\u{0430}\u{0432}\u{043B}\u{0435}\u{043D}\u{0430}.",
            'reload' => true,
        ]);
    }

    public function printNovaPoshtaLabel(
        Request $request,
        CustomerOrder $customerOrder,
        NovaPoshtaInternetDocumentService $novaPoshtaService,
    ): Response|RedirectResponse {
        $shipmentId = $request->integer('shipment');
        $shipmentQuery = $customerOrder->novaPoshtaShipments()
            ->whereNotNull('tracking_number')
            ->where('tracking_number', '!=', '');

        $shipment = $shipmentId > 0
            ? (clone $shipmentQuery)->whereKey($shipmentId)->first()
            : $shipmentQuery->first();

        if (! $shipment instanceof CustomerOrderShipment || ! $shipment->tracking_number) {
            abort(404);
        }

        try {
            $pdf = $novaPoshtaService->labelPdf($shipment);

            return response($pdf, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="nova-poshta-'.$shipment->tracking_number.'.pdf"',
            ]);
        } catch (RuntimeException $pdfException) {
            try {
                $html = $novaPoshtaService->labelHtml($shipment);

                return response($html, 200, [
                    'Content-Type' => 'text/html; charset=UTF-8',
                    'Content-Disposition' => 'inline; filename="nova-poshta-'.$shipment->tracking_number.'.html"',
                ]);
            } catch (RuntimeException) {
                return redirect()->away($novaPoshtaService->cabinetPrintUrl($shipment));
            }
        }
    }

    public function syncNovaPoshtaStatus(
        CustomerOrder $customerOrder,
        CustomerOrderNovaPoshtaStatusSyncService $syncService,
    ): RedirectResponse {
        try {
            $result = $syncService->syncOrder($customerOrder);
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('admin.customer-orders.show', $customerOrder)
                ->withErrors(['nova_poshta' => $exception->getMessage()]);
        }

        if (! ($result['checked'] ?? false)) {
            return redirect()
                ->route('admin.customer-orders.show', $customerOrder)
                ->withErrors(['nova_poshta' => $result['message'] ?? 'Nova Poshta status was not checked.']);
        }

        $status = trim((string) ($result['status'] ?? '')) ?: '-';
        $message = ($result['shipped'] ?? false)
            ? "\u{0421}\u{0442}\u{0430}\u{0442}\u{0443}\u{0441} \u{041D}\u{043E}\u{0432}\u{043E}\u{0439} \u{041F}\u{043E}\u{0447}\u{0442}\u{044B} \u{043E}\u{0431}\u{043D}\u{043E}\u{0432}\u{043B}\u{0435}\u{043D}. \u{0417}\u{0430}\u{043A}\u{0430}\u{0437} \u{043E}\u{0442}\u{043C}\u{0435}\u{0447}\u{0435}\u{043D} \u{043A}\u{0430}\u{043A} \u{043E}\u{0442}\u{043F}\u{0440}\u{0430}\u{0432}\u{043B}\u{0435}\u{043D}."
            : "\u{0421}\u{0442}\u{0430}\u{0442}\u{0443}\u{0441} \u{041D}\u{043E}\u{0432}\u{043E}\u{0439} \u{041F}\u{043E}\u{0447}\u{0442}\u{044B}: ".$status;

        return redirect()
            ->route('admin.customer-orders.show', $customerOrder)
            ->with('status', $message);
    }

    public function updateStatus(
        Request $request,
        CustomerOrder $customerOrder,
        ExchangeRateService $exchangeRateService,
        NovaPoshtaInternetDocumentService $novaPoshtaService,
    ): RedirectResponse {
        $validated = $request->validate([
            'status' => ['required', Rule::in([
                CustomerOrder::STATUS_ASSEMBLED,
                CustomerOrder::STATUS_SHIPPED,
                CustomerOrder::STATUS_COMPLETED,
                CustomerOrder::STATUS_CANCELLED,
            ])],
        ]);

        if ($validated['status'] === CustomerOrder::STATUS_ASSEMBLED && ! $customerOrder->canBeMarkedAsAssembled()) {
            throw ValidationException::withMessages([
                'status' => "\u{0421}\u{0442}\u{0430}\u{0442}\u{0443}\u{0441} \u{0437}\u{0430}\u{043A}\u{0430}\u{0437}\u{0430} \u{043C}\u{043E}\u{0436}\u{043D}\u{043E} \u{0438}\u{0437}\u{043C}\u{0435}\u{043D}\u{0438}\u{0442}\u{044C} \u{043D}\u{0430} \"\u{0421}\u{043E}\u{0431}\u{0440}\u{0430}\u{043D}\" \u{0442}\u{043E}\u{043B}\u{044C}\u{043A}\u{043E} \u{0438}\u{0437} \"\u{041E}\u{0431}\u{0440}\u{0430}\u{0431}\u{0430}\u{0442}\u{044B}\u{0432}\u{0430}\u{0435}\u{0442}\u{0441}\u{044F}\".",
            ]);
        }

        if (
            $validated['status'] === CustomerOrder::STATUS_SHIPPED
            && $customerOrder->delivery_method === CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA
        ) {
            throw ValidationException::withMessages([
                'status' => "\u{0414}\u{043B}\u{044F} \u{0437}\u{0430}\u{043A}\u{0430}\u{0437}\u{043E}\u{0432} \u{041D}\u{043E}\u{0432}\u{043E}\u{0439} \u{043F}\u{043E}\u{0447}\u{0442}\u{044B} \u{0441}\u{0442}\u{0430}\u{0442}\u{0443}\u{0441} \"\u{041E}\u{0442}\u{043F}\u{0440}\u{0430}\u{0432}\u{043B}\u{0435}\u{043D}\" \u{043E}\u{0431}\u{043D}\u{043E}\u{0432}\u{043B}\u{044F}\u{0435}\u{0442}\u{0441}\u{044F} \u{043F}\u{043E} \u{0422}\u{0422}\u{041D}.",
            ]);
        }

        if ($validated['status'] === CustomerOrder::STATUS_SHIPPED && ! $customerOrder->canBeMarkedAsShipped()) {
            throw ValidationException::withMessages([
                'status' => "\u{0421}\u{0442}\u{0430}\u{0442}\u{0443}\u{0441} \"\u{041E}\u{0442}\u{043F}\u{0440}\u{0430}\u{0432}\u{043B}\u{0435}\u{043D}\" \u{043C}\u{043E}\u{0436}\u{043D}\u{043E} \u{043F}\u{043E}\u{0441}\u{0442}\u{0430}\u{0432}\u{0438}\u{0442}\u{044C} \u{0442}\u{043E}\u{043B}\u{044C}\u{043A}\u{043E} \u{0441}\u{043E}\u{0431}\u{0440}\u{0430}\u{043D}\u{043D}\u{043E}\u{043C}\u{0443} \u{0437}\u{0430}\u{043A}\u{0430}\u{0437}\u{0443} \u{041D}\u{043E}\u{0432}\u{043E}\u{0439} \u{043F}\u{043E}\u{0447}\u{0442}\u{044B}.",
            ]);
        }

        if (
            $validated['status'] === CustomerOrder::STATUS_COMPLETED
            && (
                ! $customerOrder->canBeMarkedAsCompleted()
                || (
                    $customerOrder->delivery_method !== CustomerOrder::DELIVERY_METHOD_STO
                    && ! $this->customerOrderIsFullyPaid($customerOrder, $exchangeRateService)
                )
            )
        ) {
            throw ValidationException::withMessages([
                'status' => "\u{0412}\u{044B}\u{0434}\u{0430}\u{0442}\u{044C} \u{043C}\u{043E}\u{0436}\u{043D}\u{043E} \u{0442}\u{043E}\u{043B}\u{044C}\u{043A}\u{043E} \u{043F}\u{043E}\u{043B}\u{043D}\u{043E}\u{0441}\u{0442}\u{044C}\u{044E} \u{043E}\u{043F}\u{043B}\u{0430}\u{0447}\u{0435}\u{043D}\u{043D}\u{044B}\u{0439} \u{0437}\u{0430}\u{043A}\u{0430}\u{0437} \u{0441} \u{0441}\u{0430}\u{043C}\u{043E}\u{0432}\u{044B}\u{0432}\u{043E}\u{0437}\u{043E}\u{043C} \u{0438}\u{043B}\u{0438} \u{043E}\u{0442}\u{043F}\u{0440}\u{0430}\u{0432}\u{043B}\u{0435}\u{043D}\u{043D}\u{044B}\u{0439} \u{0437}\u{0430}\u{043A}\u{0430}\u{0437} \u{041D}\u{043E}\u{0432}\u{043E}\u{0439} \u{043F}\u{043E}\u{0447}\u{0442}\u{044B}.",
            ]);
        }

        if ($validated['status'] === CustomerOrder::STATUS_CANCELLED && $this->customerOrderIsFullyPaid($customerOrder, $exchangeRateService)) {
            throw ValidationException::withMessages([
                'status' => 'Заказ с полной предоплатой или полной оплатой нельзя отменить.',
            ]);
        }

        if ($validated['status'] === CustomerOrder::STATUS_CANCELLED && ! $customerOrder->canBeCancelled()) {
            throw ValidationException::withMessages([
                'status' => $customerOrder->status === CustomerOrder::STATUS_CANCELLED
                    ? 'Заказ уже отменен.'
                    : 'Заказ в этом статусе нельзя отменить.',
            ]);
        }

        $novaPoshtaShipment = null;
        $novaPoshtaDocument = null;
        $deletedNovaPoshtaDocument = null;
        $deletedNovaPoshtaTrackingNumber = null;

        if (
            $validated['status'] === CustomerOrder::STATUS_ASSEMBLED
            && $customerOrder->delivery_method === CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA
        ) {
            $customerOrder->loadMissing(['items', 'novaPoshtaShipment']);
            $novaPoshtaShipment = $customerOrder->novaPoshtaShipment;

            if (! $novaPoshtaShipment instanceof CustomerOrderShipment) {
                throw ValidationException::withMessages([
                    'nova_poshta' => "\u{0414}\u{043B}\u{044F} \u{0437}\u{0430}\u{043A}\u{0430}\u{0437}\u{0430} \u{041D}\u{043E}\u{0432}\u{043E}\u{0439} \u{043F}\u{043E}\u{0447}\u{0442}\u{043E}\u{0439} \u{043D}\u{0443}\u{0436}\u{043D}\u{044B} \u{0434}\u{0430}\u{043D}\u{043D}\u{044B}\u{0435} \u{043E}\u{0442}\u{043F}\u{0440}\u{0430}\u{0432}\u{043A}\u{0438}.",
                ]);
            }

            if (! $novaPoshtaShipment->tracking_number) {
                $package = $this->validateNovaPoshtaPackage($request);
                $afterpaymentAmount = $this->novaPoshtaAfterpaymentAmount($customerOrder);

                $novaPoshtaShipment->forceFill([
                    'seats_amount' => $package['nova_poshta_seats_amount'],
                    'weight' => $package['nova_poshta_weight'],
                    'length_cm' => $package['nova_poshta_length_cm'],
                    'width_cm' => $package['nova_poshta_width_cm'],
                    'height_cm' => $package['nova_poshta_height_cm'],
                    'declared_cost' => max(1, $afterpaymentAmount ?: (float) $customerOrder->total_amount),
                    'afterpayment_amount' => $afterpaymentAmount,
                ])->save();

                try {
                    $novaPoshtaDocument = $novaPoshtaService->create($customerOrder, $novaPoshtaShipment);
                } catch (RuntimeException $exception) {
                    $novaPoshtaShipment->forceFill([
                        'status' => CustomerOrderShipment::STATUS_FAILED,
                        'error_message' => $exception->getMessage(),
                    ])->save();

                    throw ValidationException::withMessages([
                        'nova_poshta' => $exception->getMessage(),
                    ]);
                }
            }
        }

        if (
            $validated['status'] === CustomerOrder::STATUS_CANCELLED
            && $customerOrder->delivery_method === CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA
        ) {
            $customerOrder->loadMissing('novaPoshtaShipment');
            $novaPoshtaShipment = $customerOrder->novaPoshtaShipment;

            if (
                $novaPoshtaShipment instanceof CustomerOrderShipment
                && ($novaPoshtaShipment->np_ref || $novaPoshtaShipment->tracking_number)
            ) {
                $deletedNovaPoshtaTrackingNumber = $novaPoshtaShipment->tracking_number;

                try {
                    $deletedNovaPoshtaDocument = $novaPoshtaService->delete($novaPoshtaShipment);
                } catch (RuntimeException $exception) {
                    throw ValidationException::withMessages([
                        'nova_poshta' => $exception->getMessage(),
                    ]);
                }
            }
        }

        DB::transaction(function () use (
            $customerOrder,
            $validated,
            $novaPoshtaShipment,
            $novaPoshtaDocument,
            $deletedNovaPoshtaDocument,
            $deletedNovaPoshtaTrackingNumber,
            $novaPoshtaService,
        ): void {
            [$catalogItemIds, $productIds] = $this->reservedInventoryIds($customerOrder);
            $oldStatus = $customerOrder->status;

            if ($validated['status'] === CustomerOrder::STATUS_CANCELLED) {
                if ($customerOrder->isIssuedToClient()) {
                    app(CustomerOrderIssuedSaleService::class)->cancelOrder($customerOrder->fresh(['items.partCatalogItem', 'items.product']));
                }

            }

            $customerOrder->forceFill([
                'status' => $validated['status'],
            ])->save();

            if ($novaPoshtaShipment instanceof CustomerOrderShipment && $novaPoshtaDocument !== null) {
                $novaPoshtaShipment->forceFill([
                    'status' => CustomerOrderShipment::STATUS_CREATED,
                    'np_ref' => $novaPoshtaDocument['ref'] ?? null,
                    'tracking_number' => $novaPoshtaDocument['tracking_number'] ?? null,
                    'label_url' => $novaPoshtaDocument['label_url'] ?? null,
                    'declared_cost' => $novaPoshtaService->declaredCost($customerOrder, $novaPoshtaShipment),
                    'raw_response' => $novaPoshtaDocument['raw_response'] ?? null,
                    'error_message' => null,
                ])->save();
            }

            if ($novaPoshtaShipment instanceof CustomerOrderShipment && $deletedNovaPoshtaDocument !== null) {
                $novaPoshtaShipment->forceFill([
                    'status' => CustomerOrderShipment::STATUS_CANCELLED,
                    'label_url' => null,
                    'raw_response' => $deletedNovaPoshtaDocument,
                    'error_message' => null,
                ])->save();
            }

            PartCatalogItem::query()
                ->whereIn('id', $catalogItemIds)
                ->get()
                ->each(fn (PartCatalogItem $catalogItem) => $this->refreshCatalogItemReservationProjection($catalogItem));
            Product::query()
                ->whereIn('id', $productIds)
                ->get()
                ->each(fn (Product $product) => $this->refreshProductReservationProjection($product));

            if ($customerOrder->isIssuedToClient()) {
                app(CustomerOrderIssuedSaleService::class)->syncOrder($customerOrder->fresh(['items.partCatalogItem', 'items.product']));
            }

            if ($oldStatus !== $validated['status']) {
                $this->recordCustomerOrderHistoryEvent(
                    $customerOrder,
                    'status_changed',
                    'Статус изменен',
                    sprintf(
                        '%s -> %s',
                        $this->customerOrderStatusLabel($oldStatus),
                        $this->customerOrderStatusLabel($validated['status']),
                    ),
                    ['status' => $oldStatus],
                    ['status' => $validated['status']],
                );
            }

            if ($novaPoshtaShipment instanceof CustomerOrderShipment && $novaPoshtaDocument !== null) {
                $this->recordCustomerOrderHistoryEvent(
                    $customerOrder,
                    'nova_poshta_ttn_created',
                    "\u{0422}\u{0422}\u{041D} \u{041D}\u{043E}\u{0432}\u{043E}\u{0439} \u{043F}\u{043E}\u{0447}\u{0442}\u{044B} \u{0441}\u{043E}\u{0437}\u{0434}\u{0430}\u{043D}\u{0430}",
                    "\u{041D}\u{043E}\u{043C}\u{0435}\u{0440}: ".($novaPoshtaDocument['tracking_number'] ?? '-'),
                    null,
                    [
                        'tracking_number' => $novaPoshtaDocument['tracking_number'] ?? null,
                    ],
                );
            }

            if ($novaPoshtaShipment instanceof CustomerOrderShipment && $deletedNovaPoshtaDocument !== null) {
                $this->recordCustomerOrderHistoryEvent(
                    $customerOrder,
                    'nova_poshta_ttn_deleted',
                    "\u{0422}\u{0422}\u{041D} \u{041D}\u{043E}\u{0432}\u{043E}\u{0439} \u{043F}\u{043E}\u{0447}\u{0442}\u{044B} \u{0443}\u{0434}\u{0430}\u{043B}\u{0435}\u{043D}\u{0430}",
                    "\u{041D}\u{043E}\u{043C}\u{0435}\u{0440}: ".($deletedNovaPoshtaTrackingNumber ?: '-'),
                    [
                        'tracking_number' => $deletedNovaPoshtaTrackingNumber,
                    ],
                    [
                        'status' => CustomerOrderShipment::STATUS_CANCELLED,
                    ],
                );
            }

            PartCatalogItem::query()
                ->whereIn('id', $catalogItemIds)
                ->get()
                ->each(fn (PartCatalogItem $catalogItem) => $this->refreshCatalogItemReservationProjection($catalogItem));
            Product::query()
                ->whereIn('id', $productIds)
                ->get()
                ->each(fn (Product $product) => $this->refreshProductReservationProjection($product));
        });

        $message = $validated['status'] === CustomerOrder::STATUS_CANCELLED
            ? 'Заказ отменен. Товары сняты с резерва.'
            : "\u{0417}\u{0430}\u{043A}\u{0430}\u{0437} \u{043E}\u{0442}\u{043C}\u{0435}\u{0447}\u{0435}\u{043D} \u{043A}\u{0430}\u{043A} \u{0441}\u{043E}\u{0431}\u{0440}\u{0430}\u{043D}\u{043D}\u{044B}\u{0439}.";

        if ($validated['status'] === CustomerOrder::STATUS_SHIPPED) {
            $message = "\u{0417}\u{0430}\u{043A}\u{0430}\u{0437} \u{043E}\u{0442}\u{043C}\u{0435}\u{0447}\u{0435}\u{043D} \u{043A}\u{0430}\u{043A} \u{043E}\u{0442}\u{043F}\u{0440}\u{0430}\u{0432}\u{043B}\u{0435}\u{043D}\u{043D}\u{044B}\u{0439}.";
        }

        if ($validated['status'] === CustomerOrder::STATUS_COMPLETED) {
            $message = "\u{0417}\u{0430}\u{043A}\u{0430}\u{0437} \u{0432}\u{044B}\u{0434}\u{0430}\u{043D} \u{043A}\u{043B}\u{0438}\u{0435}\u{043D}\u{0442}\u{0443} \u{0438} \u{0437}\u{0430}\u{0432}\u{0435}\u{0440}\u{0448}\u{0435}\u{043D}.";
        }

        if (
            $validated['status'] === CustomerOrder::STATUS_ASSEMBLED
            && $customerOrder->delivery_method === CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA
        ) {
            $trackingNumber = $novaPoshtaDocument['tracking_number']
                ?? $customerOrder->fresh('novaPoshtaShipment')?->novaPoshtaShipment?->tracking_number;
            $message = $trackingNumber
                ? "\u{0417}\u{0430}\u{043A}\u{0430}\u{0437} \u{0441}\u{043E}\u{0431}\u{0440}\u{0430}\u{043D}. \u{0422}\u{0422}\u{041D} \u{041D}\u{043E}\u{0432}\u{043E}\u{0439} \u{043F}\u{043E}\u{0447}\u{0442}\u{044B}: ".$trackingNumber.'.'
                : $message;
        }

        return redirect()
            ->back()
            ->with('status', $message);
    }

    public function returnToStock(Request $request, CustomerOrder $customerOrder): RedirectResponse
    {
        $customerOrder->loadMissing([
            'novaPoshtaShipment',
            'historyEvents',
            'items.partCatalogItem',
            'items.product.stockItems.location.warehouse',
            'items.product.sourcePartCatalogItem',
        ]);

        if (! $customerOrder->canBeReturnedToStock()) {
            throw ValidationException::withMessages([
                'return_to_stock' => "\u{0412}\u{0435}\u{0440}\u{043D}\u{0443}\u{0442}\u{044C} \u{043D}\u{0430} \u{0441}\u{043A}\u{043B}\u{0430}\u{0434} \u{043C}\u{043E}\u{0436}\u{043D}\u{043E} \u{0442}\u{043E}\u{043B}\u{044C}\u{043A}\u{043E} \u{043E}\u{0442}\u{043A}\u{0430}\u{0437} \u{041D}\u{043E}\u{0432}\u{043E}\u{0439} \u{043F}\u{043E}\u{0447}\u{0442}\u{044B} \u{0441} \u{043F}\u{043E}\u{043B}\u{0443}\u{0447}\u{0435}\u{043D}\u{043D}\u{044B}\u{043C} \u{0432}\u{043E}\u{0437}\u{0432}\u{0440}\u{0430}\u{0442}\u{043E}\u{043C}.",
            ]);
        }

        $validated = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'floor' => ['nullable', 'string', 'max:50'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
        ]);

        $warehouse = Warehouse::query()
            ->whereKey($validated['warehouse_id'])
            ->where('is_active', true)
            ->where('type', '!=', Warehouse::TYPE_DONOR)
            ->first();
        $location = Location::query()
            ->whereKey($validated['location_id'])
            ->where('warehouse_id', $validated['warehouse_id'])
            ->where('is_active', true)
            ->whereHas('warehouse', fn (Builder $query) => $query
                ->where('type', '!=', Warehouse::TYPE_DONOR))
            ->first();

        if (! $warehouse instanceof Warehouse) {
            throw ValidationException::withMessages([
                'warehouse_id' => "\u{0412}\u{044B}\u{0431}\u{0435}\u{0440}\u{0438}\u{0442}\u{0435} \u{0441}\u{043A}\u{043B}\u{0430}\u{0434}.",
            ]);
        }

        if (! $location instanceof Location) {
            throw ValidationException::withMessages([
                'location_id' => "\u{0412}\u{044B}\u{0431}\u{0435}\u{0440}\u{0438}\u{0442}\u{0435} \u{044F}\u{0447}\u{0435}\u{0439}\u{043A}\u{0443}.",
            ]);
        }

        $floor = is_string($validated['floor'] ?? null) && $validated['floor'] !== ''
            ? $validated['floor']
            : 'floor_1';
        if ($warehouse->hasMultipleFloors() && $this->normalizedReturnLocationFloor($location) !== $floor) {
            throw ValidationException::withMessages([
                'location_id' => "\u{042F}\u{0447}\u{0435}\u{0439}\u{043A}\u{0430} \u{043D}\u{0435} \u{043E}\u{0442}\u{043D}\u{043E}\u{0441}\u{0438}\u{0442}\u{0441}\u{044F} \u{043A} \u{0432}\u{044B}\u{0431}\u{0440}\u{0430}\u{043D}\u{043D}\u{043E}\u{043C}\u{0443} \u{044D}\u{0442}\u{0430}\u{0436}\u{0443}.",
            ]);
        }

        DB::transaction(function () use ($customerOrder, $location, $warehouse): void {
            [$catalogItemIds, $productIds] = $this->reservedInventoryIds($customerOrder);

            $this->recordCustomerOrderHistoryEvent(
                $customerOrder,
                'returned_to_stock',
                "\u{0412}\u{043E}\u{0437}\u{0432}\u{0440}\u{0430}\u{0442} \u{043F}\u{0440}\u{0438}\u{043D}\u{044F}\u{0442} \u{043D}\u{0430} \u{0441}\u{043A}\u{043B}\u{0430}\u{0434}",
                trim(($warehouse->name ?: '').' / '.$this->returnLocationDisplayCode($location)),
                null,
                [
                    'warehouse_id' => $warehouse->id,
                    'warehouse_name' => $warehouse->name,
                    'location_id' => $location->id,
                    'location_code' => $this->returnLocationDisplayCode($location),
                ],
            );

            PartCatalogItem::query()
                ->whereIn('id', $catalogItemIds)
                ->get()
                ->each(fn (PartCatalogItem $catalogItem) => $this->refreshCatalogItemReservationProjection($catalogItem));
            Product::query()
                ->whereIn('id', $productIds)
                ->with(['stockItems', 'sourcePartCatalogItem'])
                ->get()
                ->each(function (Product $product) use ($customerOrder, $location): void {
                    $this->returnProductToStockLocation($product, $customerOrder, $location);
                    $this->refreshProductReservationProjection($product->refresh());
                });
        });

        return redirect()
            ->back()
            ->with('status', "\u{0412}\u{043E}\u{0437}\u{0432}\u{0440}\u{0430}\u{0442} \u{043F}\u{0440}\u{0438}\u{043D}\u{044F}\u{0442} \u{043D}\u{0430} \u{0441}\u{043A}\u{043B}\u{0430}\u{0434}.");
    }

    public function recreate(CustomerOrder $customerOrder, ExchangeRateService $exchangeRateService): RedirectResponse
    {
        $customerOrder->load(['items.partCatalogItem', 'items.product.sourcePartCatalogItem', 'items.product.stockItems']);

        if ($customerOrder->status !== CustomerOrder::STATUS_CANCELLED) {
            throw ValidationException::withMessages([
                'order' => "\u{041F}\u{0435}\u{0440}\u{0435}\u{0441}\u{043E}\u{0437}\u{0434}\u{0430}\u{0442}\u{044C} \u{043C}\u{043E}\u{0436}\u{043D}\u{043E} \u{0442}\u{043E}\u{043B}\u{044C}\u{043A}\u{043E} \u{043E}\u{0442}\u{043C}\u{0435}\u{043D}\u{0435}\u{043D}\u{043D}\u{044B}\u{0439} \u{0437}\u{0430}\u{043A}\u{0430}\u{0437}.",
            ]);
        }

        if ($customerOrder->items->isEmpty()) {
            throw ValidationException::withMessages([
                'order' => "\u{0412} \u{0437}\u{0430}\u{043A}\u{0430}\u{0437}\u{0435} \u{043D}\u{0435}\u{0442} \u{0437}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{0435}\u{0439} \u{0434}\u{043B}\u{044F} \u{043F}\u{0435}\u{0440}\u{0435}\u{0441}\u{043E}\u{0437}\u{0434}\u{0430}\u{043D}\u{0438}\u{044F}.",
            ]);
        }

        $usdRate = $exchangeRateService->usdRateForDate();
        $usdExchangeRate = (float) ($usdRate['rate'] ?? 0);

        $newOrder = DB::transaction(function () use ($customerOrder, $exchangeRateService, $usdRate, $usdExchangeRate): CustomerOrder {
            $order = CustomerOrder::query()->create([
                'number' => $this->nextNumber(),
                'status' => CustomerOrder::STATUS_PROCESSING,
                'counterparty_id' => $customerOrder->counterparty_id,
                'client_phone' => $customerOrder->client_phone,
                'client_first_name' => $customerOrder->client_first_name,
                'client_last_name' => $customerOrder->client_last_name,
                'delivery_method' => $customerOrder->delivery_method,
                'note' => $customerOrder->note,
                'currency' => 'UAH',
                'total_amount' => 0,
            ]);

            $this->recordCustomerOrderHistoryEvent(
                $order,
                'order_recreated',
                "\u{0417}\u{0430}\u{043A}\u{0430}\u{0437} \u{043F}\u{0435}\u{0440}\u{0435}\u{0441}\u{043E}\u{0437}\u{0434}\u{0430}\u{043D}",
                sprintf(
                    "\u{0421}\u{043E}\u{0437}\u{0434}\u{0430}\u{043D} \u{0438}\u{0437} \u{043E}\u{0442}\u{043C}\u{0435}\u{043D}\u{0435}\u{043D}\u{043D}\u{043E}\u{0433}\u{043E} \u{0437}\u{0430}\u{043A}\u{0430}\u{0437}\u{0430} %s \u{0441} \u{043F}\u{0435}\u{0440}\u{0435}\u{0441}\u{0447}\u{0435}\u{0442}\u{043E}\u{043C} \u{043F}\u{043E} \u{0442}\u{0435}\u{043A}\u{0443}\u{0449}\u{0435}\u{043C}\u{0443} \u{043A}\u{0443}\u{0440}\u{0441}\u{0443} USD.",
                    $customerOrder->number,
                ),
                null,
                [
                    'source_order_id' => $customerOrder->id,
                    'source_order_number' => $customerOrder->number,
                    'usd_exchange_rate' => $usdExchangeRate > 0 ? $usdExchangeRate : null,
                ],
            );

            $total = 0.0;
            $catalogItems = collect();
            $products = collect();

            foreach ($customerOrder->items as $sourceItem) {
                [$product, $catalogItem] = $this->orderableInventoryForRecreatedItem($sourceItem);
                $quantity = round((float) $sourceItem->quantity, 3);

                $this->ensureRecreatedItemQuantityAvailable($sourceItem, $product, $catalogItem, $quantity);

                [$unitPrice, $totalPrice, $usdHint, $usdLineTotal] = $this->recreatedCustomerOrderItemPricing(
                    $sourceItem,
                    $product,
                    $catalogItem,
                    $exchangeRateService,
                    $usdRate,
                );

                $orderItem = $order->items()->create([
                    'part_catalog_item_id' => $catalogItem?->id,
                    'product_id' => $product?->id,
                    'name' => $this->customerOrderItemName($product, $catalogItem, $sourceItem->name),
                    'part_number' => $sourceItem->part_number ?: $product?->external_sku ?: $catalogItem?->part_number,
                    'code' => $sourceItem->code ?: $product?->sku ?: data_get($catalogItem?->raw_attributes, 'code'),
                    'donor_vin' => $sourceItem->donor_vin ?: $product?->donorCar?->vin ?: data_get($catalogItem?->raw_attributes, 'donor_vin'),
                    'category' => $sourceItem->category ?: $this->productCategoryDisplay($product) ?: data_get($catalogItem?->raw_attributes, 'category_display') ?: $catalogItem?->compatibility_text,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_price' => $totalPrice,
                    'currency' => 'UAH',
                    'unit_price_usd_hint' => $usdHint,
                    'total_price_usd_hint' => $usdLineTotal,
                    'usd_exchange_rate' => $usdHint !== null && $usdExchangeRate > 0 ? $usdExchangeRate : null,
                    'source_url' => $product instanceof Product ? route('admin.products.show', $product) : ($sourceItem->source_url ?: ($catalogItem ? $this->nikolaCarsProductUrl($catalogItem) : null)),
                    'image_url' => $sourceItem->image_url ?: $this->productImageUrl($product) ?: data_get($catalogItem?->raw_attributes, 'image_urls.0'),
                ]);

                $this->recordCustomerOrderHistoryEvent(
                    $order,
                    'item_added',
                    'Запчасть добавлена',
                    $this->customerOrderItemHistoryDescription($orderItem),
                    null,
                    $this->customerOrderItemHistoryValues($orderItem),
                    $orderItem,
                );

                $total += $totalPrice;

                if ($catalogItem instanceof PartCatalogItem) {
                    $catalogItems->push($catalogItem);
                }
                if ($product instanceof Product) {
                    $products->push($product);
                }
            }

            $order->forceFill(['total_amount' => round($total, 2)])->save();

            $catalogItems->unique('id')->each(fn (PartCatalogItem $catalogItem) => $this->refreshCatalogItemReservationProjection($catalogItem));
            $products->unique('id')->each(fn (Product $product) => $this->refreshProductReservationProjection($product));

            return $order;
        });

        return redirect()
            ->route('admin.customer-orders.show', $newOrder)
            ->with('status', "\u{0417}\u{0430}\u{043A}\u{0430}\u{0437} \u{043F}\u{0435}\u{0440}\u{0435}\u{0441}\u{043E}\u{0437}\u{0434}\u{0430}\u{043D}. \u{0417}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{0438} \u{043F}\u{043E}\u{0441}\u{0442}\u{0430}\u{0432}\u{043B}\u{0435}\u{043D}\u{044B} \u{0432} \u{0440}\u{0435}\u{0437}\u{0435}\u{0440}\u{0432}.");
    }

    public function confirmPayment(Request $request, CustomerOrder $customerOrder, ExchangeRateService $exchangeRateService): RedirectResponse
    {
        abort_unless($customerOrder->canConfirmPayment(), 404);

        return $this->storeCustomerOrderPayment($request, $customerOrder, $exchangeRateService, true);
    }

    public function storePrepayment(Request $request, CustomerOrder $customerOrder, ExchangeRateService $exchangeRateService): RedirectResponse
    {
        abort_unless($customerOrder->canAcceptPrepayment(), 404);

        return $this->storeCustomerOrderPayment($request, $customerOrder, $exchangeRateService, false);
    }

    public function destroyPrepayment(CustomerOrder $customerOrder): RedirectResponse
    {
        abort_unless($customerOrder->canAcceptPrepayment() && (float) $customerOrder->paid_amount_uah > 0, 404);

        DB::transaction(function () use ($customerOrder): void {
            [$catalogItemIds, $productIds] = $this->reservedInventoryIds($customerOrder);
            $oldStatus = $customerOrder->status;
            $oldPayment = [
                'payment_type' => $customerOrder->payment_type,
                'payment_received_amount' => (float) $customerOrder->payment_received_amount,
                'payment_received_amount_uah' => (float) $customerOrder->payment_received_amount_uah,
                'paid_cash_uah' => (float) $customerOrder->paid_cash_uah,
                'paid_cash_usd' => (float) $customerOrder->paid_cash_usd,
                'paid_bank_tov_uah' => (float) $customerOrder->paid_bank_tov_uah,
                'paid_bank_fop_uah' => (float) $customerOrder->paid_bank_fop_uah,
                'paid_prom_uah' => (float) $customerOrder->paid_prom_uah,
                'paid_amount_uah' => (float) $customerOrder->paid_amount_uah,
            ];
            $nextStatus = $customerOrder->status;

            if (
                $customerOrder->delivery_method === CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA
                && $customerOrder->status === CustomerOrder::STATUS_PROCESSING
            ) {
                $nextStatus = CustomerOrder::STATUS_WAITING_PREPAYMENT;
            }

            $customerOrder->forceFill([
                'status' => $nextStatus,
                'payment_type' => null,
                'payment_received_amount' => 0,
                'payment_received_amount_uah' => 0,
                'paid_cash_uah' => 0,
                'paid_cash_usd' => 0,
                'paid_bank_tov_uah' => 0,
                'paid_bank_fop_uah' => 0,
                'paid_prom_uah' => 0,
                'paid_amount_uah' => 0,
                'payment_confirmed_at' => null,
            ])->save();

            $this->recordCustomerOrderHistoryEvent(
                $customerOrder,
                'prepayment_deleted',
                "\u{041F}\u{0440}\u{0435}\u{0434}\u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0430} \u{0443}\u{0434}\u{0430}\u{043B}\u{0435}\u{043D}\u{0430}",
                "\u{0421}\u{0443}\u{043C}\u{043C}\u{0430}: ".$this->formatCustomerOrderMoney($oldPayment['paid_amount_uah'], 'UAH'),
                $oldPayment,
                [
                    'paid_amount_uah' => 0,
                ],
            );

            if ($oldStatus !== $customerOrder->status) {
                $this->recordCustomerOrderHistoryEvent(
                    $customerOrder,
                    'status_changed',
                    'Статус изменен',
                    sprintf(
                        '%s -> %s',
                        $this->customerOrderStatusLabel($oldStatus),
                        $this->customerOrderStatusLabel($customerOrder->status),
                    ),
                    ['status' => $oldStatus],
                    ['status' => $customerOrder->status],
                );

                PartCatalogItem::query()
                    ->whereIn('id', $catalogItemIds)
                    ->get()
                    ->each(fn (PartCatalogItem $catalogItem) => $this->refreshCatalogItemReservationProjection($catalogItem));
                Product::query()
                    ->whereIn('id', $productIds)
                    ->get()
                    ->each(fn (Product $product) => $this->refreshProductReservationProjection($product));
            }
        });

        return redirect()
            ->back()
            ->with('status', "\u{041F}\u{0440}\u{0435}\u{0434}\u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0430} \u{0443}\u{0434}\u{0430}\u{043B}\u{0435}\u{043D}\u{0430}.");
    }

    public function destroyPrepaymentEntry(CustomerOrder $customerOrder, CustomerOrderHistoryEvent $historyEvent): RedirectResponse
    {
        abort_unless($historyEvent->customer_order_id === $customerOrder->id, 404);
        abort_unless($customerOrder->canAcceptPrepayment() && (float) $customerOrder->paid_amount_uah > 0, 404);
        abort_unless(in_array($historyEvent->event_type, ['prepayment_received', 'payment_confirmed'], true), 404);
        abort_if(CustomerOrderHistoryEvent::query()
            ->where('customer_order_id', $customerOrder->id)
            ->where('event_type', 'prepayment_deleted')
            ->where('new_values->deleted_event_id', $historyEvent->id)
            ->exists(), 404);

        $paymentType = (string) data_get($historyEvent->new_values, 'payment_type', '');
        $receivedAmount = round((float) data_get($historyEvent->new_values, 'payment_received_amount', 0), 2);
        $receivedAmountUah = round((float) data_get($historyEvent->new_values, 'payment_received_amount_uah', $receivedAmount), 2);
        abort_unless(isset(CustomerOrder::PAYMENT_TYPE_LABELS[$paymentType]) && $receivedAmount > 0 && $receivedAmountUah > 0, 404);

        DB::transaction(function () use ($customerOrder, $historyEvent, $paymentType, $receivedAmount, $receivedAmountUah): void {
            [$catalogItemIds, $productIds] = $this->reservedInventoryIds($customerOrder);
            $customerOrder->refresh();
            $oldStatus = $customerOrder->status;
            $oldPayment = [
                'payment_type' => $customerOrder->payment_type,
                'payment_received_amount' => (float) $customerOrder->payment_received_amount,
                'payment_received_amount_uah' => (float) $customerOrder->payment_received_amount_uah,
                'paid_cash_uah' => (float) $customerOrder->paid_cash_uah,
                'paid_cash_usd' => (float) $customerOrder->paid_cash_usd,
                'paid_bank_tov_uah' => (float) $customerOrder->paid_bank_tov_uah,
                'paid_bank_fop_uah' => (float) $customerOrder->paid_bank_fop_uah,
                'paid_prom_uah' => (float) $customerOrder->paid_prom_uah,
                'paid_amount_uah' => (float) $customerOrder->paid_amount_uah,
            ];

            $paidCashUah = (float) $customerOrder->paid_cash_uah;
            $paidCashUsd = (float) $customerOrder->paid_cash_usd;
            $paidBankTovUah = (float) $customerOrder->paid_bank_tov_uah;
            $paidBankFopUah = (float) $customerOrder->paid_bank_fop_uah;
            $paidPromUah = (float) $customerOrder->paid_prom_uah;

            match ($paymentType) {
                CustomerOrder::PAYMENT_TYPE_CASH_UAH => $paidCashUah = max(0, round($paidCashUah - $receivedAmount, 2)),
                CustomerOrder::PAYMENT_TYPE_CASH_USD => $paidCashUsd = max(0, round($paidCashUsd - $receivedAmount, 2)),
                CustomerOrder::PAYMENT_TYPE_BANK_TOV => $paidBankTovUah = max(0, round($paidBankTovUah - $receivedAmount, 2)),
                CustomerOrder::PAYMENT_TYPE_BANK_FOP,
                CustomerOrder::PAYMENT_TYPE_BANK_FOP_AFTERPAYMENT => $paidBankFopUah = max(0, round($paidBankFopUah - $receivedAmount, 2)),
                CustomerOrder::PAYMENT_TYPE_PROM => $paidPromUah = max(0, round($paidPromUah - $receivedAmount, 2)),
            };

            $paidAmountUah = max(0, round((float) $customerOrder->paid_amount_uah - $receivedAmountUah, 2));
            $paymentSnapshot = collect([
                CustomerOrder::PAYMENT_TYPE_CASH_UAH => $paidCashUah,
                CustomerOrder::PAYMENT_TYPE_CASH_USD => $paidCashUsd,
                CustomerOrder::PAYMENT_TYPE_BANK_TOV => $paidBankTovUah,
                CustomerOrder::PAYMENT_TYPE_BANK_FOP => $paidBankFopUah,
                CustomerOrder::PAYMENT_TYPE_PROM => $paidPromUah,
            ])->filter(fn (float $amount): bool => $amount > 0);
            $lastPaymentType = $paymentSnapshot->keys()->last();
            $lastPaymentAmount = $lastPaymentType !== null ? (float) $paymentSnapshot->last() : 0.0;
            $lastPaymentAmountUah = $lastPaymentType === CustomerOrder::PAYMENT_TYPE_CASH_USD ? $paidAmountUah : $lastPaymentAmount;
            $nextStatus = $customerOrder->status;

            if (
                $customerOrder->delivery_method === CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA
                && $customerOrder->status === CustomerOrder::STATUS_PROCESSING
                && $paidAmountUah <= 0
            ) {
                $nextStatus = CustomerOrder::STATUS_WAITING_PREPAYMENT;
            }

            $customerOrder->forceFill([
                'status' => $nextStatus,
                'payment_type' => $lastPaymentType,
                'payment_received_amount' => $lastPaymentAmount,
                'payment_received_amount_uah' => $lastPaymentAmountUah,
                'paid_cash_uah' => $paidCashUah,
                'paid_cash_usd' => $paidCashUsd,
                'paid_bank_tov_uah' => $paidBankTovUah,
                'paid_bank_fop_uah' => $paidBankFopUah,
                'paid_prom_uah' => $paidPromUah,
                'paid_amount_uah' => $paidAmountUah,
                'payment_confirmed_at' => $paidAmountUah >= round((float) $customerOrder->total_amount, 2)
                    ? $customerOrder->payment_confirmed_at
                    : null,
            ])->save();

            $this->recordCustomerOrderHistoryEvent(
                $customerOrder,
                'prepayment_deleted',
                "\u{041F}\u{0440}\u{0435}\u{0434}\u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0430} \u{0443}\u{0434}\u{0430}\u{043B}\u{0435}\u{043D}\u{0430}",
                "\u{0421}\u{0443}\u{043C}\u{043C}\u{0430}: ".$this->formatCustomerOrderMoney($receivedAmount, $paymentType === CustomerOrder::PAYMENT_TYPE_CASH_USD ? 'USD' : 'UAH'),
                $oldPayment,
                [
                    'deleted_event_id' => $historyEvent->id,
                    'payment_type' => $paymentType,
                    'payment_received_amount' => $receivedAmount,
                    'payment_received_amount_uah' => $receivedAmountUah,
                    'paid_amount_uah' => $paidAmountUah,
                ],
            );

            if ($oldStatus !== $customerOrder->status) {
                $this->recordCustomerOrderHistoryEvent(
                    $customerOrder,
                    'status_changed',
                    "\u{0421}\u{0442}\u{0430}\u{0442}\u{0443}\u{0441} \u{0438}\u{0437}\u{043C}\u{0435}\u{043D}\u{0435}\u{043D}",
                    sprintf(
                        '%s -> %s',
                        $this->customerOrderStatusLabel($oldStatus),
                        $this->customerOrderStatusLabel($customerOrder->status),
                    ),
                    ['status' => $oldStatus],
                    ['status' => $customerOrder->status],
                );

                PartCatalogItem::query()
                    ->whereIn('id', $catalogItemIds)
                    ->get()
                    ->each(fn (PartCatalogItem $catalogItem) => $this->refreshCatalogItemReservationProjection($catalogItem));
                Product::query()
                    ->whereIn('id', $productIds)
                    ->get()
                    ->each(fn (Product $product) => $this->refreshProductReservationProjection($product));
            }
        });

        return redirect()
            ->back()
            ->with('status', "\u{041F}\u{0440}\u{0435}\u{0434}\u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0430} \u{0443}\u{0434}\u{0430}\u{043B}\u{0435}\u{043D}\u{0430}.");
    }

    protected function storeCustomerOrderPayment(
        Request $request,
        CustomerOrder $customerOrder,
        ExchangeRateService $exchangeRateService,
        bool $requireFullPayment,
    ): RedirectResponse {
        $request->merge($this->normalizeCustomerOrderPaymentAmounts($request->all()));

        $validated = $request->has('payments')
            ? $request->validate([
                'payments' => ['required', 'array', 'min:1', 'max:10'],
                'payments.*.payment_type' => ['required', Rule::in(array_keys(CustomerOrder::PAYMENT_TYPE_LABELS))],
                'payments.*.received_amount' => ['required', 'numeric', 'min:0.01', 'max:999999999'],
            ])
            : $request->validate([
                'payment_type' => ['required', Rule::in(array_keys(CustomerOrder::PAYMENT_TYPE_LABELS))],
                'received_amount' => ['required', 'numeric', 'min:0.01', 'max:999999999'],
            ]);

        $paymentParts = $this->customerOrderPaymentParts($validated);
        $currentUsdRate = $exchangeRateService->currentUsdRate();

        if ($paymentParts->countBy('payment_type')->contains(fn (int $count): bool => $count > 1)) {
            throw ValidationException::withMessages([
                'payments' => 'Выберите разные типы оплаты.',
            ]);
        }

        $usesPromPayment = $paymentParts->contains(
            fn (array $paymentPart): bool => $paymentPart['payment_type'] === CustomerOrder::PAYMENT_TYPE_PROM,
        );
        $usesBankFopAfterpayment = $paymentParts->contains(
            fn (array $paymentPart): bool => $paymentPart['payment_type'] === CustomerOrder::PAYMENT_TYPE_BANK_FOP_AFTERPAYMENT,
        );

        if ($usesPromPayment && (
            $requireFullPayment
            || $customerOrder->delivery_method !== CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA
            || $paymentParts->count() !== 1
        )) {
            throw ValidationException::withMessages([
                'payments' => "\u{0050}\u{0072}\u{006F}\u{006D}-\u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0430} \u{0434}\u{043E}\u{0441}\u{0442}\u{0443}\u{043F}\u{043D}\u{0430} \u{0442}\u{043E}\u{043B}\u{044C}\u{043A}\u{043E} \u{0434}\u{043B}\u{044F} \u{043F}\u{0440}\u{0435}\u{0434}\u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{044B} \u{0437}\u{0430}\u{043A}\u{0430}\u{0437}\u{0430} \u{041D}\u{043E}\u{0432}\u{043E}\u{0439} \u{043F}\u{043E}\u{0447}\u{0442}\u{043E}\u{0439}.",
            ]);
        }

        if ($usesBankFopAfterpayment && $customerOrder->delivery_method !== CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA) {
            throw ValidationException::withMessages([
                'payments' => "\u{041D}\u{0430}\u{043B}\u{043E}\u{0436}\u{0435}\u{043D}\u{043D}\u{044B}\u{0439} \u{043F}\u{043B}\u{0430}\u{0442}\u{0435}\u{0436} \u{0434}\u{043E}\u{0441}\u{0442}\u{0443}\u{043F}\u{0435}\u{043D} \u{0442}\u{043E}\u{043B}\u{044C}\u{043A}\u{043E} \u{0434}\u{043B}\u{044F} \u{0437}\u{0430}\u{043A}\u{0430}\u{0437}\u{043E}\u{0432} \u{041D}\u{043E}\u{0432}\u{043E}\u{0439} \u{043F}\u{043E}\u{0447}\u{0442}\u{043E}\u{0439}.",
            ]);
        }

        $customerOrder->loadMissing('items');
        $totalAmountUah = $this->customerOrderTotalAmountUah(
            $customerOrder,
            $exchangeRateService,
            $this->customerOrderUsdRate($customerOrder, $exchangeRateService),
        );
        $paymentDueUah = max(0, round($totalAmountUah - (float) $customerOrder->paid_amount_uah, 2));
        $paymentDueUsd = $this->customerOrderPaymentDueUsd($customerOrder, $paymentDueUah, $currentUsdRate);
        $paymentUsdRate = $this->customerOrderPaymentConversionUsdRate($customerOrder, $paymentDueUah, $paymentDueUsd, $currentUsdRate);
        $rate = (float) ($paymentUsdRate['rate'] ?? 0);

        if ($usesPromPayment) {
            $paymentParts = $paymentParts->map(function (array $paymentPart) use ($paymentDueUah): array {
                if ($paymentPart['payment_type'] === CustomerOrder::PAYMENT_TYPE_PROM) {
                    $paymentPart['received_amount'] = $paymentDueUah;
                }

                return $paymentPart;
            });
        }

        if ($paymentParts->contains(fn (array $paymentPart): bool => $paymentPart['payment_type'] === CustomerOrder::PAYMENT_TYPE_CASH_USD) && $rate <= 0) {
            throw ValidationException::withMessages([
                'received_amount' => 'Не удалось получить курс USD для пересчета оплаты.',
            ]);
        }

        $paymentParts = $paymentParts->map(function (array $paymentPart) use ($rate): array {
            $paymentPart['received_amount_uah'] = $paymentPart['payment_type'] === CustomerOrder::PAYMENT_TYPE_CASH_USD
                ? round($paymentPart['received_amount'] * $rate, 2)
                : $paymentPart['received_amount'];

            return $paymentPart;
        });

        $lastPaymentPart = $paymentParts->last();
        $receivedAmountUah = round($paymentParts->sum('received_amount_uah'), 2);
        $paidAmountUah = round((float) $customerOrder->paid_amount_uah + $receivedAmountUah, 2);
        $isFullyPaid = $this->customerOrderPaymentCoversTotal($paidAmountUah, $totalAmountUah);

        if ($requireFullPayment && ! $isFullyPaid) {
            throw ValidationException::withMessages([
                'payments' => 'Сумма оплаты должна быть не меньше суммы заказа.',
            ]);
        }

        if ($requireFullPayment && $paidAmountUah < $totalAmountUah) {
            $paidAmountUah = $totalAmountUah;
        }

        $paymentSummary = $paymentParts
            ->map(fn (array $paymentPart): string => sprintf(
                '%s, получено %s',
                CustomerOrder::PAYMENT_TYPE_LABELS[$paymentPart['payment_type']] ?? $paymentPart['payment_type'],
                $this->formatCustomerOrderMoney($paymentPart['received_amount'], $paymentPart['payment_type'] === CustomerOrder::PAYMENT_TYPE_CASH_USD ? 'USD' : 'UAH'),
            ))
            ->join('; ');
        $paymentType = $lastPaymentPart['payment_type'];
        $receivedAmount = $lastPaymentPart['received_amount'];
        $paymentLabel = $paymentParts->count() > 1 ? $paymentSummary : (CustomerOrder::PAYMENT_TYPE_LABELS[$paymentType] ?? $paymentType);
        $shouldConfirmPayment = $isFullyPaid;

        DB::transaction(function () use ($customerOrder, $paymentParts, $lastPaymentPart, $paymentType, $paymentLabel, $receivedAmount, $receivedAmountUah, $paidAmountUah, $shouldConfirmPayment, $requireFullPayment): void {
            $oldStatus = $customerOrder->status;
            [$catalogItemIds, $productIds] = $this->reservedInventoryIds($customerOrder);
            $nextStatus = $customerOrder->status;

            if (
                $customerOrder->delivery_method === CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA
                && $customerOrder->status === CustomerOrder::STATUS_WAITING_PREPAYMENT
                && $paidAmountUah > 0
            ) {
                $nextStatus = CustomerOrder::STATUS_PROCESSING;
            }

            $customerOrder->forceFill([
                'status' => $nextStatus,
                'payment_type' => $lastPaymentPart['payment_type'],
                'payment_received_amount' => $lastPaymentPart['received_amount'],
                'payment_received_amount_uah' => $receivedAmountUah,
                'paid_cash_uah' => round((float) $customerOrder->paid_cash_uah + $paymentParts->where('payment_type', CustomerOrder::PAYMENT_TYPE_CASH_UAH)->sum('received_amount'), 2),
                'paid_cash_usd' => round((float) $customerOrder->paid_cash_usd + $paymentParts->where('payment_type', CustomerOrder::PAYMENT_TYPE_CASH_USD)->sum('received_amount'), 2),
                'paid_bank_tov_uah' => round((float) $customerOrder->paid_bank_tov_uah + $paymentParts->where('payment_type', CustomerOrder::PAYMENT_TYPE_BANK_TOV)->sum('received_amount'), 2),
                'paid_bank_fop_uah' => round((float) $customerOrder->paid_bank_fop_uah + $paymentParts
                    ->whereIn('payment_type', [CustomerOrder::PAYMENT_TYPE_BANK_FOP, CustomerOrder::PAYMENT_TYPE_BANK_FOP_AFTERPAYMENT])
                    ->sum('received_amount'), 2),
                'paid_prom_uah' => round((float) $customerOrder->paid_prom_uah + $paymentParts->where('payment_type', CustomerOrder::PAYMENT_TYPE_PROM)->sum('received_amount'), 2),
                'paid_amount_uah' => $paidAmountUah,
                'payment_confirmed_at' => $shouldConfirmPayment ? now() : $customerOrder->payment_confirmed_at,
            ])->save();

            if ($customerOrder->isIssuedToClient()) {
                app(CustomerOrderIssuedSaleService::class)->syncOrder($customerOrder->fresh(['items.partCatalogItem', 'items.product']));
            }

            $this->recordCustomerOrderHistoryEvent(
                $customerOrder,
                $shouldConfirmPayment ? 'payment_confirmed' : 'prepayment_received',
                'Оплата подтверждена',
                sprintf(
                    '%s, получено %s',
                    $paymentLabel,
                    $this->formatCustomerOrderMoney($receivedAmount, $paymentType === CustomerOrder::PAYMENT_TYPE_CASH_USD ? 'USD' : 'UAH'),
                ),
                [],
                [
                    'payment_type' => $paymentType,
                    'payment_received_amount' => $receivedAmount,
                    'payment_received_amount_uah' => $receivedAmountUah,
                    'paid_amount_uah' => $paidAmountUah,
                    'is_prepayment_flow' => ! $requireFullPayment,
                ],
            );

            if ($oldStatus !== $customerOrder->status) {
                $this->recordCustomerOrderHistoryEvent(
                    $customerOrder,
                    'status_changed',
                    'Статус изменен',
                    sprintf(
                        '%s -> %s',
                        $this->customerOrderStatusLabel($oldStatus),
                        $this->customerOrderStatusLabel($customerOrder->status),
                    ),
                    ['status' => $oldStatus],
                    ['status' => $customerOrder->status],
                );

                PartCatalogItem::query()
                    ->whereIn('id', $catalogItemIds)
                    ->get()
                    ->each(fn (PartCatalogItem $catalogItem) => $this->refreshCatalogItemReservationProjection($catalogItem));
                Product::query()
                    ->whereIn('id', $productIds)
                    ->get()
                    ->each(fn (Product $product) => $this->refreshProductReservationProjection($product));
            }
        });

        $freshOrder = $customerOrder->fresh();
        $statusMessage = 'Оплата сохранена.';

        if (! $requireFullPayment) {
            $statusMessage = "\u{041F}\u{0440}\u{0435}\u{0434}\u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0430} \u{0441}\u{043E}\u{0445}\u{0440}\u{0430}\u{043D}\u{0435}\u{043D}\u{0430}.";
        }

        if ($freshOrder?->payment_confirmed_at) {
            $statusMessage = 'Оплата получена полностью.';
        }

        return redirect()
            ->back()
            ->with('status', $statusMessage);
    }

    protected function customerOrderPaymentParts(array $validated): Collection
    {
        $payments = $validated['payments'] ?? [[
            'payment_type' => $validated['payment_type'],
            'received_amount' => $validated['received_amount'],
        ]];

        return collect($payments)
            ->map(fn (array $payment): array => [
                'payment_type' => $payment['payment_type'],
                'received_amount' => round((float) $payment['received_amount'], 2),
            ])
            ->values();
    }

    protected function normalizeCustomerOrderPaymentAmounts(array $payload): array
    {
        if (isset($payload['received_amount'])) {
            $payload['received_amount'] = $this->normalizeCustomerOrderPaymentAmount($payload['received_amount']);
        }

        if (isset($payload['payments']) && is_array($payload['payments'])) {
            foreach ($payload['payments'] as $index => $payment) {
                if (is_array($payment) && array_key_exists('received_amount', $payment)) {
                    $payload['payments'][$index]['received_amount'] = $this->normalizeCustomerOrderPaymentAmount($payment['received_amount']);
                }
            }
        }

        return $payload;
    }

    protected function normalizeCustomerOrderPaymentAmount(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return str_replace(',', '.', trim($value));
    }

    public function storeItem(Request $request, CustomerOrder $customerOrder, ExchangeRateService $exchangeRateService): RedirectResponse
    {
        $this->ensureCustomerOrderCanBeEdited($customerOrder);
        $this->ensureCustomerOrderItemsCanBeChanged($customerOrder);

        $validated = $request->validate([
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')],
            'part_catalog_item_id' => ['nullable', 'integer', Rule::exists('part_catalog_items', 'id')->where('source', 'nikolacars')],
            'name' => ['nullable', 'string', 'max:255', 'required_without_all:product_id,part_catalog_item_id'],
            'part_number' => ['nullable', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:255'],
            'donor_vin' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string'],
            'quantity' => ['required', 'numeric', 'min:0.001'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'unit_price_usd_hint' => ['nullable', 'numeric', 'min:0'],
            'source_url' => ['nullable', 'string', 'max:2048'],
            'image_url' => ['nullable', 'string', 'max:2048'],
        ]);

        $usdRate = $this->customerOrderUsdRate($customerOrder, $exchangeRateService, true);
        $usdExchangeRate = (float) ($usdRate['rate'] ?? 0);
        [$product, $catalogItem] = $this->productAndCatalogItemFromRequest($validated);
        $quantity = round((float) $validated['quantity'], 3);
        $this->ensureCustomerOrderItemQuantityAvailable($product, $catalogItem, $quantity, 'quantity');
        $priceAmount = $product?->selling_price ?? $catalogItem?->price_amount;
        $currency = $product?->currency ?? $catalogItem?->currency;
        $usdHint = array_key_exists('unit_price_usd_hint', $validated) && $validated['unit_price_usd_hint'] !== null
            ? round((float) $validated['unit_price_usd_hint'], 2)
            : ($product instanceof Product
                ? $this->productPriceAmountUsd($product, $usdRate)
                : $catalogItem?->priceAmountUsd($usdRate));
        $unitPrice = array_key_exists('unit_price', $validated) && $validated['unit_price'] !== null
            ? round((float) $validated['unit_price'], 2)
            : ($priceAmount !== null
                ? $exchangeRateService->productSellingPriceUahRoundedToTen((float) $priceAmount, $currency ?: 'USD', $usdRate)
                : ($usdHint !== null ? $exchangeRateService->productSellingPriceUahRoundedToTen($usdHint, 'USD', $usdRate) : 0.0));
        $totalPrice = round($quantity * $unitPrice, 2);
        $usdLineTotal = $usdHint !== null ? round($quantity * $usdHint, 2) : null;

        DB::transaction(function () use ($customerOrder, $validated, $product, $catalogItem, $quantity, $unitPrice, $totalPrice, $usdHint, $usdLineTotal, $usdExchangeRate): void {
            $orderItem = $customerOrder->items()->create([
                'part_catalog_item_id' => $catalogItem?->id,
                'product_id' => $product?->id,
                'name' => $this->customerOrderItemName($product, $catalogItem, $validated['name'] ?? null),
                'part_number' => $validated['part_number'] ?? $product?->external_sku ?? $catalogItem?->part_number,
                'code' => $validated['code'] ?? $product?->sku ?? data_get($catalogItem?->raw_attributes, 'code'),
                'donor_vin' => $validated['donor_vin'] ?? $product?->donorCar?->vin ?? data_get($catalogItem?->raw_attributes, 'donor_vin'),
                'category' => isset($validated['category'])
                    ? CatalogTextEncoding::repair((string) $validated['category'])
                    : ($this->productCategoryDisplay($product) ?: (data_get($catalogItem?->raw_attributes, 'category_display') ?? $catalogItem?->compatibility_text)),
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => $totalPrice,
                'currency' => 'UAH',
                'unit_price_usd_hint' => $usdHint,
                'total_price_usd_hint' => $usdLineTotal,
                'usd_exchange_rate' => $usdHint !== null && $usdExchangeRate > 0 ? $usdExchangeRate : null,
                'source_url' => $validated['source_url'] ?? ($product ? route('admin.products.show', $product) : ($catalogItem ? $this->nikolaCarsProductUrl($catalogItem) : null)),
                'image_url' => $validated['image_url'] ?? $this->productImageUrl($product) ?? data_get($catalogItem?->raw_attributes, 'image_urls.0'),
            ]);

            $this->recordCustomerOrderHistoryEvent(
                $customerOrder,
                'item_added',
                'Запчасть добавлена',
                $this->customerOrderItemHistoryDescription($orderItem),
                null,
                $this->customerOrderItemHistoryValues($orderItem),
                $orderItem,
            );

            $this->recalculateOrderTotal($customerOrder);

            if ($catalogItem instanceof PartCatalogItem) {
                $this->refreshCatalogItemReservationProjection($catalogItem);
            }
            if ($product instanceof Product) {
                $this->refreshProductReservationProjection($product);
            }
        });

        return redirect()
            ->route('admin.customer-orders.show', $customerOrder)
            ->with('status', "\u{0422}\u{043E}\u{0432}\u{0430}\u{0440} \u{0434}\u{043E}\u{0431}\u{0430}\u{0432}\u{043B}\u{0435}\u{043D} \u{0432} \u{0437}\u{0430}\u{043A}\u{0430}\u{0437}.");
    }

    public function destroyItem(CustomerOrder $customerOrder, CustomerOrderItem $customerOrderItem): RedirectResponse
    {
        if ((int) $customerOrderItem->customer_order_id !== (int) $customerOrder->id) {
            abort(404);
        }

        $this->ensureCustomerOrderCanBeEdited($customerOrder);
        $this->ensureCustomerOrderItemsCanBeChanged($customerOrder);

        DB::transaction(function () use ($customerOrder, $customerOrderItem): void {
            $catalogItem = $customerOrderItem->partCatalogItem;
            $product = $customerOrderItem->product;
            $oldValues = $this->customerOrderItemHistoryValues($customerOrderItem);
            $description = $this->customerOrderItemHistoryDescription($customerOrderItem);

            $this->recordCustomerOrderHistoryEvent(
                $customerOrder,
                'item_removed',
                'Запчасть удалена',
                $description,
                $oldValues,
                null,
                $customerOrderItem,
            );
            $customerOrderItem->delete();
            $this->recalculateOrderTotal($customerOrder);

            if ($catalogItem instanceof PartCatalogItem) {
                $this->refreshCatalogItemReservationProjection($catalogItem);
            }
            if ($product instanceof Product) {
                $this->refreshProductReservationProjection($product);
            }
        });

        return redirect()
            ->route('admin.customer-orders.show', $customerOrder)
            ->with('status', "\u{0422}\u{043E}\u{0432}\u{0430}\u{0440} \u{0443}\u{0434}\u{0430}\u{043B}\u{0435}\u{043D} \u{0438}\u{0437} \u{0437}\u{0430}\u{043A}\u{0430}\u{0437}\u{0430}.");
    }

    public function updateItem(Request $request, CustomerOrder $customerOrder, CustomerOrderItem $customerOrderItem, ExchangeRateService $exchangeRateService): RedirectResponse
    {
        if ((int) $customerOrderItem->customer_order_id !== (int) $customerOrder->id) {
            abort(404);
        }

        $this->ensureCustomerOrderCanBeEdited($customerOrder);
        $this->ensureCustomerOrderItemsCanBeChanged($customerOrder);

        $validated = $request->validate([
            'unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        $unitPrice = round((float) $validated['unit_price'], 2);
        $totalPrice = round((float) $customerOrderItem->quantity * $unitPrice, 2);
        $usdRate = $this->customerOrderUsdRate($customerOrder, $exchangeRateService, true);
        $usdExchangeRate = (float) ($usdRate['rate'] ?? 0);
        $unitPriceUsdHint = $usdExchangeRate > 0 ? (float) ceil($unitPrice / $usdExchangeRate) : null;
        $totalPriceUsdHint = $unitPriceUsdHint !== null
            ? round((float) $customerOrderItem->quantity * $unitPriceUsdHint, 2)
            : null;

        DB::transaction(function () use ($customerOrder, $customerOrderItem, $unitPrice, $totalPrice, $unitPriceUsdHint, $totalPriceUsdHint, $usdExchangeRate): void {
            $oldUnitPrice = round((float) $customerOrderItem->unit_price, 2);

            $customerOrderItem->forceFill([
                'unit_price' => $unitPrice,
                'total_price' => $totalPrice,
                'currency' => 'UAH',
                'unit_price_usd_hint' => $unitPriceUsdHint,
                'total_price_usd_hint' => $totalPriceUsdHint,
                'usd_exchange_rate' => $unitPriceUsdHint !== null ? $usdExchangeRate : null,
            ])->save();

            if ($oldUnitPrice !== $unitPrice) {
                $this->recordCustomerOrderHistoryEvent(
                    $customerOrder,
                    'item_price_changed',
                    'Цена запчасти изменена',
                    sprintf(
                        '%s: %s -> %s',
                        $this->customerOrderItemHistoryName($customerOrderItem),
                        $this->formatCustomerOrderMoney($oldUnitPrice),
                        $this->formatCustomerOrderMoney($unitPrice),
                    ),
                    ['unit_price' => $oldUnitPrice],
                    ['unit_price' => $unitPrice],
                    $customerOrderItem,
                );
            }

            $this->recalculateOrderTotal($customerOrder);
        });

        return redirect()
            ->route('admin.customer-orders.show', $customerOrder)
            ->with('status', "\u{0426}\u{0435}\u{043D}\u{0430} \u{0442}\u{043E}\u{0432}\u{0430}\u{0440}\u{0430} \u{043E}\u{0431}\u{043D}\u{043E}\u{0432}\u{043B}\u{0435}\u{043D}\u{0430}.");
    }

    public function catalogItemSearch(Request $request, CustomerOrder $customerOrder, ExchangeRateService $exchangeRateService): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));
        $usdRate = $this->customerOrderUsdRate($customerOrder, $exchangeRateService, true);

        $products = Product::query()
            ->with(['donorCar', 'category', 'sourcePartCatalogItem'])
            ->where('is_active', true)
            ->whereIn('storage_status', [
                Product::STORAGE_STATUS_IN_STOCK,
                Product::STORAGE_STATUS_ON_DONOR,
            ])
            ->when($query !== '', function (Builder $builder) use ($query): void {
                $builder->where(function (Builder $search) use ($query): void {
                    $search
                        ->where('name', 'like', "%{$query}%")
                        ->orWhere('sku', 'like', "%{$query}%")
                        ->orWhere('external_sku', 'like', "%{$query}%")
                        ->orWhere('compatibility', 'like', "%{$query}%")
                        ->orWhereHas('sourcePartCatalogItem', fn (Builder $sourceItem) => $sourceItem
                            ->where('source', 'nikolacars')
                            ->where(function (Builder $sourceSearch) use ($query): void {
                                $sourceSearch
                                    ->where('name', 'like', "%{$query}%")
                                    ->orWhere('name_ru', 'like', "%{$query}%")
                                    ->orWhere('name_ua', 'like', "%{$query}%")
                                    ->orWhere('part_number', 'like', "%{$query}%")
                                    ->orWhere('compatibility_text', 'like', "%{$query}%")
                                    ->orWhere('raw_attributes', 'like', "%{$query}%");
                            }));
                });
            })
            ->orderBy('external_sku')
            ->orderBy('name')
            ->limit(50)
            ->get()
            ->filter(fn (Product $product): bool => $this->productCanBeOrdered($product))
            ->take(15)
            ->values();

        return response()->json($products->map(function (Product $product) use ($exchangeRateService, $usdRate): array {
            $catalogItem = $product->sourcePartCatalogItem?->source === 'nikolacars'
                ? $product->sourcePartCatalogItem
                : null;
            $unitPriceUah = $product->selling_price !== null
                ? $exchangeRateService->productSellingPriceUahRoundedToTen((float) $product->selling_price, $product->currency ?: 'USD', $usdRate)
                : null;
            $unitPriceUsd = $this->productPriceAmountUsd($product, $usdRate);

            return [
                'id' => $product->id,
                'product_id' => $product->id,
                'part_catalog_item_id' => $catalogItem?->id,
                'name' => CatalogTextEncoding::repair((string) ($catalogItem?->name_ua ?: $catalogItem?->name_ru ?: $product->name)),
                'part_number' => $product->external_sku ?: $catalogItem?->part_number,
                'code' => $product->sku ?: data_get($catalogItem?->raw_attributes, 'code'),
                'donor_vin' => $product->donorCar?->vin ?: data_get($catalogItem?->raw_attributes, 'donor_vin'),
                'category' => $this->productCategoryDisplay($product) ?: (data_get($catalogItem?->raw_attributes, 'category_display') ?? $catalogItem?->compatibility_text),
                'unit_price_uah' => $unitPriceUah,
                'unit_price_uah_text' => $unitPriceUah !== null ? number_format($unitPriceUah, 0, '.', ' ').' грн' : '-',
                'unit_price_usd_text' => $unitPriceUsd !== null ? number_format($unitPriceUsd, 2, '.', ' ').' USD' : null,
                'url' => route('admin.products.show', $product),
            ];
        })->values());
    }

    public function clientSearch(Request $request): JsonResponse
    {
        $phone = trim((string) $request->query('phone', ''));
        $digits = $this->phoneDigits($phone);

        if ($digits === '') {
            return response()->json(null);
        }

        $clients = Counterparty::query()
            ->whereIn('type', [Counterparty::TYPE_STO_CUSTOMER, Counterparty::TYPE_PARTS, Counterparty::TYPE_BOTH])
            ->whereNotNull('phone')
            ->get(['id', 'name', 'phone'])
            ->filter(function (Counterparty $counterparty) use ($digits): bool {
                $counterpartyDigits = $this->phoneDigits((string) $counterparty->phone);

                return $counterpartyDigits !== ''
                    && (Str::contains($counterpartyDigits, $digits) || Str::contains($digits, $counterpartyDigits));
            })
            ->take(8)
            ->values();

        return response()->json($clients->map(function (Counterparty $client): array {
            $parts = preg_split('/\s+/u', trim((string) $client->name), 2) ?: [];
            $isAnonymous = $client->name === Counterparty::ANONYMOUS_NAME
                && $this->phoneDigits((string) $client->phone) === $this->phoneDigits(Counterparty::ANONYMOUS_PHONE);

            return [
                'id' => $client->id,
                'phone' => $client->phone,
                'first_name' => $parts[0] ?? '',
                'last_name' => $parts[1] ?? '',
                'name' => $client->name,
                'is_anonymous' => $isAnonymous,
                'default_delivery_method' => $isAnonymous ? CustomerOrder::DELIVERY_METHOD_PICKUP : null,
            ];
        }));
    }

    public function novaPoshtaCities(Request $request, NovaPoshtaDirectoryService $directoryService): JsonResponse
    {
        try {
            return response()->json($directoryService->cities((string) $request->query('query', '')));
        } catch (RuntimeException $exception) {
            Log::warning('Nova Poshta city directory request failed.', [
                'query' => (string) $request->query('query', ''),
                'message' => $exception->getMessage(),
            ]);

            return response()->json(['message' => $exception->getMessage()], 503);
        }
    }

    public function novaPoshtaWarehouses(Request $request, NovaPoshtaDirectoryService $directoryService): JsonResponse
    {
        try {
            return response()->json($directoryService->warehouses(
                (string) $request->query('city_ref', ''),
                (string) $request->query('query', ''),
            ));
        } catch (RuntimeException $exception) {
            Log::warning('Nova Poshta warehouse directory request failed.', [
                'city_ref' => (string) $request->query('city_ref', ''),
                'query' => (string) $request->query('query', ''),
                'message' => $exception->getMessage(),
            ]);

            return response()->json(['message' => $exception->getMessage()], 503);
        }
    }

    public function store(Request $request, ExchangeRateService $exchangeRateService): JsonResponse
    {
        $validated = $request->validate([
            'client_phone' => ['nullable', 'string', 'max:255', function (string $attribute, mixed $value, \Closure $fail): void {
                if (trim((string) $value) === '') {
                    return;
                }

                if (! $this->isValidUkrainianMobilePhone((string) $value)) {
                    $fail('Телефон должен быть украинским мобильным номером в формате 0XXXXXXXXX или +380XXXXXXXXX.');
                }
            }],
            'delivery_method' => ['required', Rule::in(array_keys(CustomerOrder::DELIVERY_METHOD_LABELS))],
            'client_first_name' => ['nullable', 'string', 'max:255'],
            'client_last_name' => ['nullable', 'string', 'max:255'],
            'nova_poshta_city' => ['nullable', 'string', 'max:255'],
            'nova_poshta_warehouse' => ['nullable', 'string', 'max:255'],
            'nova_poshta_warehouse_ref' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.part_number' => ['nullable', 'string', 'max:255'],
            'items.*.code' => ['nullable', 'string', 'max:255'],
            'items.*.vin' => ['nullable', 'string', 'max:255'],
            'items.*.category' => ['nullable', 'string'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.price' => ['nullable', 'numeric', 'min:0'],
            'items.*.price_usd_hint' => ['nullable', 'numeric', 'min:0'],
            'items.*.url' => ['nullable', 'string', 'max:2048'],
            'items.*.image' => ['nullable', 'string', 'max:2048'],
        ]);

        $firstName = CatalogTextEncoding::repair(trim((string) ($validated['client_first_name'] ?? '')));
        $lastName = CatalogTextEncoding::repair(trim((string) ($validated['client_last_name'] ?? '')));
        $phone = trim((string) ($validated['client_phone'] ?? ''));
        $deliveryMethod = $validated['delivery_method'] ?? null;
        $usdRate = $exchangeRateService->usdRateForDate();
        $usdExchangeRate = (float) ($usdRate['rate'] ?? 0);

        if ($deliveryMethod !== CustomerOrder::DELIVERY_METHOD_STO) {
            $hasCustomerDetails = $phone !== '' || $firstName !== '' || $lastName !== '';

            if ($hasCustomerDetails && ($firstName === '' || $lastName === '')) {
                $messages = [];

                if ($firstName === '') {
                    $messages['client_first_name'] = "\u{0418}\u{043C}\u{044F} \u{043A}\u{043B}\u{0438}\u{0435}\u{043D}\u{0442}\u{0430} \u{043E}\u{0431}\u{044F}\u{0437}\u{0430}\u{0442}\u{0435}\u{043B}\u{044C}\u{043D}\u{043E}, \u{0435}\u{0441}\u{043B}\u{0438} \u{0443}\u{043A}\u{0430}\u{0437}\u{0430}\u{043D}\u{044B} \u{0434}\u{0430}\u{043D}\u{043D}\u{044B}\u{0435} \u{043A}\u{043B}\u{0438}\u{0435}\u{043D}\u{0442}\u{0430}.";
                }

                if ($lastName === '') {
                    $messages['client_last_name'] = "\u{0424}\u{0430}\u{043C}\u{0438}\u{043B}\u{0438}\u{044F} \u{043A}\u{043B}\u{0438}\u{0435}\u{043D}\u{0442}\u{0430} \u{043E}\u{0431}\u{044F}\u{0437}\u{0430}\u{0442}\u{0435}\u{043B}\u{044C}\u{043D}\u{0430}, \u{0435}\u{0441}\u{043B}\u{0438} \u{0443}\u{043A}\u{0430}\u{0437}\u{0430}\u{043D}\u{044B} \u{0434}\u{0430}\u{043D}\u{043D}\u{044B}\u{0435} \u{043A}\u{043B}\u{0438}\u{0435}\u{043D}\u{0442}\u{0430}.";
                }

                throw ValidationException::withMessages($messages);
            }
        }

        if ($deliveryMethod === CustomerOrder::DELIVERY_METHOD_STO) {
            $firstName = "\u{0421}\u{0422}\u{041E}";
            $lastName = '';
            $phone = '';
        }

        $novaPoshtaShipmentPayload = [];

        if ($deliveryMethod === CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA) {
            $this->ensureNovaPoshtaOrderDetails($phone, $validated, $firstName, $lastName);
            $novaPoshtaShipmentPayload = $this->novaPoshtaShipmentPayload($validated, $firstName, $lastName, $phone);
        }

        $order = DB::transaction(function () use ($validated, $firstName, $lastName, $phone, $deliveryMethod, $exchangeRateService, $usdRate, $usdExchangeRate, $novaPoshtaShipmentPayload): CustomerOrder {
            $counterparty = $deliveryMethod === CustomerOrder::DELIVERY_METHOD_STO
                ? $this->stoNikolaCarsCounterparty()
                : $this->findOrCreateCounterparty($phone, $firstName, $lastName);

            $order = CustomerOrder::query()->create([
                'number' => $this->nextNumber(),
                'status' => $deliveryMethod === CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA
                    ? CustomerOrder::STATUS_WAITING_PREPAYMENT
                    : CustomerOrder::STATUS_PROCESSING,
                'counterparty_id' => $counterparty?->id,
                'client_phone' => $phone !== '' ? $phone : null,
                'client_first_name' => $firstName !== '' ? $firstName : null,
                'client_last_name' => $lastName !== '' ? $lastName : null,
                'delivery_method' => $deliveryMethod,
                'currency' => 'UAH',
                'total_amount' => 0,
            ]);

            $this->recordCustomerOrderHistoryEvent(
                $order,
                'order_created',
                'Заказ создан',
                sprintf(
                    'Клиент: %s. Способ получения: %s.',
                    $order->client_name ?: '-',
                    $this->customerOrderDeliveryMethodLabel($order->delivery_method),
                ),
                null,
                [
                    'number' => $order->number,
                    'status' => $order->status,
                    'delivery_method' => $order->delivery_method,
                ],
            );

            $total = 0.0;
            foreach ($validated['items'] as $item) {
                $quantity = round((float) $item['quantity'], 3);
                $usdHint = isset($item['price_usd_hint']) && $item['price_usd_hint'] !== null
                    ? round((float) $item['price_usd_hint'], 2)
                    : null;
                $price = array_key_exists('price', $item) && $item['price'] !== null
                    ? round((float) $item['price'], 2)
                    : ($usdHint !== null
                        ? $exchangeRateService->productSellingPriceUahRoundedToTen($usdHint, 'USD', $usdRate)
                        : 0.0);
                $lineTotal = round($quantity * $price, 2);
                $usdLineTotal = $usdHint !== null ? round($quantity * $usdHint, 2) : null;
                $total += $lineTotal;

                [$product, $catalogItem] = $this->productAndCatalogItemFromCartItem($item);
                $this->ensureCustomerOrderItemQuantityAvailable($product, $catalogItem, $quantity, 'items', $item);

                $orderItem = $order->items()->create([
                    'part_catalog_item_id' => $catalogItem?->id,
                    'product_id' => $product?->id,
                    'name' => $this->customerOrderItemName($product, $catalogItem, $item['name']),
                    'part_number' => $item['part_number'] ?? null,
                    'code' => $item['code'] ?? null,
                    'donor_vin' => $item['vin'] ?? null,
                    'category' => isset($item['category']) ? CatalogTextEncoding::repair((string) $item['category']) : null,
                    'quantity' => $quantity,
                    'unit_price' => $price,
                    'total_price' => $lineTotal,
                    'currency' => 'UAH',
                    'unit_price_usd_hint' => $usdHint,
                    'total_price_usd_hint' => $usdLineTotal,
                    'usd_exchange_rate' => $usdHint !== null && $usdExchangeRate > 0 ? $usdExchangeRate : null,
                    'source_url' => $item['url'] ?? null,
                    'image_url' => $item['image'] ?? null,
                ]);

                $this->recordCustomerOrderHistoryEvent(
                    $order,
                    'item_added',
                    'Запчасть добавлена',
                    $this->customerOrderItemHistoryDescription($orderItem),
                    null,
                    $this->customerOrderItemHistoryValues($orderItem),
                    $orderItem,
                );

                if ($catalogItem instanceof PartCatalogItem) {
                    $rawAttributes = PartCatalogRawAttributes::from($catalogItem);
                    $reservedQuantity = (float) data_get($rawAttributes, 'reserved_quantity', 0);
                    $reservedOrders = collect((array) data_get($rawAttributes, 'reserved_orders', []))
                        ->push($order->number)
                        ->filter()
                        ->unique()
                        ->values()
                        ->all();

                    $rawAttributes['reserved_quantity'] = round($reservedQuantity + $quantity, 3);
                    $rawAttributes['reserved_orders'] = $reservedOrders;
                    $catalogItem->forceFill(['raw_attributes' => $rawAttributes])->save();
                    $this->refreshCatalogItemReservationProjection($catalogItem);
                }
                if ($product instanceof Product) {
                    $this->refreshProductReservationProjection($product);
                }
            }

            $order->forceFill(['total_amount' => round($total, 2)])->save();

            if ($deliveryMethod === CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA) {
                $order->novaPoshtaShipment()->create($novaPoshtaShipmentPayload + [
                    'carrier' => CustomerOrderShipment::CARRIER_NOVA_POSHTA,
                    'status' => CustomerOrderShipment::STATUS_DRAFT,
                    'recipient_name' => $order->client_name,
                    'recipient_phone' => $order->client_phone,
                    'declared_cost' => round($total, 2),
                ]);
            }

            return $order;
        });

        return response()->json([
            'id' => $order->id,
            'number' => $order->number,
            'url' => route('admin.customer-orders.show', $order),
        ], 201);
    }

    protected function recalculateOrderTotal(CustomerOrder $order): void
    {
        $order->forceFill([
            'total_amount' => round((float) $order->items()->sum('total_price'), 2),
            'currency' => 'UAH',
        ])->save();
    }

    protected function customerOrderItemName(?Product $product, ?PartCatalogItem $catalogItem, mixed $fallback = null): string
    {
        $catalogItem ??= $this->productNikolaCarsCatalogItem($product);

        return CatalogTextEncoding::repair($this->firstFilledString([
            $catalogItem?->name_ua,
            $fallback,
            $catalogItem?->name_ru,
            $product?->name,
            $catalogItem?->name_en,
            $catalogItem?->name,
        ]));
    }

    protected function productNikolaCarsCatalogItem(?Product $product): ?PartCatalogItem
    {
        if (! $product instanceof Product) {
            return null;
        }

        $sourceItem = $product->sourcePartCatalogItem;

        return $sourceItem instanceof PartCatalogItem && $sourceItem->source === 'nikolacars'
            ? $sourceItem
            : null;
    }

    protected function firstFilledString(array $values): string
    {
        return collect($values)
            ->map(fn (mixed $value): string => trim((string) $value))
            ->first(fn (string $value): bool => $value !== '') ?: '';
    }

    protected function ensureNovaPoshtaOrderDetails(string $phone, array $validated, string $firstName = '', string $lastName = ''): void
    {
        $messages = [];

        if (trim($firstName) === '') {
            $messages['client_first_name'] = "\u{0418}\u{043C}\u{044F} \u{043F}\u{043E}\u{043B}\u{0443}\u{0447}\u{0430}\u{0442}\u{0435}\u{043B}\u{044F} \u{043E}\u{0431}\u{044F}\u{0437}\u{0430}\u{0442}\u{0435}\u{043B}\u{044C}\u{043D}\u{043E} \u{0434}\u{043B}\u{044F} \u{041D}\u{043E}\u{0432}\u{043E}\u{0439} \u{043F}\u{043E}\u{0447}\u{0442}\u{044B}.";
        }

        if (trim($lastName) === '') {
            $messages['client_last_name'] = "\u{0424}\u{0430}\u{043C}\u{0438}\u{043B}\u{0438}\u{044F} \u{043F}\u{043E}\u{043B}\u{0443}\u{0447}\u{0430}\u{0442}\u{0435}\u{043B}\u{044F} \u{043E}\u{0431}\u{044F}\u{0437}\u{0430}\u{0442}\u{0435}\u{043B}\u{044C}\u{043D}\u{0430} \u{0434}\u{043B}\u{044F} \u{041D}\u{043E}\u{0432}\u{043E}\u{0439} \u{043F}\u{043E}\u{0447}\u{0442}\u{044B}.";
        }

        if ($phone === '') {
            $messages['client_phone'] = "\u{0422}\u{0435}\u{043B}\u{0435}\u{0444}\u{043E}\u{043D} \u{043A}\u{043B}\u{0438}\u{0435}\u{043D}\u{0442}\u{0430} \u{043E}\u{0431}\u{044F}\u{0437}\u{0430}\u{0442}\u{0435}\u{043B}\u{0435}\u{043D} \u{0434}\u{043B}\u{044F} \u{041D}\u{043E}\u{0432}\u{043E}\u{0439} \u{043F}\u{043E}\u{0447}\u{0442}\u{044B}.";
        }

        if (trim((string) ($validated['nova_poshta_city'] ?? '')) === '') {
            $messages['nova_poshta_city'] = "\u{0423}\u{043A}\u{0430}\u{0436}\u{0438}\u{0442}\u{0435} \u{0433}\u{043E}\u{0440}\u{043E}\u{0434} \u{043F}\u{043E}\u{043B}\u{0443}\u{0447}\u{0430}\u{0442}\u{0435}\u{043B}\u{044F} \u{041D}\u{043E}\u{0432}\u{043E}\u{0439} \u{043F}\u{043E}\u{0447}\u{0442}\u{044B}.";
        }

        if (trim((string) ($validated['nova_poshta_warehouse'] ?? '')) === '') {
            $messages['nova_poshta_warehouse'] = "\u{0423}\u{043A}\u{0430}\u{0436}\u{0438}\u{0442}\u{0435} \u{043E}\u{0442}\u{0434}\u{0435}\u{043B}\u{0435}\u{043D}\u{0438}\u{0435} \u{0438}\u{043B}\u{0438} \u{043F}\u{043E}\u{0447}\u{0442}\u{043E}\u{043C}\u{0430}\u{0442} \u{041D}\u{043E}\u{0432}\u{043E}\u{0439} \u{043F}\u{043E}\u{0447}\u{0442}\u{044B}.";
        }

        if (trim((string) ($validated['nova_poshta_warehouse_ref'] ?? '')) === '') {
            $messages['nova_poshta_warehouse_ref'] = "\u{0412}\u{044B}\u{0431}\u{0435}\u{0440}\u{0438}\u{0442}\u{0435} \u{043E}\u{0442}\u{0434}\u{0435}\u{043B}\u{0435}\u{043D}\u{0438}\u{0435} \u{0438}\u{043B}\u{0438} \u{043F}\u{043E}\u{0447}\u{0442}\u{043E}\u{043C}\u{0430}\u{0442} \u{0438}\u{0437} \u{043F}\u{043E}\u{0434}\u{0441}\u{043A}\u{0430}\u{0437}\u{043A}\u{0438} \u{041D}\u{043E}\u{0432}\u{043E}\u{0439} \u{041F}\u{043E}\u{0447}\u{0442}\u{044B}.";
        }

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }

    protected function novaPoshtaShipmentPayload(array $validated, string $firstName, string $lastName, string $phone): array
    {
        return [
            'recipient_city_name' => CatalogTextEncoding::repair(trim((string) ($validated['nova_poshta_city'] ?? ''))),
            'recipient_warehouse_name' => CatalogTextEncoding::repair(trim((string) ($validated['nova_poshta_warehouse'] ?? ''))),
            'recipient_warehouse_ref' => trim((string) ($validated['nova_poshta_warehouse_ref'] ?? '')) ?: null,
            'recipient_name' => trim(collect([$firstName, $lastName])->filter()->implode(' ')) ?: null,
            'recipient_phone' => $phone !== '' ? $phone : null,
            'payer_type' => (string) config('services.nova_poshta.payer_type', 'Recipient'),
            'payment_method' => (string) config('services.nova_poshta.payment_method', 'Cash'),
            'seats_amount' => max(1, (int) config('services.nova_poshta.default_seats_amount', 1)),
            'weight' => max(0.1, (float) config('services.nova_poshta.default_weight', 1)),
            'cargo_description' => $this->novaPoshtaCargoDescription(),
        ];
    }

    protected function novaPoshtaCargoDescription(): string
    {
        return "\u{0430}\u{0432}\u{0442}\u{043E}\u{0437}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{0438}\u{043D}\u{0438}";
    }

    protected function recordCustomerOrderHistoryEvent(
        CustomerOrder $order,
        string $eventType,
        string $title,
        ?string $description = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?CustomerOrderItem $item = null,
    ): void {
        $order->historyEvents()->create([
            'customer_order_item_id' => $item?->id,
            'user_id' => auth()->id(),
            'event_type' => $eventType,
            'title' => $title,
            'description' => $description,
            'old_values' => $oldValues,
            'new_values' => $newValues,
        ]);
    }

    protected function customerOrderHistoryEvents(CustomerOrder $order): Collection
    {
        $events = $order->historyEvents
            ->map(fn ($event): array => [
                'created_at' => $event->created_at,
                'title' => $event->title,
                'description' => $event->description,
                'user_name' => $event->user?->name,
            ]);

        if (! $order->historyEvents->contains('event_type', 'order_created')) {
            $events->push([
                'created_at' => $order->created_at,
                'title' => 'Заказ создан',
                'description' => sprintf(
                    'Клиент: %s. Способ получения: %s.',
                    $order->client_name ?: '-',
                    $this->customerOrderDeliveryMethodLabel($order->delivery_method),
                ),
                'user_name' => null,
            ]);
        }

        return $events
            ->sortBy(fn (array $event): int => $event['created_at']?->getTimestamp() ?? 0)
            ->values();
    }

    protected function customerOrderItemHistoryValues(CustomerOrderItem $item): array
    {
        return [
            'name' => $item->name,
            'part_number' => $item->part_number,
            'code' => $item->code,
            'donor_vin' => $item->donor_vin,
            'quantity' => (float) $item->quantity,
            'unit_price' => (float) $item->unit_price,
            'total_price' => (float) $item->total_price,
            'currency' => $item->currency,
        ];
    }

    protected function customerOrderItemHistoryDescription(CustomerOrderItem $item): string
    {
        return sprintf(
            '%s, количество %s, цена %s, сумма %s',
            $this->customerOrderItemHistoryName($item),
            $this->formatCustomerOrderQuantity((float) $item->quantity),
            $this->formatCustomerOrderMoney((float) $item->unit_price, $item->currency ?: 'UAH'),
            $this->formatCustomerOrderMoney((float) $item->total_price, $item->currency ?: 'UAH'),
        );
    }

    protected function customerOrderItemHistoryName(CustomerOrderItem $item): string
    {
        return trim(collect([$this->customerOrderItemDisplayName($item), $item->part_number ? '('.$item->part_number.')' : null])->filter()->implode(' '));
    }

    protected function formatCustomerOrderMoney(float $value, string $currency = 'UAH'): string
    {
        return $currency === 'UAH'
            ? number_format($value, 0, '.', ' ').' грн'
            : number_format($value, 2, '.', ' ').' '.$currency;
    }

    protected function formatCustomerOrderQuantity(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }

    protected function customerOrderNovaPoshtaAfterpaymentAmount(CustomerOrder $order): float
    {
        $shipments = $order->relationLoaded('novaPoshtaShipments')
            ? $order->novaPoshtaShipments
            : $order->novaPoshtaShipments()->get(['afterpayment_amount']);

        return round($shipments->sum(fn (CustomerOrderShipment $shipment): float => (float) $shipment->afterpayment_amount), 2);
    }

    protected function syncCustomerOrderShipmentItems(CustomerOrder $order, CustomerOrderShipment $shipment, Collection $itemIds): void
    {
        $itemIds = $itemIds
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        DB::transaction(function () use ($order, $shipment, $itemIds): void {
            $shipments = $order->novaPoshtaShipments()
                ->whereNotNull('tracking_number')
                ->orderBy('id')
                ->get();

            $primaryShipment = $shipments->first();

            if (! $primaryShipment instanceof CustomerOrderShipment) {
                return;
            }

            if ($itemIds->isNotEmpty()) {
                DB::table('customer_order_shipment_items')
                    ->whereIn('customer_order_shipment_id', $shipments->pluck('id')->all())
                    ->whereIn('customer_order_item_id', $itemIds->all())
                    ->delete();
            }

            $shipment->items()->sync($itemIds->all());

            $secondaryAssignedItemIds = DB::table('customer_order_shipment_items')
                ->whereIn('customer_order_shipment_id', $shipments->where('id', '!=', $primaryShipment->id)->pluck('id')->all())
                ->pluck('customer_order_item_id')
                ->map(fn ($id): int => (int) $id)
                ->unique();

            $primaryItemIds = $order->items()
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->diff($secondaryAssignedItemIds)
                ->values();

            $primaryShipment->items()->sync($primaryItemIds->all());
        });
    }

    protected function customerOrderStatusLabel(?string $status): string
    {
        return CatalogTextEncoding::repair(CustomerOrder::STATUS_LABELS[$status] ?? ($status ?: '-'));
    }

    protected function customerOrderDisplayStatusLabel(CustomerOrder $order): string
    {
        $order->loadMissing('novaPoshtaShipment');

        if (
            $order->status !== CustomerOrder::STATUS_REFUSED
            && $order->delivery_method === CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA
            && $order->novaPoshtaShipment?->np_status
        ) {
            return $this->novaPoshtaStatusDisplayLabel((string) $order->novaPoshtaShipment->np_status);
        }

        return $this->customerOrderStatusLabel($order->status);
    }

    protected function customerOrderDisplayStatusClass(CustomerOrder $order): string
    {
        $order->loadMissing('novaPoshtaShipment');

        if (
            $order->delivery_method === CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA
            && $this->novaPoshtaStatusIsSenderCreated($order->novaPoshtaShipment?->np_status)
        ) {
            return 'tag-warning';
        }

        if (
            $order->delivery_method === CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA
            && $order->novaPoshtaShipment?->np_status_code === CustomerOrder::NOVA_POSHTA_STATUS_RECEIVED
        ) {
            return 'tag-paid';
        }

        return match ($order->status) {
            CustomerOrder::STATUS_WAITING_PREPAYMENT,
            CustomerOrder::STATUS_SHIPPED => 'tag-warning',
            CustomerOrder::STATUS_CANCELLED,
            CustomerOrder::STATUS_REFUSED => 'tag-danger',
            CustomerOrder::STATUS_COMPLETED,
            CustomerOrder::STATUS_PAID => 'tag-paid',
            default => '',
        };
    }

    protected function novaPoshtaStatusDisplayLabel(?string $status): string
    {
        $status = CatalogTextEncoding::repair(trim((string) $status));

        if ($this->novaPoshtaStatusIsSenderCreated($status)) {
            return "\u{041E}\u{0447}\u{0456}\u{043A}\u{0443}\u{0454} \u{0434}\u{043E}\u{0441}\u{0442}\u{0430}\u{0432}\u{043A}\u{0443} \u{0442}\u{043E}\u{0432}\u{0430}\u{0440}\u{0430} \u{043D}\u{0430} \u{041D}\u{043E}\u{0432}\u{0443} \u{043F}\u{043E}\u{0448}\u{0442}\u{0443}.";
        }

        return $status;
    }

    protected function novaPoshtaStatusIsSenderCreated(?string $status): bool
    {
        $status = CatalogTextEncoding::repair(trim((string) $status));
        $senderCreatedInvoiceStatus = "\u{0412}\u{0456}\u{0434}\u{043F}\u{0440}\u{0430}\u{0432}\u{043D}\u{0438}\u{043A} \u{0441}\u{0430}\u{043C}\u{043E}\u{0441}\u{0442}\u{0456}\u{0439}\u{043D}\u{043E} \u{0441}\u{0442}\u{0432}\u{043E}\u{0440}\u{0438}\u{0432} \u{0446}\u{044E} \u{043D}\u{0430}\u{043A}\u{043B}\u{0430}\u{0434}\u{043D}\u{0443}, \u{0430}\u{043B}\u{0435} \u{0449}\u{0435} \u{043D}\u{0435} \u{043D}\u{0430}\u{0434}\u{0430}\u{0432} \u{0434}\u{043E} \u{0432}\u{0456}\u{0434}\u{043F}\u{0440}\u{0430}\u{0432}\u{043A}\u{0438}";

        return $status !== '' && str_contains(mb_strtolower($status), mb_strtolower($senderCreatedInvoiceStatus));
    }

    protected function customerOrderDeliveryMethodLabel(?string $deliveryMethod): string
    {
        return CatalogTextEncoding::repair(CustomerOrder::DELIVERY_METHOD_LABELS[$deliveryMethod] ?? ($deliveryMethod ?: '-'));
    }

    protected function ensureCustomerOrderCanBeEdited(CustomerOrder $order): void
    {
        if ($order->canBeEdited()) {
            return;
        }

        throw ValidationException::withMessages([
            'order' => "\u{041E}\u{0442}\u{043C}\u{0435}\u{043D}\u{0435}\u{043D}\u{043D}\u{044B}\u{0439} \u{0437}\u{0430}\u{043A}\u{0430}\u{0437} \u{043D}\u{0435}\u{043B}\u{044C}\u{0437}\u{044F} \u{0440}\u{0435}\u{0434}\u{0430}\u{043A}\u{0442}\u{0438}\u{0440}\u{043E}\u{0432}\u{0430}\u{0442}\u{044C}.",
        ]);
    }

    protected function ensureCustomerOrderItemsCanBeChanged(CustomerOrder $order): void
    {
        if ((float) $order->paid_amount_uah <= 0) {
            return;
        }

        throw ValidationException::withMessages([
            'order' => "\u{0422}\u{043E}\u{0432}\u{0430}\u{0440}\u{044B} \u{0437}\u{0430}\u{043A}\u{0430}\u{0437}\u{0430} \u{043D}\u{0435}\u{043B}\u{044C}\u{0437}\u{044F} \u{0438}\u{0437}\u{043C}\u{0435}\u{043D}\u{044F}\u{0442}\u{044C} \u{043F}\u{043E}\u{0441}\u{043B}\u{0435} \u{043F}\u{0440}\u{0435}\u{0434}\u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{044B}.",
        ]);
    }

    protected function customerOrderUsdRate(CustomerOrder $order, ExchangeRateService $exchangeRateService, bool $fetchExactRate = false): array
    {
        $date = $order->created_at ?: now();

        return $fetchExactRate
            ? $exchangeRateService->usdRateForDate($date)
            : $exchangeRateService->displayUsdRate($date);
    }

    protected function customerOrderItemUnitPriceUah(CustomerOrderItem $item, ExchangeRateService $exchangeRateService, array $usdRate): float
    {
        if (strtoupper((string) $item->currency) === 'UAH') {
            return round((float) $item->unit_price, 2);
        }

        return $exchangeRateService->productSellingPriceUahRoundedToTen(
            (float) $item->unit_price,
            $item->currency,
            $usdRate,
        );
    }

    protected function customerOrderItemTotalPriceUah(CustomerOrderItem $item, ExchangeRateService $exchangeRateService, array $usdRate): float
    {
        if (strtoupper((string) $item->currency) === 'UAH') {
            return round((float) $item->total_price, 2);
        }

        return round((float) $item->quantity * $this->customerOrderItemUnitPriceUah($item, $exchangeRateService, $usdRate), 2);
    }

    protected function customerOrderTotalAmountUah(CustomerOrder $order, ExchangeRateService $exchangeRateService, array $usdRate): float
    {
        if ($order->items->isNotEmpty()) {
            return round($order->items->sum(fn (CustomerOrderItem $item): float => $this->customerOrderItemTotalPriceUah($item, $exchangeRateService, $usdRate)), 2);
        }

        return $exchangeRateService->productSellingPriceUahRoundedToTen(
            (float) $order->total_amount,
            $order->currency,
            $usdRate,
        );
    }

    protected function customerOrderIsFullyPaid(CustomerOrder $order, ExchangeRateService $exchangeRateService): bool
    {
        $order->loadMissing('items');

        return $this->customerOrderPaymentCoversTotal(
            (float) $order->paid_amount_uah,
            $this->customerOrderTotalAmountUah(
                $order,
                $exchangeRateService,
                $this->customerOrderUsdRate($order, $exchangeRateService),
            ),
        );
    }

    protected function customerOrderPaymentCoversTotal(float $paidAmountUah, float $totalAmountUah): bool
    {
        return round($paidAmountUah) >= round($totalAmountUah);
    }

    protected function novaPoshtaAfterpaymentAmount(CustomerOrder $order): float
    {
        return max(0, round((float) $order->total_amount - (float) $order->paid_amount_uah, 2));
    }

    protected function validateNovaPoshtaPackage(Request $request): array
    {
        return $request->validate([
            'nova_poshta_seats_amount' => ['required', 'integer', 'min:1', 'max:99'],
            'nova_poshta_weight' => ['required', 'numeric', 'min:0.1', 'max:1000'],
            'nova_poshta_length_cm' => ['required', 'integer', 'min:1', 'max:300'],
            'nova_poshta_width_cm' => ['required', 'integer', 'min:1', 'max:300'],
            'nova_poshta_height_cm' => ['required', 'integer', 'min:1', 'max:300'],
        ]);
    }

    protected function customerOrderPaymentDueUsd(CustomerOrder $order, float $paymentDueUah, array $usdRate): ?float
    {
        $paymentDueUah = max(0, round($paymentDueUah, 2));
        $rate = (float) ($usdRate['rate'] ?? 0);

        if ($order->total_amount_usd_hint === null) {
            return $rate > 0 ? round($paymentDueUah / $rate, 2) : null;
        }

        $paidNonUsdUah = (float) $order->paid_cash_uah
            + (float) $order->paid_bank_tov_uah
            + (float) $order->paid_bank_fop_uah
            + (float) $order->paid_prom_uah;
        $paidNonUsd = $rate > 0 ? $paidNonUsdUah / $rate : 0.0;

        return max(0, round((float) $order->total_amount_usd_hint - (float) $order->paid_cash_usd - $paidNonUsd, 2));
    }

    protected function customerOrderPaymentConversionUsdRate(CustomerOrder $order, float $paymentDueUah, ?float $paymentDueUsd, array $fallbackUsdRate): array
    {
        $paymentDueUah = max(0, round($paymentDueUah, 2));
        $paymentDueUsd = $paymentDueUsd !== null ? max(0, round($paymentDueUsd, 2)) : null;

        if ($order->total_amount_usd_hint !== null && $paymentDueUah > 0 && $paymentDueUsd !== null && $paymentDueUsd > 0) {
            $rate = round($paymentDueUah / $paymentDueUsd, 6);

            return [
                'rate' => $rate,
                'source' => 'customer_order_due',
                'source_label' => 'customer order due',
                'date' => now()->toDateString(),
                'label' => '$ '.number_format($rate, 2, '.', ' '),
            ];
        }

        return $fallbackUsdRate;
    }

    protected function customerOrderCashSummary(): array
    {
        $summary = CustomerOrder::query()
            ->whereNotIn('status', [
                CustomerOrder::STATUS_CANCELLED,
                CustomerOrder::STATUS_REFUSED,
            ])
            ->where(function (Builder $query): void {
                $query
                    ->where('delivery_method', '!=', CustomerOrder::DELIVERY_METHOD_STO)
                    ->orWhereNull('delivery_method');
            })
            ->selectRaw('COALESCE(SUM(paid_cash_uah), 0) as cash_uah')
            ->selectRaw('COALESCE(SUM(paid_cash_usd), 0) as cash_usd')
            ->selectRaw('COALESCE(SUM(paid_bank_tov_uah), 0) as bank_tov_uah')
            ->selectRaw('COALESCE(SUM(paid_bank_fop_uah), 0) as bank_fop_uah')
            ->first();
        $bankFopAfterpaymentUah = $this->customerOrderBankFopAfterpaymentTotal();
        $bankFopAfterpaymentCommissionUah = round($bankFopAfterpaymentUah * self::NOVA_POSHTA_AFTERPAYMENT_COMMISSION_RATE, 2);

        $promUah = CustomerOrder::query()
            ->issuedToClient()
            ->where(function (Builder $query): void {
                $query
                    ->where('delivery_method', '!=', CustomerOrder::DELIVERY_METHOD_STO)
                    ->orWhereNull('delivery_method');
            })
            ->sum('paid_prom_uah');
        $promPendingUah = CustomerOrder::query()
            ->whereNotIn('status', [
                CustomerOrder::STATUS_CANCELLED,
                CustomerOrder::STATUS_REFUSED,
            ])
            ->where('paid_prom_uah', '>', 0)
            ->where(function (Builder $query): void {
                $query
                    ->where('delivery_method', '!=', CustomerOrder::DELIVERY_METHOD_STO)
                    ->orWhereNull('delivery_method');
            })
            ->where(function (Builder $query): void {
                $query
                    ->where('status', '!=', CustomerOrder::STATUS_COMPLETED)
                    ->where(function (Builder $query): void {
                        $query
                            ->where('status', '!=', CustomerOrder::STATUS_PAID)
                            ->orWhere('delivery_method', '!=', CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA)
                            ->orWhereNull('delivery_method');
                    });
            })
            ->sum('paid_prom_uah');

        return [
            CustomerOrder::PAYMENT_TYPE_CASH_UAH => (float) ($summary?->cash_uah ?? 0),
            CustomerOrder::PAYMENT_TYPE_CASH_USD => (float) ($summary?->cash_usd ?? 0),
            CustomerOrder::PAYMENT_TYPE_BANK_TOV => (float) ($summary?->bank_tov_uah ?? 0),
            CustomerOrder::PAYMENT_TYPE_BANK_FOP => max(0.0, round((float) ($summary?->bank_fop_uah ?? 0) - $bankFopAfterpaymentCommissionUah, 2)),
            CustomerOrder::PAYMENT_TYPE_BANK_FOP_AFTERPAYMENT => $bankFopAfterpaymentUah,
            'bank_fop_afterpayment_commission_uah' => $bankFopAfterpaymentCommissionUah,
            CustomerOrder::PAYMENT_TYPE_PROM => (float) $promUah,
            'prom_pending_uah' => (float) $promPendingUah,
            'sto_parts_uah' => (float) CustomerOrder::query()
                ->where('delivery_method', CustomerOrder::DELIVERY_METHOD_STO)
                ->where('status', CustomerOrder::STATUS_COMPLETED)
                ->sum('total_amount'),
        ];
    }

    protected function customerOrderBankFopAfterpaymentTotal(): float
    {
        $events = CustomerOrderHistoryEvent::query()
            ->whereIn('event_type', ['prepayment_received', 'payment_confirmed'])
            ->whereHas('order', function (Builder $query): void {
                $query
                    ->whereNotIn('status', [
                        CustomerOrder::STATUS_CANCELLED,
                        CustomerOrder::STATUS_REFUSED,
                    ])
                    ->where(function (Builder $query): void {
                        $query
                            ->where('delivery_method', '!=', CustomerOrder::DELIVERY_METHOD_STO)
                            ->orWhereNull('delivery_method');
                    });
            })
            ->get();

        $deletedEventIds = CustomerOrderHistoryEvent::query()
            ->where('event_type', 'prepayment_deleted')
            ->pluck('new_values')
            ->map(function ($values): int {
                $values = is_string($values) ? json_decode($values, true) : $values;

                return (int) data_get($values, 'deleted_event_id');
            })
            ->filter()
            ->all();

        return round($events
            ->filter(fn (CustomerOrderHistoryEvent $event): bool => data_get($event->new_values, 'payment_type') === CustomerOrder::PAYMENT_TYPE_BANK_FOP_AFTERPAYMENT
                || (bool) data_get($event->new_values, 'is_afterpayment'))
            ->reject(fn (CustomerOrderHistoryEvent $event): bool => in_array($event->id, $deletedEventIds, true))
            ->sum(fn (CustomerOrderHistoryEvent $event): float => (float) data_get($event->new_values, 'payment_received_amount_uah', 0)), 2);
    }

    protected function refreshCatalogItemReservationProjection(PartCatalogItem $catalogItem): void
    {
        app(CustomerOrderReservationProjectionService::class)->refresh($catalogItem);
    }

    protected function refreshProductReservationProjection(Product $product): void
    {
        app(CustomerOrderReservationProjectionService::class)->refresh($product);
    }

    protected function returnProductToStockLocation(Product $product, CustomerOrder $order, Location $location): void
    {
        $location->loadMissing('warehouse');
        $quantity = max(1, (int) ceil((float) $order->items
            ->where('product_id', $product->id)
            ->sum(fn (CustomerOrderItem $item): float => (float) $item->quantity)));

        $product->load('stockItems');
        $targetStockItem = $product->stockItems
            ->first(fn (StockItem $stockItem): bool => (int) $stockItem->location_id === (int) $location->id);

        if (! $targetStockItem instanceof StockItem) {
            $targetStockItem = StockItem::query()->firstOrNew([
                'product_id' => $product->id,
                'location_id' => $location->id,
                'testing_status' => $product->testing_status ?: 'not_tested',
            ]);
            $targetStockItem->warehouse_id = $location->warehouse_id;
            $targetStockItem->quantity = (int) $targetStockItem->quantity;
            $targetStockItem->reserved_quantity = (int) $targetStockItem->reserved_quantity;
            $targetStockItem->received_at ??= now();
            $targetStockItem->save();
        }

        $product->load('stockItems');
        $remainingToMove = max(0, $quantity - (int) $product->stockItems
            ->where('location_id', $location->id)
            ->sum('quantity'));

        if ($remainingToMove > 0) {
            $product->stockItems
                ->reject(fn (StockItem $stockItem): bool => (int) $stockItem->location_id === (int) $location->id)
                ->sortByDesc('available_quantity')
                ->each(function (StockItem $stockItem) use (&$remainingToMove, $location, $order): void {
                    if ($remainingToMove <= 0) {
                        return;
                    }

                    $moveQuantity = min($remainingToMove, (int) $stockItem->available_quantity);

                    if ($moveQuantity <= 0) {
                        return;
                    }

                    app(StockService::class)->move($stockItem, $moveQuantity, (int) $location->id, [
                        'document_number' => $order->number,
                        'comment' => 'Nova Poshta refused order returned to selected stock location.',
                    ]);

                    $remainingToMove -= $moveQuantity;
                });
        }

        $targetStockItem = StockItem::query()
            ->where('product_id', $product->id)
            ->where('location_id', $location->id)
            ->orderByDesc('id')
            ->first();

        if ($targetStockItem instanceof StockItem && (int) $targetStockItem->quantity < $quantity) {
            app(StockService::class)->adjust($targetStockItem, $quantity, [
                'reason' => 'customer_order_return_received',
                'document_number' => $order->number,
                'comment' => 'Nova Poshta refused order returned to stock.',
            ]);
        }

        $product->forceFill([
            'storage_status' => $location->warehouse?->type === Warehouse::TYPE_DONOR
                ? Product::STORAGE_STATUS_ON_DONOR
                : Product::STORAGE_STATUS_IN_STOCK,
            'is_active' => true,
        ])->save();

        app(NikolaCarsProductInventorySyncService::class)->syncProduct($product->refresh());
    }

    protected function activeReturnToStockWarehouses(): Collection
    {
        return Warehouse::query()
            ->where('is_active', true)
            ->where('type', '!=', Warehouse::TYPE_DONOR)
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'floor_count', 'is_active']);
    }

    protected function activeReturnToStockLocations(): Collection
    {
        return Location::query()
            ->with('warehouse:id,name,type,floor_count,is_active')
            ->where('is_active', true)
            ->whereHas('warehouse', fn (Builder $query) => $query
                ->where('is_active', true)
                ->where('type', '!=', Warehouse::TYPE_DONOR))
            ->orderBy('warehouse_id')
            ->orderBy('floor')
            ->orderBy('full_code')
            ->get(['id', 'warehouse_id', 'floor', 'cell', 'full_code', 'is_active']);
    }

    protected function returnToStockWarehouseOptions(Collection $warehouses): array
    {
        return $warehouses
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->map(fn (Warehouse $warehouse): array => [
                'id' => $warehouse->id,
                'name' => $warehouse->name,
                'type' => $warehouse->type,
                'floor_count' => $warehouse->floor_count,
                'uses_structured_locations' => $warehouse->usesStructuredLocations(),
                'floors' => collect($warehouse->availableFloors())
                    ->map(fn (string $label, string $value): array => [
                        'value' => $value,
                        'label' => $label,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    protected function returnToStockLocationOptions(Collection $locations): array
    {
        return $locations
            ->map(fn (Location $location): array => [
                'id' => $location->id,
                'warehouse_id' => $location->warehouse_id,
                'floor' => $this->normalizedReturnLocationFloor($location),
                'floor_label' => $location->floorLabel(),
                'label' => $this->returnLocationDisplayCode($location),
            ])
            ->values()
            ->all();
    }

    protected function returnToStockDefaults(Collection $orders, Collection $locations): Collection
    {
        return $orders->mapWithKeys(function (CustomerOrder $order) use ($locations): array {
            $location = $this->defaultReturnToStockLocation($order, $locations);

            return [
                $order->id => [
                    'warehouse_id' => $location?->warehouse_id,
                    'floor' => $location instanceof Location ? $this->normalizedReturnLocationFloor($location) : null,
                    'location_id' => $location?->id,
                    'label' => $location instanceof Location ? $this->returnLocationDisplayCode($location) : null,
                ],
            ];
        });
    }

    protected function defaultReturnToStockLocation(CustomerOrder $order, Collection $locations): ?Location
    {
        $order->loadMissing('items.product.stockItems.location');

        $stockLocationId = $order->items
            ->pluck('product.stockItems')
            ->flatten()
            ->filter(fn ($stockItem): bool => $stockItem instanceof StockItem)
            ->sortByDesc(fn (StockItem $stockItem): int => (int) $stockItem->id)
            ->pluck('location_id')
            ->filter()
            ->first();

        if ($stockLocationId !== null) {
            $location = $locations->first(fn (Location $location): bool => (int) $location->id === (int) $stockLocationId);

            if ($location instanceof Location) {
                return $location;
            }

            return null;
        }

        return $locations->first();
    }

    protected function normalizedReturnLocationFloor(Location $location): string
    {
        return is_string($location->floor) && $location->floor !== '' ? $location->floor : 'floor_1';
    }

    protected function returnLocationDisplayCode(Location $location): string
    {
        return trim((string) ($location->cell ?: $location->full_code)) ?: "\u{0411}\u{0435}\u{0437} \u{044F}\u{0447}\u{0435}\u{0439}\u{043A}\u{0438}";
    }

    protected function reservedInventoryIds(CustomerOrder $order): array
    {
        $items = $order->items()
            ->select(['part_catalog_item_id', 'product_id'])
            ->get();

        return [
            $items->pluck('part_catalog_item_id')->filter()->unique()->values(),
            $items->pluck('product_id')->filter()->unique()->values(),
        ];
    }

    protected function applySearch(Builder $builder, string $query): void
    {
        $builder->where(function (Builder $search) use ($query): void {
            $search
                ->where('number', 'like', "%{$query}%")
                ->orWhere('client_phone', 'like', "%{$query}%")
                ->orWhere('client_first_name', 'like', "%{$query}%")
                ->orWhere('client_last_name', 'like', "%{$query}%")
                ->orWhereHas('items', fn (Builder $items) => $items
                    ->where('name', 'like', "%{$query}%")
                    ->orWhere('part_number', 'like', "%{$query}%")
                    ->orWhere('code', 'like', "%{$query}%")
                    ->orWhere('donor_vin', 'like', "%{$query}%"));
        });
    }

    protected function findOrCreateCounterparty(string $phone, string $firstName, string $lastName): ?Counterparty
    {
        if ($phone === '') {
            if ($firstName === '' && $lastName === '') {
                return $this->anonymousCounterparty();
            }

            return null;
        }

        $digits = $this->phoneDigits($phone);
        $name = trim(collect([$firstName, $lastName])->filter()->implode(' '));
        $name = $name !== '' ? $name : $phone;

        $existing = Counterparty::query()
            ->whereIn('type', [Counterparty::TYPE_STO_CUSTOMER, Counterparty::TYPE_PARTS, Counterparty::TYPE_BOTH])
            ->whereNotNull('phone')
            ->get(['id', 'phone'])
            ->first(fn (Counterparty $counterparty): bool => $digits !== '' && $this->phoneDigits((string) $counterparty->phone) === $digits);

        if ($existing) {
            if ($name !== $phone) {
                $existing->forceFill(['name' => $name])->save();
            }

            return $existing;
        }

        return Counterparty::query()->create([
            'type' => Counterparty::TYPE_PARTS,
            'name' => $name,
            'phone' => $phone,
            'is_active' => true,
        ]);
    }

    protected function anonymousCounterparty(): Counterparty
    {
        $counterparty = Counterparty::query()->find(Counterparty::ANONYMOUS_ID)
            ?? Counterparty::query()->where('name', Counterparty::ANONYMOUS_NAME)->first()
            ?? new Counterparty;

        if ($counterparty->exists && (int) $counterparty->id === Counterparty::ANONYMOUS_ID && $counterparty->name !== Counterparty::ANONYMOUS_NAME) {
            throw new RuntimeException('Anonymous counterparty id is already occupied.');
        }

        $counterparty->forceFill([
            'id' => Counterparty::ANONYMOUS_ID,
            'type' => Counterparty::TYPE_PARTS,
            'name' => Counterparty::ANONYMOUS_NAME,
            'phone' => Counterparty::ANONYMOUS_PHONE,
            'is_active' => true,
        ])->save();

        return $counterparty;
    }

    protected function stoNikolaCarsCounterparty(): Counterparty
    {
        $counterparty = Counterparty::query()
            ->where('name', Counterparty::STO_NIKOLACARS_NAME)
            ->first() ?? new Counterparty;

        $counterparty->forceFill([
            'type' => Counterparty::TYPE_PARTS,
            'name' => Counterparty::STO_NIKOLACARS_NAME,
            'phone' => null,
            'is_active' => true,
        ])->save();

        return $counterparty;
    }

    protected function phoneDigits(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    protected function isValidUkrainianMobilePhone(string $phone): bool
    {
        $digits = $this->phoneDigits($phone);

        if (Str::startsWith($digits, '380')) {
            $digits = '0'.substr($digits, 3);
        }

        if (! preg_match('/^0\d{9}$/', $digits)) {
            return false;
        }

        $operatorCode = substr($digits, 1, 2);
        $mobileOperatorCodes = [
            '39', '50', '63', '66', '67', '68', '73', '77', '89',
            '91', '92', '93', '94', '95', '96', '97', '98', '99',
        ];

        return in_array($operatorCode, $mobileOperatorCodes, true);
    }

    protected function nikolaCarsProductUrl(PartCatalogItem $item): ?string
    {
        $product = $this->nikolaCarsProduct($item);

        return $product instanceof Product
            ? route('admin.products.show', $product)
            : null;
    }

    protected function nikolaCarsProduct(PartCatalogItem $item): ?Product
    {
        $productId = (int) data_get($item->raw_attributes, 'product_id');

        if ($productId > 0) {
            $product = Product::query()->find($productId);

            if ($product instanceof Product) {
                return $product;
            }
        }

        $product = Product::query()
            ->where('source_part_catalog_item_id', $item->id)
            ->orderBy('id')
            ->first();

        return $product instanceof Product ? $product : null;
    }

    protected function productAndCatalogItemFromRequest(array $validated): array
    {
        $product = isset($validated['product_id'])
            ? Product::query()->with(['donorCar', 'category', 'sourcePartCatalogItem'])->findOrFail((int) $validated['product_id'])
            : null;
        $catalogItem = isset($validated['part_catalog_item_id'])
            ? PartCatalogItem::query()->where('source', 'nikolacars')->findOrFail((int) $validated['part_catalog_item_id'])
            : null;

        if (! $product instanceof Product && $catalogItem instanceof PartCatalogItem) {
            $product = $this->nikolaCarsProduct($catalogItem);
        }

        if ($product instanceof Product) {
            $product->loadMissing(['donorCar', 'category', 'sourcePartCatalogItem']);
            if (! $this->productCanBeOrdered($product)) {
                throw ValidationException::withMessages([
                    'product_id' => 'Product is no longer available.',
                ]);
            }

            $sourceItem = $product->sourcePartCatalogItem;
            $catalogItem = $sourceItem?->source === 'nikolacars'
                ? $sourceItem
                : ($catalogItem instanceof PartCatalogItem && $this->catalogItemBelongsToProduct($catalogItem, $product) ? $catalogItem : null);
        } elseif ($catalogItem instanceof PartCatalogItem && ! $this->catalogItemCanBeOrdered($catalogItem)) {
            throw ValidationException::withMessages([
                'part_catalog_item_id' => 'Catalog item is no longer available.',
            ]);
        }

        return [$product, $catalogItem];
    }

    protected function productAndCatalogItemFromCartItem(array $item): array
    {
        $productId = $this->productIdFromCartItemUrl($item) ?? (isset($item['id']) ? (int) $item['id'] : null);
        $product = $productId
            ? Product::query()->with(['donorCar', 'category', 'sourcePartCatalogItem'])->find($productId)
            : null;
        $catalogItem = null;

        if ($product instanceof Product) {
            $sourceItem = $product->sourcePartCatalogItem;
            $catalogItem = $sourceItem?->source === 'nikolacars' ? $sourceItem : null;

            if (! $this->productMatchesCartItem($product, $item) || ! $this->productCanBeOrdered($product)) {
                throw ValidationException::withMessages([
                    'items' => $this->staleCartItemMessage($item),
                ]);
            }

            return [$product, $catalogItem];
        }

        $catalogItem = isset($item['id'])
            ? PartCatalogItem::query()->where('source', 'nikolacars')->find((int) $item['id'])
            : null;

        if ($catalogItem instanceof PartCatalogItem) {
            $product = $this->nikolaCarsProduct($catalogItem);

            if ($product instanceof Product) {
                $product->loadMissing(['donorCar', 'category', 'sourcePartCatalogItem']);

                if (! $this->productMatchesCartItem($product, $item) || ! $this->productCanBeOrdered($product)) {
                    throw ValidationException::withMessages([
                        'items' => $this->staleCartItemMessage($item),
                    ]);
                }

                return [$product, $catalogItem];
            }

            if (! $this->catalogItemMatchesCartItem($catalogItem, $item) || ! $this->catalogItemCanBeOrdered($catalogItem)) {
                throw ValidationException::withMessages([
                    'items' => $this->staleCartItemMessage($item),
                ]);
            }

            return [null, $catalogItem];
        }

        if (isset($item['id']) || trim((string) ($item['url'] ?? '')) !== '') {
            throw ValidationException::withMessages([
                'items' => $this->staleCartItemMessage($item),
            ]);
        }

        return [null, null];
    }

    protected function productMatchesCartItem(Product $product, array $item): bool
    {
        $productId = $this->productIdFromCartItemUrl($item);
        if ($productId !== null) {
            return (int) $product->id === $productId;
        }

        $cartCode = trim((string) ($item['code'] ?? ''));
        $productCode = trim((string) $product->sku);
        if ($cartCode !== '' && $productCode !== '' && $cartCode !== $productCode) {
            return false;
        }

        $cartPartNumber = trim((string) ($item['part_number'] ?? ''));
        $productPartNumber = trim((string) $product->external_sku);
        if ($cartPartNumber !== '' && $productPartNumber !== '' && ! $this->cartPartNumbersMatch($cartPartNumber, $productPartNumber)) {
            return false;
        }

        return true;
    }

    protected function productCanBeOrdered(Product $product): bool
    {
        $product->loadMissing(['donorCar', 'stockItems']);

        if (! app(NikolaCarsProductInventorySyncService::class)->isSellableProduct($product)) {
            return false;
        }

        if ($this->productHasZeroSalePrice($product)) {
            return false;
        }

        return $this->productAvailableForCustomerOrder($product) > 0.0;
    }

    protected function productHasZeroSalePrice(Product $product): bool
    {
        if ($product->selling_price !== null) {
            return (float) $product->selling_price <= 0.0;
        }

        $sourceItem = $product->sourcePartCatalogItem;

        return $sourceItem?->source === 'nikolacars'
            && $this->catalogItemHasZeroSalePrice($sourceItem);
    }

    protected function productStockQuantity(Product $product): float
    {
        $product->loadMissing('stockItems');
        $availableQuantity = $product->stockItems->sum(function ($stockItem): float {
            return max(0.0, round((float) $stockItem->quantity - (float) $stockItem->reserved_quantity, 3));
        });

        if ($availableQuantity > 0) {
            return round($availableQuantity, 3);
        }

        $stockQuantity = (float) $product->stockItems->sum('quantity');

        if ($product->stockItems->isNotEmpty()) {
            return round($stockQuantity, 3);
        }

        return 0.0;
    }

    protected function productPriceAmountUsd(Product $product, array $usdRate): ?float
    {
        if ($product->selling_price === null) {
            return null;
        }

        $currency = strtoupper((string) ($product->currency ?: 'USD'));
        if ($currency === 'USD') {
            return round((float) $product->selling_price, 2);
        }

        $rate = (float) ($usdRate['rate'] ?? 0);

        return $currency === 'UAH' && $rate > 0
            ? round((float) $product->selling_price / $rate, 2)
            : null;
    }

    protected function productCategoryDisplay(?Product $product): ?string
    {
        if (! $product instanceof Product) {
            return null;
        }

        $sourceItem = $product->sourcePartCatalogItem;

        return collect([
            data_get($sourceItem?->raw_attributes, 'category_display'),
            data_get($sourceItem?->raw_attributes, 'category_path'),
            $sourceItem?->compatibility_text,
            $product->category?->name,
            $product->compatibility,
        ])->map(fn (mixed $value): string => trim((string) $value))->first(fn (string $value): bool => $value !== '') ?: null;
    }

    protected function productImageUrl(?Product $product): ?string
    {
        if (! $product instanceof Product) {
            return null;
        }

        return ProductPhotoNormalizer::productPhotos($product)->first() ?: null;
    }

    protected function customerOrderItemImageUrls(Collection $items): Collection
    {
        return $items->mapWithKeys(fn (CustomerOrderItem $item): array => [
            $item->id => $this->customerOrderItemImages($item)->all(),
        ]);
    }

    protected function customerOrderItemDisplayNames(Collection $items): Collection
    {
        return $items->mapWithKeys(fn (CustomerOrderItem $item): array => [
            $item->id => $this->customerOrderItemDisplayName($item),
        ]);
    }

    protected function customerOrderItemDisplayName(CustomerOrderItem $item): string
    {
        return $this->customerOrderItemName(
            $item->product instanceof Product ? $item->product : null,
            $this->customerOrderItemCatalogItemForDisplay($item),
            $item->name,
        );
    }

    protected function customerOrderItemCatalogItemForDisplay(CustomerOrderItem $item): ?PartCatalogItem
    {
        if ($item->product instanceof Product) {
            $productItem = $this->productNikolaCarsCatalogItem($item->product);

            if ($productItem instanceof PartCatalogItem) {
                return $productItem;
            }

            if ($item->partCatalogItem instanceof PartCatalogItem
                && $this->catalogItemBelongsToProduct($item->partCatalogItem, $item->product)) {
                return $item->partCatalogItem;
            }
        }

        return $item->partCatalogItem instanceof PartCatalogItem
            ? $item->partCatalogItem
            : null;
    }

    protected function customerOrderItemImages(CustomerOrderItem $item): Collection
    {
        $catalogItem = $this->customerOrderItemCatalogItemForDisplay($item);
        $productPhotos = $item->product instanceof Product
            ? ProductPhotoNormalizer::productPhotos($item->product)
            : collect();

        return collect([$item->image_url])
            ->merge($productPhotos)
            ->merge($catalogItem instanceof PartCatalogItem ? (array) data_get($catalogItem->raw_attributes, 'image_urls', []) : [])
            ->merge($catalogItem instanceof PartCatalogItem ? (array) data_get($catalogItem->raw_attributes, 'part_image_urls', []) : [])
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->reject(fn (string $value): bool => ProductPhotoNormalizer::isCatalogSchemeImage($value))
            ->unique(fn (string $value): string => ProductPhotoNormalizer::imageKey($value))
            ->map(fn (string $value): string => PublicStorageUrl::url($value) ?? $value)
            ->values();
    }

    protected function catalogItemMatchesCartItem(PartCatalogItem $catalogItem, array $item): bool
    {
        $productId = $this->productIdFromCartItemUrl($item);
        if ($productId !== null && $this->productIdFromCatalogItem($catalogItem) !== $productId) {
            return false;
        }

        $cartCode = trim((string) ($item['code'] ?? ''));
        $catalogCode = trim((string) data_get($catalogItem->raw_attributes, 'code', ''));
        if ($cartCode !== '' && $catalogCode !== '' && $cartCode !== $catalogCode) {
            return false;
        }

        $cartPartNumber = trim((string) ($item['part_number'] ?? ''));
        $catalogPartNumber = trim((string) $catalogItem->part_number);
        if ($cartPartNumber !== '' && $catalogPartNumber !== '' && ! $this->cartPartNumbersMatch($cartPartNumber, $catalogPartNumber)) {
            return false;
        }

        return true;
    }

    protected function catalogItemCanBeOrdered(PartCatalogItem $catalogItem): bool
    {
        if ($catalogItem->source !== 'nikolacars') {
            return false;
        }

        if ($this->catalogItemHasZeroSalePrice($catalogItem)) {
            return false;
        }

        if (app(NikolaCarsInventoryService::class)->isManuallySold($catalogItem)) {
            return false;
        }

        if (trim((string) $catalogItem->quality) === 'Разбит'
            || trim((string) data_get($catalogItem->raw_attributes, 'donor_damage_status')) === 'Разбит') {
            return false;
        }

        $storageStatus = trim((string) data_get($catalogItem->raw_attributes, 'storage_status'));
        if (in_array($storageStatus, [Product::STORAGE_STATUS_SOLD, Product::STORAGE_STATUS_WRITTEN_OFF], true)) {
            return false;
        }

        return $this->catalogItemAvailableForCustomerOrder($catalogItem) > 0.0;
    }

    protected function catalogItemHasZeroSalePrice(PartCatalogItem $catalogItem): bool
    {
        return $catalogItem->price_amount !== null && (float) $catalogItem->price_amount <= 0.0;
    }

    protected function orderableInventoryForRecreatedItem(CustomerOrderItem $item): array
    {
        $product = $item->product_id
            ? Product::query()->with(['donorCar', 'category', 'sourcePartCatalogItem', 'stockItems'])->find((int) $item->product_id)
            : null;
        $catalogItem = $item->part_catalog_item_id
            ? PartCatalogItem::query()->where('source', 'nikolacars')->find((int) $item->part_catalog_item_id)
            : null;

        if (! $product instanceof Product && $catalogItem instanceof PartCatalogItem) {
            $product = $this->nikolaCarsProduct($catalogItem);
            $product?->loadMissing(['donorCar', 'category', 'sourcePartCatalogItem', 'stockItems']);
        }

        if ($product instanceof Product) {
            $product->loadMissing(['donorCar', 'category', 'sourcePartCatalogItem', 'stockItems']);
            $sourceItem = $product->sourcePartCatalogItem;
            $catalogItem = $sourceItem?->source === 'nikolacars'
                ? $sourceItem
                : ($catalogItem instanceof PartCatalogItem && $this->catalogItemBelongsToProduct($catalogItem, $product) ? $catalogItem : null);

            if (! $this->productCanBeOrdered($product)) {
                throw ValidationException::withMessages([
                    'order' => $this->recreateUnavailableItemMessage($item),
                ]);
            }

            return [$product, $catalogItem];
        }

        if ($catalogItem instanceof PartCatalogItem && $this->catalogItemCanBeOrdered($catalogItem)) {
            return [null, $catalogItem];
        }

        throw ValidationException::withMessages([
            'order' => $this->recreateUnavailableItemMessage($item),
        ]);
    }

    protected function ensureRecreatedItemQuantityAvailable(CustomerOrderItem $item, ?Product $product, ?PartCatalogItem $catalogItem, float $quantity): void
    {
        $available = $product instanceof Product
            ? $this->productAvailableForCustomerOrder($product)
            : ($catalogItem instanceof PartCatalogItem ? $this->catalogItemAvailableForCustomerOrder($catalogItem) : 0.0);

        if ($available + 0.0001 >= $quantity) {
            return;
        }

        throw ValidationException::withMessages([
            'order' => sprintf(
                "\u{041D}\u{0435}\u{043B}\u{044C}\u{0437}\u{044F} \u{043F}\u{0435}\u{0440}\u{0435}\u{0441}\u{043E}\u{0437}\u{0434}\u{0430}\u{0442}\u{044C} \u{0437}\u{0430}\u{043A}\u{0430}\u{0437}: %s \u{0441}\u{0435}\u{0439}\u{0447}\u{0430}\u{0441} \u{0434}\u{043E}\u{0441}\u{0442}\u{0443}\u{043F}\u{043D}\u{043E} %s, \u{043D}\u{0443}\u{0436}\u{043D}\u{043E} %s.",
                $this->customerOrderItemHistoryName($item),
                $this->formatCustomerOrderQuantity($available),
                $this->formatCustomerOrderQuantity($quantity),
            ),
        ]);
    }

    protected function ensureCustomerOrderItemQuantityAvailable(
        ?Product $product,
        ?PartCatalogItem $catalogItem,
        float $quantity,
        string $field,
        ?array $cartItem = null,
    ): void {
        if (! $product instanceof Product && ! $catalogItem instanceof PartCatalogItem) {
            return;
        }

        $available = $product instanceof Product
            ? $this->productAvailableForCustomerOrder($product)
            : $this->catalogItemAvailableForCustomerOrder($catalogItem);

        if ($available + 0.0001 >= $quantity) {
            return;
        }

        $label = $cartItem !== null
            ? trim(collect([
                $cartItem['name'] ?? null,
                $cartItem['code'] ?? null,
                $cartItem['part_number'] ?? null,
            ])->filter()->implode(' / '))
            : $this->customerOrderItemName($product, $catalogItem);

        throw ValidationException::withMessages([
            $field => sprintf(
                "\u{041D}\u{0435}\u{0434}\u{043E}\u{0441}\u{0442}\u{0443}\u{043F}\u{043D}\u{043E}\u{0435} \u{043A}\u{043E}\u{043B}\u{0438}\u{0447}\u{0435}\u{0441}\u{0442}\u{0432}\u{043E}%s: \u{0441}\u{0435}\u{0439}\u{0447}\u{0430}\u{0441} \u{0434}\u{043E}\u{0441}\u{0442}\u{0443}\u{043F}\u{043D}\u{043E} %s, \u{043D}\u{0443}\u{0436}\u{043D}\u{043E} %s.",
                $label !== '' ? ' '.$label : '',
                $this->formatCustomerOrderQuantity($available),
                $this->formatCustomerOrderQuantity($quantity),
            ),
        ]);
    }

    protected function productAvailableForCustomerOrder(Product $product): float
    {
        $product->loadMissing(['sourcePartCatalogItem', 'stockItems']);
        $stockQuantity = round((float) $product->stockItems->sum('quantity'), 3);
        $catalogItem = $product->sourcePartCatalogItem?->source === 'nikolacars'
            ? $product->sourcePartCatalogItem
            : null;
        $stockItemReservedQuantity = round((float) $product->stockItems->sum('reserved_quantity'), 3);
        $customerOrderReservedQuantity = $this->reservedCustomerOrderQuantity($product, $catalogItem);

        return max(0.0, round($stockQuantity - max($stockItemReservedQuantity, $customerOrderReservedQuantity), 3));
    }

    protected function catalogItemAvailableForCustomerOrder(PartCatalogItem $catalogItem): float
    {
        $stockQuantity = app(NikolaCarsInventoryService::class)->inventoryQuantity(collect([$catalogItem]));

        return max(0.0, round($stockQuantity - $this->reservedCustomerOrderQuantity(null, $catalogItem), 3));
    }

    protected function reservedCustomerOrderQuantity(?Product $product, ?PartCatalogItem $catalogItem): float
    {
        if (! $product instanceof Product && ! $catalogItem instanceof PartCatalogItem) {
            return 0.0;
        }

        $reserved = CustomerOrderItem::query()
            ->whereHas('order', fn (Builder $query) => $query->reservable())
            ->where(function (Builder $query) use ($product, $catalogItem): void {
                if ($product instanceof Product) {
                    $query->where('product_id', $product->id);
                }

                if ($catalogItem instanceof PartCatalogItem) {
                    $method = $product instanceof Product ? 'orWhere' : 'where';
                    $query->{$method}('part_catalog_item_id', $catalogItem->id);
                }
            })
            ->sum('quantity');

        return round((float) $reserved, 3);
    }

    protected function recreatedCustomerOrderItemPricing(
        CustomerOrderItem $item,
        ?Product $product,
        ?PartCatalogItem $catalogItem,
        ExchangeRateService $exchangeRateService,
        array $usdRate,
    ): array {
        $priceAmount = $product?->selling_price ?? $catalogItem?->price_amount;
        $currency = $product?->currency ?? $catalogItem?->currency;
        $usdExchangeRate = (float) ($usdRate['rate'] ?? 0);
        $usdHint = $product instanceof Product
            ? $this->productPriceAmountUsd($product, $usdRate)
            : null;
        $usdHint ??= $catalogItem instanceof PartCatalogItem ? $catalogItem->priceAmountUsd($usdRate) : null;
        $usdHint ??= $item->unit_price_usd_hint !== null ? round((float) $item->unit_price_usd_hint, 2) : null;

        $unitPrice = $priceAmount !== null
            ? $exchangeRateService->productSellingPriceUahRoundedToTen((float) $priceAmount, $currency ?: 'USD', $usdRate)
            : ($usdHint !== null && $usdExchangeRate > 0
                ? $exchangeRateService->productSellingPriceUahRoundedToTen($usdHint, 'USD', $usdRate)
                : round((float) $item->unit_price, 2));
        $totalPrice = round((float) $item->quantity * $unitPrice, 2);
        $usdLineTotal = $usdHint !== null ? round((float) $item->quantity * $usdHint, 2) : null;

        return [$unitPrice, $totalPrice, $usdHint, $usdLineTotal];
    }

    protected function recreateUnavailableItemMessage(CustomerOrderItem $item): string
    {
        return sprintf(
            "\u{041D}\u{0435}\u{043B}\u{044C}\u{0437}\u{044F} \u{043F}\u{0435}\u{0440}\u{0435}\u{0441}\u{043E}\u{0437}\u{0434}\u{0430}\u{0442}\u{044C} \u{0437}\u{0430}\u{043A}\u{0430}\u{0437}: \u{0437}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{044C} %s \u{0443}\u{0436}\u{0435} \u{043D}\u{0435}\u{0434}\u{043E}\u{0441}\u{0442}\u{0443}\u{043F}\u{043D}\u{0430}.",
            $this->customerOrderItemHistoryName($item),
        );
    }

    protected function productIdFromCartItemUrl(array $item): ?int
    {
        $sourceUrl = trim((string) ($item['url'] ?? ''));
        if ($sourceUrl === '') {
            return null;
        }

        $path = (string) (parse_url($sourceUrl, PHP_URL_PATH) ?: $sourceUrl);

        return preg_match('~(?:^|/)admin/products/(\d+)(?:$|[/?#])~', $path, $matches) === 1
            ? (int) $matches[1]
            : null;
    }

    protected function productIdFromCatalogItem(PartCatalogItem $catalogItem): ?int
    {
        $productId = (int) data_get($catalogItem->raw_attributes, 'product_id');
        if ($productId > 0) {
            return $productId;
        }

        return preg_match('~^nikolacars://(?:donor-product|inventory-product)/(\d+)$~', (string) $catalogItem->source_url, $matches) === 1
            ? (int) $matches[1]
            : null;
    }

    protected function catalogItemBelongsToProduct(PartCatalogItem $catalogItem, Product $product): bool
    {
        if ((int) $product->source_part_catalog_item_id === (int) $catalogItem->id) {
            return true;
        }

        return (int) ($this->productIdFromCatalogItem($catalogItem) ?? 0) === (int) $product->id;
    }

    protected function cartPartNumbersMatch(string $cartPartNumber, string $catalogPartNumber): bool
    {
        $normalize = fn (string $value): string => Str::upper(str_replace(' ', '', trim($value)));
        $cartPartNumber = $normalize($cartPartNumber);
        $catalogPartNumber = $normalize($catalogPartNumber);

        if ($cartPartNumber === $catalogPartNumber) {
            return true;
        }

        return Str::before($cartPartNumber, '-') === Str::before($catalogPartNumber, '-');
    }

    protected function staleCartItemMessage(array $item): string
    {
        $label = trim(collect([
            $item['name'] ?? null,
            $item['code'] ?? null,
            $item['part_number'] ?? null,
        ])->filter()->implode(' / '));

        return 'Товар в корзине устарел или уже недоступен'.($label !== '' ? ': '.$label : '').'. Очистите корзину и добавьте товар заново.';
    }

    protected function customerOrderItemProductUrls(Collection $items): Collection
    {
        return $items->mapWithKeys(fn (CustomerOrderItem $item): array => [
            $item->id => $item->product instanceof Product
                ? route('admin.products.show', $item->product)
                : ($item->partCatalogItem instanceof PartCatalogItem
                ? $this->nikolaCarsProductUrl($item->partCatalogItem)
                : $this->customerOrderItemSourceProductUrl($item)),
        ]);
    }

    protected function customerOrderItemSourceProductUrl(CustomerOrderItem $item): ?string
    {
        $sourceUrl = trim((string) $item->source_url);
        if ($sourceUrl === '') {
            return null;
        }

        $path = (string) (parse_url($sourceUrl, PHP_URL_PATH) ?: $sourceUrl);
        if (preg_match('~(?:^|/)admin/products/(\d+)(?:$|[/?#])~', $path, $matches) !== 1) {
            return null;
        }

        $product = Product::query()->find((int) $matches[1]);

        return $product instanceof Product ? route('admin.products.show', $product) : null;
    }

    protected function customerOrderItemDisplayCodes(Collection $items): Collection
    {
        return $items->mapWithKeys(fn (CustomerOrderItem $item): array => [
            $item->id => $item->product instanceof Product
                ? ($item->product->sku ?: $item->code)
                : ($item->partCatalogItem instanceof PartCatalogItem
                ? ($this->nikolaCarsProduct($item->partCatalogItem)?->sku ?: $item->code)
                : $item->code),
        ]);
    }

    protected function customerOrderItemDisplayPartNumbers(Collection $items): Collection
    {
        return $items->mapWithKeys(fn (CustomerOrderItem $item): array => [
            $item->id => $this->customerOrderItemDisplayPartNumber($item),
        ]);
    }

    protected function customerOrderItemDisplayPartNumber(CustomerOrderItem $item): ?string
    {
        $product = $item->product instanceof Product ? $item->product : null;
        $catalogItem = $this->customerOrderItemCatalogItemForDisplay($item);

        $values = $product instanceof Product
            ? [$product->external_sku, $item->part_number, $catalogItem?->part_number]
            : [$catalogItem?->part_number, $item->part_number];

        foreach ($values as $value) {
            $partNumber = trim((string) $value);

            if ($partNumber !== '') {
                return $partNumber;
            }
        }

        return null;
    }

    protected function nextNumber(): string
    {
        $prefix = 'ORD-'.now()->format('Ymd').'-';
        $lastNumber = CustomerOrder::query()
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('number')
            ->value('number');

        $next = $lastNumber ? ((int) Str::afterLast($lastNumber, '-') + 1) : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
