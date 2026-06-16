<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Counterparty;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\PartCatalogItem;
use App\Models\Product;
use App\Services\CustomerOrderIssuedSaleService;
use App\Services\CustomerOrderReservationProjectionService;
use App\Services\ExchangeRateService;
use App\Services\NikolaCarsInventoryService;
use App\Services\NikolaCarsProductInventorySyncService;
use App\Support\CatalogTextEncoding;
use App\Support\PartCatalogRawAttributes;
use App\Support\ProductPhotoNormalizer;
use App\Support\PublicStorageUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CustomerOrderController extends Controller
{
    public function index(Request $request, ExchangeRateService $exchangeRateService): View
    {
        $query = trim((string) $request->query('q', ''));
        $tab = $request->query('tab') === 'cancelled' ? 'cancelled' : 'active';
        $paymentUsdRate = $exchangeRateService->currentUsdRate();
        $ordersQuery = CustomerOrder::query()
            ->with(['counterparty', 'creator.stoEmployee', 'items.partCatalogItem', 'items.product.sourcePartCatalogItem'])
            ->when($query !== '', fn (Builder $builder) => $this->applySearch($builder, $query));
        $orders = (clone $ordersQuery)
            ->when(
                $tab === 'cancelled',
                fn (Builder $builder) => $builder->where('status', CustomerOrder::STATUS_CANCELLED),
                fn (Builder $builder) => $builder->whereNotIn('status', [
                    CustomerOrder::STATUS_CANCELLED,
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
                }),
            )
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();
        $shippedNovaPoshtaOrders = $tab === 'active'
            ? (clone $ordersQuery)
                ->where('status', CustomerOrder::STATUS_SHIPPED)
                ->where('delivery_method', CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA)
                ->orderByDesc('id')
                ->limit(30)
                ->get()
            : collect();
        $completedOrders = $tab === 'active'
            ? (clone $ordersQuery)
                ->issuedToClient()
                ->orderByDesc('id')
                ->limit(30)
                ->get()
            : collect();
        $ordersForTotals = $orders->getCollection()
            ->concat($shippedNovaPoshtaOrders)
            ->concat($completedOrders);
        $orderUsdRates = $ordersForTotals
            ->mapWithKeys(fn (CustomerOrder $order): array => [
                $order->id => $this->customerOrderUsdRate($order, $exchangeRateService),
            ]);

        return view('admin.customer_orders.index', [
            'orders' => $orders,
            'shippedNovaPoshtaOrders' => $shippedNovaPoshtaOrders,
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
            'query' => $query,
            'tab' => $tab,
        ]);
    }

    public function show(CustomerOrder $customerOrder, ExchangeRateService $exchangeRateService): View
    {
        $order = $customerOrder->load(['counterparty', 'items.partCatalogItem', 'items.product.sourcePartCatalogItem', 'historyEvents.user']);
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

        $validated = $request->validate([
            'delivery_method' => ['required', Rule::in(array_keys(CustomerOrder::DELIVERY_METHOD_LABELS))],
        ]);

        $oldDeliveryMethod = $customerOrder->delivery_method;

        if ($oldDeliveryMethod !== $validated['delivery_method']) {
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

            $customerOrder->forceFill($payload)->save();

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

        return redirect()
            ->route('admin.customer-orders.show', $customerOrder)
            ->with('status', 'Способ получения обновлен.');
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

    public function updateStatus(Request $request, CustomerOrder $customerOrder, ExchangeRateService $exchangeRateService): RedirectResponse
    {
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
                'status' => "\u{0417}\u{0430}\u{0432}\u{0435}\u{0440}\u{0448}\u{0438}\u{0442}\u{044C} \u{043C}\u{043E}\u{0436}\u{043D}\u{043E} \u{0442}\u{043E}\u{043B}\u{044C}\u{043A}\u{043E} \u{043F}\u{043E}\u{043B}\u{043D}\u{043E}\u{0441}\u{0442}\u{044C}\u{044E} \u{043E}\u{043F}\u{043B}\u{0430}\u{0447}\u{0435}\u{043D}\u{043D}\u{044B}\u{0439} \u{0437}\u{0430}\u{043A}\u{0430}\u{0437} \u{0441} \u{0441}\u{0430}\u{043C}\u{043E}\u{0432}\u{044B}\u{0432}\u{043E}\u{0437}\u{043E}\u{043C}.",
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

        DB::transaction(function () use ($customerOrder, $validated): void {
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

        return redirect()
            ->back()
            ->with('status', $message);
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

        DB::transaction(function () use ($customerOrder, $paymentParts, $lastPaymentPart, $paymentType, $paymentLabel, $receivedAmount, $receivedAmountUah, $paidAmountUah, $shouldConfirmPayment): void {
            $oldStatus = $customerOrder->status;
            [$catalogItemIds, $productIds] = $this->reservedInventoryIds($customerOrder);

            $customerOrder->forceFill([
                'payment_type' => $lastPaymentPart['payment_type'],
                'payment_received_amount' => $lastPaymentPart['received_amount'],
                'payment_received_amount_uah' => $receivedAmountUah,
                'paid_cash_uah' => round((float) $customerOrder->paid_cash_uah + $paymentParts->where('payment_type', CustomerOrder::PAYMENT_TYPE_CASH_UAH)->sum('received_amount'), 2),
                'paid_cash_usd' => round((float) $customerOrder->paid_cash_usd + $paymentParts->where('payment_type', CustomerOrder::PAYMENT_TYPE_CASH_USD)->sum('received_amount'), 2),
                'paid_bank_tov_uah' => round((float) $customerOrder->paid_bank_tov_uah + $paymentParts->where('payment_type', CustomerOrder::PAYMENT_TYPE_BANK_TOV)->sum('received_amount'), 2),
                'paid_bank_fop_uah' => round((float) $customerOrder->paid_bank_fop_uah + $paymentParts->where('payment_type', CustomerOrder::PAYMENT_TYPE_BANK_FOP)->sum('received_amount'), 2),
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

        $order = DB::transaction(function () use ($validated, $firstName, $lastName, $phone, $deliveryMethod, $exchangeRateService, $usdRate, $usdExchangeRate): CustomerOrder {
            $counterparty = $deliveryMethod === CustomerOrder::DELIVERY_METHOD_STO
                ? $this->stoNikolaCarsCounterparty()
                : $this->findOrCreateCounterparty($phone, $firstName, $lastName);

            $order = CustomerOrder::query()->create([
                'number' => $this->nextNumber(),
                'status' => CustomerOrder::STATUS_PROCESSING,
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

    protected function customerOrderStatusLabel(?string $status): string
    {
        return CatalogTextEncoding::repair(CustomerOrder::STATUS_LABELS[$status] ?? ($status ?: '-'));
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

    protected function customerOrderPaymentDueUsd(CustomerOrder $order, float $paymentDueUah, array $usdRate): ?float
    {
        $paymentDueUah = max(0, round($paymentDueUah, 2));
        $rate = (float) ($usdRate['rate'] ?? 0);

        if ($order->total_amount_usd_hint === null) {
            return $rate > 0 ? round($paymentDueUah / $rate, 2) : null;
        }

        $paidNonUsdUah = (float) $order->paid_cash_uah
            + (float) $order->paid_bank_tov_uah
            + (float) $order->paid_bank_fop_uah;
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
            ->where('status', '!=', CustomerOrder::STATUS_CANCELLED)
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

        return [
            CustomerOrder::PAYMENT_TYPE_CASH_UAH => (float) ($summary?->cash_uah ?? 0),
            CustomerOrder::PAYMENT_TYPE_CASH_USD => (float) ($summary?->cash_usd ?? 0),
            CustomerOrder::PAYMENT_TYPE_BANK_TOV => (float) ($summary?->bank_tov_uah ?? 0),
            CustomerOrder::PAYMENT_TYPE_BANK_FOP => (float) ($summary?->bank_fop_uah ?? 0),
        ];
    }

    protected function refreshCatalogItemReservationProjection(PartCatalogItem $catalogItem): void
    {
        app(CustomerOrderReservationProjectionService::class)->refresh($catalogItem);
    }

    protected function refreshProductReservationProjection(Product $product): void
    {
        app(CustomerOrderReservationProjectionService::class)->refresh($product);
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
            throw new \RuntimeException('Anonymous counterparty id is already occupied.');
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
