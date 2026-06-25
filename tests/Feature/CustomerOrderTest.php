<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\CustomerOrderController;
use App\Models\Counterparty;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderHistoryEvent;
use App\Models\CustomerOrderShipment;
use App\Models\ExchangeRate;
use App\Models\Location;
use App\Models\PartCatalogItem;
use App\Models\PartSale;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\StockItem;
use App\Models\StoEmployee;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CustomerOrderIssuedSaleService;
use App\Services\NikolaCarsInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CustomerOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_order_can_be_created_from_cart_payload(): void
    {
        $user = $this->adminUser();
        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_date' => now()->toDateString(),
            'rate' => 41.25,
            'source' => 'monobank',
            'fetched_at' => now(),
        ]);
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://part/1',
            'part_number' => '1034344-20-B',
            'name' => 'Door handle',
            'raw_attributes' => [
                'code' => 'NC-1',
                'stock_quantity' => 2,
            ],
        ]);
        $product = Product::query()->create([
            'sku' => 'NC-1',
            'external_sku' => '1034344-20-B',
            'name' => 'Door handle',
            'slug' => 'door-handle-cart',
            'source_part_catalog_item_id' => $item->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'is_active' => true,
            'selling_price' => 150,
            'currency' => 'USD',
        ]);
        $stockItem = $this->createProductStockItem($product, 2);

        $response = $this->actingAs($user)->postJson(route('admin.customer-orders.store'), [
            'client_phone' => '+380501112233',
            'client_first_name' => 'Ivan',
            'client_last_name' => 'Petrov',
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA,
            'nova_poshta_city' => 'Kyiv',
            'nova_poshta_warehouse' => 'Warehouse 1',
            'nova_poshta_warehouse_ref' => 'warehouse-ref-1',
            'note' => 'Ship today',
            'items' => [[
                'id' => $product->id,
                'name' => 'Door handle',
                'part_number' => '1034344-20-B',
                'code' => 'NC-1',
                'vin' => '5YJSA1E20GF129213',
                'category' => 'Body',
                'quantity' => 2,
                'price' => 6190,
                'price_usd_hint' => 150,
            ]],
        ]);

        $response->assertCreated()->assertJsonPath('url', route('admin.customer-orders.show', CustomerOrder::query()->first()));

        $order = CustomerOrder::query()->with(['items', 'novaPoshtaShipment'])->firstOrFail();
        $this->assertSame('Ivan', $order->client_first_name);
        $this->assertSame('Petrov', $order->client_last_name);
        $this->assertSame(Counterparty::TYPE_PARTS, $order->counterparty?->type);
        $this->assertSame(CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA, $order->delivery_method);
        $this->assertSame(CustomerOrder::STATUS_WAITING_PREPAYMENT, $order->status);
        $this->assertSame("\u{0416}\u{0434}\u{0435}\u{043C} \u{043F}\u{0440}\u{0435}\u{0434}\u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0443}", $order->status_label);
        $this->assertNull($order->note);
        $this->assertSame('UAH', $order->currency);
        $this->assertSame('12380.00', $order->total_amount);
        $this->assertSame('Kyiv', $order->novaPoshtaShipment?->recipient_city_name);
        $this->assertSame('Warehouse 1', $order->novaPoshtaShipment?->recipient_warehouse_name);
        $this->assertSame('warehouse-ref-1', $order->novaPoshtaShipment?->recipient_warehouse_ref);
        $this->assertSame('draft', $order->novaPoshtaShipment?->status);
        $this->assertSame('Door handle', $order->items->first()->name);
        $this->assertSame($product->id, $order->items->first()->product_id);
        $this->assertSame($item->id, $order->items->first()->part_catalog_item_id);
        $this->assertSame('UAH', $order->items->first()->currency);
        $this->assertSame('6190.00', $order->items->first()->unit_price);
        $this->assertSame('12380.00', $order->items->first()->total_price);
        $this->assertSame('150.00', $order->items->first()->unit_price_usd_hint);
        $this->assertSame('300.00', $order->items->first()->total_price_usd_hint);
        $this->assertSame('41.250000', $order->items->first()->usd_exchange_rate);

        $item->refresh();
        $this->assertSame(2.0, (float) data_get($item->raw_attributes, 'reserved_quantity'));
        $this->assertContains($order->number, data_get($item->raw_attributes, 'reserved_orders', []));
        $this->assertSame(2, $stockItem->refresh()->reserved_quantity);
        $this->assertSame(0, $stockItem->available_quantity);
        $this->assertDatabaseHas('reservations', [
            'product_id' => $product->id,
            'stock_item_id' => $stockItem->id,
            'customer_order_id' => 'customer-order:'.$order->id,
            'quantity' => 2,
            'status' => 'active',
        ]);
    }

    public function test_customer_order_rejects_zero_sale_price_cart_product(): void
    {
        $user = $this->adminUser('admin-customer-order-zero-sale-price-cart@example.com');
        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_date' => now()->toDateString(),
            'rate' => 41.25,
            'source' => 'monobank',
            'fetched_at' => now(),
        ]);
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://part/zero-sale-price-cart',
            'part_number' => '1034344-20-Z',
            'name' => 'Zero price handle',
            'price_amount' => 0,
            'currency' => 'USD',
            'raw_attributes' => [
                'code' => 'NC-ZERO',
                'stock_quantity' => 1,
            ],
        ]);
        $product = Product::query()->create([
            'sku' => 'NC-ZERO',
            'external_sku' => '1034344-20-Z',
            'name' => 'Zero price handle',
            'slug' => 'zero-price-handle-cart',
            'source_part_catalog_item_id' => $item->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'is_active' => true,
            'selling_price' => 0,
            'currency' => 'USD',
        ]);

        $this->actingAs($user)->postJson(route('admin.customer-orders.store'), [
            'client_phone' => '+380501112233',
            'client_first_name' => 'Ivan',
            'client_last_name' => 'Petrov',
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA,
            'nova_poshta_city' => 'Kyiv',
            'nova_poshta_warehouse' => 'Warehouse 1',
            'nova_poshta_warehouse_ref' => 'warehouse-ref-1',
            'items' => [[
                'id' => $product->id,
                'name' => 'Zero price handle',
                'part_number' => '1034344-20-Z',
                'code' => 'NC-ZERO',
                'quantity' => 1,
                'price' => 0,
                'price_usd_hint' => 0,
            ]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        $this->assertDatabaseCount('customer_orders', 0);
        $this->assertDatabaseCount('customer_order_items', 0);
    }

    public function test_customer_order_rejects_cart_quantity_above_available_product_stock(): void
    {
        $user = $this->adminUser('admin-customer-order-quantity-stock-cart@example.com');
        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_date' => now()->toDateString(),
            'rate' => 41.25,
            'source' => 'monobank',
            'fetched_at' => now(),
        ]);
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://part/quantity-stock-cart',
            'part_number' => '1034344-20-Q',
            'name' => 'Quantity limited handle',
            'price_amount' => 150,
            'currency' => 'USD',
            'raw_attributes' => [
                'code' => 'NC-QTY',
                'stock_quantity' => 1,
            ],
        ]);
        $product = Product::query()->create([
            'sku' => 'NC-QTY',
            'external_sku' => '1034344-20-Q',
            'name' => 'Quantity limited handle',
            'slug' => 'quantity-limited-handle-cart',
            'source_part_catalog_item_id' => $item->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'is_active' => true,
            'selling_price' => 150,
            'currency' => 'USD',
        ]);
        $this->createProductStockItem($product);

        $this->actingAs($user)->postJson(route('admin.customer-orders.store'), [
            'client_phone' => '+380501112233',
            'client_first_name' => 'Ivan',
            'client_last_name' => 'Petrov',
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA,
            'nova_poshta_city' => 'Kyiv',
            'nova_poshta_warehouse' => 'Warehouse 1',
            'nova_poshta_warehouse_ref' => 'warehouse-ref-1',
            'items' => [[
                'id' => $product->id,
                'name' => 'Quantity limited handle',
                'part_number' => '1034344-20-Q',
                'code' => 'NC-QTY',
                'quantity' => 2,
                'price' => 6190,
                'price_usd_hint' => 150,
            ]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        $this->assertDatabaseCount('customer_orders', 0);
        $this->assertDatabaseCount('customer_order_items', 0);
    }

    public function test_customer_order_uses_product_sale_price_over_zero_catalog_projection_price(): void
    {
        $user = $this->adminUser('admin-customer-order-product-price-source@example.com');
        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_date' => now()->toDateString(),
            'rate' => 41.25,
            'source' => 'monobank',
            'fetched_at' => now(),
        ]);
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://part/legacy-zero-price-cart',
            'part_number' => '1034344-20-P',
            'name' => 'Legacy zero price projection',
            'price_amount' => 0,
            'currency' => 'USD',
            'raw_attributes' => [
                'code' => 'NC-PRODUCT-PRICE',
                'stock_quantity' => 1,
            ],
        ]);
        $product = Product::query()->create([
            'sku' => 'NC-PRODUCT-PRICE',
            'external_sku' => '1034344-20-P',
            'name' => 'Product price handle',
            'slug' => 'product-price-handle-cart',
            'source_part_catalog_item_id' => $item->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'is_active' => true,
            'selling_price' => 120,
            'currency' => 'USD',
        ]);
        $warehouse = Warehouse::query()->create([
            'name' => 'Main warehouse',
            'type' => Warehouse::TYPE_MAIN,
            'floor_count' => 1,
            'is_active' => true,
        ]);
        $location = Location::query()->create([
            'warehouse_id' => $warehouse->id,
            'floor' => 'floor_1',
            'cell' => 'A1',
            'full_code' => 'A1',
            'is_active' => true,
        ]);
        StockItem::query()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'location_id' => $location->id,
            'quantity' => 1,
            'reserved_quantity' => 0,
            'available_quantity' => 1,
            'testing_status' => 'not_tested',
        ]);

        $this->actingAs($user)->postJson(route('admin.customer-orders.store'), [
            'client_phone' => '+380501112233',
            'client_first_name' => 'Ivan',
            'client_last_name' => 'Petrov',
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA,
            'nova_poshta_city' => 'Kyiv',
            'nova_poshta_warehouse' => 'Warehouse 1',
            'nova_poshta_warehouse_ref' => 'warehouse-ref-1',
            'items' => [[
                'id' => $product->id,
                'name' => 'Product price handle',
                'part_number' => '1034344-20-P',
                'code' => 'NC-PRODUCT-PRICE',
                'quantity' => 1,
                'price' => 4950,
                'price_usd_hint' => 120,
            ]],
        ])->assertCreated();

        $orderItem = CustomerOrder::query()->with('items')->firstOrFail()->items->first();

        $this->assertSame($product->id, $orderItem->product_id);
        $this->assertSame($item->id, $orderItem->part_catalog_item_id);
    }

    public function test_nova_poshta_customer_order_requires_selected_warehouse_ref(): void
    {
        $user = $this->adminUser('admin-customer-order-np-ref-required@example.com');

        $this->actingAs($user)->postJson(route('admin.customer-orders.store'), [
            'client_phone' => '+380501112233',
            'client_first_name' => 'Ivan',
            'client_last_name' => 'Petrov',
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA,
            'nova_poshta_city' => 'Kyiv',
            'nova_poshta_warehouse' => 'Warehouse 1',
            'items' => [[
                'name' => 'Mirror',
                'quantity' => 1,
                'price' => 10,
            ]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('nova_poshta_warehouse_ref');

        $this->assertDatabaseCount('customer_orders', 0);
    }

    public function test_customer_order_accepts_077_mobile_phone(): void
    {
        $user = $this->adminUser('admin-customer-order-077@example.com');

        $this->actingAs($user)->postJson(route('admin.customer-orders.store'), [
            'client_phone' => '+380771112233',
            'client_first_name' => 'Ivan',
            'client_last_name' => 'Petrov',
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'items' => [[
                'name' => 'Mirror',
                'quantity' => 1,
                'price' => 10,
            ]],
        ])->assertCreated();

        $order = CustomerOrder::query()->firstOrFail();
        $this->assertSame('+380771112233', $order->client_phone);
        $this->assertSame(CustomerOrder::DELIVERY_METHOD_PICKUP, $order->delivery_method);
    }

    public function test_pickup_processing_order_status_label_is_collecting(): void
    {
        $user = $this->adminUser('admin-customer-order-pickup-collecting@example.com');

        $this->actingAs($user)->postJson(route('admin.customer-orders.store'), [
            'client_phone' => '+380501112233',
            'client_first_name' => 'Ivan',
            'client_last_name' => 'Petrov',
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'items' => [[
                'name' => 'Mirror',
                'quantity' => 1,
                'price' => 10,
            ]],
        ])->assertCreated();

        $order = CustomerOrder::query()->firstOrFail();
        $this->assertSame(CustomerOrder::STATUS_PROCESSING, $order->status);
        $this->assertSame("\u{0421}\u{043E}\u{0431}\u{0438}\u{0440}\u{0430}\u{0435}\u{0442}\u{0441}\u{044F}", $order->status_label);
    }

    public function test_customer_order_requires_delivery_method_when_created_from_cart(): void
    {
        $user = $this->adminUser('admin-customer-order-delivery-required@example.com');

        $this->actingAs($user)->postJson(route('admin.customer-orders.store'), [
            'client_phone' => '+380501112233',
            'items' => [[
                'name' => 'Mirror',
                'quantity' => 1,
                'price' => 10,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('delivery_method');

        $this->assertDatabaseCount('customer_orders', 0);
    }

    public function test_customer_order_requires_client_name_when_created_for_customer_delivery(): void
    {
        $user = $this->adminUser('admin-customer-order-client-name-required@example.com');

        $this->actingAs($user)->postJson(route('admin.customer-orders.store'), [
            'client_phone' => '+380501112233',
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA,
            'items' => [[
                'name' => 'Mirror',
                'quantity' => 1,
                'price' => 10,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'client_first_name',
            'client_last_name',
        ]);

        $this->assertDatabaseCount('customer_orders', 0);
    }

    public function test_customer_order_uses_anonymous_counterparty_when_customer_details_are_unknown(): void
    {
        $user = $this->adminUser('admin-customer-order-anonymous-client@example.com');

        $this->actingAs($user)->postJson(route('admin.customer-orders.store'), [
            'client_phone' => '',
            'client_first_name' => '',
            'client_last_name' => '',
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'items' => [[
                'name' => 'Mirror',
                'quantity' => 1,
                'price' => 10,
            ]],
        ])->assertCreated();

        $order = CustomerOrder::query()->with('counterparty')->firstOrFail();
        $this->assertNull($order->client_phone);
        $this->assertNull($order->client_first_name);
        $this->assertNull($order->client_last_name);
        $this->assertSame(Counterparty::ANONYMOUS_ID, $order->counterparty?->id);
        $this->assertSame(Counterparty::ANONYMOUS_NAME, $order->counterparty?->name);
        $this->assertSame(Counterparty::ANONYMOUS_PHONE, $order->counterparty?->phone);
        $this->assertSame(Counterparty::TYPE_PARTS, $order->counterparty?->type);
    }

    public function test_sto_customer_order_can_be_created_with_sto_as_client(): void
    {
        $user = $this->adminUser('admin-customer-order-sto-no-client@example.com');

        $this->actingAs($user)->postJson(route('admin.customer-orders.store'), [
            'client_phone' => '',
            'client_first_name' => '',
            'client_last_name' => '',
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_STO,
            'items' => [[
                'name' => 'Mirror',
                'quantity' => 1,
                'price' => 10,
            ]],
        ])->assertCreated();

        $order = CustomerOrder::query()->with('counterparty')->firstOrFail();
        $this->assertSame(Counterparty::STO_NIKOLACARS_NAME, $order->counterparty?->name);
        $this->assertSame(Counterparty::TYPE_PARTS, $order->counterparty?->type);
        $this->assertNull($order->client_phone);
        $this->assertSame("\u{0421}\u{0422}\u{041E}", $order->client_first_name);
        $this->assertNull($order->client_last_name);
        $this->assertSame("\u{0421}\u{0422}\u{041E}", $order->client_name);
        $this->assertSame(CustomerOrder::DELIVERY_METHOD_STO, $order->delivery_method);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.show', $order))
            ->assertOk()
            ->assertSee(Counterparty::STO_NIKOLACARS_NAME)
            ->assertSee(route('admin.counterparties.show', $order->counterparty), false);
    }

    public function test_nikolacars_cart_disables_client_fields_for_sto_delivery(): void
    {
        $user = $this->adminUser('admin-customer-order-sto-ui@example.com');

        $this->actingAs($user)
            ->get(route('admin.zapchasti.index'))
            ->assertOk()
            ->assertSee('<option value="sto">', false)
            ->assertSee('data-nikolacars-cart-first-name', false)
            ->assertSee('data-nikolacars-cart-last-name', false)
            ->assertSee('data-nikolacars-cart-np-city', false)
            ->assertSee('data-nikolacars-cart-np-city-ref', false)
            ->assertSee('data-nikolacars-cart-np-warehouse', false)
            ->assertSee('data-nikolacars-cart-np-warehouse-ref', false)
            ->assertSee('data-nikolacars-cart-delivery-method required', false);

        $cartScript = file_get_contents(base_path('resources/js/admin/part-catalog.js'));
        $this->assertStringContainsString("deliveryMethodInput?.value === 'sto'", $cartScript);
        $this->assertStringContainsString('input.disabled = disabled', $cartScript);
        $this->assertStringContainsString('customerHasAnyDetails', $cartScript);
        $this->assertStringContainsString('selectedAnonymousClient', $cartScript);
        $this->assertStringContainsString('isStoDelivery() || selectedAnonymousClient', $cartScript);
        $this->assertStringContainsString('default_delivery_method', $cartScript);
    }

    public function test_customer_order_pages_show_uah_total_with_usd_hint(): void
    {
        $user = $this->adminUser('admin-customer-order-total-hint@example.com');
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0004',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'total_amount' => 12380,
            'currency' => 'UAH',
        ]);
        $order->items()->create([
            'name' => 'Door handle',
            'code' => 'NC-ORDER-CODE',
            'quantity' => 2,
            'unit_price' => 6190,
            'total_price' => 12380,
            'currency' => 'UAH',
            'unit_price_usd_hint' => 150,
            'total_price_usd_hint' => 300,
        ]);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.index'))
            ->assertOk()
            ->assertSee("12 380 \u{0433}\u{0440}\u{043D}")
            ->assertSee('300.00 USD')
            ->assertSeeInOrder(['NC-ORDER-CODE', 'Door handle']);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.show', $order))
            ->assertOk()
            ->assertSee("12 380 \u{0433}\u{0440}\u{043D}")
            ->assertSee('300.00 USD')
            ->assertSeeInOrder(['NC-ORDER-CODE', 'Door handle'])
            ->assertDontSee("\u{041A}\u{043E}\u{0434}: NC-ORDER-CODE");
    }

    public function test_customer_order_show_highlights_zero_usd_item_price(): void
    {
        $user = $this->adminUser('admin-customer-order-zero-usd-price@example.com');
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0044',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'total_amount' => 2000,
            'currency' => 'UAH',
        ]);
        $order->items()->create([
            'name' => 'Zero USD part',
            'quantity' => 1,
            'unit_price' => 0,
            'total_price' => 0,
            'currency' => 'UAH',
            'unit_price_usd_hint' => 0,
            'total_price_usd_hint' => 0,
        ]);
        $order->items()->create([
            'name' => 'Normal USD part',
            'quantity' => 1,
            'unit_price' => 2000,
            'total_price' => 2000,
            'currency' => 'UAH',
            'unit_price_usd_hint' => 50,
            'total_price_usd_hint' => 50,
        ]);

        $html = $this->actingAs($user)
            ->get(route('admin.customer-orders.show', $order))
            ->assertOk()
            ->assertSee('0.00 USD')
            ->getContent();

        $zeroPriceRow = $this->tableRowContaining($html, 'Zero USD part');
        $normalPriceRow = $this->tableRowContaining($html, 'Normal USD part');

        $this->assertStringContainsString('customer-order-zero-usd-price', $zeroPriceRow);
        $this->assertStringNotContainsString('customer-order-zero-usd-price', $normalPriceRow);
    }

    public function test_customer_order_show_displays_item_photo_preview(): void
    {
        $user = $this->adminUser('admin-customer-order-photo-preview@example.com');
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0021',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'total_amount' => 1000,
            'currency' => 'UAH',
        ]);
        $product = Product::query()->create([
            'sku' => 'NC-PHOTO',
            'external_sku' => 'PHOTO-1',
            'name' => 'Door handle',
            'slug' => 'door-handle-photo-preview',
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'is_active' => true,
            'main_image' => 'https://example.test/door-handle.jpg',
            'images_json' => ['https://example.test/door-handle-detail.jpg'],
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'name' => 'Door handle',
            'quantity' => 1,
            'unit_price' => 1000,
            'total_price' => 1000,
            'currency' => 'UAH',
            'image_url' => 'https://example.test/door-handle.jpg',
        ]);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.show', $order))
            ->assertOk()
            ->assertSee('class="table-preview"', false)
            ->assertSee('src="https://example.test/door-handle.jpg"', false)
            ->assertSee('data-customer-order-photo-trigger', false)
            ->assertSee('data-customer-order-photo-lightbox', false)
            ->assertSee('data-customer-order-photo-next', false)
            ->assertSee('door-handle-detail.jpg', false);
    }

    public function test_cancelled_customer_order_status_uses_danger_tag(): void
    {
        $user = $this->adminUser('admin-customer-order-cancelled-tag@example.com');
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0020',
            'status' => CustomerOrder::STATUS_CANCELLED,
            'total_amount' => 0,
            'currency' => 'UAH',
        ]);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.index', ['tab' => 'cancelled']))
            ->assertOk()
            ->assertSee('class="tag tag-danger"', false);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.show', $order))
            ->assertOk()
            ->assertSee('class="tag tag-danger"', false);
    }

    public function test_customer_orders_index_shows_order_creator_under_number(): void
    {
        $user = $this->adminUser('admin-customer-order-creator-viewer@example.com');
        $creator = User::query()->create([
            'name' => 'Warehouse Worker',
            'email' => 'order-creator@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_WAREHOUSE_WORKER,
            'is_active' => true,
        ]);
        StoEmployee::query()->create([
            'cash_employee_name' => 'Order Creator Employee',
            'last_name' => 'Creator',
            'first_name' => 'Employee',
            'position' => "\u{0421}\u{043A}\u{043B}\u{0430}\u{0434}",
            'is_active' => true,
            'user_id' => $creator->id,
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0023',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'total_amount' => 0,
            'currency' => 'UAH',
            'created_by' => $creator->id,
        ]);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.index'))
            ->assertOk()
            ->assertSeeInOrder([$order->number, "\u{0421}\u{043E}\u{0437}\u{0434}\u{0430}\u{043B}: Order Creator Employee"])
            ->assertDontSee("\u{0421}\u{043E}\u{0437}\u{0434}\u{0430}\u{043B}: Warehouse Worker");
    }

    public function test_customer_order_show_displays_creator_next_to_created_at(): void
    {
        $user = $this->adminUser('admin-customer-order-creator-show-viewer@example.com');
        $creator = User::query()->create([
            'name' => 'Order Creator',
            'email' => 'order-show-creator@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_WAREHOUSE_WORKER,
            'is_active' => true,
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0098',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'total_amount' => 100,
            'currency' => 'UAH',
            'created_by' => $creator->id,
            'created_at' => '2026-06-19 15:24:00',
        ]);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.show', $order))
            ->assertOk()
            ->assertSee($order->created_at?->timezone('Europe/Kiev')->format('d.m.Y H:i'))
            ->assertSee('(Order Creator)');
    }

    public function test_customer_orders_index_shows_anonymous_counterparty_as_client_name(): void
    {
        $user = $this->adminUser('admin-customer-order-anonymous-index@example.com');
        $counterparty = Counterparty::query()->updateOrCreate(['id' => Counterparty::ANONYMOUS_ID], [
            'type' => Counterparty::TYPE_PARTS,
            'name' => Counterparty::ANONYMOUS_NAME,
            'phone' => Counterparty::ANONYMOUS_PHONE,
            'is_active' => true,
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0099',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'counterparty_id' => $counterparty->id,
            'client_phone' => null,
            'client_first_name' => null,
            'client_last_name' => null,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'total_amount' => 0,
            'currency' => 'UAH',
        ]);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.index'))
            ->assertOk()
            ->assertSee($order->number)
            ->assertSee(Counterparty::ANONYMOUS_NAME);
    }

    public function test_customer_orders_index_separates_active_shipped_cancelled_and_completed_blocks(): void
    {
        $user = $this->adminUser('admin-customer-order-tabs@example.com');
        $shippedHeading = "\u{041D}\u{043E}\u{0432}\u{0430}\u{044F} \u{043F}\u{043E}\u{0447}\u{0442}\u{0430}: \u{041E}\u{0442}\u{043F}\u{0440}\u{0430}\u{0432}\u{043B}\u{0435}\u{043D}";
        $activeOrder = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0021',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'total_amount' => 0,
            'currency' => 'UAH',
        ]);
        $cancelledOrder = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0022',
            'status' => CustomerOrder::STATUS_CANCELLED,
            'total_amount' => 0,
            'currency' => 'UAH',
        ]);
        $shippedNovaPoshtaOrder = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0023',
            'status' => CustomerOrder::STATUS_SHIPPED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA,
            'total_amount' => 0,
            'currency' => 'UAH',
        ]);
        $completedOrder = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0024',
            'status' => CustomerOrder::STATUS_COMPLETED,
            'total_amount' => 0,
            'currency' => 'UAH',
        ]);

        $response = $this->actingAs($user)
            ->get(route('admin.customer-orders.index'))
            ->assertOk()
            ->assertSee("\u{0410}\u{043A}\u{0442}\u{0438}\u{0432}\u{043D}\u{044B}\u{0435}")
            ->assertSee("\u{041E}\u{0442}\u{043C}\u{0435}\u{043D}\u{0435}\u{043D}\u{043D}\u{044B}\u{0435} \u{0437}\u{0430}\u{043A}\u{0430}\u{0437}\u{044B}")
            ->assertSee("\u{0412}\u{044B}\u{0434}\u{0430}\u{043D}")
            ->assertSee($shippedHeading)
            ->assertSee($activeOrder->number)
            ->assertSeeInOrder([
                $activeOrder->number,
                $shippedHeading,
                $shippedNovaPoshtaOrder->number,
                $completedOrder->number,
            ])
            ->assertDontSee($cancelledOrder->number)
            ->assertDontSee('tab=completed', false);
        $this->assertSame(1, substr_count($response->getContent(), $shippedNovaPoshtaOrder->number));

        $this->actingAs($user)
            ->get(route('admin.customer-orders.index', ['tab' => 'cancelled']))
            ->assertOk()
            ->assertSee($cancelledOrder->number)
            ->assertDontSee($activeOrder->number)
            ->assertDontSee($shippedNovaPoshtaOrder->number)
            ->assertDontSee($completedOrder->number);
    }

    public function test_customer_order_item_names_and_codes_link_to_product_pages(): void
    {
        $user = $this->adminUser('admin-customer-order-linked-items@example.com');
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://part/linked-order-item',
            'part_number' => '1034344-20-B',
            'name' => 'Linked handle',
            'raw_attributes' => [
                'code' => 'NC-LINK',
            ],
        ]);
        $product = Product::query()->create([
            'sku' => 'NC-LINK',
            'external_sku' => '1034344-20-B',
            'name' => 'Linked handle',
            'slug' => 'linked-handle',
            'source_part_catalog_item_id' => $catalogItem->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'selling_price' => 3600,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260604-0030',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'total_amount' => 1500,
            'currency' => 'UAH',
        ]);
        $order->items()->create([
            'part_catalog_item_id' => $catalogItem->id,
            'name' => 'Linked handle',
            'part_number' => '1034344-20-B',
            'code' => 'NC-LINK',
            'quantity' => 1,
            'unit_price' => 1500,
            'total_price' => 1500,
            'currency' => 'UAH',
        ]);

        $productUrl = route('admin.products.show', $product);
        $productUrlPattern = preg_quote($productUrl, '/');

        $showResponse = $this->actingAs($user)
            ->get(route('admin.customer-orders.show', $order))
            ->assertOk();

        $this->assertMatchesRegularExpression(
            '/<a href="'.$productUrlPattern.'">\s*NC-LINK\s+Linked handle\s*<\/a>/',
            $showResponse->getContent(),
        );
        $this->assertMatchesRegularExpression(
            '/<a href="'.$productUrlPattern.'">1034344-20-B<\/a>/',
            $showResponse->getContent(),
        );

        $indexResponse = $this->actingAs($user)
            ->get(route('admin.customer-orders.index'))
            ->assertOk();

        $this->assertMatchesRegularExpression(
            '/<a href="'.$productUrlPattern.'">\s*<strong>NC-LINK<\/strong>\s+Linked handle\s*<\/a>/',
            $indexResponse->getContent(),
        );
    }

    public function test_customer_order_pages_prefer_ukrainian_catalog_name_for_items(): void
    {
        $user = $this->adminUser('admin-customer-order-ukrainian-item-name@example.com');
        $nameUa = "\u{0420}\u{0443}\u{0447}\u{043A}\u{0430} \u{0434}\u{0432}\u{0435}\u{0440}\u{0435}\u{0439}";
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://part/ukrainian-order-item',
            'part_number' => '1034344-20-U',
            'name' => 'Door handle',
            'name_ua' => $nameUa,
            'raw_attributes' => [
                'code' => 'NC-UA',
            ],
        ]);
        $product = Product::query()->create([
            'sku' => 'NC-UA',
            'external_sku' => '1034344-20-U',
            'name' => 'Door handle',
            'slug' => 'door-handle-ukrainian-order-item',
            'source_part_catalog_item_id' => $catalogItem->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'is_active' => true,
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260604-0033',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'total_amount' => 1500,
            'currency' => 'UAH',
        ]);
        $order->items()->create([
            'part_catalog_item_id' => $catalogItem->id,
            'product_id' => $product->id,
            'name' => 'Door handle',
            'part_number' => '1034344-20-U',
            'code' => 'NC-UA',
            'quantity' => 1,
            'unit_price' => 1500,
            'total_price' => 1500,
            'currency' => 'UAH',
        ]);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.show', $order))
            ->assertOk()
            ->assertSeeInOrder(['NC-UA', $nameUa])
            ->assertDontSee('Door handle');

        $this->actingAs($user)
            ->get(route('admin.customer-orders.index'))
            ->assertOk()
            ->assertSeeInOrder(['NC-UA', $nameUa])
            ->assertDontSee('Door handle');
    }

    public function test_customer_order_items_display_current_product_sku_when_snapshot_code_is_stale(): void
    {
        $user = $this->adminUser('admin-customer-order-current-product-code@example.com');
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://part/stale-code-order-item',
            'part_number' => '1127503-01-D',
            'name' => 'Parking sensor',
            'raw_attributes' => [
                'code' => '578',
            ],
        ]);
        $product = Product::query()->create([
            'sku' => 'NC-578',
            'external_sku' => '1127503-01-D',
            'name' => 'Parking sensor',
            'slug' => 'parking-sensor',
            'source_part_catalog_item_id' => $catalogItem->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'is_active' => true,
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260604-0031',
            'status' => CustomerOrder::STATUS_CANCELLED,
            'total_amount' => 1500,
            'currency' => 'UAH',
        ]);
        $order->items()->create([
            'part_catalog_item_id' => $catalogItem->id,
            'name' => 'Parking sensor',
            'part_number' => '1127503',
            'code' => 'DON23-1887',
            'quantity' => 1,
            'unit_price' => 1500,
            'total_price' => 1500,
            'currency' => 'UAH',
        ]);

        $productUrlPattern = preg_quote(route('admin.products.show', $product), '/');

        $showResponse = $this->actingAs($user)
            ->get(route('admin.customer-orders.show', $order))
            ->assertOk();

        $this->assertMatchesRegularExpression(
            '/<a href="'.$productUrlPattern.'">\s*NC-578\s+Parking sensor\s*<\/a>/',
            $showResponse->getContent(),
        );
        $this->assertMatchesRegularExpression(
            '/<a href="'.$productUrlPattern.'">1127503-01-D<\/a>/',
            $showResponse->getContent(),
        );
        $showResponse->assertDontSee('DON23-1887');

        $indexResponse = $this->actingAs($user)
            ->get(route('admin.customer-orders.index', ['tab' => 'cancelled']))
            ->assertOk();

        $this->assertMatchesRegularExpression(
            '/<a href="'.$productUrlPattern.'">\s*<strong>NC-578<\/strong>\s+Parking sensor\s*<\/a>/',
            $indexResponse->getContent(),
        );
        $this->assertMatchesRegularExpression(
            '/<a href="'.$productUrlPattern.'">\s*'.preg_quote("\u{0410}\u{0440}\u{0442}.:", '/').'\s+1127503-01-D\s*<\/a>/',
            $indexResponse->getContent(),
        );
        $indexResponse->assertDontSee('DON23-1887');
    }

    public function test_customer_order_item_with_product_id_keeps_snapshot_article_when_product_article_is_blank(): void
    {
        $user = $this->adminUser('admin-customer-order-snapshot-article@example.com');
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://part/stale-catalog-article',
            'part_number' => 'STALE-CATALOG-ARTICLE',
            'name' => 'Snapshot article part',
        ]);
        $product = Product::query()->create([
            'sku' => 'NC-SNAPSHOT-ARTICLE',
            'external_sku' => null,
            'name' => 'Snapshot article part',
            'slug' => 'snapshot-article-part',
            'source_part_catalog_item_id' => $catalogItem->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'is_active' => true,
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260604-0034',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'total_amount' => 1500,
            'currency' => 'UAH',
        ]);
        $order->items()->create([
            'part_catalog_item_id' => $catalogItem->id,
            'product_id' => $product->id,
            'name' => 'Snapshot article part',
            'part_number' => 'SNAPSHOT-ARTICLE',
            'code' => 'NC-SNAPSHOT-ARTICLE',
            'quantity' => 1,
            'unit_price' => 1500,
            'total_price' => 1500,
            'currency' => 'UAH',
        ]);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.show', $order))
            ->assertOk()
            ->assertSee('SNAPSHOT-ARTICLE')
            ->assertDontSee('STALE-CATALOG-ARTICLE');
    }

    public function test_customer_order_item_with_product_source_url_links_to_product_page(): void
    {
        $user = $this->adminUser('admin-customer-order-source-url-product-link@example.com');
        $product = Product::query()->create([
            'sku' => 'NC-34',
            'external_sku' => '1104424-10-J',
            'name' => 'Main battery',
            'slug' => 'main-battery',
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'is_active' => true,
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260604-0032',
            'status' => CustomerOrder::STATUS_CANCELLED,
            'total_amount' => 1500,
            'currency' => 'UAH',
        ]);
        $order->items()->create([
            'part_catalog_item_id' => null,
            'name' => "\u{041E}\u{0441}\u{043D}\u{043E}\u{0432}\u{043D}\u{0430} \u{0431}\u{0430}\u{0442}\u{0430}\u{0440}\u{0435}\u{044F}",
            'code' => '32',
            'quantity' => 1,
            'unit_price' => 1500,
            'total_price' => 1500,
            'currency' => 'UAH',
            'source_url' => 'http://sklad-zapchastey.test/admin/products/'.$product->id,
        ]);

        $productUrlPattern = preg_quote(route('admin.products.show', $product), '/');

        $showResponse = $this->actingAs($user)
            ->get(route('admin.customer-orders.show', $order))
            ->assertOk();

        $this->assertMatchesRegularExpression(
            '/<a href="'.$productUrlPattern."\">\\s*32\\s+\u{041E}\u{0441}\u{043D}\u{043E}\u{0432}\u{043D}\u{0430} \u{0431}\u{0430}\u{0442}\u{0430}\u{0440}\u{0435}\u{044F}\\s*<\\/a>/",
            $showResponse->getContent(),
        );

        $indexResponse = $this->actingAs($user)
            ->get(route('admin.customer-orders.index', ['tab' => 'cancelled']))
            ->assertOk();

        $this->assertMatchesRegularExpression(
            '/<a href="'.$productUrlPattern."\">\\s*<strong>32<\\/strong>\\s+\u{041E}\u{0441}\u{043D}\u{043E}\u{0432}\u{043D}\u{0430} \u{0431}\u{0430}\u{0442}\u{0430}\u{0440}\u{0435}\u{044F}\\s*<\\/a>/",
            $indexResponse->getContent(),
        );
    }

    public function test_customer_order_created_from_product_source_url_keeps_catalog_item_link(): void
    {
        $user = $this->adminUser('admin-customer-order-product-url-catalog-link@example.com');
        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_date' => now()->toDateString(),
            'rate' => 41.25,
            'source' => 'monobank',
            'fetched_at' => now(),
        ]);
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/34',
            'part_number' => '1104424-10-J',
            'name' => 'Main battery',
            'price_amount' => 3600,
            'currency' => 'USD',
            'raw_attributes' => [
                'code' => '34',
                'stock_quantity' => 1,
            ],
        ]);
        $product = Product::query()->create([
            'sku' => 'NC-34',
            'external_sku' => '1104424-10-J',
            'name' => 'Main battery',
            'slug' => 'main-battery-from-url',
            'source_part_catalog_item_id' => $catalogItem->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'selling_price' => 3600,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $this->createProductStockItem($product);

        $this->actingAs($user)
            ->postJson(route('admin.customer-orders.store'), [
                'delivery_method' => CustomerOrder::DELIVERY_METHOD_STO,
                'items' => [[
                    'id' => $product->id,
                    'name' => 'Main battery',
                    'part_number' => '1104424',
                    'code' => '32',
                    'quantity' => 1,
                    'price_usd_hint' => 3600,
                    'url' => 'http://sklad-zapchastey.test/admin/products/'.$product->id,
                ]],
            ])
            ->assertCreated();

        $this->assertDatabaseHas('customer_order_items', [
            'product_id' => $product->id,
            'part_catalog_item_id' => $catalogItem->id,
            'source_url' => 'http://sklad-zapchastey.test/admin/products/'.$product->id,
        ]);
    }

    public function test_customer_order_can_be_created_with_four_items_and_long_image_urls(): void
    {
        $user = $this->adminUser('admin-customer-order-long-images@example.com');
        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_date' => now()->toDateString(),
            'rate' => 41.25,
            'source' => 'monobank',
            'fetched_at' => now(),
        ]);

        $items = collect(range(1, 4))->map(function (int $number): array {
            $catalogItem = PartCatalogItem::query()->create([
                'source' => 'nikolacars',
                'source_url' => "nikolacars://part/long-image-{$number}",
                'part_number' => "1104424-1{$number}-J",
                'name' => "Part {$number}",
                'raw_attributes' => [
                    'code' => "NC-LONG-{$number}",
                    'stock_quantity' => 1,
                ],
            ]);
            $product = Product::query()->create([
                'sku' => "NC-LONG-{$number}",
                'external_sku' => "1104424-1{$number}-J",
                'name' => "Part {$number}",
                'slug' => "part-long-image-{$number}",
                'source_part_catalog_item_id' => $catalogItem->id,
                'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
                'selling_price' => 1000,
                'currency' => 'USD',
                'is_active' => true,
            ]);
            $this->createProductStockItem($product);

            $longImageUrl = 'https://sklad.nikolacars.kiev.ua/storage/competitor-catalog/nikolacars/'
                .str_repeat("nested-path-segment-{$number}/", 12)
                ."part-{$number}.jpg";

            return [
                'id' => $product->id,
                'name' => "Part {$number}",
                'part_number' => "1104424-1{$number}-J",
                'code' => "NC-LONG-{$number}",
                'quantity' => 1,
                'price' => 1000,
                'url' => 'http://sklad-zapchastey.test/admin/products/'.$product->id,
                'image' => $longImageUrl,
            ];
        })->all();

        $this->actingAs($user)
            ->postJson(route('admin.customer-orders.store'), [
                'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
                'client_first_name' => 'Ivan',
                'client_last_name' => 'Petrov',
                'items' => $items,
            ])
            ->assertCreated();

        $this->assertDatabaseCount('customer_order_items', 4);
        $this->assertGreaterThan(255, strlen($items[0]['image']));
        $this->assertDatabaseHas('customer_order_items', [
            'code' => 'NC-LONG-1',
            'image_url' => $items[0]['image'],
        ]);
    }

    public function test_customer_orders_index_collapses_items_after_third_position(): void
    {
        $user = $this->adminUser('admin-customer-order-collapsed-items@example.com');
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260615-0001',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'total_amount' => 5000,
            'currency' => 'UAH',
        ]);

        foreach (range(1, 5) as $number) {
            $order->items()->create([
                'name' => "Collapsed item {$number}",
                'part_number' => "COLLAPSED-{$number}",
                'code' => "NC-COLLAPSED-{$number}",
                'quantity' => 1,
                'unit_price' => 1000,
                'total_price' => 1000,
                'currency' => 'UAH',
            ]);
        }

        $response = $this->actingAs($user)
            ->get(route('admin.customer-orders.index'))
            ->assertOk()
            ->assertSee('customer-order-extra-items', false)
            ->assertSee("\u{041F}\u{043E}\u{043A}\u{0430}\u{0437}\u{0430}\u{0442}\u{044C} \u{0435}\u{0449}\u{0451} 2")
            ->assertSee("\u{0421}\u{043A}\u{0440}\u{044B}\u{0442}\u{044C} 2")
            ->assertSee("\u{041E}\u{0442}\u{043C}\u{0435}\u{043D}\u{0438}\u{0442}\u{044C}");

        $this->assertMatchesRegularExpression(
            '/Collapsed item 3.*<details>.*Collapsed item 4.*Collapsed item 5/s',
            $response->getContent(),
        );
    }

    public function test_customer_order_rejects_product_url_that_is_not_sellable_inventory(): void
    {
        $user = $this->adminUser('admin-customer-order-unsellable-product-url@example.com');
        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_date' => now()->toDateString(),
            'rate' => 41.25,
            'source' => 'monobank',
            'fetched_at' => now(),
        ]);
        $officialItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=1044831-00-F',
            'part_number' => '1044831-00-F',
            'name' => 'STEERING GEAR ASSEMBLY - LEFT HAND DRIVE',
        ]);
        $product = Product::query()->create([
            'sku' => 'DON4-1592',
            'external_sku' => '1044831-00-F',
            'name' => 'STEERING GEAR ASSEMBLY - LEFT HAND DRIVE',
            'slug' => 'unsellable-steering-gear',
            'source_part_catalog_item_id' => $officialItem->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'is_active' => true,
            'is_auto_generated' => true,
        ]);

        $this->actingAs($user)
            ->postJson(route('admin.customer-orders.store'), [
                'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
                'client_first_name' => 'Ivan',
                'client_last_name' => 'Petrov',
                'items' => [[
                    'id' => $product->id,
                    'name' => "\u{0420}\u{0443}\u{043B}\u{044C}\u{043E}\u{0432}\u{0430} \u{0440}\u{0435}\u{0439}\u{043A}\u{0430} \u{0432} \u{0437}\u{0431}\u{043E}\u{0440}\u{0456} \u{0437} \u{0435}\u{043B}\u{0435}\u{043A}\u{0442}\u{0440}\u{043E}\u{043F}\u{0440}\u{0438}\u{0432}\u{043E}\u{0434}\u{043E}\u{043C}",
                    'part_number' => '1044831',
                    'code' => 'DON4-1592',
                    'quantity' => 1,
                    'price' => 100250,
                    'url' => 'http://sklad-zapchastey.test/admin/products/'.$product->id,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        $this->assertDatabaseMissing('customer_order_items', [
            'source_url' => 'http://sklad-zapchastey.test/admin/products/'.$product->id,
        ]);
    }

    public function test_customer_orders_index_shows_assembled_orders_in_active_list(): void
    {
        $user = $this->adminUser('admin-customer-order-assembled-list@example.com');
        $assembledOrder = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0023',
            'status' => CustomerOrder::STATUS_ASSEMBLED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'total_amount' => 0,
            'currency' => 'UAH',
        ]);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.index'))
            ->assertOk()
            ->assertSee($assembledOrder->number)
            ->assertDontSee("\u{0421}\u{043E}\u{0431}\u{0440}\u{0430}\u{043D}\u{044B}");
    }

    public function test_customer_orders_index_shows_nova_poshta_print_icon_next_to_ttn(): void
    {
        $user = $this->adminUser('admin-customer-order-index-np-print@example.com');
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0024-NP',
            'status' => CustomerOrder::STATUS_ASSEMBLED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA,
            'total_amount' => 1500,
            'currency' => 'UAH',
            'paid_cash_uah' => 500,
            'paid_amount_uah' => 500,
        ]);
        $order->novaPoshtaShipment()->create([
            'carrier' => CustomerOrderShipment::CARRIER_NOVA_POSHTA,
            'status' => CustomerOrderShipment::STATUS_CREATED,
            'recipient_city_name' => 'Kyiv',
            'recipient_warehouse_name' => 'Warehouse 1',
            'recipient_warehouse_ref' => 'warehouse-ref-1',
            'recipient_name' => 'Ivan Petrov',
            'recipient_phone' => '+380501112233',
            'tracking_number' => '20450000000424',
            'np_ref' => 'np-print-ref',
        ]);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.index'))
            ->assertOk()
            ->assertSee('20450000000424')
            ->assertSee(route('admin.customer-orders.nova-poshta.label', $order), false)
            ->assertSee("\u{041F}\u{0435}\u{0447}\u{0430}\u{0442}\u{044C} \u{0422}\u{0422}\u{041D}");
    }

    public function test_cancelled_customer_order_cannot_be_edited_from_show_page(): void
    {
        $user = $this->adminUser('admin-customer-order-cancelled-readonly@example.com');
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://part/cancelled-readonly',
            'part_number' => '1034344-20-B',
            'name' => 'Cancelled readonly handle',
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0022',
            'status' => CustomerOrder::STATUS_CANCELLED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'total_amount' => 100,
            'currency' => 'UAH',
        ]);
        $item = $order->items()->create([
            'name' => 'Cancelled readonly handle',
            'quantity' => 1,
            'unit_price' => 100,
            'total_price' => 100,
            'currency' => 'UAH',
        ]);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.show', $order))
            ->assertOk()
            ->assertSee(route('admin.customer-orders.recreate', $order), false)
            ->assertSee("\u{041F}\u{0435}\u{0440}\u{0435}\u{0441}\u{043E}\u{0437}\u{0434}\u{0430}\u{0442}\u{044C}")
            ->assertDontSee('data-customer-order-add-item', false)
            ->assertDontSee('data-customer-order-unit-price', false)
            ->assertDontSee(route('admin.customer-orders.items.store', $order), false)
            ->assertDontSee(route('admin.customer-orders.items.destroy', [$order, $item]), false)
            ->assertDontSee(route('admin.customer-orders.delivery-method.update', $order), false);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->patch(route('admin.customer-orders.delivery-method.update', $order), [
                'delivery_method' => CustomerOrder::DELIVERY_METHOD_STO,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order))
            ->assertSessionHasErrors('order');

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->post(route('admin.customer-orders.items.store', $order), [
                'part_catalog_item_id' => $catalogItem->id,
                'quantity' => 1,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order))
            ->assertSessionHasErrors('order');

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->patch(route('admin.customer-orders.items.update', [$order, $item]), [
                'unit_price' => 200,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order))
            ->assertSessionHasErrors('order');

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->delete(route('admin.customer-orders.items.destroy', [$order, $item]))
            ->assertRedirect(route('admin.customer-orders.show', $order))
            ->assertSessionHasErrors('order');

        $this->assertSame(CustomerOrder::DELIVERY_METHOD_PICKUP, $order->refresh()->delivery_method);
        $this->assertSame(1, $order->items()->count());
        $this->assertSame('100.00', $item->refresh()->unit_price);
        $this->assertSame('100.00', $order->total_amount);
    }

    public function test_customer_order_items_cannot_be_changed_after_prepayment(): void
    {
        $user = $this->adminUser('admin-customer-order-prepaid-items-readonly@example.com');
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://part/prepaid-readonly-extra',
            'part_number' => '1034344-20-B',
            'name' => 'Prepaid readonly extra',
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0022-PREPAID-LOCK',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'total_amount' => 100,
            'currency' => 'UAH',
            'paid_cash_uah' => 20,
            'paid_amount_uah' => 20,
        ]);
        $item = $order->items()->create([
            'name' => 'Prepaid readonly handle',
            'quantity' => 1,
            'unit_price' => 100,
            'total_price' => 100,
            'currency' => 'UAH',
        ]);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.show', $order))
            ->assertOk()
            ->assertDontSee('data-customer-order-add-item', false)
            ->assertDontSee('data-customer-order-unit-price', false)
            ->assertDontSee(route('admin.customer-orders.items.store', $order), false)
            ->assertDontSee(route('admin.customer-orders.items.destroy', [$order, $item]), false);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->post(route('admin.customer-orders.items.store', $order), [
                'part_catalog_item_id' => $catalogItem->id,
                'quantity' => 1,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order))
            ->assertSessionHasErrors('order');

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->patch(route('admin.customer-orders.items.update', [$order, $item]), [
                'unit_price' => 200,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order))
            ->assertSessionHasErrors('order');

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->delete(route('admin.customer-orders.items.destroy', [$order, $item]))
            ->assertRedirect(route('admin.customer-orders.show', $order))
            ->assertSessionHasErrors('order');

        $order->refresh();

        $this->assertSame(1, $order->items()->count());
        $this->assertSame('100.00', $item->refresh()->unit_price);
        $this->assertSame('100.00', $order->total_amount);
    }

    public function test_customer_order_note_can_be_updated_even_when_order_is_cancelled(): void
    {
        $user = $this->adminUser('admin-customer-order-note-update@example.com');
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0030',
            'status' => CustomerOrder::STATUS_CANCELLED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'note' => 'Old note',
            'total_amount' => 100,
            'currency' => 'UAH',
        ]);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.show', $order))
            ->assertOk()
            ->assertSee(route('admin.customer-orders.note.update', $order), false);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->patch(route('admin.customer-orders.note.update', $order), [
                'note' => 'Call client before pickup',
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order))
            ->assertSessionHasNoErrors();

        $this->assertSame('Call client before pickup', $order->refresh()->note);
        $this->assertDatabaseHas('customer_order_history_events', [
            'customer_order_id' => $order->id,
            'event_type' => 'note_updated',
            'old_values' => json_encode(['note' => 'Old note']),
            'new_values' => json_encode(['note' => 'Call client before pickup']),
        ]);
    }

    public function test_cancelled_customer_order_can_be_recreated_with_current_usd_rate_and_reservations(): void
    {
        $user = $this->adminUser('admin-customer-order-recreate@example.com');
        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_date' => now()->toDateString(),
            'rate' => 42,
            'source' => 'monobank',
            'fetched_at' => now(),
        ]);
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://part/recreate',
            'part_number' => '1034344-20-B',
            'name' => 'Door handle',
            'price_amount' => 150,
            'currency' => 'USD',
            'raw_attributes' => [
                'code' => 'NC-RECREATE',
                'stock_quantity' => 1,
            ],
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0024',
            'status' => CustomerOrder::STATUS_CANCELLED,
            'client_phone' => '+380501112233',
            'client_first_name' => 'Ivan',
            'client_last_name' => 'Petrov',
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'note' => 'Call before pickup',
            'total_amount' => 6000,
            'currency' => 'UAH',
        ]);
        $order->items()->create([
            'part_catalog_item_id' => $catalogItem->id,
            'name' => 'Door handle',
            'part_number' => '1034344-20-B',
            'code' => 'NC-RECREATE',
            'quantity' => 1,
            'unit_price' => 6000,
            'total_price' => 6000,
            'currency' => 'UAH',
            'unit_price_usd_hint' => 150,
            'total_price_usd_hint' => 150,
            'usd_exchange_rate' => 40,
        ]);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->post(route('admin.customer-orders.recreate', $order))
            ->assertRedirect();

        $newOrder = CustomerOrder::query()
            ->where('id', '!=', $order->id)
            ->with('items')
            ->firstOrFail();
        $newItem = $newOrder->items->first();

        $this->assertSame(CustomerOrder::STATUS_PROCESSING, $newOrder->status);
        $this->assertSame(CustomerOrder::DELIVERY_METHOD_PICKUP, $newOrder->delivery_method);
        $this->assertSame('Ivan', $newOrder->client_first_name);
        $this->assertSame('Call before pickup', $newOrder->note);
        $this->assertSame($catalogItem->id, $newItem->part_catalog_item_id);
        $this->assertSame('6300.00', $newItem->unit_price);
        $this->assertSame('6300.00', $newOrder->total_amount);
        $this->assertSame('150.00', $newItem->unit_price_usd_hint);
        $this->assertSame('42.000000', $newItem->usd_exchange_rate);
        $this->assertSame(1.0, (float) data_get($catalogItem->refresh()->raw_attributes, 'reserved_quantity'));
        $this->assertContains($newOrder->number, data_get($catalogItem->raw_attributes, 'reserved_orders', []));
        $this->assertNotContains($order->number, data_get($catalogItem->raw_attributes, 'reserved_orders', []));
    }

    public function test_cancelled_customer_order_recreate_checks_available_quantity_before_copying_items(): void
    {
        $user = $this->adminUser('admin-customer-order-recreate-stock@example.com');
        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_date' => now()->toDateString(),
            'rate' => 42,
            'source' => 'monobank',
            'fetched_at' => now(),
        ]);
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://part/recreate-stock',
            'part_number' => '1034344-20-B',
            'name' => 'Door handle',
            'price_amount' => 150,
            'currency' => 'USD',
            'raw_attributes' => [
                'code' => 'NC-RECREATE-STOCK',
                'stock_quantity' => 1,
            ],
        ]);
        $cancelledOrder = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0025',
            'status' => CustomerOrder::STATUS_CANCELLED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'total_amount' => 6000,
            'currency' => 'UAH',
        ]);
        $cancelledOrder->items()->create([
            'part_catalog_item_id' => $catalogItem->id,
            'name' => 'Door handle',
            'part_number' => '1034344-20-B',
            'code' => 'NC-RECREATE-STOCK',
            'quantity' => 1,
            'unit_price' => 6000,
            'total_price' => 6000,
            'currency' => 'UAH',
            'unit_price_usd_hint' => 150,
        ]);
        $activeOrder = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0026',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'total_amount' => 6000,
            'currency' => 'UAH',
        ]);
        $activeOrder->items()->create([
            'part_catalog_item_id' => $catalogItem->id,
            'name' => 'Door handle',
            'quantity' => 1,
            'unit_price' => 6000,
            'total_price' => 6000,
            'currency' => 'UAH',
        ]);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $cancelledOrder))
            ->post(route('admin.customer-orders.recreate', $cancelledOrder))
            ->assertRedirect(route('admin.customer-orders.show', $cancelledOrder))
            ->assertSessionHasErrors('order');

        $this->assertSame(2, CustomerOrder::query()->count());
    }

    public function test_customer_order_rejects_invalid_mobile_phone(): void
    {
        $user = $this->adminUser('admin-customer-order-invalid-phone@example.com');

        $this->actingAs($user)->postJson(route('admin.customer-orders.store'), [
            'client_phone' => '+380441112233',
            'client_first_name' => 'Ivan',
            'client_last_name' => 'Petrov',
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'items' => [[
                'name' => 'Mirror',
                'quantity' => 1,
                'price' => 10,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('client_phone');
    }

    public function test_reserved_nikolacars_item_cannot_be_updated_or_deleted(): void
    {
        $user = $this->adminUser('admin-customer-order-reserved@example.com');
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://part/reserved',
            'part_number' => '1034344-20-B',
            'name' => 'Reserved handle',
            'price_amount' => 150,
            'currency' => 'USD',
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0001',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'total_amount' => 150,
            'currency' => 'USD',
        ]);
        $order->items()->create([
            'part_catalog_item_id' => $item->id,
            'name' => 'Reserved handle',
            'quantity' => 1,
            'unit_price' => 150,
            'total_price' => 150,
            'currency' => 'USD',
        ]);

        $this->actingAs($user)
            ->patchJson(route('admin.zapchasti.update', $item), [
                'part_number' => '9999999-99-Z',
                'price_amount' => 10,
            ])
            ->assertStatus(422);

        $this->actingAs($user)
            ->deleteJson(route('admin.zapchasti.destroy', $item))
            ->assertStatus(422);

        $item->refresh();
        $this->assertSame('1034344-20-B', $item->part_number);
        $this->assertSame('150.00', $item->price_amount);
    }

    public function test_customer_order_delivery_method_can_be_updated_from_modal_on_show_page(): void
    {
        $user = $this->adminUser('admin-customer-order-delivery-update@example.com');
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0002',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'total_amount' => 0,
            'currency' => 'USD',
        ]);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.show', $order))
            ->assertOk()
            ->assertSee($order->delivery_method_label)
            ->assertSee('data-customer-order-delivery-edit', false)
            ->assertSee('data-customer-order-delivery-dialog', false)
            ->assertSee(route('admin.customer-orders.delivery-method.update', $order), false)
            ->assertSee('name="nova_poshta_city"', false)
            ->assertSee('name="nova_poshta_warehouse"', false);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->patch(route('admin.customer-orders.delivery-method.update', $order), [
                'delivery_method' => CustomerOrder::DELIVERY_METHOD_STO,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order));

        $order->refresh()->load('counterparty');

        $this->assertSame(CustomerOrder::DELIVERY_METHOD_STO, $order->delivery_method);
        $this->assertSame(Counterparty::STO_NIKOLACARS_NAME, $order->counterparty?->name);
        $this->assertNull($order->client_phone);
        $this->assertSame("\u{0421}\u{0422}\u{041E}", $order->client_first_name);
        $this->assertNull($order->client_last_name);
    }

    public function test_customer_order_delivery_method_cannot_be_cleared_from_show_page(): void
    {
        $user = $this->adminUser('admin-customer-order-delivery-clear@example.com');
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0021',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'total_amount' => 0,
            'currency' => 'USD',
        ]);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->patch(route('admin.customer-orders.delivery-method.update', $order), [
                'delivery_method' => '',
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order))
            ->assertSessionHasErrors('delivery_method');

        $this->assertSame(CustomerOrder::DELIVERY_METHOD_PICKUP, $order->refresh()->delivery_method);
    }

    public function test_customer_order_item_can_be_added_from_show_page(): void
    {
        $user = $this->adminUser('admin-customer-order-item-add@example.com');
        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_date' => now()->toDateString(),
            'rate' => 41.25,
            'source' => 'monobank',
            'fetched_at' => now(),
        ]);
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://part/add',
            'part_number' => '1034344-20-B',
            'name' => 'Door handle',
            'price_amount' => 150,
            'currency' => 'USD',
            'raw_attributes' => [
                'code' => 'NC-ADD',
                'donor_vin' => '5YJSA1E20GF129213',
                'stock_quantity' => 2,
            ],
        ]);
        $product = Product::query()->create([
            'sku' => 'NC-ADD',
            'external_sku' => '1034344-20-B',
            'name' => 'Door handle',
            'slug' => 'door-handle-show-add',
            'source_part_catalog_item_id' => $catalogItem->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'selling_price' => 150,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $stockItem = $this->createProductStockItem($product, 2);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0009',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'total_amount' => 0,
            'currency' => 'UAH',
        ]);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->post(route('admin.customer-orders.items.store', $order), [
                'product_id' => $product->id,
                'part_catalog_item_id' => $catalogItem->id,
                'quantity' => 2,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order));

        $item = $order->items()->firstOrFail();
        $this->assertSame($product->id, $item->product_id);
        $this->assertSame($catalogItem->id, $item->part_catalog_item_id);
        $this->assertSame('Door handle', $item->name);
        $this->assertSame('1034344-20-B', $item->part_number);
        $this->assertSame('NC-ADD', $item->code);
        $this->assertSame('5YJSA1E20GF129213', $item->donor_vin);
        $this->assertSame('150.00', $item->unit_price_usd_hint);
        $this->assertSame('12380.00', $item->total_price);
        $this->assertSame('12380.00', $order->refresh()->total_amount);
        $this->assertSame(2.0, (float) data_get($catalogItem->refresh()->raw_attributes, 'reserved_quantity'));
        $this->assertContains($order->number, data_get($catalogItem->raw_attributes, 'reserved_orders', []));
        $this->assertSame(2, $stockItem->refresh()->reserved_quantity);
        $this->assertSame(0, $stockItem->available_quantity);
        $this->assertDatabaseHas('reservations', [
            'product_id' => $product->id,
            'stock_item_id' => $stockItem->id,
            'customer_order_id' => 'customer-order:'.$order->id,
            'quantity' => 2,
            'status' => 'active',
        ]);
    }

    public function test_customer_order_item_add_rejects_quantity_above_available_product_stock(): void
    {
        $user = $this->adminUser('admin-customer-order-item-add-quantity-stock@example.com');
        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_date' => now()->toDateString(),
            'rate' => 41.25,
            'source' => 'monobank',
            'fetched_at' => now(),
        ]);
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://part/add-quantity-stock',
            'part_number' => '1034344-20-R',
            'name' => 'Quantity checked handle',
            'price_amount' => 150,
            'currency' => 'USD',
            'raw_attributes' => [
                'code' => 'NC-ADD-QTY',
                'stock_quantity' => 1,
            ],
        ]);
        $product = Product::query()->create([
            'sku' => 'NC-ADD-QTY',
            'external_sku' => '1034344-20-R',
            'name' => 'Quantity checked handle',
            'slug' => 'quantity-checked-handle-show-add',
            'source_part_catalog_item_id' => $catalogItem->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'selling_price' => 150,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $this->createProductStockItem($product);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0013',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'total_amount' => 0,
            'currency' => 'UAH',
        ]);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->post(route('admin.customer-orders.items.store', $order), [
                'product_id' => $product->id,
                'part_catalog_item_id' => $catalogItem->id,
                'quantity' => 2,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order))
            ->assertSessionHasErrors('quantity');

        $this->assertSame(0, $order->items()->count());
        $this->assertSame(0.0, (float) data_get($catalogItem->refresh()->raw_attributes, 'reserved_quantity', 0));
    }

    public function test_customer_order_item_delete_releases_product_stock_reservation(): void
    {
        $user = $this->adminUser('admin-customer-order-item-delete-stock-reservation@example.com');
        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_date' => now()->toDateString(),
            'rate' => 41.25,
            'source' => 'monobank',
            'fetched_at' => now(),
        ]);
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://part/delete-stock-reservation',
            'part_number' => '1034344-20-D',
            'name' => 'Delete reserved handle',
            'price_amount' => 150,
            'currency' => 'USD',
            'raw_attributes' => [
                'code' => 'NC-DELETE-RESERVE',
                'stock_quantity' => 1,
            ],
        ]);
        $product = Product::query()->create([
            'sku' => 'NC-DELETE-RESERVE',
            'external_sku' => '1034344-20-D',
            'name' => 'Delete reserved handle',
            'slug' => 'delete-reserved-handle',
            'source_part_catalog_item_id' => $catalogItem->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'selling_price' => 150,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $stockItem = $this->createProductStockItem($product);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0012',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'total_amount' => 0,
            'currency' => 'UAH',
        ]);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->post(route('admin.customer-orders.items.store', $order), [
                'product_id' => $product->id,
                'part_catalog_item_id' => $catalogItem->id,
                'quantity' => 1,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order));

        $orderItem = $order->items()->firstOrFail();
        $this->assertSame(1, $stockItem->refresh()->reserved_quantity);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->delete(route('admin.customer-orders.items.destroy', [$order, $orderItem]))
            ->assertRedirect(route('admin.customer-orders.show', $order));

        $this->assertSame(0, $stockItem->refresh()->reserved_quantity);
        $this->assertSame(1, $stockItem->available_quantity);
        $this->assertSame(0.0, (float) data_get($catalogItem->refresh()->raw_attributes, 'reserved_quantity', 0));
        $this->assertDatabaseHas('reservations', [
            'product_id' => $product->id,
            'stock_item_id' => $stockItem->id,
            'customer_order_id' => 'customer-order:'.$order->id,
            'status' => 'released',
        ]);
    }

    public function test_customer_order_show_uses_catalog_picker_instead_of_manual_item_form(): void
    {
        $user = $this->adminUser('admin-customer-order-catalog-picker@example.com');
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0014',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'total_amount' => 0,
            'currency' => 'UAH',
        ]);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.show', $order))
            ->assertOk()
            ->assertSee('data-customer-order-add-item', false)
            ->assertSee('customer-orders\\/'.$order->id.'\\/items\\/catalog-search', false)
            ->assertDontSee('name="name"', false)
            ->assertDontSee('name="part_number"', false)
            ->assertDontSee("ID \u{0438}\u{0437} /admin/zapchasti");
    }

    public function test_customer_order_catalog_item_search_returns_nikolacars_parts(): void
    {
        $user = $this->adminUser('admin-customer-order-catalog-search@example.com');
        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_date' => now()->toDateString(),
            'rate' => 41.25,
            'source' => 'monobank',
            'fetched_at' => now(),
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0015',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'total_amount' => 0,
            'currency' => 'UAH',
        ]);
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://part/search',
            'part_number' => '1034344-20-B',
            'name' => 'Door handle',
            'price_amount' => 150,
            'currency' => 'USD',
            'raw_attributes' => [
                'code' => 'NC-SEARCH',
                'donor_vin' => '5YJSA1E20GF129213',
            ],
        ]);
        $product = Product::query()->create([
            'sku' => 'NC-SEARCH',
            'external_sku' => '1034344-20-B',
            'name' => 'Door handle',
            'slug' => 'nc-search',
            'source_part_catalog_item_id' => $item->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 150,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $this->createProductStockItem($product);
        $rawAttributes = $item->raw_attributes->getArrayCopy();
        $rawAttributes['product_id'] = $product->id;
        $item->forceFill(['raw_attributes' => $rawAttributes])->save();
        PartCatalogItem::query()->create([
            'source' => 'tcars',
            'source_url' => 'tcars://part/search',
            'part_number' => '1034344-20-B',
            'name' => 'Competitor handle',
        ]);

        $this->actingAs($user)
            ->getJson(route('admin.customer-orders.items.catalog-search', [$order, 'q' => '1034344']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $item->id)
            ->assertJsonPath('0.url', route('admin.products.show', $product))
            ->assertJsonPath('0.unit_price_uah_text', "6 190 \u{0433}\u{0440}\u{043D}");

        $this->actingAs($user)
            ->getJson(route('admin.customer-orders.items.catalog-search', [$order, 'q' => 'NC-SEARCH']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $item->id);
    }

    public function test_customer_order_catalog_item_search_excludes_fully_reserved_products(): void
    {
        $user = $this->adminUser('admin-customer-order-catalog-search-reserved@example.com');
        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_date' => now()->toDateString(),
            'rate' => 41.25,
            'source' => 'monobank',
            'fetched_at' => now(),
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0016',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'total_amount' => 0,
            'currency' => 'UAH',
        ]);
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://part/fully-reserved-search',
            'part_number' => '6006555-00-C',
            'name' => 'Fully reserved bracket',
            'price_amount' => 5,
            'currency' => 'USD',
            'raw_attributes' => [
                'code' => 'NC-FULLY-RESERVED',
                'stock_quantity' => 1,
            ],
        ]);
        $product = Product::query()->create([
            'sku' => 'NC-FULLY-RESERVED',
            'external_sku' => '6006555-00-C',
            'name' => 'Fully reserved bracket',
            'slug' => 'fully-reserved-bracket',
            'source_part_catalog_item_id' => $catalogItem->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'selling_price' => 5,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $this->createProductStockItem($product);
        $catalogItem->forceFill([
            'raw_attributes' => array_merge($catalogItem->raw_attributes->getArrayCopy(), [
                'product_id' => $product->id,
            ]),
        ])->save();

        $reservedOrder = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0017',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'total_amount' => 210,
            'currency' => 'UAH',
        ]);
        $reservedOrder->items()->create([
            'part_catalog_item_id' => $catalogItem->id,
            'product_id' => $product->id,
            'name' => 'Fully reserved bracket',
            'part_number' => '6006555-00-C',
            'code' => 'NC-FULLY-RESERVED',
            'quantity' => 1,
            'unit_price' => 210,
            'total_price' => 210,
            'currency' => 'UAH',
        ]);

        $this->actingAs($user)
            ->getJson(route('admin.customer-orders.items.catalog-search', [$order, 'q' => 'NC-FULLY-RESERVED']))
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_customer_order_item_can_be_deleted_from_show_page(): void
    {
        $user = $this->adminUser('admin-customer-order-item-delete@example.com');
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://part/delete',
            'part_number' => '1034344-20-B',
            'name' => 'Reserved handle',
            'raw_attributes' => [
                'reserved_quantity' => 1,
                'reserved_orders' => ['ORD-20260601-0010'],
            ],
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0010',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'total_amount' => 6190,
            'currency' => 'UAH',
        ]);
        $item = $order->items()->create([
            'part_catalog_item_id' => $catalogItem->id,
            'name' => 'Reserved handle',
            'quantity' => 1,
            'unit_price' => 6190,
            'total_price' => 6190,
            'currency' => 'UAH',
        ]);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->delete(route('admin.customer-orders.items.destroy', [$order, $item]))
            ->assertRedirect(route('admin.customer-orders.show', $order));

        $this->assertDatabaseMissing('customer_order_items', ['id' => $item->id]);
        $this->assertSame('0.00', $order->refresh()->total_amount);
        $this->assertSame(0.0, (float) data_get($catalogItem->refresh()->raw_attributes, 'reserved_quantity'));
        $this->assertSame([], data_get($catalogItem->raw_attributes, 'reserved_orders'));
    }

    public function test_customer_order_item_uah_price_can_be_updated_from_show_page(): void
    {
        $user = $this->adminUser('admin-customer-order-item-price-update@example.com');
        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_date' => now()->toDateString(),
            'rate' => 40,
            'source' => 'monobank',
            'fetched_at' => now(),
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0012',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'total_amount' => 12380,
            'currency' => 'UAH',
        ]);
        $item = $order->items()->create([
            'name' => 'Door handle',
            'quantity' => 2,
            'unit_price' => 6190,
            'total_price' => 12380,
            'currency' => 'UAH',
            'unit_price_usd_hint' => 150,
            'total_price_usd_hint' => 300,
        ]);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->patch(route('admin.customer-orders.items.update', [$order, $item]), [
                'unit_price' => 7000,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order));

        $this->assertSame('7000.00', $item->refresh()->unit_price);
        $this->assertSame('14000.00', $item->total_price);
        $this->assertSame('175.00', $item->unit_price_usd_hint);
        $this->assertSame('350.00', $item->total_price_usd_hint);
        $this->assertSame('40.000000', $item->usd_exchange_rate);
        $this->assertSame('14000.00', $order->refresh()->total_amount);
        $this->assertSame(350.0, $order->total_amount_usd_hint);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.show', $order))
            ->assertOk()
            ->assertSee('data-customer-order-summary', false)
            ->assertSee('data-customer-order-unit-price', false)
            ->assertSee("14 000 \u{0433}\u{0440}\u{043D}")
            ->assertSee('350.00 USD');
    }

    public function test_customer_order_item_uah_price_update_ceil_rounds_usd_hint(): void
    {
        $user = $this->adminUser('admin-customer-order-item-price-ceil-usd@example.com');
        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_date' => now()->toDateString(),
            'rate' => 44.96,
            'source' => 'monobank',
            'fetched_at' => now(),
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0012-CEIL',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'total_amount' => 1000,
            'currency' => 'UAH',
        ]);
        $item = $order->items()->create([
            'name' => 'Console rear cover',
            'quantity' => 1,
            'unit_price' => 1000,
            'total_price' => 1000,
            'currency' => 'UAH',
            'unit_price_usd_hint' => 23,
            'total_price_usd_hint' => 23,
        ]);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->patch(route('admin.customer-orders.items.update', [$order, $item]), [
                'unit_price' => 1450,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order));

        $this->assertSame('1450.00', $item->refresh()->unit_price);
        $this->assertSame('1450.00', $item->total_price);
        $this->assertSame('33.00', $item->unit_price_usd_hint);
        $this->assertSame('33.00', $item->total_price_usd_hint);
        $this->assertSame('44.960000', $item->usd_exchange_rate);
        $this->assertSame('1450.00', $order->refresh()->total_amount);
        $this->assertSame(33.0, $order->total_amount_usd_hint);
    }

    public function test_customer_order_item_price_update_keeps_inventory_price_unchanged_and_delete_does_not_restore_it(): void
    {
        $user = $this->adminUser('admin-customer-order-item-price-catalog-update@example.com');
        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_date' => now()->toDateString(),
            'rate' => 43,
            'source' => 'monobank',
            'fetched_at' => now(),
        ]);
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://part/price-sync',
            'part_number' => '1034344-20-B',
            'name' => 'Price sync handle',
            'price_amount' => 150,
            'currency' => 'USD',
        ]);
        $product = Product::query()->create([
            'sku' => 'DON4-0559',
            'external_sku' => '1034344-20-B',
            'name' => 'Price sync handle',
            'slug' => 'price-sync-handle',
            'source_part_catalog_item_id' => $catalogItem->id,
            'selling_price' => 150,
            'currency' => 'USD',
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'is_active' => true,
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0016',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'total_amount' => 6450,
            'currency' => 'UAH',
        ]);
        $item = $order->items()->create([
            'part_catalog_item_id' => $catalogItem->id,
            'product_id' => $product->id,
            'name' => 'Price sync handle',
            'quantity' => 1,
            'unit_price' => 6450,
            'total_price' => 6450,
            'currency' => 'UAH',
        ]);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->patch(route('admin.customer-orders.items.update', [$order, $item]), [
                'unit_price' => 7000,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order));

        $this->assertSame('150.00', $product->refresh()->selling_price);
        $this->assertSame('USD', $product->currency);
        $this->assertSame('150.00', $catalogItem->refresh()->price_amount);
        $this->assertSame('USD', $catalogItem->currency);
        $this->assertSame('7000.00', $item->refresh()->unit_price);
        $this->assertSame('163.00', $item->unit_price_usd_hint);
        $this->assertNull($item->catalog_original_price_amount);
        $this->assertNull($item->catalog_original_currency);
        $this->assertFalse($item->catalog_price_snapshot_taken);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->delete(route('admin.customer-orders.items.destroy', [$order, $item]))
            ->assertRedirect(route('admin.customer-orders.show', $order));

        $this->assertSame('150.00', $product->refresh()->selling_price);
        $this->assertSame('USD', $product->currency);
        $this->assertSame('150.00', $catalogItem->refresh()->price_amount);
        $this->assertSame('USD', $catalogItem->currency);
    }

    public function test_cancelled_customer_order_does_not_change_inventory_price_from_order_item_snapshot(): void
    {
        $user = $this->adminUser('admin-customer-order-cancel-price-restore@example.com');
        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_date' => now()->toDateString(),
            'rate' => 43,
            'source' => 'monobank',
            'fetched_at' => now(),
        ]);
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://part/cancel-price-sync',
            'part_number' => '1034345-20-B',
            'name' => 'Cancel price sync handle',
            'price_amount' => 120,
            'currency' => 'USD',
            'raw_attributes' => [
                'reserved_quantity' => 1,
                'reserved_orders' => ['ORD-20260601-0017'],
            ],
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0017',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'total_amount' => 5160,
            'currency' => 'UAH',
        ]);
        $item = $order->items()->create([
            'part_catalog_item_id' => $catalogItem->id,
            'name' => 'Cancel price sync handle',
            'quantity' => 1,
            'unit_price' => 5160,
            'total_price' => 5160,
            'currency' => 'UAH',
            'catalog_original_price_amount' => 90,
            'catalog_original_currency' => 'USD',
            'catalog_price_snapshot_taken' => true,
        ]);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->patch(route('admin.customer-orders.items.update', [$order, $item]), [
                'unit_price' => 8600,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order));

        $this->assertSame('120.00', $catalogItem->refresh()->price_amount);
        $this->assertSame('200.00', $item->refresh()->unit_price_usd_hint);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->patch(route('admin.customer-orders.status.update', $order), [
                'status' => CustomerOrder::STATUS_CANCELLED,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order));

        $this->assertSame('120.00', $catalogItem->refresh()->price_amount);
        $this->assertSame('USD', $catalogItem->currency);
    }

    public function test_retired_active_customer_order_price_sync_command_does_not_change_catalog(): void
    {
        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_date' => now()->toDateString(),
            'rate' => 43,
            'source' => 'monobank',
            'fetched_at' => now(),
        ]);
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://part/backfill-price-sync',
            'part_number' => '1034346-20-B',
            'name' => 'Backfill price sync handle',
            'price_amount' => 100,
            'currency' => 'USD',
        ]);
        $cancelledCatalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://part/backfill-cancelled-price-sync',
            'part_number' => '1034347-20-B',
            'name' => 'Cancelled backfill price sync handle',
            'price_amount' => 90,
            'currency' => 'USD',
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0018',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'total_amount' => 8600,
            'currency' => 'UAH',
        ]);
        $item = $order->items()->create([
            'part_catalog_item_id' => $catalogItem->id,
            'name' => 'Backfill price sync handle',
            'quantity' => 1,
            'unit_price' => 8600,
            'total_price' => 8600,
            'currency' => 'UAH',
        ]);
        $cancelledOrder = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0019',
            'status' => CustomerOrder::STATUS_CANCELLED,
            'total_amount' => 7740,
            'currency' => 'UAH',
        ]);
        $cancelledOrder->items()->create([
            'part_catalog_item_id' => $cancelledCatalogItem->id,
            'name' => 'Cancelled backfill price sync handle',
            'quantity' => 1,
            'unit_price' => 7740,
            'total_price' => 7740,
            'currency' => 'UAH',
        ]);

        $this->artisan('customer-orders:sync-active-prices-to-catalog')
            ->assertExitCode(0);

        $this->assertSame('100.00', $catalogItem->refresh()->price_amount);
        $this->assertSame('USD', $catalogItem->currency);
        $this->assertNull($item->refresh()->catalog_original_price_amount);
        $this->assertNull($item->catalog_original_currency);
        $this->assertFalse($item->catalog_price_snapshot_taken);
        $this->assertSame('90.00', $cancelledCatalogItem->refresh()->price_amount);
    }

    public function test_customer_order_show_converts_legacy_usd_item_price_to_editable_uah_price(): void
    {
        $user = $this->adminUser('admin-customer-order-legacy-usd-price@example.com');
        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_date' => now()->toDateString(),
            'rate' => 43,
            'source' => 'monobank',
            'fetched_at' => now(),
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0013',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'total_amount' => 150,
            'currency' => 'USD',
        ]);
        $order->items()->create([
            'name' => 'Legacy USD item',
            'quantity' => 1,
            'unit_price' => 150,
            'total_price' => 150,
            'currency' => 'USD',
        ]);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.show', $order))
            ->assertOk()
            ->assertSee('value="6450.00"', false)
            ->assertSee("6 450 \u{0433}\u{0440}\u{043D}");

        $this->actingAs($user)
            ->get(route('admin.customer-orders.index'))
            ->assertOk()
            ->assertSee("6 450 \u{0433}\u{0440}\u{043D}");
    }

    public function test_customer_order_show_uses_order_creation_date_usd_rate(): void
    {
        $user = $this->adminUser('admin-customer-order-created-rate@example.com');
        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_date' => '2026-06-01',
            'rate' => 40,
            'source' => 'nbu',
            'fetched_at' => now(),
        ]);
        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_date' => now()->toDateString(),
            'rate' => 50,
            'source' => 'monobank',
            'fetched_at' => now(),
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0013-RATE',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'total_amount' => 100,
            'currency' => 'USD',
        ]);
        $order->forceFill([
            'created_at' => '2026-06-01 12:00:00',
            'updated_at' => '2026-06-01 12:00:00',
        ])->save();
        $order->items()->create([
            'name' => 'Legacy USD item',
            'quantity' => 1,
            'unit_price' => 100,
            'total_price' => 100,
            'currency' => 'USD',
        ]);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.show', $order))
            ->assertOk()
            ->assertSee('data-usd-rate="40', false)
            ->assertSee('value="4000.00"', false)
            ->assertSee('4 000', false)
            ->assertDontSee('5 000', false);
    }

    public function test_deleting_one_item_from_assembled_order_keeps_remaining_items_reserved(): void
    {
        $user = $this->adminUser('admin-customer-order-item-delete-assembled@example.com');
        $deletedCatalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://part/delete-from-assembled',
            'part_number' => '1034344-20-B',
            'name' => 'Deleted reserved handle',
            'raw_attributes' => [
                'reserved_quantity' => 1,
                'reserved_orders' => ['ORD-20260601-0011'],
            ],
        ]);
        $remainingCatalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://part/keep-reserved-assembled',
            'part_number' => '1034345-20-B',
            'name' => 'Remaining reserved handle',
            'raw_attributes' => [
                'reserved_quantity' => 1,
                'reserved_orders' => ['ORD-20260601-0011'],
            ],
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0011',
            'status' => CustomerOrder::STATUS_ASSEMBLED,
            'total_amount' => 12380,
            'currency' => 'UAH',
        ]);
        $deletedItem = $order->items()->create([
            'part_catalog_item_id' => $deletedCatalogItem->id,
            'name' => 'Deleted reserved handle',
            'quantity' => 1,
            'unit_price' => 6190,
            'total_price' => 6190,
            'currency' => 'UAH',
        ]);
        $order->items()->create([
            'part_catalog_item_id' => $remainingCatalogItem->id,
            'name' => 'Remaining reserved handle',
            'quantity' => 1,
            'unit_price' => 6190,
            'total_price' => 6190,
            'currency' => 'UAH',
        ]);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->delete(route('admin.customer-orders.items.destroy', [$order, $deletedItem]))
            ->assertRedirect(route('admin.customer-orders.show', $order));

        $this->assertSame(0.0, (float) data_get($deletedCatalogItem->refresh()->raw_attributes, 'reserved_quantity'));
        $this->assertSame([], data_get($deletedCatalogItem->raw_attributes, 'reserved_orders'));
        $this->assertSame(1.0, (float) data_get($remainingCatalogItem->refresh()->raw_attributes, 'reserved_quantity'));
        $this->assertSame([$order->number], data_get($remainingCatalogItem->raw_attributes, 'reserved_orders'));
    }

    public function test_customer_order_can_be_marked_as_assembled(): void
    {
        $user = $this->adminUser('admin-customer-order-status@example.com');
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0004',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'total_amount' => 0,
            'currency' => 'USD',
        ]);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->patch(route('admin.customer-orders.status.update', $order), [
                'status' => CustomerOrder::STATUS_ASSEMBLED,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order))
            ->assertSessionHasNoErrors();

        $this->assertSame(CustomerOrder::STATUS_ASSEMBLED, $order->refresh()->status);
        $this->assertSame("\u{0421}\u{043E}\u{0431}\u{0440}\u{0430}\u{043D}", $order->status_label);
    }

    public function test_customer_order_can_be_cancelled_and_releases_catalog_reservations(): void
    {
        $user = $this->adminUser('admin-customer-order-cancel@example.com');
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://part/cancel-reserved',
            'part_number' => '1034344-20-B',
            'name' => 'Reserved handle',
            'raw_attributes' => [
                'reserved_quantity' => 2,
                'reserved_orders' => ['ORD-20260601-0009'],
            ],
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0009',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'total_amount' => 100,
            'currency' => 'UAH',
        ]);
        $order->items()->create([
            'part_catalog_item_id' => $item->id,
            'name' => 'Reserved handle',
            'quantity' => 2,
            'unit_price' => 50,
            'total_price' => 100,
            'currency' => 'UAH',
        ]);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.index'))
            ->assertOk()
            ->assertSee("\u{041E}\u{0442}\u{043C}\u{0435}\u{043D}\u{0438}\u{0442}\u{044C}");

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->patch(route('admin.customer-orders.status.update', $order), [
                'status' => CustomerOrder::STATUS_CANCELLED,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order));

        $this->assertSame(CustomerOrder::STATUS_CANCELLED, $order->refresh()->status);
        $this->assertSame("\u{041E}\u{0442}\u{043C}\u{0435}\u{043D}\u{0435}\u{043D}", $order->status_label);

        $item->refresh();
        $this->assertSame(0.0, (float) data_get($item->raw_attributes, 'reserved_quantity'));
        $this->assertSame([], data_get($item->raw_attributes, 'reserved_orders'));
    }

    public function test_cancelling_nova_poshta_order_deletes_existing_ttn(): void
    {
        $user = $this->adminUser('admin-customer-order-cancel-np-ttn@example.com');
        config([
            'services.nova_poshta.api_key' => 'test-api-key',
            'services.nova_poshta.api_url' => 'https://api.novaposhta.test/v2.0/json/',
            'services.nova_poshta.sender_city_ref' => 'sender-city-ref',
            'services.nova_poshta.sender_ref' => 'sender-ref',
            'services.nova_poshta.sender_address_ref' => 'sender-address-ref',
            'services.nova_poshta.sender_contact_ref' => 'sender-contact-ref',
            'services.nova_poshta.sender_phone' => '0500000000',
        ]);
        Http::fake([
            'https://api.novaposhta.test/v2.0/json/' => Http::response([
                'success' => true,
                'data' => [['Ref' => 'np-document-ref']],
                'errors' => [],
                'warnings' => [],
                'info' => [],
            ], 200),
        ]);

        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0031',
            'status' => CustomerOrder::STATUS_ASSEMBLED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA,
            'total_amount' => 100,
            'currency' => 'UAH',
        ]);
        $order->novaPoshtaShipment()->create([
            'carrier' => CustomerOrderShipment::CARRIER_NOVA_POSHTA,
            'status' => CustomerOrderShipment::STATUS_CREATED,
            'np_ref' => 'np-document-ref',
            'tracking_number' => '20450000000000',
        ]);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->patch(route('admin.customer-orders.status.update', $order), [
                'status' => CustomerOrder::STATUS_CANCELLED,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order));

        $order->refresh()->load('novaPoshtaShipment');

        $this->assertSame(CustomerOrder::STATUS_CANCELLED, $order->status);
        $this->assertSame(CustomerOrderShipment::STATUS_CANCELLED, $order->novaPoshtaShipment?->status);
        $this->assertSame('np-document-ref', $order->novaPoshtaShipment?->np_ref);
        $this->assertSame('20450000000000', $order->novaPoshtaShipment?->tracking_number);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $payload['modelName'] === 'InternetDocument'
                && $payload['calledMethod'] === 'delete'
                && data_get($payload, 'methodProperties.DocumentRefs.0') === 'np-document-ref';
        });

        $this->assertDatabaseHas('customer_order_history_events', [
            'customer_order_id' => $order->id,
            'event_type' => 'nova_poshta_ttn_deleted',
        ]);
    }

    public function test_nova_poshta_order_is_not_cancelled_when_ttn_delete_fails(): void
    {
        $user = $this->adminUser('admin-customer-order-cancel-np-ttn-fails@example.com');
        config([
            'services.nova_poshta.api_key' => 'test-api-key',
            'services.nova_poshta.api_url' => 'https://api.novaposhta.test/v2.0/json/',
            'services.nova_poshta.sender_city_ref' => 'sender-city-ref',
            'services.nova_poshta.sender_ref' => 'sender-ref',
            'services.nova_poshta.sender_address_ref' => 'sender-address-ref',
            'services.nova_poshta.sender_contact_ref' => 'sender-contact-ref',
            'services.nova_poshta.sender_phone' => '0500000000',
        ]);
        Http::fake([
            'https://api.novaposhta.test/v2.0/json/' => Http::response([
                'success' => false,
                'data' => [],
                'errors' => ['Document cannot be deleted'],
                'warnings' => [],
                'info' => [],
            ], 200),
        ]);

        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0032',
            'status' => CustomerOrder::STATUS_ASSEMBLED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA,
            'total_amount' => 100,
            'currency' => 'UAH',
        ]);
        $order->novaPoshtaShipment()->create([
            'carrier' => CustomerOrderShipment::CARRIER_NOVA_POSHTA,
            'status' => CustomerOrderShipment::STATUS_CREATED,
            'np_ref' => 'np-document-ref',
            'tracking_number' => '20450000000001',
        ]);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->patch(route('admin.customer-orders.status.update', $order), [
                'status' => CustomerOrder::STATUS_CANCELLED,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order))
            ->assertSessionHasErrors('nova_poshta');

        $order->refresh()->load('novaPoshtaShipment');

        $this->assertSame(CustomerOrder::STATUS_ASSEMBLED, $order->status);
        $this->assertSame(CustomerOrderShipment::STATUS_CREATED, $order->novaPoshtaShipment?->status);
        $this->assertSame('20450000000001', $order->novaPoshtaShipment?->tracking_number);
    }

    public function test_cancelling_nova_poshta_order_accepts_already_deleted_ttn_status(): void
    {
        $user = $this->adminUser('admin-customer-order-cancel-np-ttn-already-deleted@example.com');
        config([
            'services.nova_poshta.api_key' => 'test-api-key',
            'services.nova_poshta.api_url' => 'https://api.novaposhta.test/v2.0/json/',
            'services.nova_poshta.sender_city_ref' => 'sender-city-ref',
            'services.nova_poshta.sender_ref' => 'sender-ref',
            'services.nova_poshta.sender_address_ref' => 'sender-address-ref',
            'services.nova_poshta.sender_contact_ref' => 'sender-contact-ref',
            'services.nova_poshta.sender_phone' => '0500000000',
        ]);
        Http::fake([
            'https://api.novaposhta.test/v2.0/json/' => Http::sequence()
                ->push([
                    'success' => false,
                    'data' => [],
                    'errors' => [
                        'Error getting payment info 20450000000002',
                        'No document changed DeletionMark',
                    ],
                    'warnings' => [],
                    'info' => [],
                ], 200)
                ->push([
                    'success' => true,
                    'data' => [[
                        'Number' => '20450000000002',
                        'StatusCode' => '2',
                        'Status' => "\u{0412}\u{0438}\u{0434}\u{0430}\u{043B}\u{0435}\u{043D}\u{043E}",
                    ]],
                    'errors' => [],
                    'warnings' => [],
                    'info' => [],
                ], 200),
        ]);

        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0033',
            'status' => CustomerOrder::STATUS_ASSEMBLED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA,
            'total_amount' => 100,
            'currency' => 'UAH',
        ]);
        $order->novaPoshtaShipment()->create([
            'carrier' => CustomerOrderShipment::CARRIER_NOVA_POSHTA,
            'status' => CustomerOrderShipment::STATUS_CREATED,
            'np_ref' => 'np-document-ref',
            'tracking_number' => '20450000000002',
            'recipient_phone' => '0771007442',
        ]);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->patch(route('admin.customer-orders.status.update', $order), [
                'status' => CustomerOrder::STATUS_CANCELLED,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order));

        $order->refresh()->load('novaPoshtaShipment');

        $this->assertSame(CustomerOrder::STATUS_CANCELLED, $order->status);
        $this->assertSame(CustomerOrderShipment::STATUS_CANCELLED, $order->novaPoshtaShipment?->status);
        $this->assertSame('20450000000002', $order->novaPoshtaShipment?->tracking_number);

        Http::assertSentCount(2);
        $this->assertDatabaseHas('customer_order_history_events', [
            'customer_order_id' => $order->id,
            'event_type' => 'nova_poshta_ttn_deleted',
        ]);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.show', $order))
            ->assertOk()
            ->assertSee('20450000000002')
            ->assertSee("\u{0423}\u{0434}\u{0430}\u{043B}\u{0435}\u{043D}\u{0430}")
            ->assertDontSee(route('admin.customer-orders.nova-poshta.label', $order), false);
    }

    public function test_fully_prepaid_customer_order_cannot_be_cancelled(): void
    {
        $user = $this->adminUser('admin-customer-order-fully-prepaid-cancel@example.com');
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://part/fully-prepaid-cancel',
            'part_number' => '1034344-20-F',
            'name' => 'Fully prepaid reserved handle',
            'raw_attributes' => [
                'reserved_quantity' => 1,
                'reserved_orders' => ['ORD-20260601-0024'],
            ],
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0024',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'total_amount' => 1500,
            'currency' => 'UAH',
            'payment_type' => CustomerOrder::PAYMENT_TYPE_CASH_UAH,
            'payment_received_amount' => 1500,
            'payment_received_amount_uah' => 1500,
            'paid_cash_uah' => 1500,
            'paid_amount_uah' => 1500,
        ]);
        $order->items()->create([
            'part_catalog_item_id' => $item->id,
            'name' => 'Fully prepaid reserved handle',
            'quantity' => 1,
            'unit_price' => 1500,
            'total_price' => 1500,
            'currency' => 'UAH',
        ]);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.index'))
            ->assertOk()
            ->assertSee($order->number)
            ->assertDontSee("\u{041E}\u{0442}\u{043C}\u{0435}\u{043D}\u{0438}\u{0442}\u{044C} \u{0437}\u{0430}\u{043A}\u{0430}\u{0437} {$order->number}", false);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.show', $order))
            ->assertOk()
            ->assertDontSee("\u{041E}\u{0442}\u{043C}\u{0435}\u{043D}\u{0438}\u{0442}\u{044C} \u{0437}\u{0430}\u{043A}\u{0430}\u{0437} {$order->number}", false);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->patch(route('admin.customer-orders.status.update', $order), [
                'status' => CustomerOrder::STATUS_CANCELLED,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order))
            ->assertSessionHasErrors('status');

        $this->assertSame(CustomerOrder::STATUS_PROCESSING, $order->refresh()->status);
        $item->refresh();
        $this->assertSame(1.0, (float) data_get($item->raw_attributes, 'reserved_quantity'));
        $this->assertSame([$order->number], data_get($item->raw_attributes, 'reserved_orders'));
    }

    public function test_assembled_customer_order_cannot_be_marked_as_assembled_again(): void
    {
        $user = $this->adminUser('admin-customer-order-status-invalid@example.com');
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0005',
            'status' => CustomerOrder::STATUS_ASSEMBLED,
            'total_amount' => 0,
            'currency' => 'USD',
        ]);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->patch(route('admin.customer-orders.status.update', $order), [
                'status' => CustomerOrder::STATUS_ASSEMBLED,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order))
            ->assertSessionHasErrors('status');

        $this->assertSame(CustomerOrder::STATUS_ASSEMBLED, $order->refresh()->status);
    }

    public function test_customer_orders_index_shows_delivery_method_without_open_button(): void
    {
        $user = $this->adminUser('admin-customer-order-index@example.com');
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0003',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'client_first_name' => 'Ivan',
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'total_amount' => 0,
            'currency' => 'USD',
        ]);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.index'))
            ->assertOk()
            ->assertSee($order->number)
            ->assertSee("\u{0421}\u{043F}\u{043E}\u{0441}\u{043E}\u{0431}<br>\u{043F}\u{043E}\u{043B}\u{0443}\u{0447}\u{0435}\u{043D}\u{0438}\u{044F}", false)
            ->assertSee($order->delivery_method_label)
            ->assertDontSee(">\u{041E}\u{0442}\u{043A}\u{0440}\u{044B}\u{0442}\u{044C}</a>", false);
    }

    public function test_assembled_pickup_and_nova_poshta_orders_are_shown_in_active_list(): void
    {
        $user = $this->adminUser('admin-customer-order-pickup-waiting@example.com');
        $processingOrder = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0006',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'client_first_name' => 'Processing',
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'total_amount' => 0,
            'currency' => 'USD',
        ]);
        $waitingPickupOrder = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0007',
            'status' => CustomerOrder::STATUS_ASSEMBLED,
            'client_first_name' => 'Waiting',
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'total_amount' => 0,
            'currency' => 'USD',
        ]);
        $assembledNovaPoshtaOrder = CustomerOrder::query()->create([
            'number' => 'ORD-20260601-0008',
            'status' => CustomerOrder::STATUS_ASSEMBLED,
            'client_first_name' => 'Shipping',
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA,
            'total_amount' => 0,
            'currency' => 'USD',
        ]);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.index'))
            ->assertOk()
            ->assertSeeInOrder([
                $assembledNovaPoshtaOrder->number,
                $waitingPickupOrder->number,
                $processingOrder->number,
            ])
            ->assertDontSee("\u{0421}\u{043E}\u{0431}\u{0440}\u{0430}\u{043D}\u{044B}");
    }

    public function test_assembled_nova_poshta_customer_order_cannot_be_manually_marked_as_shipped_and_keeps_reservation(): void
    {
        $user = $this->adminUser('admin-customer-order-shipped@example.com');
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://part/shipped-reserved',
            'part_number' => '1034344-20-B',
            'name' => 'Shipped reserved handle',
            'raw_attributes' => [
                'reserved_quantity' => 1,
                'reserved_orders' => ['ORD-20260604-0001'],
            ],
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260604-0001',
            'status' => CustomerOrder::STATUS_ASSEMBLED,
            'client_first_name' => 'Shipping',
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA,
            'total_amount' => 1500,
            'currency' => 'UAH',
        ]);
        $order->items()->create([
            'part_catalog_item_id' => $catalogItem->id,
            'name' => 'Shipped reserved handle',
            'part_number' => '1034344-20-B',
            'quantity' => 1,
            'unit_price' => 1500,
            'total_price' => 1500,
            'currency' => 'UAH',
        ]);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.index'))
            ->assertOk()
            ->assertSee($order->number)
            ->assertDontSee('value="'.CustomerOrder::STATUS_SHIPPED.'"', false);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->patch(route('admin.customer-orders.status.update', $order), [
                'status' => CustomerOrder::STATUS_SHIPPED,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order))
            ->assertSessionHasErrors('status');

        $this->assertSame(CustomerOrder::STATUS_ASSEMBLED, $order->refresh()->status);
        $this->assertSame(1.0, (float) data_get($catalogItem->refresh()->raw_attributes, 'reserved_quantity'));
        $this->assertSame([$order->number], data_get($catalogItem->raw_attributes, 'reserved_orders'));

        $this->assertDatabaseMissing('customer_order_history_events', [
            'customer_order_id' => $order->id,
            'event_type' => 'status_changed',
        ]);
    }

    public function test_pickup_customer_order_cannot_be_marked_as_shipped(): void
    {
        $user = $this->adminUser('admin-customer-order-shipped-invalid@example.com');
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260604-0002',
            'status' => CustomerOrder::STATUS_ASSEMBLED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'total_amount' => 0,
            'currency' => 'UAH',
        ]);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->patch(route('admin.customer-orders.status.update', $order), [
                'status' => CustomerOrder::STATUS_SHIPPED,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order))
            ->assertSessionHasErrors('status');

        $this->assertSame(CustomerOrder::STATUS_ASSEMBLED, $order->refresh()->status);
    }

    public function test_customer_order_client_search_finds_counterparty_by_phone(): void
    {
        $user = $this->adminUser('admin-customer-order-phone@example.com');
        Counterparty::query()->create([
            'type' => Counterparty::TYPE_PARTS,
            'name' => 'Ivan Petrov',
            'phone' => '+38 (050) 111-22-33',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->getJson(route('admin.customer-orders.clients.search', ['phone' => '0501112233']))
            ->assertOk()
            ->assertJsonPath('0.first_name', 'Ivan')
            ->assertJsonPath('0.last_name', 'Petrov');
    }

    public function test_customer_order_client_search_marks_anonymous_counterparty_for_pickup(): void
    {
        $user = $this->adminUser('admin-customer-order-anonymous-phone@example.com');
        Counterparty::query()->create([
            'type' => Counterparty::TYPE_PARTS,
            'name' => Counterparty::ANONYMOUS_NAME,
            'phone' => Counterparty::ANONYMOUS_PHONE,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->getJson(route('admin.customer-orders.clients.search', ['phone' => Counterparty::ANONYMOUS_PHONE]))
            ->assertOk()
            ->assertJsonPath('0.name', Counterparty::ANONYMOUS_NAME)
            ->assertJsonPath('0.is_anonymous', true)
            ->assertJsonPath('0.default_delivery_method', CustomerOrder::DELIVERY_METHOD_PICKUP);
    }

    public function test_nova_poshta_city_suggestions_use_directory_api(): void
    {
        $user = $this->adminUser('admin-customer-order-np-city-suggestions@example.com');
        config([
            'services.nova_poshta.api_key' => 'test-api-key',
            'services.nova_poshta.api_url' => 'https://api.novaposhta.test/v2.0/json/',
        ]);
        Http::fake([
            'api.novaposhta.test/*' => Http::response([
                'success' => true,
                'data' => [[
                    'Ref' => 'city-ref',
                    'Description' => "\u{041A}\u{0438}\u{0457}\u{0432}",
                    'DescriptionRu' => "\u{041A}\u{0438}\u{0435}\u{0432}",
                    'AreaDescription' => "\u{041A}\u{0438}\u{0457}\u{0432}\u{0441}\u{044C}\u{043A}\u{0430}",
                    'SettlementTypeDescription' => "\u{043C}\u{0456}\u{0441}\u{0442}\u{043E}",
                ]],
            ]),
        ]);

        $this->actingAs($user)
            ->getJson(route('admin.customer-orders.nova-poshta.cities', ['query' => "\u{041A}\u{0438}\u{0457}"]))
            ->assertOk()
            ->assertJsonPath('0.ref', 'city-ref')
            ->assertJsonPath('0.description', "\u{041A}\u{0438}\u{0457}\u{0432}");

        Http::assertSent(fn ($request): bool => $request['modelName'] === 'Address'
            && $request['calledMethod'] === 'getCities'
            && $request['methodProperties']['FindByString'] === "\u{041A}\u{0438}\u{0457}");
    }

    public function test_nova_poshta_city_suggestions_report_missing_configuration(): void
    {
        $user = $this->adminUser('admin-customer-order-np-city-missing-config@example.com');
        config([
            'services.nova_poshta.api_key' => null,
            'services.nova_poshta.api_url' => 'https://api.novaposhta.test/v2.0/json/',
        ]);
        Http::fake();

        $this->actingAs($user)
            ->getJson(route('admin.customer-orders.nova-poshta.cities', ['query' => "\u{041A}\u{0438}\u{0457}"]))
            ->assertStatus(503)
            ->assertJsonPath('message', 'Nova Poshta is not configured.');

        Http::assertNothingSent();
    }

    public function test_nova_poshta_warehouse_suggestions_use_selected_city_ref(): void
    {
        $user = $this->adminUser('admin-customer-order-np-warehouse-suggestions@example.com');
        config([
            'services.nova_poshta.api_key' => 'test-api-key',
            'services.nova_poshta.api_url' => 'https://api.novaposhta.test/v2.0/json/',
        ]);
        Http::fake([
            'api.novaposhta.test/*' => Http::response([
                'success' => true,
                'data' => [[
                    'Ref' => 'warehouse-ref',
                    'Description' => "\u{0412}\u{0456}\u{0434}\u{0434}\u{0456}\u{043B}\u{0435}\u{043D}\u{043D}\u{044F} \u{2116}203",
                    'DescriptionRu' => "\u{041E}\u{0442}\u{0434}\u{0435}\u{043B}\u{0435}\u{043D}\u{0438}\u{0435} \u{2116}203",
                    'Number' => '203',
                    'CategoryOfWarehouse' => 'Branch',
                    'TypeOfWarehouse' => 'Warehouse',
                ]],
            ]),
        ]);

        $this->actingAs($user)
            ->getJson(route('admin.customer-orders.nova-poshta.warehouses', [
                'city_ref' => 'city-ref',
                'query' => '203',
            ]))
            ->assertOk()
            ->assertJsonPath('0.ref', 'warehouse-ref')
            ->assertJsonPath('0.number', '203');

        Http::assertSent(fn ($request): bool => $request['modelName'] === 'Address'
            && $request['calledMethod'] === 'getWarehouses'
            && $request['methodProperties']['CityRef'] === 'city-ref'
            && $request['methodProperties']['FindByString'] === '203');
    }

    public function test_nova_poshta_tracking_number_suggestions_exclude_final_and_used_ttns(): void
    {
        $user = $this->adminUser('admin-customer-order-np-ttn-suggestions@example.com');
        config([
            'services.nova_poshta.api_key' => 'test-api-key',
            'services.nova_poshta.api_url' => 'https://api.novaposhta.test/v2.0/json/',
        ]);
        Http::fake([
            'api.novaposhta.test/*' => Http::response([
                'success' => true,
                'data' => [
                    [
                        'Ref' => 'available-ref',
                        'IntDocNumber' => '20450000001000',
                        'DateTime' => '24.06.2026',
                        'StateName' => "\u{0412}\u{0456}\u{0434}\u{043F}\u{0440}\u{0430}\u{0432}\u{043D}\u{0438}\u{043A} \u{0441}\u{0430}\u{043C}\u{043E}\u{0441}\u{0442}\u{0456}\u{0439}\u{043D}\u{043E} \u{0441}\u{0442}\u{0432}\u{043E}\u{0440}\u{0438}\u{0432}",
                        'RecipientDescription' => 'Ivan Petrov',
                        'CityRecipientDescription' => 'Kyiv',
                    ],
                    [
                        'Ref' => 'received-ref',
                        'IntDocNumber' => '20450000001001',
                        'StateName' => "\u{041E}\u{0442}\u{0440}\u{0438}\u{043C}\u{0430}\u{043D}\u{043E}",
                    ],
                    [
                        'Ref' => 'refused-ref',
                        'IntDocNumber' => '20450000001002',
                        'StateName' => "\u{0412}\u{0456}\u{0434}\u{043C}\u{043E}\u{0432}\u{0430} \u{043E}\u{0434}\u{0435}\u{0440}\u{0436}\u{0443}\u{0432}\u{0430}\u{0447}\u{0430}",
                    ],
                    [
                        'Ref' => 'used-ref',
                        'IntDocNumber' => '20450000001003',
                        'StateName' => "\u{041E}\u{0447}\u{0456}\u{043A}\u{0443}\u{0454} \u{0432}\u{0456}\u{0434}\u{043F}\u{0440}\u{0430}\u{0432}\u{043B}\u{0435}\u{043D}\u{043D}\u{044F}",
                    ],
                ],
                'errors' => [],
                'warnings' => [],
                'info' => [],
            ], 200),
        ]);

        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260603-0012-NP-TTN-SUG',
            'status' => CustomerOrder::STATUS_ASSEMBLED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA,
            'total_amount' => 1500,
            'currency' => 'UAH',
        ]);
        $otherOrder = CustomerOrder::query()->create([
            'number' => 'ORD-20260603-0012-NP-TTN-USED',
            'status' => CustomerOrder::STATUS_ASSEMBLED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA,
            'total_amount' => 1500,
            'currency' => 'UAH',
        ]);
        $otherOrder->novaPoshtaShipment()->create([
            'carrier' => CustomerOrderShipment::CARRIER_NOVA_POSHTA,
            'status' => CustomerOrderShipment::STATUS_CREATED,
            'tracking_number' => '20450000001003',
        ]);

        $this->actingAs($user)
            ->getJson(route('admin.customer-orders.nova-poshta.tracking-number.suggestions', [
                $order,
                'query' => '204500000010',
            ]))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.tracking_number', '20450000001000')
            ->assertJsonPath('0.ref', 'available-ref')
            ->assertJsonPath('0.recipient', 'Ivan Petrov')
            ->assertJsonPath('0.city', 'Kyiv');

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $payload['modelName'] === 'InternetDocument'
                && $payload['calledMethod'] === 'getDocumentList'
                && data_get($payload, 'methodProperties.DateTimeFrom') !== null
                && data_get($payload, 'methodProperties.DateTimeTo') !== null;
        });
    }

    public function test_customer_orders_index_has_available_nova_poshta_ttn_panel(): void
    {
        $user = $this->adminUser('admin-customer-order-np-ttn-panel@example.com');

        $this->actingAs($user)
            ->get(route('admin.customer-orders.index'))
            ->assertOk()
            ->assertSee('data-customer-order-available-ttns', false)
            ->assertSee('hidden', false)
            ->assertSee(route('admin.customer-orders.nova-poshta.tracking-number.suggestions.available'), false);
    }

    public function test_available_nova_poshta_tracking_number_suggestions_exclude_all_used_ttns(): void
    {
        $user = $this->adminUser('admin-customer-order-np-available-ttn-suggestions@example.com');
        config([
            'services.nova_poshta.api_key' => 'test-api-key',
            'services.nova_poshta.api_url' => 'https://api.novaposhta.test/v2.0/json/',
        ]);
        Http::fake([
            'api.novaposhta.test/*' => Http::response([
                'success' => true,
                'data' => [
                    [
                        'Ref' => 'available-ref',
                        'IntDocNumber' => '20450000002000',
                        'StateName' => "\u{041E}\u{0447}\u{0456}\u{043A}\u{0443}\u{0454} \u{0432}\u{0456}\u{0434}\u{043F}\u{0440}\u{0430}\u{0432}\u{043B}\u{0435}\u{043D}\u{043D}\u{044F}",
                    ],
                    [
                        'Ref' => 'used-ref',
                        'IntDocNumber' => '20450000002001',
                        'StateName' => "\u{041E}\u{0447}\u{0456}\u{043A}\u{0443}\u{0454} \u{0432}\u{0456}\u{0434}\u{043F}\u{0440}\u{0430}\u{0432}\u{043B}\u{0435}\u{043D}\u{043D}\u{044F}",
                    ],
                ],
                'errors' => [],
                'warnings' => [],
                'info' => [],
            ], 200),
        ]);

        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260603-0012-NP-TTN-GLOBAL',
            'status' => CustomerOrder::STATUS_ASSEMBLED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA,
            'total_amount' => 1500,
            'currency' => 'UAH',
        ]);
        $order->novaPoshtaShipment()->create([
            'carrier' => CustomerOrderShipment::CARRIER_NOVA_POSHTA,
            'status' => CustomerOrderShipment::STATUS_CREATED,
            'tracking_number' => '20450000002001',
        ]);

        $this->actingAs($user)
            ->getJson(route('admin.customer-orders.nova-poshta.tracking-number.suggestions.available'))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.tracking_number', '20450000002000');
    }

    public function test_customer_order_show_displays_order_history_events(): void
    {
        $user = $this->adminUser('admin-customer-order-history@example.com');
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260603-0001',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'client_first_name' => 'Ivan',
            'client_last_name' => 'Petrov',
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'total_amount' => 0,
            'currency' => 'UAH',
        ]);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.show', $order))
            ->assertOk()
            ->assertSee("\u{0418}\u{0441}\u{0442}\u{043E}\u{0440}\u{0438}\u{044F} \u{0437}\u{0430}\u{043A}\u{0430}\u{0437}\u{0430}")
            ->assertSee("\u{0417}\u{0430}\u{043A}\u{0430}\u{0437} \u{0441}\u{043E}\u{0437}\u{0434}\u{0430}\u{043D}")
            ->assertSee('Ivan Petrov');

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->patch(route('admin.customer-orders.status.update', $order), [
                'status' => CustomerOrder::STATUS_ASSEMBLED,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order))
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->post(route('admin.customer-orders.items.store', $order), [
                'name' => 'Door handle',
                'part_number' => '1034344-20-B',
                'quantity' => 1,
                'unit_price' => 1000,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order));

        $orderItem = $order->items()->firstOrFail();

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->patch(route('admin.customer-orders.items.update', [$order, $orderItem]), [
                'unit_price' => 1200,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order));

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->delete(route('admin.customer-orders.items.destroy', [$order, $orderItem]))
            ->assertRedirect(route('admin.customer-orders.show', $order));

        $this->assertSame(
            [
                'status_changed',
                'item_added',
                'item_price_changed',
                'item_removed',
            ],
            CustomerOrderHistoryEvent::query()
                ->where('customer_order_id', $order->id)
                ->orderBy('id')
                ->pluck('event_type')
                ->all(),
        );

        $this->actingAs($user)
            ->get(route('admin.customer-orders.show', $order))
            ->assertOk()
            ->assertSee("\u{0421}\u{0442}\u{0430}\u{0442}\u{0443}\u{0441} \u{0438}\u{0437}\u{043C}\u{0435}\u{043D}\u{0435}\u{043D}")
            ->assertSee("\u{0417}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{044C} \u{0434}\u{043E}\u{0431}\u{0430}\u{0432}\u{043B}\u{0435}\u{043D}\u{0430}")
            ->assertSee("\u{0426}\u{0435}\u{043D}\u{0430} \u{0437}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{0438} \u{0438}\u{0437}\u{043C}\u{0435}\u{043D}\u{0435}\u{043D}\u{0430}")
            ->assertSee("\u{0417}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{044C} \u{0443}\u{0434}\u{0430}\u{043B}\u{0435}\u{043D}\u{0430}")
            ->assertSee('Door handle')
            ->assertSee("1 000 \u{0433}\u{0440}\u{043D}")
            ->assertSee("1 200 \u{0433}\u{0440}\u{043D}");
    }

    public function test_assembled_customer_order_shows_waiting_payment_controls(): void
    {
        $user = $this->adminUser('admin-customer-order-payment-controls@example.com');
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260603-0010',
            'status' => CustomerOrder::STATUS_ASSEMBLED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'total_amount' => 1500,
            'currency' => 'UAH',
        ]);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.index'))
            ->assertOk()
            ->assertSee($order->number)
            ->assertDontSee("\u{041E}\u{0436}\u{0438}\u{0434}\u{0430}\u{0435}\u{0442} \u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0443}")
            ->assertSee("\u{041F}\u{043E}\u{0434}\u{0442}\u{0432}\u{0435}\u{0440}\u{0434}\u{0438}\u{0442}\u{044C} \u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0443}")
            ->assertSee("\u{0422}\u{0438}\u{043F} \u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{044B}")
            ->assertSee("\u{041D}\u{0430}\u{043B}, \u{0433}\u{0440}\u{043D}")
            ->assertSee("\u{041D}\u{0430}\u{043B} USD")
            ->assertSee("\u{0411}\u{0435}\u{0437}\u{041D}\u{0430}\u{043B} \u{0422}\u{041E}\u{0412}")
            ->assertSee("\u{0411}\u{0435}\u{0437}\u{041D}\u{0430}\u{043B} \u{0424}\u{041E}\u{041F}")
            ->assertSee("\u{041F}\u{043E}\u{043B}\u{0443}\u{0447}\u{0435}\u{043D}\u{043D}\u{0430}\u{044F} \u{0441}\u{0443}\u{043C}\u{043C}\u{0430}");
    }

    public function test_sto_customer_order_can_be_issued_without_payment_and_hides_payment_controls(): void
    {
        $user = $this->adminUser('admin-customer-order-sto-no-payment@example.com');
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260603-0010-STO',
            'status' => CustomerOrder::STATUS_ASSEMBLED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_STO,
            'total_amount' => 1500,
            'currency' => 'UAH',
        ]);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.index'))
            ->assertOk()
            ->assertSee($order->number)
            ->assertSee('value="'.CustomerOrder::STATUS_COMPLETED.'"', false)
            ->assertDontSee("\u{041E}\u{0436}\u{0438}\u{0434}\u{0430}\u{0435}\u{0442} \u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0443}")
            ->assertDontSee(route('admin.customer-orders.prepayment.store', $order), false)
            ->assertDontSee(route('admin.customer-orders.payment.confirm', $order), false);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.show', $order))
            ->assertOk()
            ->assertSee('value="'.CustomerOrder::STATUS_COMPLETED.'"', false)
            ->assertDontSee(route('admin.customer-orders.prepayment.store', $order), false)
            ->assertDontSee(route('admin.customer-orders.payment.confirm', $order), false);

        $this->actingAs($user)
            ->post(route('admin.customer-orders.prepayment.store', $order), [
                'payment_type' => CustomerOrder::PAYMENT_TYPE_CASH_UAH,
                'received_amount' => 1500,
            ])
            ->assertStatus(404);

        $this->actingAs($user)
            ->post(route('admin.customer-orders.payment.confirm', $order), [
                'payment_type' => CustomerOrder::PAYMENT_TYPE_CASH_UAH,
                'received_amount' => 1500,
            ])
            ->assertStatus(404);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->patch(route('admin.customer-orders.status.update', $order), [
                'status' => CustomerOrder::STATUS_COMPLETED,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order));

        $order->refresh();

        $this->assertSame(CustomerOrder::STATUS_COMPLETED, $order->status);
        $this->assertSame(0.0, (float) $order->paid_amount_uah);
        $this->assertNull($order->payment_confirmed_at);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.show', $order))
            ->assertOk()
            ->assertSee("\u{0411}\u{0435}\u{0437} \u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{044B}");
    }

    public function test_customer_order_cash_summary_reports_completed_sto_parts_separately(): void
    {
        CustomerOrder::query()->create([
            'number' => 'ORD-20260603-0010-CASH',
            'status' => CustomerOrder::STATUS_PAID,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'total_amount' => 1500,
            'currency' => 'UAH',
            'paid_cash_uah' => 100,
            'paid_cash_usd' => 10,
            'paid_bank_tov_uah' => 200,
            'paid_bank_fop_uah' => 300,
            'paid_amount_uah' => 1000,
        ]);
        CustomerOrder::query()->create([
            'number' => 'ORD-20260603-0010-PROM-FROZEN',
            'status' => CustomerOrder::STATUS_SHIPPED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA,
            'total_amount' => 4000,
            'currency' => 'UAH',
            'paid_prom_uah' => 4000,
            'paid_amount_uah' => 4000,
        ]);
        CustomerOrder::query()->create([
            'number' => 'ORD-20260603-0010-PROM-ISSUED',
            'status' => CustomerOrder::STATUS_COMPLETED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA,
            'total_amount' => 2500,
            'currency' => 'UAH',
            'paid_prom_uah' => 2500,
            'paid_amount_uah' => 2500,
        ]);
        CustomerOrder::query()->create([
            'number' => 'ORD-20260603-0010-STO-CASH',
            'status' => CustomerOrder::STATUS_COMPLETED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_STO,
            'total_amount' => 9000,
            'currency' => 'UAH',
            'paid_cash_uah' => 9000,
            'paid_cash_usd' => 90,
            'paid_bank_tov_uah' => 8000,
            'paid_bank_fop_uah' => 7000,
            'paid_amount_uah' => 9000,
        ]);
        CustomerOrder::query()->create([
            'number' => 'ORD-20260603-0010-STO-ACTIVE',
            'status' => CustomerOrder::STATUS_ASSEMBLED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_STO,
            'total_amount' => 5000,
            'currency' => 'UAH',
        ]);
        CustomerOrder::query()->create([
            'number' => 'ORD-20260603-0010-STO-CANCELLED',
            'status' => CustomerOrder::STATUS_CANCELLED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_STO,
            'total_amount' => 4000,
            'currency' => 'UAH',
        ]);

        $controller = app(CustomerOrderController::class);
        $method = new \ReflectionMethod($controller, 'customerOrderCashSummary');
        $method->setAccessible(true);
        $summary = $method->invoke($controller);

        $this->assertSame(100.0, $summary[CustomerOrder::PAYMENT_TYPE_CASH_UAH]);
        $this->assertSame(10.0, $summary[CustomerOrder::PAYMENT_TYPE_CASH_USD]);
        $this->assertSame(200.0, $summary[CustomerOrder::PAYMENT_TYPE_BANK_TOV]);
        $this->assertSame(300.0, $summary[CustomerOrder::PAYMENT_TYPE_BANK_FOP]);
        $this->assertSame(2500.0, $summary[CustomerOrder::PAYMENT_TYPE_PROM]);
        $this->assertSame(4000.0, $summary['prom_pending_uah']);
        $this->assertSame(9000.0, $summary['sto_parts_uah']);
    }

    public function test_customer_order_payment_can_be_confirmed(): void
    {
        $user = $this->adminUser('admin-customer-order-payment-confirm@example.com');
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260603-0011',
            'status' => CustomerOrder::STATUS_ASSEMBLED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'total_amount' => 1500,
            'currency' => 'UAH',
        ]);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->post(route('admin.customer-orders.payment.confirm', $order), [
                'payment_type' => CustomerOrder::PAYMENT_TYPE_BANK_FOP,
                'received_amount' => 1500,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order));

        $order->refresh();

        $this->assertSame(CustomerOrder::STATUS_ASSEMBLED, $order->status);
        $this->assertSame(CustomerOrder::PAYMENT_TYPE_BANK_FOP, $order->payment_type);
        $this->assertSame(1500.0, (float) $order->payment_received_amount);
        $this->assertSame(1500.0, (float) $order->payment_received_amount_uah);
        $this->assertSame(1500.0, (float) $order->paid_bank_fop_uah);
        $this->assertSame(1500.0, (float) $order->paid_amount_uah);
        $this->assertNotNull($order->payment_confirmed_at);

        $this->assertDatabaseHas('customer_order_history_events', [
            'customer_order_id' => $order->id,
            'event_type' => 'payment_confirmed',
            'title' => "\u{041E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0430} \u{043F}\u{043E}\u{0434}\u{0442}\u{0432}\u{0435}\u{0440}\u{0436}\u{0434}\u{0435}\u{043D}\u{0430}",
        ]);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.show', $order))
            ->assertOk()
            ->assertSee("\u{0421}\u{043E}\u{0431}\u{0440}\u{0430}\u{043D}")
            ->assertSee("\u{041E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0430}")
            ->assertSee("\u{041E}\u{043F}\u{043B}\u{0430}\u{0447}\u{0435}\u{043D}\u{043E}")
            ->assertSee("\u{0411}\u{0435}\u{0437}\u{041D}\u{0430}\u{043B} \u{0424}\u{041E}\u{041F}")
            ->assertDontSee("\u{041E}\u{0436}\u{0438}\u{0434}\u{0430}\u{0435}\u{0442} \u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0443}");
    }

    public function test_customer_order_prepayment_can_be_recorded_without_full_payment(): void
    {
        $user = $this->adminUser('admin-customer-order-prepayment@example.com');
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260603-0011-P',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'total_amount' => 1500,
            'currency' => 'UAH',
        ]);
        $order->items()->create([
            'name' => 'Prepaid handle',
            'quantity' => 1,
            'unit_price' => 1500,
            'total_price' => 1500,
            'currency' => 'UAH',
            'unit_price_usd_hint' => 35,
            'total_price_usd_hint' => 35,
        ]);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->post(route('admin.customer-orders.prepayment.store', $order), [
                'payment_type' => CustomerOrder::PAYMENT_TYPE_CASH_UAH,
                'received_amount' => 500,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order));

        $order->refresh();

        $this->assertSame(CustomerOrder::STATUS_PROCESSING, $order->status);
        $this->assertSame(CustomerOrder::PAYMENT_TYPE_CASH_UAH, $order->payment_type);
        $this->assertSame(500.0, (float) $order->payment_received_amount);
        $this->assertSame(500.0, (float) $order->payment_received_amount_uah);
        $this->assertSame(500.0, (float) $order->paid_cash_uah);
        $this->assertSame(500.0, (float) $order->paid_amount_uah);
        $this->assertNull($order->payment_confirmed_at);

        $this->assertDatabaseHas('customer_order_history_events', [
            'customer_order_id' => $order->id,
            'event_type' => 'prepayment_received',
        ]);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.index'))
            ->assertOk()
            ->assertSee("1 500 \u{0433}\u{0440}\u{043D}")
            ->assertSee('35.00 USD')
            ->assertSee(CustomerOrder::PAYMENT_TYPE_LABELS[CustomerOrder::PAYMENT_TYPE_CASH_UAH].": 500 \u{0433}\u{0440}\u{043D} (\u{043F}\u{0440}\u{0435}\u{0434}\u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0430})")
            ->assertSee("\u{041F}\u{043E}\u{0434}\u{0442}\u{0432}\u{0435}\u{0440}\u{0434}\u{0438}\u{0442}\u{044C} \u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0443}")
            ->assertSee(route('admin.customer-orders.prepayment.store', $order), false)
            ->assertSee('data-payment-requires-full-amount="0"', false);
    }

    public function test_customer_order_full_prepayment_keeps_individual_prepayment_rows(): void
    {
        $user = $this->adminUser('admin-customer-order-split-full-prepayment@example.com');
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260603-0011-SPLIT',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'total_amount' => 40,
            'currency' => 'UAH',
        ]);

        foreach ([20, 20] as $amount) {
            $this->actingAs($user)
                ->from(route('admin.customer-orders.show', $order))
                ->post(route('admin.customer-orders.prepayment.store', $order), [
                    'payment_type' => CustomerOrder::PAYMENT_TYPE_BANK_FOP,
                    'received_amount' => $amount,
                ])
                ->assertRedirect(route('admin.customer-orders.show', $order));
        }

        $order->refresh();

        $this->assertSame(40.0, (float) $order->paid_amount_uah);
        $this->assertSame(40.0, (float) $order->paid_bank_fop_uah);
        $this->assertNotNull($order->payment_confirmed_at);
        $this->assertDatabaseHas('customer_order_history_events', [
            'customer_order_id' => $order->id,
            'event_type' => 'prepayment_received',
        ]);
        $this->assertDatabaseHas('customer_order_history_events', [
            'customer_order_id' => $order->id,
            'event_type' => 'payment_confirmed',
            'new_values->is_prepayment_flow' => true,
        ]);

        $response = $this->actingAs($user)
            ->get(route('admin.customer-orders.show', $order))
            ->assertOk()
            ->assertSee("\u{041F}\u{0440}\u{0435}\u{0434}\u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0430}")
            ->assertSee("\u{041E}\u{043F}\u{043B}\u{0430}\u{0447}\u{0435}\u{043D}\u{043E}")
            ->assertDontSee('id="customer-order-prepayment"', false);

        $this->assertSame(
            2,
            substr_count($response->getContent(), CustomerOrder::PAYMENT_TYPE_LABELS[CustomerOrder::PAYMENT_TYPE_BANK_FOP].": 20 \u{0433}\u{0440}\u{043D}"),
        );
        $this->assertMatchesRegularExpression(
            "/\u{0421}\u{0443}\u{043C}\u{043C}\u{0430} \u{0437}\u{0430}\u{043A}\u{0430}\u{0437}\u{0430}.*40 \u{0433}\u{0440}\u{043D}.*\u{041E}\u{043F}\u{043B}\u{0430}\u{0447}\u{0435}\u{043D}\u{043E}/su",
            $response->getContent(),
        );
    }

    public function test_customer_order_show_ignores_prepayments_before_last_deletion(): void
    {
        $user = $this->adminUser('admin-customer-order-deleted-prepayments-hidden@example.com');
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260603-0011-DEL-HIST',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA,
            'total_amount' => 40,
            'currency' => 'UAH',
            'payment_type' => CustomerOrder::PAYMENT_TYPE_CASH_UAH,
            'payment_received_amount' => 20,
            'payment_received_amount_uah' => 20,
            'paid_cash_uah' => 20,
            'paid_bank_fop_uah' => 20,
            'paid_amount_uah' => 40,
            'payment_confirmed_at' => now(),
        ]);

        $events = [
            ['prepayment_received', CustomerOrder::PAYMENT_TYPE_BANK_FOP, 20, 1],
            ['prepayment_deleted', null, 0, 2],
            ['prepayment_received', CustomerOrder::PAYMENT_TYPE_BANK_FOP, 20, 3],
            ['prepayment_deleted', null, 0, 4],
            ['prepayment_received', CustomerOrder::PAYMENT_TYPE_BANK_FOP, 20, 5],
            ['payment_confirmed', CustomerOrder::PAYMENT_TYPE_CASH_UAH, 20, 6],
        ];

        $activeBankFopEvent = null;
        $cashConfirmedEvent = null;

        foreach ($events as [$eventType, $paymentType, $amount, $minute]) {
            $event = $order->historyEvents()->create([
                'event_type' => $eventType,
                'title' => $eventType,
                'description' => $eventType,
                'new_values' => $paymentType
                    ? [
                        'payment_type' => $paymentType,
                        'payment_received_amount' => $amount,
                        'payment_received_amount_uah' => $amount,
                        'paid_amount_uah' => $eventType === 'payment_confirmed' ? 40 : $amount,
                    ]
                    : ['paid_amount_uah' => 0],
                'created_at' => now()->addMinutes($minute),
                'updated_at' => now()->addMinutes($minute),
            ]);

            if ($eventType === 'prepayment_received' && $minute === 5) {
                $activeBankFopEvent = $event;
            }

            if ($eventType === 'payment_confirmed') {
                $cashConfirmedEvent = $event;
            }
        }

        $response = $this->actingAs($user)
            ->get(route('admin.customer-orders.show', $order))
            ->assertOk()
            ->assertSee("\u{041E}\u{043F}\u{043B}\u{0430}\u{0447}\u{0435}\u{043D}\u{043E}")
            ->assertSee(route('admin.customer-orders.prepayment-entry.destroy', [$order, $activeBankFopEvent]), false)
            ->assertSee(route('admin.customer-orders.prepayment-entry.destroy', [$order, $cashConfirmedEvent]), false);

        $this->assertSame(
            1,
            substr_count($response->getContent(), CustomerOrder::PAYMENT_TYPE_LABELS[CustomerOrder::PAYMENT_TYPE_BANK_FOP].": 20 \u{0433}\u{0440}\u{043D}"),
        );
        $this->assertSame(
            1,
            substr_count($response->getContent(), CustomerOrder::PAYMENT_TYPE_LABELS[CustomerOrder::PAYMENT_TYPE_CASH_UAH].": 20 \u{0433}\u{0440}\u{043D}"),
        );

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->delete(route('admin.customer-orders.prepayment-entry.destroy', [$order, $activeBankFopEvent]))
            ->assertRedirect(route('admin.customer-orders.show', $order));

        $order->refresh();

        $this->assertSame(20.0, (float) $order->paid_amount_uah);
        $this->assertSame(0.0, (float) $order->paid_bank_fop_uah);
        $this->assertSame(20.0, (float) $order->paid_cash_uah);
        $this->assertNull($order->payment_confirmed_at);
        $this->assertDatabaseHas('customer_order_history_events', [
            'customer_order_id' => $order->id,
            'event_type' => 'prepayment_deleted',
            'new_values->deleted_event_id' => $activeBankFopEvent->id,
        ]);

        $afterDeleteResponse = $this->actingAs($user)
            ->get(route('admin.customer-orders.show', $order))
            ->assertOk();

        $this->assertSame(
            0,
            substr_count($afterDeleteResponse->getContent(), CustomerOrder::PAYMENT_TYPE_LABELS[CustomerOrder::PAYMENT_TYPE_BANK_FOP].": 20 \u{0433}\u{0440}\u{043D}"),
        );
        $this->assertSame(
            1,
            substr_count($afterDeleteResponse->getContent(), CustomerOrder::PAYMENT_TYPE_LABELS[CustomerOrder::PAYMENT_TYPE_CASH_UAH].": 20 \u{0433}\u{0440}\u{043D}"),
        );
    }

    public function test_customer_order_prepayment_can_be_deleted(): void
    {
        $user = $this->adminUser('admin-customer-order-prepayment-delete@example.com');
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260603-0010-DEL',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA,
            'total_amount' => 1500,
            'currency' => 'UAH',
            'payment_type' => CustomerOrder::PAYMENT_TYPE_BANK_FOP,
            'payment_received_amount' => 20,
            'payment_received_amount_uah' => 20,
            'paid_bank_fop_uah' => 20,
            'paid_amount_uah' => 20,
        ]);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.show', $order))
            ->assertOk()
            ->assertSee(route('admin.customer-orders.prepayment.destroy', $order), false)
            ->assertSee(CustomerOrder::PAYMENT_TYPE_LABELS[CustomerOrder::PAYMENT_TYPE_BANK_FOP].": 20 \u{0433}\u{0440}\u{043D}")
            ->assertSee("\u{0423}\u{0434}\u{0430}\u{043B}\u{0438}\u{0442}\u{044C} \u{043F}\u{0440}\u{0435}\u{0434}\u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0443}");

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->delete(route('admin.customer-orders.prepayment.destroy', $order))
            ->assertRedirect(route('admin.customer-orders.show', $order));

        $order->refresh();

        $this->assertSame(CustomerOrder::STATUS_WAITING_PREPAYMENT, $order->status);
        $this->assertNull($order->payment_type);
        $this->assertSame(0.0, (float) $order->payment_received_amount);
        $this->assertSame(0.0, (float) $order->payment_received_amount_uah);
        $this->assertSame(0.0, (float) $order->paid_bank_fop_uah);
        $this->assertSame(0.0, (float) $order->paid_amount_uah);

        $this->assertDatabaseHas('customer_order_history_events', [
            'customer_order_id' => $order->id,
            'event_type' => 'prepayment_deleted',
        ]);
        $this->assertDatabaseHas('customer_order_history_events', [
            'customer_order_id' => $order->id,
            'event_type' => 'status_changed',
        ]);
    }

    public function test_nova_poshta_order_waits_for_prepayment_before_assembly(): void
    {
        $user = $this->adminUser('admin-customer-order-np-waiting-prepayment@example.com');
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260603-0011-NP',
            'status' => CustomerOrder::STATUS_WAITING_PREPAYMENT,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA,
            'client_phone' => '+380501112233',
            'client_first_name' => 'Ivan',
            'client_last_name' => 'Petrov',
            'total_amount' => 1500,
            'currency' => 'UAH',
        ]);
        $order->novaPoshtaShipment()->create([
            'carrier' => CustomerOrderShipment::CARRIER_NOVA_POSHTA,
            'status' => CustomerOrderShipment::STATUS_DRAFT,
            'recipient_city_name' => 'Kyiv',
            'recipient_warehouse_name' => 'Warehouse 1',
            'recipient_name' => 'Ivan Petrov',
            'recipient_phone' => '+380501112233',
        ]);

        $this->assertFalse($order->canBeMarkedAsAssembled());

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->patch(route('admin.customer-orders.status.update', $order), [
                'status' => CustomerOrder::STATUS_ASSEMBLED,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order))
            ->assertSessionHasErrors('status');

        $this->assertSame(CustomerOrder::STATUS_WAITING_PREPAYMENT, $order->refresh()->status);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->post(route('admin.customer-orders.prepayment.store', $order), [
                'payment_type' => CustomerOrder::PAYMENT_TYPE_CASH_UAH,
                'received_amount' => 500,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order));

        $order->refresh();

        $this->assertSame(CustomerOrder::STATUS_PROCESSING, $order->status);
        $this->assertTrue($order->canBeMarkedAsAssembled());
        $this->assertSame(500.0, (float) $order->paid_amount_uah);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.show', $order))
            ->assertOk()
            ->assertSee('value="'.CustomerOrder::STATUS_ASSEMBLED.'"', false)
            ->assertSee('name="nova_poshta_weight"', false)
            ->assertSee('name="nova_poshta_length_cm"', false)
            ->assertSee('name="nova_poshta_width_cm"', false)
            ->assertSee('name="nova_poshta_height_cm"', false)
            ->assertSee("\u{041D}\u{0430}\u{043B}\u{043E}\u{0436}\u{0435}\u{043D}\u{043D}\u{044B}\u{0439} \u{043F}\u{043B}\u{0430}\u{0442}\u{0435}\u{0436}: 1 000 \u{0433}\u{0440}\u{043D}")
            ->assertSee(CustomerOrder::PAYMENT_TYPE_LABELS[CustomerOrder::PAYMENT_TYPE_CASH_UAH].": 500 \u{0433}\u{0440}\u{043D}")
            ->assertSee("\u{041F}\u{0440}\u{0435}\u{0434}\u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0430}");
    }

    public function test_nova_poshta_assemble_button_on_index_opens_package_modal(): void
    {
        $user = $this->adminUser('admin-customer-order-np-index-assemble@example.com');
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260603-0011-NP-INDEX',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA,
            'client_phone' => '+380501112233',
            'client_first_name' => 'Ivan',
            'client_last_name' => 'Petrov',
            'total_amount' => 1500,
            'currency' => 'UAH',
            'paid_cash_uah' => 500,
            'paid_amount_uah' => 500,
        ]);
        $order->novaPoshtaShipment()->create([
            'carrier' => CustomerOrderShipment::CARRIER_NOVA_POSHTA,
            'status' => CustomerOrderShipment::STATUS_DRAFT,
            'recipient_city_name' => 'Kyiv',
            'recipient_warehouse_name' => 'Warehouse 1',
            'recipient_warehouse_ref' => 'warehouse-ref-1',
            'recipient_name' => 'Ivan Petrov',
            'recipient_phone' => '+380501112233',
        ]);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.index'))
            ->assertOk()
            ->assertSee('customer-order-assemble-'.$order->id, false)
            ->assertSee("\u{041F}\u{043E}\u{0441}\u{044B}\u{043B}\u{043A}\u{0430} \u{041D}\u{043E}\u{0432}\u{043E}\u{0439} \u{043F}\u{043E}\u{0447}\u{0442}\u{044B}")
            ->assertSee('name="nova_poshta_weight"', false)
            ->assertSee('name="nova_poshta_length_cm"', false)
            ->assertSee("\u{041D}\u{0430}\u{043B}\u{043E}\u{0436}\u{0435}\u{043D}\u{043D}\u{044B}\u{0439} \u{043F}\u{043B}\u{0430}\u{0442}\u{0435}\u{0436}: 1 000 \u{0433}\u{0440}\u{043D}");
    }

    public function test_nova_poshta_prepayment_can_be_recorded_as_prom_payment_for_full_order_amount(): void
    {
        $user = $this->adminUser('admin-customer-order-np-prom-prepayment@example.com');
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260603-0011-NP-PROM',
            'status' => CustomerOrder::STATUS_WAITING_PREPAYMENT,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA,
            'client_phone' => '+380501112233',
            'client_first_name' => 'Ivan',
            'client_last_name' => 'Petrov',
            'total_amount' => 1500,
            'currency' => 'UAH',
        ]);
        $order->items()->create([
            'name' => 'Prom paid handle',
            'quantity' => 1,
            'unit_price' => 1500,
            'total_price' => 1500,
            'currency' => 'UAH',
        ]);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.show', $order))
            ->assertOk()
            ->assertSee(CustomerOrder::PAYMENT_TYPE_LABELS[CustomerOrder::PAYMENT_TYPE_PROM])
            ->assertSee('data-fixed-amount="1500.00"', false);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->post(route('admin.customer-orders.prepayment.store', $order), [
                'payment_type' => CustomerOrder::PAYMENT_TYPE_PROM,
                'received_amount' => 1,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order));

        $order->refresh();

        $this->assertSame(CustomerOrder::STATUS_PROCESSING, $order->status);
        $this->assertSame(CustomerOrder::PAYMENT_TYPE_PROM, $order->payment_type);
        $this->assertSame(1500.0, (float) $order->payment_received_amount);
        $this->assertSame(1500.0, (float) $order->payment_received_amount_uah);
        $this->assertSame(1500.0, (float) $order->paid_prom_uah);
        $this->assertSame(1500.0, (float) $order->paid_amount_uah);
        $this->assertNotNull($order->payment_confirmed_at);

        $controller = app(CustomerOrderController::class);
        $method = new \ReflectionMethod($controller, 'customerOrderCashSummary');
        $method->setAccessible(true);
        $summary = $method->invoke($controller);

        $this->assertSame(0.0, $summary[CustomerOrder::PAYMENT_TYPE_PROM]);

        $this->assertDatabaseHas('customer_order_history_events', [
            'customer_order_id' => $order->id,
            'event_type' => 'payment_confirmed',
            'new_values->payment_type' => CustomerOrder::PAYMENT_TYPE_PROM,
            'new_values->payment_received_amount' => 1500,
            'new_values->is_prepayment_flow' => true,
        ]);
    }

    public function test_prom_paid_nova_poshta_order_creates_ttn_without_afterpayment(): void
    {
        $user = $this->adminUser('admin-customer-order-np-prom-ttn@example.com');
        config([
            'services.nova_poshta.api_key' => 'test-api-key',
            'services.nova_poshta.sender_city_ref' => 'sender-city-ref',
            'services.nova_poshta.sender_ref' => 'sender-ref',
            'services.nova_poshta.sender_address_ref' => 'sender-address-ref',
            'services.nova_poshta.sender_contact_ref' => 'sender-contact-ref',
            'services.nova_poshta.sender_phone' => '0500000000',
        ]);
        $sentNovaPoshtaPayload = null;
        Http::fake([
            'https://api.novaposhta.ua/v2.0/json/' => function ($request) use (&$sentNovaPoshtaPayload) {
                $sentNovaPoshtaPayload = $request->data();

                return Http::response([
                    'success' => true,
                    'data' => [[
                        'Ref' => 'np-prom-document-ref',
                        'IntDocNumber' => '20450000000999',
                    ]],
                    'errors' => [],
                    'warnings' => [],
                    'info' => [],
                ], 200);
            },
        ]);

        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260603-0011-NP-PROM-TTN',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA,
            'client_phone' => '+380501112233',
            'client_first_name' => 'Ivan',
            'client_last_name' => 'Petrov',
            'total_amount' => 1500,
            'currency' => 'UAH',
            'payment_type' => CustomerOrder::PAYMENT_TYPE_PROM,
            'paid_prom_uah' => 1500,
            'paid_amount_uah' => 1500,
            'payment_confirmed_at' => now(),
        ]);
        $order->novaPoshtaShipment()->create([
            'carrier' => CustomerOrderShipment::CARRIER_NOVA_POSHTA,
            'status' => CustomerOrderShipment::STATUS_DRAFT,
            'recipient_city_name' => 'Kyiv',
            'recipient_warehouse_name' => 'Warehouse 7',
            'recipient_warehouse_ref' => 'warehouse-ref-7',
            'recipient_name' => 'Ivan Petrov',
            'recipient_phone' => '+380501112233',
        ]);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->patch(route('admin.customer-orders.status.update', $order), [
                'status' => CustomerOrder::STATUS_ASSEMBLED,
                'nova_poshta_seats_amount' => 1,
                'nova_poshta_weight' => 2.5,
                'nova_poshta_length_cm' => 45,
                'nova_poshta_width_cm' => 30,
                'nova_poshta_height_cm' => 20,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order))
            ->assertSessionHasNoErrors();

        $order->refresh()->load('novaPoshtaShipment');

        $this->assertSame(CustomerOrder::STATUS_ASSEMBLED, $order->status);
        $this->assertSame(CustomerOrderShipment::STATUS_CREATED, $order->novaPoshtaShipment?->status);
        $this->assertSame('np-prom-document-ref', $order->novaPoshtaShipment?->np_ref);
        $this->assertSame('20450000000999', $order->novaPoshtaShipment?->tracking_number);
        $this->assertSame(0.0, (float) $order->novaPoshtaShipment?->afterpayment_amount);

        $this->assertNotNull($sentNovaPoshtaPayload);
        $this->assertSame('InternetDocument', $sentNovaPoshtaPayload['modelName']);
        $this->assertSame('save', $sentNovaPoshtaPayload['calledMethod']);
        $this->assertSame(1500, data_get($sentNovaPoshtaPayload, 'methodProperties.Cost'));
        $this->assertNull(data_get($sentNovaPoshtaPayload, 'methodProperties.AfterpaymentOnGoodsCost'));

        $this->assertDatabaseHas('customer_order_history_events', [
            'customer_order_id' => $order->id,
            'event_type' => 'nova_poshta_ttn_created',
        ]);
    }

    public function test_assembling_nova_poshta_order_creates_ttn(): void
    {
        $user = $this->adminUser('admin-customer-order-np-ttn@example.com');
        config([
            'services.nova_poshta.api_key' => 'test-api-key',
            'services.nova_poshta.sender_city_ref' => 'sender-city-ref',
            'services.nova_poshta.sender_ref' => 'sender-ref',
            'services.nova_poshta.sender_address_ref' => 'sender-address-ref',
            'services.nova_poshta.sender_contact_ref' => 'sender-contact-ref',
            'services.nova_poshta.sender_phone' => '0500000000',
        ]);
        $sentNovaPoshtaPayload = null;
        Http::fake([
            'https://api.novaposhta.ua/v2.0/json/' => function ($request) use (&$sentNovaPoshtaPayload) {
                $sentNovaPoshtaPayload = $request->data();

                return Http::response([
                    'success' => true,
                    'data' => [[
                        'Ref' => 'np-document-ref',
                        'IntDocNumber' => '20450000000000',
                    ]],
                    'errors' => [],
                    'warnings' => [],
                    'info' => [],
                ], 200);
            },
        ]);

        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260603-0012-NP',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA,
            'client_phone' => '+380501112233',
            'client_first_name' => 'Ivan',
            'client_last_name' => 'Petrov',
            'total_amount' => 1500,
            'currency' => 'UAH',
            'paid_cash_uah' => 500,
            'paid_amount_uah' => 500,
        ]);
        $order->novaPoshtaShipment()->create([
            'carrier' => CustomerOrderShipment::CARRIER_NOVA_POSHTA,
            'status' => CustomerOrderShipment::STATUS_DRAFT,
            'recipient_city_name' => 'Kyiv',
            'recipient_warehouse_name' => 'Warehouse 7',
            'recipient_warehouse_ref' => 'warehouse-ref-7',
            'recipient_name' => 'Ivan Petrov',
            'recipient_phone' => '+380501112233',
        ]);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->patch(route('admin.customer-orders.status.update', $order), [
                'status' => CustomerOrder::STATUS_ASSEMBLED,
                'nova_poshta_seats_amount' => 1,
                'nova_poshta_weight' => 2.5,
                'nova_poshta_length_cm' => 45,
                'nova_poshta_width_cm' => 30,
                'nova_poshta_height_cm' => 20,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order))
            ->assertSessionHasNoErrors();

        $order->refresh()->load('novaPoshtaShipment');

        $this->assertSame(CustomerOrder::STATUS_ASSEMBLED, $order->status);
        $this->assertSame(CustomerOrderShipment::STATUS_CREATED, $order->novaPoshtaShipment?->status);
        $this->assertSame('np-document-ref', $order->novaPoshtaShipment?->np_ref);
        $this->assertSame('20450000000000', $order->novaPoshtaShipment?->tracking_number);
        $this->assertSame(1, $order->novaPoshtaShipment?->seats_amount);
        $this->assertSame(2.5, (float) $order->novaPoshtaShipment?->weight);
        $this->assertSame(45, $order->novaPoshtaShipment?->length_cm);
        $this->assertSame(30, $order->novaPoshtaShipment?->width_cm);
        $this->assertSame(20, $order->novaPoshtaShipment?->height_cm);
        $this->assertSame(1000.0, (float) $order->novaPoshtaShipment?->afterpayment_amount);

        $this->assertNotNull($sentNovaPoshtaPayload);
        $this->assertSame('InternetDocument', $sentNovaPoshtaPayload['modelName']);
        $this->assertSame('save', $sentNovaPoshtaPayload['calledMethod']);
        $this->assertSame('Kyiv', data_get($sentNovaPoshtaPayload, 'methodProperties.RecipientCityName'));
        $this->assertSame('warehouse-ref-7', data_get($sentNovaPoshtaPayload, 'methodProperties.RecipientAddress'));
        $this->assertNull(data_get($sentNovaPoshtaPayload, 'methodProperties.RecipientAddressName'));
        $this->assertSame('Parcel', data_get($sentNovaPoshtaPayload, 'methodProperties.CargoType'));
        $this->assertSame("\u{0430}\u{0432}\u{0442}\u{043E}\u{0437}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{0438}\u{043D}\u{0438}", data_get($sentNovaPoshtaPayload, 'methodProperties.Description'));
        $this->assertSame('380501112233', data_get($sentNovaPoshtaPayload, 'methodProperties.RecipientsPhone'));
        $this->assertSame(1000, data_get($sentNovaPoshtaPayload, 'methodProperties.Cost'));
        $this->assertSame('2.5', data_get($sentNovaPoshtaPayload, 'methodProperties.OptionsSeat.0.weight'));
        $this->assertSame('45', data_get($sentNovaPoshtaPayload, 'methodProperties.OptionsSeat.0.volumetricLength'));
        $this->assertSame('30', data_get($sentNovaPoshtaPayload, 'methodProperties.OptionsSeat.0.volumetricWidth'));
        $this->assertSame('20', data_get($sentNovaPoshtaPayload, 'methodProperties.OptionsSeat.0.volumetricHeight'));
        $this->assertSame('1000', data_get($sentNovaPoshtaPayload, 'methodProperties.AfterpaymentOnGoodsCost'));
        $this->assertNull(data_get($sentNovaPoshtaPayload, 'methodProperties.BackwardDeliveryData'));

        $this->assertDatabaseHas('customer_order_history_events', [
            'customer_order_id' => $order->id,
            'event_type' => 'nova_poshta_ttn_created',
        ]);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.show', $order))
            ->assertOk()
            ->assertSee('20450000000000')
            ->assertSee(route('admin.customer-orders.nova-poshta.label', $order), false)
            ->assertDontSee(route('admin.customer-orders.payment.confirm', $order), false);
    }

    public function test_nova_poshta_tracking_url_uses_path_format(): void
    {
        $shipment = new CustomerOrderShipment([
            'tracking_number' => '20451468469271',
        ]);

        $this->assertSame(
            'https://novaposhta.ua/tracking/20451468469271',
            $shipment->tracking_url,
        );
    }

    public function test_nova_poshta_order_cannot_be_manually_marked_as_shipped(): void
    {
        $user = $this->adminUser('admin-customer-order-np-no-manual-shipped@example.com');
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260603-0012-NP-NO-SHIP',
            'status' => CustomerOrder::STATUS_ASSEMBLED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA,
            'total_amount' => 1500,
            'currency' => 'UAH',
            'paid_cash_uah' => 500,
            'paid_amount_uah' => 500,
        ]);
        $order->novaPoshtaShipment()->create([
            'carrier' => CustomerOrderShipment::CARRIER_NOVA_POSHTA,
            'status' => CustomerOrderShipment::STATUS_CREATED,
            'tracking_number' => '20450000000001',
            'np_ref' => 'np-document-ref-no-ship',
            'recipient_city_name' => 'Kyiv',
            'recipient_warehouse_name' => 'Warehouse 7',
            'recipient_warehouse_ref' => 'warehouse-ref-7',
            'recipient_name' => 'Ivan Petrov',
            'recipient_phone' => '+380501112233',
        ]);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.index'))
            ->assertOk()
            ->assertSee($order->number)
            ->assertDontSee('value="'.CustomerOrder::STATUS_SHIPPED.'"', false);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.show', $order))
            ->assertOk()
            ->assertSee($order->number)
            ->assertDontSee('value="'.CustomerOrder::STATUS_SHIPPED.'"', false);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->patch(route('admin.customer-orders.status.update', $order), [
                'status' => CustomerOrder::STATUS_SHIPPED,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order))
            ->assertSessionHasErrors('status');

        $this->assertSame(CustomerOrder::STATUS_ASSEMBLED, $order->refresh()->status);
    }

    public function test_nova_poshta_status_sync_marks_accepted_ttn_as_shipped_and_locks_delivery_method(): void
    {
        $user = $this->adminUser('admin-customer-order-np-sync-shipped@example.com');
        config([
            'services.nova_poshta.api_key' => 'test-api-key',
        ]);
        Http::fake([
            'https://api.novaposhta.ua/v2.0/json/' => Http::response([
                'success' => true,
                'data' => [[
                    'Number' => '20450000000002',
                    'StatusCode' => '4',
                    'Status' => "\u{0412}\u{0456}\u{0434}\u{043F}\u{0440}\u{0430}\u{0432}\u{043B}\u{0435}\u{043D}\u{043D}\u{044F} \u{0443} \u{043C}\u{0456}\u{0441}\u{0442}\u{0456} \u{041A}\u{0438}\u{0457}\u{0432}",
                ]],
                'errors' => [],
                'warnings' => [],
                'info' => [],
            ], 200),
        ]);

        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260603-0012-NP-SYNC',
            'status' => CustomerOrder::STATUS_ASSEMBLED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA,
            'client_phone' => '+380501112233',
            'client_first_name' => 'Ivan',
            'client_last_name' => 'Petrov',
            'total_amount' => 1500,
            'currency' => 'UAH',
            'paid_cash_uah' => 500,
            'paid_amount_uah' => 500,
        ]);
        $order->novaPoshtaShipment()->create([
            'carrier' => CustomerOrderShipment::CARRIER_NOVA_POSHTA,
            'status' => CustomerOrderShipment::STATUS_CREATED,
            'tracking_number' => '20450000000002',
            'np_ref' => 'np-document-ref-sync',
            'recipient_city_name' => 'Kyiv',
            'recipient_warehouse_name' => 'Warehouse 7',
            'recipient_warehouse_ref' => 'warehouse-ref-7',
            'recipient_name' => 'Ivan Petrov',
            'recipient_phone' => '+380501112233',
        ]);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->post(route('admin.customer-orders.nova-poshta.sync-status', $order))
            ->assertRedirect(route('admin.customer-orders.show', $order))
            ->assertSessionHasNoErrors();

        $order->refresh()->load('novaPoshtaShipment');

        $this->assertSame(CustomerOrder::STATUS_SHIPPED, $order->status);
        $this->assertSame('4', $order->novaPoshtaShipment?->np_status_code);
        $this->assertSame("\u{0412}\u{0456}\u{0434}\u{043F}\u{0440}\u{0430}\u{0432}\u{043B}\u{0435}\u{043D}\u{043D}\u{044F} \u{0443} \u{043C}\u{0456}\u{0441}\u{0442}\u{0456} \u{041A}\u{0438}\u{0457}\u{0432}", $order->novaPoshtaShipment?->np_status);
        $this->assertNotNull($order->novaPoshtaShipment?->np_status_checked_at);
        $this->assertDatabaseHas('customer_order_history_events', [
            'customer_order_id' => $order->id,
            'event_type' => 'status_changed',
            'new_values->status' => CustomerOrder::STATUS_SHIPPED,
        ]);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $payload['modelName'] === 'TrackingDocument'
                && $payload['calledMethod'] === 'getStatusDocuments'
                && data_get($payload, 'methodProperties.Documents.0.DocumentNumber') === '20450000000002'
                && data_get($payload, 'methodProperties.Documents.0.Phone') === '380501112233';
        });

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->patch(route('admin.customer-orders.delivery-method.update', $order), [
                'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order))
            ->assertSessionHasErrors('delivery_method');

        $this->assertSame(CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA, $order->refresh()->delivery_method);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.show', $order))
            ->assertOk()
            ->assertSee("\u{0412}\u{0456}\u{0434}\u{043F}\u{0440}\u{0430}\u{0432}\u{043B}\u{0435}\u{043D}\u{043D}\u{044F} \u{0443} \u{043C}\u{0456}\u{0441}\u{0442}\u{0456} \u{041A}\u{0438}\u{0457}\u{0432}");
    }

    public function test_nova_poshta_status_sync_updates_secondary_ttns(): void
    {
        $user = $this->adminUser('admin-customer-order-np-sync-secondary@example.com');
        config([
            'services.nova_poshta.api_key' => 'test-api-key',
        ]);
        Http::fake([
            'https://api.novaposhta.ua/v2.0/json/' => function ($request) {
                $trackingNumber = data_get($request->data(), 'methodProperties.Documents.0.DocumentNumber');

                return Http::response([
                    'success' => true,
                    'data' => [[
                        'Number' => $trackingNumber,
                        'StatusCode' => '7',
                        'Status' => "\u{041F}\u{0440}\u{0438}\u{0431}\u{0443}\u{0432} \u{0443} \u{0432}\u{0456}\u{0434}\u{0434}\u{0456}\u{043B}\u{0435}\u{043D}\u{043D}\u{044F}",
                    ]],
                    'errors' => [],
                    'warnings' => [],
                    'info' => [],
                ], 200);
            },
        ]);

        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260603-0012-NP-MULTI-SYNC',
            'status' => CustomerOrder::STATUS_SHIPPED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA,
            'client_phone' => '+380501112233',
            'client_first_name' => 'Ivan',
            'client_last_name' => 'Petrov',
            'total_amount' => 1500,
            'currency' => 'UAH',
            'paid_cash_uah' => 500,
            'paid_amount_uah' => 500,
        ]);
        $primaryShipment = $order->novaPoshtaShipments()->create([
            'carrier' => CustomerOrderShipment::CARRIER_NOVA_POSHTA,
            'status' => CustomerOrderShipment::STATUS_CREATED,
            'tracking_number' => '20450000001001',
            'recipient_phone' => '+380501112233',
            'np_status_code' => '5',
            'np_status' => "\u{0412}\u{0456}\u{0434}\u{043F}\u{0440}\u{0430}\u{0432}\u{043B}\u{0435}\u{043D}\u{043D}\u{044F} \u{043F}\u{0440}\u{044F}\u{043C}\u{0443}\u{0454}",
        ]);
        $secondaryShipment = $order->novaPoshtaShipments()->create([
            'carrier' => CustomerOrderShipment::CARRIER_NOVA_POSHTA,
            'status' => CustomerOrderShipment::STATUS_CREATED,
            'tracking_number' => '20450000001002',
            'recipient_phone' => '+380501112233',
            'np_status_code' => '5',
            'np_status' => "\u{0412}\u{0456}\u{0434}\u{043F}\u{0440}\u{0430}\u{0432}\u{043B}\u{0435}\u{043D}\u{043D}\u{044F} \u{043F}\u{0440}\u{044F}\u{043C}\u{0443}\u{0454}",
        ]);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->post(route('admin.customer-orders.nova-poshta.sync-status', $order))
            ->assertRedirect(route('admin.customer-orders.show', $order))
            ->assertSessionHasNoErrors();

        $this->assertSame('7', $primaryShipment->refresh()->np_status_code);
        $this->assertSame('7', $secondaryShipment->refresh()->np_status_code);
        $this->assertSame("\u{041F}\u{0440}\u{0438}\u{0431}\u{0443}\u{0432} \u{0443} \u{0432}\u{0456}\u{0434}\u{0434}\u{0456}\u{043B}\u{0435}\u{043D}\u{043D}\u{044F}", $secondaryShipment->np_status);
        $this->assertNotNull($secondaryShipment->np_status_checked_at);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => data_get($request->data(), 'methodProperties.Documents.0.DocumentNumber') === '20450000001001');
        Http::assertSent(fn ($request): bool => data_get($request->data(), 'methodProperties.Documents.0.DocumentNumber') === '20450000001002');
    }

    public function test_nova_poshta_status_sync_keeps_waiting_ttn_assembled(): void
    {
        $user = $this->adminUser('admin-customer-order-np-sync-waiting@example.com');
        config([
            'services.nova_poshta.api_key' => 'test-api-key',
        ]);
        Http::fake([
            'https://api.novaposhta.ua/v2.0/json/' => Http::response([
                'success' => true,
                'data' => [[
                    'Number' => '20450000000003',
                    'StatusCode' => '4',
                    'Status' => "\u{041E}\u{0447}\u{0456}\u{043A}\u{0443}\u{0454} \u{0432}\u{0456}\u{0434}\u{043F}\u{0440}\u{0430}\u{0432}\u{043B}\u{0435}\u{043D}\u{043D}\u{044F}",
                ]],
                'errors' => [],
                'warnings' => [],
                'info' => [],
            ], 200),
        ]);

        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260603-0012-NP-WAIT',
            'status' => CustomerOrder::STATUS_ASSEMBLED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA,
            'client_phone' => '+380501112233',
            'client_first_name' => 'Ivan',
            'client_last_name' => 'Petrov',
            'total_amount' => 1500,
            'currency' => 'UAH',
            'paid_cash_uah' => 500,
            'paid_amount_uah' => 500,
        ]);
        $order->novaPoshtaShipment()->create([
            'carrier' => CustomerOrderShipment::CARRIER_NOVA_POSHTA,
            'status' => CustomerOrderShipment::STATUS_CREATED,
            'tracking_number' => '20450000000003',
            'np_ref' => 'np-document-ref-wait',
            'recipient_city_name' => 'Kyiv',
            'recipient_warehouse_name' => 'Warehouse 7',
            'recipient_warehouse_ref' => 'warehouse-ref-7',
            'recipient_name' => 'Ivan Petrov',
            'recipient_phone' => '+380501112233',
        ]);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->post(route('admin.customer-orders.nova-poshta.sync-status', $order))
            ->assertRedirect(route('admin.customer-orders.show', $order))
            ->assertSessionHasNoErrors();

        $order->refresh()->load('novaPoshtaShipment');

        $this->assertSame(CustomerOrder::STATUS_ASSEMBLED, $order->status);
        $this->assertSame('4', $order->novaPoshtaShipment?->np_status_code);
        $this->assertSame("\u{041E}\u{0447}\u{0456}\u{043A}\u{0443}\u{0454} \u{0432}\u{0456}\u{0434}\u{043F}\u{0440}\u{0430}\u{0432}\u{043B}\u{0435}\u{043D}\u{043D}\u{044F}", $order->novaPoshtaShipment?->np_status);
    }

    public function test_customer_order_pages_show_usd_cash_prepayment_in_usd(): void
    {
        $user = $this->adminUser('admin-customer-order-usd-prepayment-display@example.com');
        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_date' => now()->toDateString(),
            'rate' => 43,
            'source' => 'monobank',
            'fetched_at' => now(),
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260603-0011-U',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'total_amount' => 122550,
            'currency' => 'UAH',
            'payment_type' => CustomerOrder::PAYMENT_TYPE_CASH_USD,
            'payment_received_amount' => 100,
            'payment_received_amount_uah' => 4300,
            'paid_cash_usd' => 100,
            'paid_amount_uah' => 4300,
        ]);
        $order->items()->create([
            'name' => 'USD prepaid handle',
            'quantity' => 1,
            'unit_price' => 122550,
            'total_price' => 122550,
            'currency' => 'UAH',
            'unit_price_usd_hint' => 2850,
            'total_price_usd_hint' => 2850,
        ]);
        $usdPrepayment = CustomerOrder::PAYMENT_TYPE_LABELS[CustomerOrder::PAYMENT_TYPE_CASH_USD].': 100.00 USD';
        $indexUsdPrepayment = "\u{041F}\u{0440}\u{0435}\u{0434}\u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0430} (".CustomerOrder::PAYMENT_TYPE_LABELS[CustomerOrder::PAYMENT_TYPE_CASH_USD].'): 100.00 USD';

        $this->actingAs($user)
            ->get(route('admin.customer-orders.show', $order))
            ->assertOk()
            ->assertSeeInOrder([
                "122 550 \u{0433}\u{0440}\u{043D}",
                '2 850.00 USD',
                $usdPrepayment,
            ])
            ->assertDontSee("\u{0412} \u{0443}\u{0447}\u{0435}\u{0442}\u{0435}:")
            ->assertSee('data-payment-due-usd="2750"', false)
            ->assertSee('2 750.00 USD');

        $this->actingAs($user)
            ->get(route('admin.customer-orders.index'))
            ->assertOk()
            ->assertSeeInOrder([
                "122 550 \u{0433}\u{0440}\u{043D}",
                '2 850.00 USD',
                $indexUsdPrepayment,
            ])
            ->assertDontSee("\u{0412} \u{0443}\u{0447}\u{0435}\u{0442}\u{0435}:");
    }

    public function test_customer_order_prepayment_modal_uses_usd_hint_for_usd_due(): void
    {
        $user = $this->adminUser('admin-customer-order-usd-prepayment-due@example.com');
        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_date' => now()->toDateString(),
            'rate' => 43,
            'source' => 'monobank',
            'fetched_at' => now(),
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260603-0011-D',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'total_amount' => 122550,
            'currency' => 'UAH',
        ]);
        $order->items()->create([
            'name' => 'USD due handle',
            'quantity' => 1,
            'unit_price' => 122550,
            'total_price' => 122550,
            'currency' => 'UAH',
            'unit_price_usd_hint' => 2850,
            'total_price_usd_hint' => 2850,
        ]);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.show', $order))
            ->assertOk()
            ->assertSee('data-payment-due-uah="122550"', false)
            ->assertSee('data-payment-due-usd="2850"', false)
            ->assertSee('2 850.00 USD');
    }

    public function test_customer_order_show_exposes_prepayment_button_near_total(): void
    {
        $user = $this->adminUser('admin-customer-order-prepayment-button@example.com');
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260603-0011-B',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'total_amount' => 1500,
            'currency' => 'UAH',
        ]);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.show', $order))
            ->assertOk()
            ->assertSee("\u{0412}\u{043D}\u{0435}\u{0441}\u{0442}\u{0438} \u{043F}\u{0440}\u{0435}\u{0434}\u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0443}")
            ->assertSee('customer-order-prepayment', false)
            ->assertSee(route('admin.customer-orders.prepayment.store', $order), false)
            ->assertSee('data-payment-requires-full-amount="0"', false)
            ->assertSee('value=""', false);
    }

    public function test_fully_prepaid_assembled_customer_order_shows_paid_without_confirm_button(): void
    {
        $user = $this->adminUser('admin-customer-order-fully-prepaid@example.com');
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260603-0011-F',
            'status' => CustomerOrder::STATUS_ASSEMBLED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'total_amount' => 1500,
            'currency' => 'UAH',
            'payment_type' => CustomerOrder::PAYMENT_TYPE_CASH_UAH,
            'payment_received_amount' => 1500,
            'payment_received_amount_uah' => 1500,
            'paid_cash_uah' => 1500,
            'paid_amount_uah' => 1500,
            'payment_confirmed_at' => null,
        ]);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.index'))
            ->assertOk()
            ->assertSee($order->number)
            ->assertSee("\u{041E}\u{043F}\u{043B}\u{0430}\u{0447}\u{0435}\u{043D}\u{043E}")
            ->assertSee("\u{0421}\u{043E}\u{0431}\u{0440}\u{0430}\u{043D}")
            ->assertSee("\u{0412}\u{044B}\u{0434}\u{0430}\u{043D}")
            ->assertDontSee("\u{041E}\u{0436}\u{0438}\u{0434}\u{0430}\u{0435}\u{0442} \u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0443}")
            ->assertDontSee("\u{041F}\u{043E}\u{0434}\u{0442}\u{0432}\u{0435}\u{0440}\u{0434}\u{0438}\u{0442}\u{044C} \u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0443}");

        $this->actingAs($user)
            ->get(route('admin.customer-orders.show', $order))
            ->assertOk()
            ->assertSee("\u{041E}\u{043F}\u{043B}\u{0430}\u{0447}\u{0435}\u{043D}\u{043E}")
            ->assertSee("\u{0421}\u{043E}\u{0431}\u{0440}\u{0430}\u{043D}")
            ->assertSee("\u{0412}\u{044B}\u{0434}\u{0430}\u{043D}")
            ->assertDontSee("\u{041E}\u{0436}\u{0438}\u{0434}\u{0430}\u{0435}\u{0442} \u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0443}")
            ->assertDontSee("\u{041F}\u{043E}\u{0434}\u{0442}\u{0432}\u{0435}\u{0440}\u{0434}\u{0438}\u{0442}\u{044C} \u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0443}");

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->patch(route('admin.customer-orders.status.update', $order), [
                'status' => CustomerOrder::STATUS_COMPLETED,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order));

        $this->assertSame(CustomerOrder::STATUS_COMPLETED, $order->refresh()->status);
    }

    public function test_customer_order_payment_can_be_split_between_payment_types(): void
    {
        $user = $this->adminUser('admin-customer-order-payment-split@example.com');
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260603-0012',
            'status' => CustomerOrder::STATUS_ASSEMBLED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'total_amount' => 1500,
            'currency' => 'UAH',
        ]);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->post(route('admin.customer-orders.payment.confirm', $order), [
                'payments' => [
                    [
                        'payment_type' => CustomerOrder::PAYMENT_TYPE_CASH_UAH,
                        'received_amount' => 500,
                    ],
                    [
                        'payment_type' => CustomerOrder::PAYMENT_TYPE_BANK_FOP,
                        'received_amount' => 1000,
                    ],
                ],
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order));

        $order->refresh();

        $this->assertSame(CustomerOrder::STATUS_ASSEMBLED, $order->status);
        $this->assertSame(500.0, (float) $order->paid_cash_uah);
        $this->assertSame(1000.0, (float) $order->paid_bank_fop_uah);
        $this->assertSame(1500.0, (float) $order->paid_amount_uah);
        $this->assertNotNull($order->payment_confirmed_at);
    }

    public function test_customer_order_payment_rejects_duplicate_split_payment_types(): void
    {
        $user = $this->adminUser('admin-customer-order-payment-duplicate-types@example.com');
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260603-0012-D',
            'status' => CustomerOrder::STATUS_ASSEMBLED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'total_amount' => 1500,
            'currency' => 'UAH',
        ]);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->post(route('admin.customer-orders.payment.confirm', $order), [
                'payments' => [
                    [
                        'payment_type' => CustomerOrder::PAYMENT_TYPE_CASH_UAH,
                        'received_amount' => 500,
                    ],
                    [
                        'payment_type' => CustomerOrder::PAYMENT_TYPE_CASH_UAH,
                        'received_amount' => 1000,
                    ],
                ],
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order))
            ->assertSessionHasErrors('payments');

        $order->refresh();

        $this->assertSame(CustomerOrder::STATUS_ASSEMBLED, $order->status);
        $this->assertSame(0.0, (float) $order->paid_amount_uah);
    }

    public function test_customer_order_payment_form_exposes_split_payment_remainder_data(): void
    {
        $user = $this->adminUser('admin-customer-order-payment-remainder-form@example.com');
        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_date' => now()->toDateString(),
            'rate' => 43,
            'source' => 'monobank',
            'fetched_at' => now(),
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260603-0012-A',
            'status' => CustomerOrder::STATUS_ASSEMBLED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'total_amount' => 153930,
            'currency' => 'UAH',
        ]);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.show', $order))
            ->assertOk()
            ->assertSee('data-customer-order-payment-form', false)
            ->assertSee('data-payment-due-uah="153930"', false)
            ->assertSee('data-payment-usd-rate="43"', false)
            ->assertSee('data-payment-add', false)
            ->assertSee('customer-order-payment-icon--add', false)
            ->assertSee('customer-order-payment-icon--remove', false)
            ->assertSee("aria-label=\"\u{0414}\u{043E}\u{0431}\u{0430}\u{0432}\u{0438}\u{0442}\u{044C} \u{0447}\u{0430}\u{0441}\u{0442}\u{044C} \u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{044B}\"", false)
            ->assertSee('data-payment-remainder', false)
            ->assertSee("\u{041E}\u{0441}\u{0442}\u{0430}\u{043B}\u{043E}\u{0441}\u{044C} \u{0432}\u{043D}\u{0435}\u{0441}\u{0442}\u{0438}", false)
            ->assertDontSee("\u{00B7} \u{043A}\u{0443}\u{0440}\u{0441} USD:", false);
    }

    public function test_customer_order_payment_form_uses_displayed_usd_due_rate(): void
    {
        $user = $this->adminUser('admin-customer-order-payment-visible-usd-rate@example.com');
        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_date' => now()->toDateString(),
            'rate' => 43,
            'source' => 'monobank',
            'fetched_at' => now(),
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260603-0012-V',
            'status' => CustomerOrder::STATUS_ASSEMBLED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'total_amount' => 6740,
            'currency' => 'UAH',
        ]);
        $order->items()->create([
            'name' => 'Displayed USD due handle',
            'quantity' => 1,
            'unit_price' => 6740,
            'total_price' => 6740,
            'currency' => 'UAH',
            'unit_price_usd_hint' => 150,
            'total_price_usd_hint' => 150,
        ]);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.show', $order))
            ->assertOk()
            ->assertSee('data-payment-due-uah="6740"', false)
            ->assertSee('data-payment-due-usd="150"', false)
            ->assertSee('data-payment-usd-rate="44.933333"', false)
            ->assertSee('150.00 USD');
    }

    public function test_customer_order_cash_usd_payment_accepts_displayed_usd_due_when_current_rate_is_lower(): void
    {
        $user = $this->adminUser('admin-customer-order-payment-visible-usd-due@example.com');
        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_date' => now()->toDateString(),
            'rate' => 43,
            'source' => 'monobank',
            'fetched_at' => now(),
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260603-0012-U',
            'status' => CustomerOrder::STATUS_ASSEMBLED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'total_amount' => 6740,
            'currency' => 'UAH',
        ]);
        $order->items()->create([
            'name' => 'Displayed USD due handle',
            'quantity' => 1,
            'unit_price' => 6740,
            'total_price' => 6740,
            'currency' => 'UAH',
            'unit_price_usd_hint' => 150,
            'total_price_usd_hint' => 150,
        ]);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->post(route('admin.customer-orders.payment.confirm', $order), [
                'payments' => [
                    [
                        'payment_type' => CustomerOrder::PAYMENT_TYPE_CASH_USD,
                        'received_amount' => 150,
                    ],
                ],
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order));

        $order->refresh();

        $this->assertSame(CustomerOrder::STATUS_ASSEMBLED, $order->status);
        $this->assertSame(CustomerOrder::PAYMENT_TYPE_CASH_USD, $order->payment_type);
        $this->assertSame(150.0, (float) $order->payment_received_amount);
        $this->assertSame(6740.0, (float) $order->payment_received_amount_uah);
        $this->assertSame(150.0, (float) $order->paid_cash_usd);
        $this->assertSame(6740.0, (float) $order->paid_amount_uah);
        $this->assertNotNull($order->payment_confirmed_at);
    }

    public function test_customer_order_cash_usd_payment_accepts_comma_decimal_amount(): void
    {
        $user = $this->adminUser('admin-customer-order-payment-comma-usd-due@example.com');
        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_date' => now()->toDateString(),
            'rate' => 43,
            'source' => 'monobank',
            'fetched_at' => now(),
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260603-0012-COM',
            'status' => CustomerOrder::STATUS_ASSEMBLED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'total_amount' => 6740,
            'currency' => 'UAH',
        ]);
        $order->items()->create([
            'name' => 'Comma USD due handle',
            'quantity' => 1,
            'unit_price' => 6740,
            'total_price' => 6740,
            'currency' => 'UAH',
            'unit_price_usd_hint' => 150,
            'total_price_usd_hint' => 150,
        ]);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->post(route('admin.customer-orders.payment.confirm', $order), [
                'payments' => [
                    [
                        'payment_type' => CustomerOrder::PAYMENT_TYPE_CASH_USD,
                        'received_amount' => '150,00',
                    ],
                ],
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order));

        $order->refresh();

        $this->assertSame(CustomerOrder::STATUS_ASSEMBLED, $order->status);
        $this->assertSame(150.0, (float) $order->paid_cash_usd);
        $this->assertSame(6740.0, (float) $order->paid_amount_uah);
    }

    public function test_customer_order_payment_can_be_split_between_cash_uah_and_cash_usd(): void
    {
        $user = $this->adminUser('admin-customer-order-payment-split-usd@example.com');
        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_date' => now()->toDateString(),
            'rate' => 43,
            'source' => 'monobank',
            'fetched_at' => now(),
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260603-0012-B',
            'status' => CustomerOrder::STATUS_ASSEMBLED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'total_amount' => 153930,
            'currency' => 'UAH',
        ]);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->post(route('admin.customer-orders.payment.confirm', $order), [
                'payments' => [
                    [
                        'payment_type' => CustomerOrder::PAYMENT_TYPE_CASH_UAH,
                        'received_amount' => 24930,
                    ],
                    [
                        'payment_type' => CustomerOrder::PAYMENT_TYPE_CASH_USD,
                        'received_amount' => 3000,
                    ],
                ],
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order));

        $order->refresh();

        $this->assertSame(CustomerOrder::STATUS_ASSEMBLED, $order->status);
        $this->assertSame(24930.0, (float) $order->paid_cash_uah);
        $this->assertSame(3000.0, (float) $order->paid_cash_usd);
        $this->assertSame(153930.0, (float) $order->paid_amount_uah);
        $this->assertNotNull($order->payment_confirmed_at);
    }

    public function test_customer_order_payment_accepts_split_total_matching_visible_rounded_uah_amount(): void
    {
        $user = $this->adminUser('admin-customer-order-payment-split-rounded-usd@example.com');
        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_date' => now()->toDateString(),
            'rate' => 44.3583,
            'source' => 'monobank',
            'fetched_at' => now(),
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260603-0012-C',
            'status' => CustomerOrder::STATUS_ASSEMBLED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'total_amount' => 150820,
            'currency' => 'UAH',
        ]);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->post(route('admin.customer-orders.payment.confirm', $order), [
                'payments' => [
                    [
                        'payment_type' => CustomerOrder::PAYMENT_TYPE_CASH_USD,
                        'received_amount' => 3000,
                    ],
                    [
                        'payment_type' => CustomerOrder::PAYMENT_TYPE_CASH_UAH,
                        'received_amount' => 17745,
                    ],
                ],
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order));

        $order->refresh();

        $this->assertSame(CustomerOrder::STATUS_ASSEMBLED, $order->status);
        $this->assertSame(3000.0, (float) $order->paid_cash_usd);
        $this->assertSame(17745.0, (float) $order->paid_cash_uah);
        $this->assertSame(150820.0, (float) $order->paid_amount_uah);
        $this->assertNotNull($order->payment_confirmed_at);
    }

    public function test_paid_customer_order_keeps_catalog_item_reserved(): void
    {
        $user = $this->adminUser('admin-customer-order-payment-reservation@example.com');
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://part/paid-reserved',
            'part_number' => '1034344-20-B',
            'name' => 'Paid reserved handle',
            'raw_attributes' => [
                'stock_quantity' => 1,
                'reserved_quantity' => 1,
                'reserved_orders' => ['ORD-20260603-0013'],
            ],
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260603-0013',
            'status' => CustomerOrder::STATUS_ASSEMBLED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'total_amount' => 1500,
            'currency' => 'UAH',
        ]);
        $order->items()->create([
            'part_catalog_item_id' => $catalogItem->id,
            'name' => 'Paid reserved handle',
            'part_number' => '1034344-20-B',
            'quantity' => 1,
            'unit_price' => 1500,
            'total_price' => 1500,
            'currency' => 'UAH',
        ]);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->post(route('admin.customer-orders.payment.confirm', $order), [
                'payment_type' => CustomerOrder::PAYMENT_TYPE_BANK_FOP,
                'received_amount' => 1500,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order));

        $this->assertSame(CustomerOrder::STATUS_ASSEMBLED, $order->refresh()->status);
        $this->assertSame(1.0, (float) data_get($catalogItem->refresh()->raw_attributes, 'reserved_quantity'));
        $this->assertSame([$order->number], data_get($catalogItem->raw_attributes, 'reserved_orders'));

        $this->actingAs($user)
            ->get(route('admin.zapchasti.index'))
            ->assertOk()
            ->assertSee('nikolacars-reserved-row', false)
            ->assertSee('nikolacars-reserved-note', false);
    }

    public function test_paid_nova_poshta_customer_order_stays_shipped_and_keeps_reservation(): void
    {
        $user = $this->adminUser('admin-customer-order-nova-poshta-paid-issued@example.com');
        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_date' => now()->toDateString(),
            'rate' => 43,
            'source' => 'monobank',
            'fetched_at' => now(),
        ]);
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://part/nova-poshta-paid-issued',
            'part_number' => '1034344-20-B',
            'name' => 'Nova Poshta paid handle',
            'raw_attributes' => [
                'stock_quantity' => 1,
                'reserved_quantity' => 1,
                'reserved_orders' => ['ORD-20260604-0014'],
            ],
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260604-0014',
            'status' => CustomerOrder::STATUS_SHIPPED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA,
            'total_amount' => 1500,
            'currency' => 'UAH',
        ]);
        $order->items()->create([
            'part_catalog_item_id' => $catalogItem->id,
            'name' => 'Nova Poshta paid handle',
            'part_number' => '1034344-20-B',
            'quantity' => 1,
            'unit_price' => 1500,
            'total_price' => 1500,
            'currency' => 'UAH',
        ]);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->post(route('admin.customer-orders.payment.confirm', $order), [
                'payment_type' => CustomerOrder::PAYMENT_TYPE_BANK_FOP,
                'received_amount' => 1500,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order));

        $this->assertSame(CustomerOrder::STATUS_SHIPPED, $order->refresh()->status);
        $this->assertFalse($order->isIssuedToClient());
        $this->assertSame(CustomerOrder::STATUS_LABELS[CustomerOrder::STATUS_SHIPPED], $order->status_label);
        $catalogItem->refresh();
        $this->assertSame(1.0, (float) data_get($catalogItem->raw_attributes, 'stock_quantity'));
        $this->assertNull(data_get($catalogItem->raw_attributes, 'storage_status'));
        $this->assertSame(1.0, (float) data_get($catalogItem->raw_attributes, 'reserved_quantity'));
        $this->assertSame([$order->number], data_get($catalogItem->raw_attributes, 'reserved_orders'));
        $this->assertDatabaseMissing('part_sales', [
            'part_catalog_item_id' => $catalogItem->id,
            'document_number' => $order->number,
            'source_file' => 'customer-order-issued',
            'source_row_hash' => 'customer-order-'.$order->id.'-item-'.$order->items()->firstOrFail()->id,
        ]);

        $indexResponse = $this->actingAs($user)
            ->get(route('admin.customer-orders.index'))
            ->assertOk()
            ->assertSee($order->number)
            ->assertSee(CustomerOrder::STATUS_LABELS[CustomerOrder::STATUS_SHIPPED])
            ->assertSee("\u{041E}\u{043F}\u{043B}\u{0430}\u{0447}\u{0435}\u{043D}\u{043E}");
        $indexOrderRow = $this->tableRowContaining($indexResponse->getContent(), $order->number);
        $this->assertStringContainsString(CustomerOrder::STATUS_LABELS[CustomerOrder::STATUS_SHIPPED], $indexOrderRow);
        $this->assertStringContainsString("\u{041E}\u{043F}\u{043B}\u{0430}\u{0447}\u{0435}\u{043D}\u{043E}", $indexOrderRow);
        $this->assertStringContainsString('value="'.CustomerOrder::STATUS_COMPLETED.'"', $indexOrderRow);
        $this->assertStringContainsString("\u{0412}\u{044B}\u{0434}\u{0430}\u{043D}", $indexOrderRow);

        $showResponse = $this->actingAs($user)
            ->get(route('admin.customer-orders.show', $order))
            ->assertOk()
            ->assertSee(CustomerOrder::STATUS_LABELS[CustomerOrder::STATUS_SHIPPED])
            ->assertSee("\u{041E}\u{043F}\u{043B}\u{0430}\u{0447}\u{0435}\u{043D}\u{043E}");
        $showStatusRow = $this->tableRowContaining($showResponse->getContent(), "<th>\u{0421}\u{0442}\u{0430}\u{0442}\u{0443}\u{0441}</th>");
        $this->assertStringContainsString(CustomerOrder::STATUS_LABELS[CustomerOrder::STATUS_SHIPPED], $showStatusRow);
        $this->assertStringContainsString('value="'.CustomerOrder::STATUS_COMPLETED.'"', $showStatusRow);
        $this->assertStringContainsString("\u{0412}\u{044B}\u{0434}\u{0430}\u{043D}", $showStatusRow);
    }

    public function test_paid_shipped_nova_poshta_customer_order_can_be_marked_as_completed(): void
    {
        $user = $this->adminUser('admin-customer-order-nova-poshta-shipped-completed@example.com');
        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_date' => now()->toDateString(),
            'rate' => 43,
            'source' => 'monobank',
            'fetched_at' => now(),
        ]);
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://part/nova-poshta-shipped-completed',
            'part_number' => '1034344-20-B',
            'name' => 'Nova Poshta completed handle',
            'raw_attributes' => [
                'stock_quantity' => 1,
                'reserved_quantity' => 1,
                'reserved_orders' => ['ORD-20260604-0015'],
            ],
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260604-0015',
            'status' => CustomerOrder::STATUS_SHIPPED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA,
            'total_amount' => 1500,
            'currency' => 'UAH',
            'paid_amount_uah' => 1500,
            'payment_confirmed_at' => now(),
        ]);
        $orderItem = $order->items()->create([
            'part_catalog_item_id' => $catalogItem->id,
            'name' => 'Nova Poshta completed handle',
            'part_number' => '1034344-20-B',
            'quantity' => 1,
            'unit_price' => 1500,
            'total_price' => 1500,
            'currency' => 'UAH',
        ]);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->patch(route('admin.customer-orders.status.update', $order), [
                'status' => CustomerOrder::STATUS_COMPLETED,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order));

        $this->assertSame(CustomerOrder::STATUS_COMPLETED, $order->refresh()->status);
        $this->assertTrue($order->isIssuedToClient());
        $catalogItem->refresh();
        $this->assertSame(0.0, (float) data_get($catalogItem->raw_attributes, 'stock_quantity'));
        $this->assertSame(Product::STORAGE_STATUS_SOLD, data_get($catalogItem->raw_attributes, 'storage_status'));
        $this->assertSame(0.0, (float) data_get($catalogItem->raw_attributes, 'reserved_quantity'));
        $this->assertSame([], data_get($catalogItem->raw_attributes, 'reserved_orders'));
        $this->assertDatabaseHas('part_sales', [
            'part_catalog_item_id' => $catalogItem->id,
            'document_number' => $order->number,
            'source_file' => 'customer-order-issued',
            'source_row_hash' => 'customer-order-'.$order->id.'-item-'.$orderItem->id,
        ]);
    }

    public function test_received_nova_poshta_customer_order_releases_reservation_and_creates_sale(): void
    {
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://part/nova-poshta-received-issued',
            'part_number' => '1034344-20-R',
            'name' => 'Nova Poshta received handle',
            'raw_attributes' => [
                'stock_quantity' => 1,
                'reserved_quantity' => 1,
                'reserved_orders' => ['ORD-20260604-0016'],
            ],
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260604-0016',
            'status' => CustomerOrder::STATUS_SHIPPED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA,
            'total_amount' => 1500,
            'currency' => 'UAH',
        ]);
        $order->novaPoshtaShipments()->create([
            'carrier' => CustomerOrderShipment::CARRIER_NOVA_POSHTA,
            'status' => CustomerOrderShipment::STATUS_CREATED,
            'tracking_number' => '20400000000001',
            'np_status_code' => CustomerOrder::NOVA_POSHTA_STATUS_RECEIVED,
            'np_status' => "\u{0412}\u{0456}\u{0434}\u{043F}\u{0440}\u{0430}\u{0432}\u{043B}\u{0435}\u{043D}\u{043D}\u{044F} \u{043E}\u{0442}\u{0440}\u{0438}\u{043C}\u{0430}\u{043D}\u{043E}",
        ]);
        $orderItem = $order->items()->create([
            'part_catalog_item_id' => $catalogItem->id,
            'name' => 'Nova Poshta received handle',
            'part_number' => '1034344-20-R',
            'quantity' => 1,
            'unit_price' => 1500,
            'total_price' => 1500,
            'currency' => 'UAH',
        ]);

        $this->assertTrue($order->fresh('novaPoshtaShipment')->isIssuedToClient());
        $this->assertFalse(CustomerOrder::query()->reservable()->whereKey($order->id)->exists());

        $this->artisan('customer-orders:sync-issued-sales')
            ->assertExitCode(0);

        $catalogItem->refresh();
        $this->assertSame(0.0, (float) data_get($catalogItem->raw_attributes, 'stock_quantity'));
        $this->assertSame(Product::STORAGE_STATUS_SOLD, data_get($catalogItem->raw_attributes, 'storage_status'));
        $this->assertSame(0.0, (float) data_get($catalogItem->raw_attributes, 'reserved_quantity'));
        $this->assertSame([], data_get($catalogItem->raw_attributes, 'reserved_orders'));
        $this->assertDatabaseHas('part_sales', [
            'part_catalog_item_id' => $catalogItem->id,
            'document_number' => $order->number,
            'source_file' => 'customer-order-issued',
            'source_row_hash' => 'customer-order-'.$order->id.'-item-'.$orderItem->id,
        ]);
    }

    public function test_refused_nova_poshta_order_with_received_return_stays_active_and_can_be_returned_to_stock(): void
    {
        $user = $this->adminUser('admin-customer-order-return-to-stock@example.com');
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://inventory-product/refused-return',
            'part_number' => '1034344-20-V',
            'name' => 'Refused returned handle',
            'raw_attributes' => [
                'stock_quantity' => 1,
            ],
        ]);
        $product = Product::query()->create([
            'name' => 'Refused returned handle',
            'slug' => 'refused-returned-handle',
            'sku' => 'NC-REFUSED-RETURNED',
            'external_sku' => '1034344-20-V',
            'source_part_catalog_item_id' => $catalogItem->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'is_active' => true,
            'selling_price' => 10,
            'currency' => 'USD',
        ]);
        $stockItem = $this->createProductStockItem($product, 1, 1);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260604-0026',
            'status' => CustomerOrder::STATUS_REFUSED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA,
            'total_amount' => 450,
            'currency' => 'UAH',
        ]);
        $order->novaPoshtaShipments()->create([
            'carrier' => CustomerOrderShipment::CARRIER_NOVA_POSHTA,
            'status' => CustomerOrderShipment::STATUS_CREATED,
            'tracking_number' => '20400000000002',
            'np_return_tracking_number' => '20400000000003',
            'np_return_status_code' => CustomerOrder::NOVA_POSHTA_STATUS_RECEIVED,
            'np_return_status' => "\u{0412}\u{0456}\u{0434}\u{043F}\u{0440}\u{0430}\u{0432}\u{043B}\u{0435}\u{043D}\u{043D}\u{044F} \u{043E}\u{0442}\u{0440}\u{0438}\u{043C}\u{0430}\u{043D}\u{043E}",
        ]);
        $order->items()->create([
            'part_catalog_item_id' => $catalogItem->id,
            'product_id' => $product->id,
            'name' => 'Refused returned handle',
            'part_number' => '1034344-20-V',
            'quantity' => 1,
            'unit_price' => 450,
            'total_price' => 450,
            'currency' => 'UAH',
        ]);
        Reservation::query()->create([
            'product_id' => $product->id,
            'stock_item_id' => $stockItem->id,
            'customer_order_id' => 'customer-order:'.$order->id,
            'quantity' => 1,
            'status' => 'active',
            'comment' => 'Customer order '.$order->number,
        ]);

        $activeHtml = $this->actingAs($user)
            ->get(route('admin.customer-orders.index'))
            ->assertOk()
            ->assertSee('ORD-20260604-0026')
            ->assertSee("\u{0412}\u{0435}\u{0440}\u{043D}\u{0443}\u{0442}\u{044C} \u{043D}\u{0430} \u{0441}\u{043A}\u{043B}\u{0430}\u{0434}")
            ->getContent();
        $this->assertStringContainsString('ORD-20260604-0026', $activeHtml);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.index', ['tab' => 'cancelled']))
            ->assertOk()
            ->assertDontSee('ORD-20260604-0026');

        $this->actingAs($user)
            ->from(route('admin.customer-orders.index'))
            ->post(route('admin.customer-orders.return-to-stock', $order), [
                'warehouse_id' => $stockItem->warehouse_id,
                'floor' => $stockItem->location->floor,
                'location_id' => $stockItem->location_id,
            ])
            ->assertRedirect(route('admin.customer-orders.index'));

        $stockItem->refresh();
        $this->assertSame(0, $stockItem->reserved_quantity);
        $this->assertSame(1, $stockItem->quantity);
        $this->assertSame(1, $stockItem->available_quantity);
        $this->assertSame(Product::STORAGE_STATUS_IN_STOCK, $product->refresh()->storage_status);
        $this->assertDatabaseHas('customer_order_history_events', [
            'customer_order_id' => $order->id,
            'event_type' => 'returned_to_stock',
        ]);
        $this->assertFalse(CustomerOrder::query()->reservable()->whereKey($order->id)->exists());

        $this->actingAs($user)
            ->get(route('admin.customer-orders.index'))
            ->assertOk()
            ->assertSee('ORD-20260604-0026')
            ->assertDontSee('customer-order-return-to-stock-'.$order->id, false);
    }

    public function test_refused_nova_poshta_return_cannot_be_returned_to_donor_warehouse(): void
    {
        $user = $this->adminUser('admin-customer-order-return-not-donor@example.com');
        $donorWarehouse = Warehouse::query()->create([
            'name' => Warehouse::DONOR_WAREHOUSE_NAME,
            'type' => Warehouse::TYPE_DONOR,
            'floor_count' => 1,
            'is_active' => true,
        ]);
        $donorLocation = Location::query()->create([
            'warehouse_id' => $donorWarehouse->id,
            'floor' => 'floor_1',
            'cell' => 'DONOR-CELL',
            'full_code' => 'DONOR-CELL',
            'is_active' => true,
        ]);
        $mainWarehouse = Warehouse::query()->create([
            'name' => 'Main return warehouse',
            'type' => Warehouse::TYPE_MAIN,
            'floor_count' => 1,
            'is_active' => true,
        ]);
        Location::query()->create([
            'warehouse_id' => $mainWarehouse->id,
            'floor' => 'floor_1',
            'cell' => 'MAIN-CELL',
            'full_code' => 'MAIN-CELL',
            'is_active' => true,
        ]);
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://inventory-product/refused-donor-return',
            'part_number' => '1034344-20-D',
            'name' => 'Refused donor returned handle',
            'raw_attributes' => [
                'stock_quantity' => 1,
            ],
        ]);
        $product = Product::query()->create([
            'name' => 'Refused donor returned handle',
            'slug' => 'refused-donor-returned-handle',
            'sku' => 'NC-REFUSED-DONOR',
            'external_sku' => '1034344-20-D',
            'source_part_catalog_item_id' => $catalogItem->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'is_active' => true,
            'selling_price' => 10,
            'currency' => 'USD',
        ]);
        $stockItem = StockItem::query()->create([
            'product_id' => $product->id,
            'warehouse_id' => $donorWarehouse->id,
            'location_id' => $donorLocation->id,
            'quantity' => 1,
            'reserved_quantity' => 1,
            'available_quantity' => 0,
            'testing_status' => 'not_tested',
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260604-0027',
            'status' => CustomerOrder::STATUS_REFUSED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA,
            'total_amount' => 450,
            'currency' => 'UAH',
        ]);
        $order->novaPoshtaShipments()->create([
            'carrier' => CustomerOrderShipment::CARRIER_NOVA_POSHTA,
            'status' => CustomerOrderShipment::STATUS_CREATED,
            'tracking_number' => '20400000000004',
            'np_return_tracking_number' => '20400000000005',
            'np_return_status_code' => CustomerOrder::NOVA_POSHTA_STATUS_RECEIVED,
            'np_return_status' => "\u{0412}\u{0456}\u{0434}\u{043F}\u{0440}\u{0430}\u{0432}\u{043B}\u{0435}\u{043D}\u{043D}\u{044F} \u{043E}\u{0442}\u{0440}\u{0438}\u{043C}\u{0430}\u{043D}\u{043E}",
        ]);
        $order->items()->create([
            'part_catalog_item_id' => $catalogItem->id,
            'product_id' => $product->id,
            'name' => 'Refused donor returned handle',
            'part_number' => '1034344-20-D',
            'quantity' => 1,
            'unit_price' => 450,
            'total_price' => 450,
            'currency' => 'UAH',
        ]);
        Reservation::query()->create([
            'product_id' => $product->id,
            'stock_item_id' => $stockItem->id,
            'customer_order_id' => 'customer-order:'.$order->id,
            'quantity' => 1,
            'status' => 'active',
            'comment' => 'Customer order '.$order->number,
        ]);

        $response = $this->actingAs($user)
            ->get(route('admin.customer-orders.index'))
            ->assertOk()
            ->assertSee('ORD-20260604-0027')
            ->assertDontSee(Warehouse::DONOR_WAREHOUSE_NAME);

        $this->assertStringContainsString('data-return-warehouse data-selected=""', $response->getContent());
        $this->assertStringNotContainsString('value="'.$donorWarehouse->id.'"', $response->getContent());

        $this->actingAs($user)
            ->from(route('admin.customer-orders.index'))
            ->post(route('admin.customer-orders.return-to-stock', $order), [
                'warehouse_id' => $donorWarehouse->id,
                'floor' => $donorLocation->floor,
                'location_id' => $donorLocation->id,
            ])
            ->assertRedirect(route('admin.customer-orders.index'))
            ->assertSessionHasErrors('warehouse_id');
    }

    public function test_paid_pickup_customer_order_can_be_marked_as_completed_and_releases_reservation(): void
    {
        $user = $this->adminUser('admin-customer-order-completed@example.com');
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://part/completed-reserved',
            'part_number' => '1034344-20-B',
            'name' => 'Completed reserved handle',
            'raw_attributes' => [
                'stock_quantity' => 1,
                'reserved_quantity' => 1,
                'reserved_orders' => ['ORD-20260604-0020'],
            ],
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260604-0020',
            'status' => CustomerOrder::STATUS_PAID,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'total_amount' => 1500,
            'currency' => 'UAH',
            'paid_amount_uah' => 1500,
            'payment_confirmed_at' => now(),
        ]);
        $product = Product::query()->create([
            'name' => 'Completed reserved handle',
            'slug' => 'completed-reserved-handle',
            'sku' => 'NC-COMPLETED-RESERVED',
            'source_part_catalog_item_id' => $catalogItem->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'is_active' => true,
        ]);
        $order->items()->create([
            'part_catalog_item_id' => $catalogItem->id,
            'name' => 'Completed reserved handle',
            'part_number' => '1034344',
            'quantity' => 1,
            'unit_price' => 1500,
            'total_price' => 1500,
            'currency' => 'UAH',
        ]);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.index'))
            ->assertOk()
            ->assertSee($order->number)
            ->assertSee("\u{0412}\u{044B}\u{0434}\u{0430}\u{043D}");

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->patch(route('admin.customer-orders.status.update', $order), [
                'status' => CustomerOrder::STATUS_COMPLETED,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order));

        $this->assertSame(CustomerOrder::STATUS_COMPLETED, $order->refresh()->status);
        $this->assertSame(CustomerOrder::STATUS_LABELS[CustomerOrder::STATUS_COMPLETED], $order->status_label);
        $this->assertFalse($order->canBeEdited());
        $catalogItem->refresh();
        $this->assertSame(0.0, (float) data_get($catalogItem->raw_attributes, 'stock_quantity'));
        $this->assertSame(Product::STORAGE_STATUS_SOLD, data_get($catalogItem->raw_attributes, 'storage_status'));
        $this->assertSame(0.0, (float) data_get($catalogItem->raw_attributes, 'reserved_quantity'));
        $this->assertSame([], data_get($catalogItem->raw_attributes, 'reserved_orders'));
        $this->assertSame(Product::STORAGE_STATUS_SOLD, $product->refresh()->storage_status);
        $this->assertFalse((bool) $product->is_active);
        $sale = PartSale::query()
            ->where('part_catalog_item_id', $catalogItem->id)
            ->where('document_number', $order->number)
            ->where('source_file', 'customer-order-issued')
            ->firstOrFail();
        $this->assertSame('1034344-20-B', $sale->part_number);
        $this->assertSame('1034344-20-B', data_get($sale->raw_attributes, 'original_part_number'));
        $this->assertDatabaseHas('part_sales', [
            'part_catalog_item_id' => $catalogItem->id,
            'document_number' => $order->number,
            'source_file' => 'customer-order-issued',
        ]);

        $this->artisan('customer-orders:sync-issued-sales')
            ->assertExitCode(0);

        $this->assertSame(1, PartSale::query()
            ->where('part_catalog_item_id', $catalogItem->id)
            ->where('document_number', $order->number)
            ->where('source_file', 'customer-order-issued')
            ->count());
        $this->assertSame(0.0, (float) data_get($catalogItem->refresh()->raw_attributes, 'stock_quantity'));
        $this->assertSame(0.0, (float) data_get($catalogItem->raw_attributes, 'reserved_quantity'));
        $this->assertSame([], data_get($catalogItem->raw_attributes, 'reserved_orders'));

        $this->actingAs($user)
            ->get(route('admin.zapchasti.index', ['q' => 'Completed reserved handle']))
            ->assertOk()
            ->assertDontSee(route('admin.zapchasti.show', $catalogItem), false);

        $this->actingAs($user)
            ->get(route('admin.zapchasti.index', [
                'q' => 'Completed reserved handle',
                'hide_sold' => '0',
            ]))
            ->assertOk()
            ->assertSee('Completed reserved handle');

        $this->actingAs($user)
            ->get(route('admin.nikolacars-sales.index', ['q' => $order->number]))
            ->assertOk()
            ->assertSee('1034344-20-B');

        $this->actingAs($user)
            ->get(route('admin.customer-orders.index'))
            ->assertOk()
            ->assertSee($order->number)
            ->assertSee("\u{041E}\u{043F}\u{043B}\u{0430}\u{0447}\u{0435}\u{043D}\u{043E}");
    }

    public function test_completed_product_order_releases_stock_reservation_before_stock_writeoff(): void
    {
        $user = $this->adminUser('admin-customer-order-completed-stock-reservation@example.com');
        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_date' => now()->toDateString(),
            'rate' => 41.25,
            'source' => 'monobank',
            'fetched_at' => now(),
        ]);
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://part/completed-stock-reservation',
            'part_number' => '1034344-20-S',
            'name' => 'Completed stock reservation handle',
            'price_amount' => 150,
            'currency' => 'USD',
            'raw_attributes' => [
                'code' => 'NC-COMPLETED-STOCK',
                'stock_quantity' => 1,
            ],
        ]);
        $product = Product::query()->create([
            'sku' => 'NC-COMPLETED-STOCK',
            'external_sku' => '1034344-20-S',
            'name' => 'Completed stock reservation handle',
            'slug' => 'completed-stock-reservation-handle',
            'source_part_catalog_item_id' => $catalogItem->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'selling_price' => 150,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $stockItem = $this->createProductStockItem($product);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260604-0025',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'total_amount' => 0,
            'currency' => 'UAH',
        ]);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->post(route('admin.customer-orders.items.store', $order), [
                'product_id' => $product->id,
                'part_catalog_item_id' => $catalogItem->id,
                'quantity' => 1,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order));

        $this->assertSame(1, $stockItem->refresh()->reserved_quantity);

        $order->forceFill([
            'status' => CustomerOrder::STATUS_PAID,
            'paid_amount_uah' => $order->refresh()->total_amount,
            'payment_confirmed_at' => now(),
        ])->save();

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->patch(route('admin.customer-orders.status.update', $order), [
                'status' => CustomerOrder::STATUS_COMPLETED,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order));

        $this->assertSame(0, $stockItem->refresh()->reserved_quantity);
        $this->assertSame(0, $stockItem->quantity);
        $this->assertSame(0, $stockItem->available_quantity);
        $this->assertSame(Product::STORAGE_STATUS_SOLD, $product->refresh()->storage_status);
        $this->assertSame(0.0, (float) data_get($catalogItem->refresh()->raw_attributes, 'reserved_quantity'));
        $this->assertDatabaseHas('reservations', [
            'product_id' => $product->id,
            'stock_item_id' => $stockItem->id,
            'customer_order_id' => 'customer-order:'.$order->id,
            'status' => 'released',
        ]);
        $this->assertDatabaseHas('part_sales', [
            'product_id' => $product->id,
            'part_catalog_item_id' => $catalogItem->id,
            'document_number' => $order->number,
            'source_file' => 'customer-order-issued',
        ]);
    }

    public function test_cancelled_issued_product_order_restores_catalog_quantity_once(): void
    {
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://inventory-product/cancel-issued-once',
            'part_number' => '1034344-20-C',
            'name' => 'Cancel issued once handle',
            'raw_attributes' => [
                'stock_quantity' => 1,
            ],
        ]);
        $product = Product::query()->create([
            'name' => 'Cancel issued once handle',
            'slug' => 'cancel-issued-once-handle',
            'sku' => 'NC-CANCEL-ISSUED-ONCE',
            'external_sku' => '1034344-20-C',
            'source_part_catalog_item_id' => $catalogItem->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'is_active' => true,
            'selling_price' => 10,
            'currency' => 'USD',
        ]);
        $warehouse = Warehouse::query()->create([
            'name' => 'Main warehouse',
            'type' => Warehouse::TYPE_MAIN,
            'floor_count' => 1,
            'is_active' => true,
        ]);
        $location = Location::query()->create([
            'warehouse_id' => $warehouse->id,
            'floor' => 'floor_1',
            'cell' => 'A1',
            'full_code' => 'A1',
            'is_active' => true,
        ]);
        $stockItem = StockItem::query()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'location_id' => $location->id,
            'quantity' => 1,
            'reserved_quantity' => 0,
            'available_quantity' => 1,
            'testing_status' => 'not_tested',
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260604-0024',
            'status' => CustomerOrder::STATUS_COMPLETED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'total_amount' => 450,
            'currency' => 'UAH',
            'paid_amount_uah' => 450,
            'payment_confirmed_at' => now(),
        ]);
        $orderItem = $order->items()->create([
            'part_catalog_item_id' => $catalogItem->id,
            'product_id' => $product->id,
            'name' => 'Cancel issued once handle',
            'part_number' => '1034344-20-C',
            'quantity' => 1,
            'unit_price' => 450,
            'total_price' => 450,
            'currency' => 'UAH',
            'unit_price_usd_hint' => 10,
        ]);

        $service = app(CustomerOrderIssuedSaleService::class);
        $this->assertSame(1, $service->syncOrder($order->fresh(['items.partCatalogItem', 'items.product'])));

        $this->assertSame(0, $stockItem->refresh()->quantity);
        $this->assertSame(0.0, (float) data_get($catalogItem->refresh()->raw_attributes, 'stock_quantity'));
        $this->assertSame(Product::STORAGE_STATUS_SOLD, $product->refresh()->storage_status);

        $this->assertSame(1, $service->cancelOrder($order->fresh(['items.partCatalogItem', 'items.product'])));

        $catalogItem->refresh();
        $product->refresh();
        $stockItem->refresh();

        $this->assertSame(1, $stockItem->quantity);
        $this->assertSame(1, $stockItem->available_quantity);
        $this->assertSame(1.0, (float) data_get($catalogItem->raw_attributes, 'stock_quantity'));
        $this->assertNull(data_get($catalogItem->raw_attributes, 'customer_order_sale_id'));
        $this->assertNull(data_get($catalogItem->raw_attributes, 'sold_document_number'));
        $this->assertNull(data_get($catalogItem->raw_attributes, 'stock_quantity_before_customer_order_sale_'.$orderItem->id));
        $this->assertSame(Product::STORAGE_STATUS_IN_STOCK, $product->storage_status);
        $this->assertTrue((bool) $product->is_active);
        $this->assertDatabaseMissing('part_sales', [
            'source_file' => 'customer-order-issued',
            'source_row_hash' => 'customer-order-'.$order->id.'-item-'.$orderItem->id,
        ]);
    }

    public function test_issued_product_order_uses_current_product_catalog_projection_when_order_item_keeps_stale_catalog_id(): void
    {
        $oldCatalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://inventory-product/stale-order-link',
            'part_number' => '1081439-S0-A',
            'name' => 'Old projected part',
            'price_amount' => 10,
            'currency' => 'USD',
            'raw_attributes' => [
                'stock_quantity' => 9,
            ],
        ]);
        $currentCatalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://inventory-product/current-order-link',
            'part_number' => '1081421-E0-C',
            'name' => 'Current projected part',
            'price_amount' => 25,
            'currency' => 'USD',
            'raw_attributes' => [
                'stock_quantity' => 1,
            ],
        ]);
        $product = Product::query()->create([
            'name' => 'Current product part',
            'slug' => 'current-product-part',
            'sku' => 'NC-CURRENT-LINK',
            'external_sku' => '1081421-E0-C',
            'source_part_catalog_item_id' => $currentCatalogItem->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'is_active' => true,
            'selling_price' => 25,
            'currency' => 'USD',
        ]);
        $currentCatalogItem->forceFill([
            'source_url' => 'nikolacars://inventory-product/'.$product->id,
            'raw_attributes' => [
                'product_id' => $product->id,
                'stock_quantity' => 1,
            ],
        ])->save();

        $warehouse = Warehouse::query()->create([
            'name' => 'Main warehouse',
            'type' => Warehouse::TYPE_MAIN,
            'floor_count' => 1,
            'is_active' => true,
        ]);
        $location = Location::query()->create([
            'warehouse_id' => $warehouse->id,
            'floor' => 'floor_1',
            'cell' => 'A1',
            'full_code' => 'A1',
            'is_active' => true,
        ]);
        StockItem::query()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'location_id' => $location->id,
            'quantity' => 1,
            'reserved_quantity' => 0,
            'available_quantity' => 1,
            'testing_status' => 'not_tested',
        ]);

        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260611-0091',
            'status' => CustomerOrder::STATUS_COMPLETED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'total_amount' => 1000,
            'currency' => 'UAH',
            'paid_amount_uah' => 1000,
            'payment_confirmed_at' => now(),
        ]);
        $orderItem = $order->items()->create([
            'part_catalog_item_id' => $oldCatalogItem->id,
            'product_id' => $product->id,
            'name' => 'Current product part',
            'part_number' => '1081421-E0-C',
            'code' => 'NC-CURRENT-LINK',
            'quantity' => 1,
            'unit_price' => 1000,
            'total_price' => 1000,
            'currency' => 'UAH',
            'unit_price_usd_hint' => 25,
        ]);

        $this->assertSame(1, app(CustomerOrderIssuedSaleService::class)->syncOrder(
            $order->fresh(['items.partCatalogItem', 'items.product.sourcePartCatalogItem'])
        ));

        $sale = PartSale::query()
            ->where('source_file', 'customer-order-issued')
            ->where('source_row_hash', 'customer-order-'.$order->id.'-item-'.$orderItem->id)
            ->firstOrFail();

        $this->assertSame($currentCatalogItem->id, $sale->part_catalog_item_id);
        $this->assertSame($product->id, $sale->product_id);
        $this->assertSame('1081421-E0-C', $sale->part_number);
        $this->assertSame(0.0, (float) data_get($currentCatalogItem->refresh()->raw_attributes, 'stock_quantity'));
        $this->assertSame(9.0, (float) data_get($oldCatalogItem->refresh()->raw_attributes, 'stock_quantity'));
    }

    public function test_issued_product_order_does_not_use_catalog_stock_when_product_has_no_stock_items(): void
    {
        $user = $this->adminUser('admin-customer-order-catalog-stock-product@example.com');
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/catalog-stock-product',
            'part_number' => '1068964-00-J',
            'name' => 'Catalog stock latch',
            'price_amount' => 25,
            'currency' => 'USD',
            'raw_attributes' => [
                'code' => '107',
                'stock_quantity' => 2,
                'reserved_quantity' => 1,
                'reserved_orders' => ['ORD-20260611-0003'],
            ],
        ]);
        $product = Product::query()->create([
            'name' => 'Catalog stock latch',
            'slug' => 'catalog-stock-latch',
            'sku' => 'NC-107',
            'external_sku' => '1068964-00-J',
            'source_part_catalog_item_id' => $catalogItem->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'is_active' => true,
            'selling_price' => 25,
            'currency' => 'USD',
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260611-0003',
            'status' => CustomerOrder::STATUS_COMPLETED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'total_amount' => 1340,
            'currency' => 'UAH',
            'paid_amount_uah' => 1340,
            'payment_confirmed_at' => now(),
        ]);
        $orderItem = $order->items()->create([
            'part_catalog_item_id' => $catalogItem->id,
            'product_id' => $product->id,
            'name' => 'Catalog stock latch',
            'part_number' => '1068964',
            'code' => '107',
            'quantity' => 1,
            'unit_price' => 1340,
            'total_price' => 1340,
            'currency' => 'UAH',
            'unit_price_usd_hint' => 25,
        ]);

        $this->assertSame(1, app(CustomerOrderIssuedSaleService::class)->syncOrder(
            $order->fresh(['items.partCatalogItem', 'items.product'])
        ));

        $catalogItem->refresh();
        $product->refresh();
        $sale = PartSale::query()
            ->where('source_file', 'customer-order-issued')
            ->where('source_row_hash', 'customer-order-'.$order->id.'-item-'.$orderItem->id)
            ->firstOrFail();

        $this->assertSame(0, $product->stockItems()->count());
        $this->assertSame(0.0, (float) data_get($catalogItem->raw_attributes, 'stock_quantity'));
        $this->assertSame(0.0, (float) data_get($catalogItem->raw_attributes, 'reserved_quantity'));
        $this->assertSame([], data_get($catalogItem->raw_attributes, 'reserved_orders'));
        $this->assertSame(Product::STORAGE_STATUS_SOLD, data_get($catalogItem->raw_attributes, 'storage_status'));
        $this->assertSame(Product::STORAGE_STATUS_SOLD, $product->storage_status);
        $this->assertFalse((bool) $product->is_active);
        $this->assertSame(0.0, (float) data_get($sale->raw_attributes, 'stock_quantity_before_sale'));
        $this->assertSame(0.0, (float) data_get($sale->raw_attributes, 'stock_quantity_after_sale'));

        $inventoryGroup = app(NikolaCarsInventoryService::class)
            ->itemGroups(collect([$catalogItem]), ['rate' => 40], fn (PartCatalogItem $item): string => (string) $item->name)
            ->first();

        $this->assertSame(0.0, $inventoryGroup['stock_quantity']);
        $this->assertSame(0.0, $inventoryGroup['reserved_quantity']);
        $this->assertSame(0.0, $inventoryGroup['availability_reserved_quantity']);
        $this->assertSame(0.0, $inventoryGroup['quantity']);

    }

    public function test_issued_product_order_uses_product_stock_when_catalog_stock_is_inflated(): void
    {
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/inflated-catalog-stock',
            'part_number' => '1068964-00-J',
            'name' => 'Inflated catalog stock latch',
            'price_amount' => 25,
            'currency' => 'USD',
            'raw_attributes' => [
                'code' => '107',
                'source_row' => [
                    'code' => '107',
                    'stock' => '1',
                ],
                'stock_quantity' => 2,
                'reserved_quantity' => 1,
                'reserved_orders' => ['ORD-20260611-0004'],
            ],
        ]);
        $product = Product::query()->create([
            'name' => 'Inflated catalog stock latch',
            'slug' => 'inflated-catalog-stock-latch',
            'sku' => 'NC-107-INFLATED',
            'external_sku' => '1068964-00-J',
            'source_part_catalog_item_id' => $catalogItem->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'is_active' => true,
            'selling_price' => 25,
            'currency' => 'USD',
        ]);
        $this->createProductStockItem($product);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260611-0004',
            'status' => CustomerOrder::STATUS_COMPLETED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'total_amount' => 1340,
            'currency' => 'UAH',
            'paid_amount_uah' => 1340,
            'payment_confirmed_at' => now(),
        ]);
        $orderItem = $order->items()->create([
            'part_catalog_item_id' => $catalogItem->id,
            'product_id' => $product->id,
            'name' => 'Inflated catalog stock latch',
            'part_number' => '1068964',
            'code' => '107',
            'quantity' => 1,
            'unit_price' => 1340,
            'total_price' => 1340,
            'currency' => 'UAH',
            'unit_price_usd_hint' => 25,
        ]);

        $this->assertSame(1, app(CustomerOrderIssuedSaleService::class)->syncOrder(
            $order->fresh(['items.partCatalogItem', 'items.product'])
        ));

        $catalogItem->refresh();
        $product->refresh();
        $sale = PartSale::query()
            ->where('source_file', 'customer-order-issued')
            ->where('source_row_hash', 'customer-order-'.$order->id.'-item-'.$orderItem->id)
            ->firstOrFail();

        $this->assertSame(1, $product->stockItems()->count());
        $this->assertSame(0, (int) $product->stockItems()->sum('quantity'));
        $this->assertSame(0.0, (float) data_get($catalogItem->raw_attributes, 'stock_quantity'));
        $this->assertSame(0.0, (float) data_get($catalogItem->raw_attributes, 'reserved_quantity'));
        $this->assertSame([], data_get($catalogItem->raw_attributes, 'reserved_orders'));
        $this->assertSame(Product::STORAGE_STATUS_SOLD, data_get($catalogItem->raw_attributes, 'storage_status'));
        $this->assertSame(Product::STORAGE_STATUS_SOLD, $product->storage_status);
        $this->assertFalse((bool) $product->is_active);
        $this->assertSame(1.0, (float) data_get($sale->raw_attributes, 'stock_quantity_before_sale'));
        $this->assertSame(0.0, (float) data_get($sale->raw_attributes, 'stock_quantity_after_sale'));
    }

    public function test_issued_paid_customer_order_cannot_be_cancelled(): void
    {
        $user = $this->adminUser('admin-customer-order-cancel-issued@example.com');
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://part/cancel-issued',
            'part_number' => '1034344-20-B',
            'name' => 'Cancel issued handle',
            'raw_attributes' => [
                'stock_quantity' => 1,
                'reserved_quantity' => 1,
                'reserved_orders' => ['ORD-20260604-0023'],
            ],
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260604-0023',
            'status' => CustomerOrder::STATUS_PAID,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'total_amount' => 1500,
            'currency' => 'UAH',
            'paid_amount_uah' => 1500,
            'payment_confirmed_at' => now(),
        ]);
        $product = Product::query()->create([
            'name' => 'Cancel issued handle',
            'slug' => 'cancel-issued-handle',
            'sku' => 'NC-CANCEL-ISSUED',
            'source_part_catalog_item_id' => $catalogItem->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'is_active' => true,
        ]);
        $warehouse = Warehouse::query()->create([
            'name' => 'Main warehouse',
            'type' => Warehouse::TYPE_MAIN,
            'floor_count' => 1,
            'is_active' => true,
        ]);
        $location = Location::query()->create([
            'warehouse_id' => $warehouse->id,
            'floor' => 'floor_1',
            'cell' => 'A1',
            'full_code' => 'A1',
            'is_active' => true,
        ]);
        $stockItem = StockItem::query()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'location_id' => $location->id,
            'quantity' => 1,
            'reserved_quantity' => 0,
            'available_quantity' => 1,
            'testing_status' => 'not_tested',
        ]);
        $orderItem = $order->items()->create([
            'part_catalog_item_id' => $catalogItem->id,
            'name' => 'Cancel issued handle',
            'part_number' => '1034344-20-B',
            'quantity' => 1,
            'unit_price' => 1500,
            'total_price' => 1500,
            'currency' => 'UAH',
        ]);

        $this->actingAs($user)
            ->patch(route('admin.customer-orders.status.update', $order), [
                'status' => CustomerOrder::STATUS_COMPLETED,
            ])
            ->assertRedirect();

        $this->assertSame(CustomerOrder::STATUS_COMPLETED, $order->refresh()->status);
        $this->assertSame(0.0, (float) data_get($catalogItem->refresh()->raw_attributes, 'stock_quantity'));
        $this->assertSame(Product::STORAGE_STATUS_SOLD, $product->refresh()->storage_status);
        $this->assertSame(0, $stockItem->refresh()->quantity);
        $this->assertSame(0, $stockItem->available_quantity);
        $this->assertDatabaseHas('part_sales', [
            'source_file' => 'customer-order-issued',
            'source_row_hash' => 'customer-order-'.$order->id.'-item-'.$orderItem->id,
        ]);

        $this->artisan('customer-orders:sync-issued-sales')
            ->assertExitCode(0);

        $this->assertSame(0, $stockItem->refresh()->quantity);
        $this->assertSame(0.0, (float) data_get($catalogItem->refresh()->raw_attributes, 'stock_quantity'));

        $this->actingAs($user)
            ->get(route('admin.customer-orders.index'))
            ->assertOk()
            ->assertSee($order->number)
            ->assertDontSee("\u{041E}\u{0442}\u{043C}\u{0435}\u{043D}\u{0438}\u{0442}\u{044C} \u{0437}\u{0430}\u{043A}\u{0430}\u{0437} {$order->number}", false);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.show', $order))
            ->assertOk()
            ->assertSee("\u{0412}\u{044B}\u{0434}\u{0430}\u{043D}")
            ->assertDontSee("\u{041E}\u{0442}\u{043C}\u{0435}\u{043D}\u{0438}\u{0442}\u{044C} \u{0437}\u{0430}\u{043A}\u{0430}\u{0437} {$order->number}", false);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->patch(route('admin.customer-orders.status.update', $order), [
                'status' => CustomerOrder::STATUS_CANCELLED,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order))
            ->assertSessionHasErrors('status');

        $this->assertSame(CustomerOrder::STATUS_COMPLETED, $order->refresh()->status);
        $catalogItem->refresh();
        $this->assertSame(0.0, (float) data_get($catalogItem->raw_attributes, 'stock_quantity'));
        $this->assertSame(0.0, (float) data_get($catalogItem->raw_attributes, 'reserved_quantity'));
        $this->assertSame([], data_get($catalogItem->raw_attributes, 'reserved_orders'));
        $this->assertSame(Product::STORAGE_STATUS_SOLD, $product->refresh()->storage_status);
        $this->assertFalse((bool) $product->is_active);
        $this->assertSame(0, $stockItem->refresh()->quantity);
        $this->assertSame(0, $stockItem->available_quantity);
        $this->assertDatabaseHas('part_sales', [
            'source_file' => 'customer-order-issued',
            'source_row_hash' => 'customer-order-'.$order->id.'-item-'.$orderItem->id,
        ]);
    }

    public function test_paid_nova_poshta_customer_order_cannot_be_marked_as_completed(): void
    {
        $user = $this->adminUser('admin-customer-order-completed-invalid@example.com');
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260604-0021',
            'status' => CustomerOrder::STATUS_PAID,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_NOVA_POSHTA,
            'total_amount' => 1500,
            'currency' => 'UAH',
            'paid_amount_uah' => 1500,
            'payment_confirmed_at' => now(),
        ]);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->patch(route('admin.customer-orders.status.update', $order), [
                'status' => CustomerOrder::STATUS_COMPLETED,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order))
            ->assertSessionHasErrors('status');

        $this->assertSame(CustomerOrder::STATUS_PAID, $order->refresh()->status);
    }

    public function test_paid_pickup_customer_order_cannot_be_completed_until_fully_paid(): void
    {
        $user = $this->adminUser('admin-customer-order-completed-not-fully-paid@example.com');
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260604-0022',
            'status' => CustomerOrder::STATUS_PAID,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'total_amount' => 1500,
            'currency' => 'UAH',
            'paid_amount_uah' => 500,
            'payment_confirmed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.index'))
            ->assertOk()
            ->assertSee($order->number)
            ->assertDontSee("\u{0412}\u{044B}\u{0434}\u{0430}\u{043D}\u{043E}");

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->patch(route('admin.customer-orders.status.update', $order), [
                'status' => CustomerOrder::STATUS_COMPLETED,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order))
            ->assertSessionHasErrors('status');

        $this->assertSame(CustomerOrder::STATUS_PAID, $order->refresh()->status);
    }

    public function test_customer_order_payment_rejects_amount_less_than_order_total(): void
    {
        $user = $this->adminUser('admin-customer-order-payment-partial@example.com');
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260603-0012',
            'status' => CustomerOrder::STATUS_ASSEMBLED,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'total_amount' => 1500,
            'currency' => 'UAH',
        ]);

        $this->actingAs($user)
            ->from(route('admin.customer-orders.show', $order))
            ->post(route('admin.customer-orders.payment.confirm', $order), [
                'payment_type' => CustomerOrder::PAYMENT_TYPE_CASH_UAH,
                'received_amount' => 500,
            ])
            ->assertRedirect(route('admin.customer-orders.show', $order))
            ->assertSessionHasErrors('payments');

        $order->refresh();

        $this->assertSame(CustomerOrder::STATUS_ASSEMBLED, $order->status);
        $this->assertSame(0.0, (float) $order->paid_cash_uah);
        $this->assertSame(0.0, (float) $order->paid_amount_uah);
        $this->assertNull($order->payment_confirmed_at);

        $this->actingAs($user)
            ->get(route('admin.customer-orders.index'))
            ->assertOk()
            ->assertSee("\u{041A}\u{0430}\u{0441}\u{0441}\u{0430}")
            ->assertSee("\u{041D}\u{0430}\u{043B}, \u{0433}\u{0440}\u{043D}")
            ->assertSee("0 \u{0433}\u{0440}\u{043D}")
            ->assertSee("\u{041D}\u{0430}\u{043B} USD")
            ->assertSee("\u{0411}\u{0435}\u{0437}\u{041D}\u{0430}\u{043B} \u{0422}\u{041E}\u{0412}")
            ->assertSee("\u{0411}\u{0435}\u{0437}\u{041D}\u{0430}\u{043B} \u{0424}\u{041E}\u{041F}")
            ->assertDontSee("\u{041E}\u{0436}\u{0438}\u{0434}\u{0430}\u{0435}\u{0442} \u{043E}\u{043F}\u{043B}\u{0430}\u{0442}\u{0443}");
    }

    protected function adminUser(string $email = 'admin-customer-order@example.com'): User
    {
        return User::query()->create([
            'name' => 'Admin',
            'email' => $email,
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    private function createProductStockItem(Product $product, int $quantity = 1, int $reservedQuantity = 0): StockItem
    {
        $warehouse = Warehouse::query()->create([
            'name' => 'Main warehouse '.$product->id,
            'type' => Warehouse::TYPE_MAIN,
            'floor_count' => 1,
            'is_active' => true,
        ]);
        $location = Location::query()->create([
            'warehouse_id' => $warehouse->id,
            'floor' => 'floor_1',
            'cell' => 'A'.$product->id,
            'full_code' => 'TEST-'.$product->id,
            'is_active' => true,
        ]);

        return StockItem::query()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'location_id' => $location->id,
            'quantity' => $quantity,
            'reserved_quantity' => $reservedQuantity,
            'available_quantity' => max(0, $quantity - $reservedQuantity),
            'testing_status' => 'not_tested',
        ]);
    }

    private function tableRowContaining(string $html, string $needle): string
    {
        $position = strpos($html, $needle);

        $this->assertNotFalse($position);

        $rowStart = strrpos(substr($html, 0, $position), '<tr');
        $rowEnd = strpos($html, '</tr>', $position);

        $this->assertNotFalse($rowStart);
        $this->assertNotFalse($rowEnd);

        return substr($html, $rowStart, $rowEnd - $rowStart);
    }
}
