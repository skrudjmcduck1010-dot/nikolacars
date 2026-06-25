<?php

namespace Tests\Feature;

use App\Models\CustomerOrder;
use App\Models\DonorCar;
use App\Models\Location;
use App\Models\PartCatalogItem;
use App\Models\Product;
use App\Models\StockItem;
use App\Models\Warehouse;
use App\Services\NikolaCarsInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NikolaCarsInventoryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_item_groups_merge_exact_articles_without_merging_prefix_variants(): void
    {
        $first = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'https://example.test/items/1',
            'part_number' => '1234567-00-A',
            'name' => 'First',
            'name_ua' => 'First UA',
            'price_amount' => 10,
            'currency' => 'USD',
            'main_category_name' => 'Body',
            'quality' => 'A',
            'raw_attributes' => [
                'stock_quantity' => 2,
                'code' => 'C1',
                'donor_vin' => 'VIN1',
                'image_urls' => ['https://example.test/1.jpg'],
            ],
        ]);
        $sameArticle = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'https://example.test/items/2',
            'part_number' => '123456700A',
            'name' => 'Second',
            'name_ua' => 'Second UA',
            'price_amount' => 10,
            'currency' => 'USD',
            'main_category_name' => 'Body',
            'quality' => 'B',
            'raw_attributes' => [
                'stock_quantity' => 3,
                'code' => 'C2',
                'category_display' => 'Shelf',
                'image_urls' => ['https://example.test/2.jpg'],
            ],
        ]);
        $prefixVariant = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'https://example.test/items/3',
            'part_number' => '1234567-01-B',
            'name' => 'Third',
            'name_ua' => 'Third UA',
            'price_amount' => 15,
            'currency' => 'USD',
            'main_category_name' => 'Body',
            'quality' => 'C',
            'raw_attributes' => [
                'stock_quantity' => 4,
                'code' => 'C3',
                'category_display' => 'Variant shelf',
                'image_urls' => ['https://example.test/3.jpg'],
            ],
        ]);

        $groups = app(NikolaCarsInventoryService::class)->itemGroups(
            collect([$first, $sameArticle, $prefixVariant]),
            ['rate' => 40],
            fn (PartCatalogItem $item): string => (string) $item->name_ua,
        );

        $this->assertCount(2, $groups);
        $group = $groups->first();
        $this->assertSame($first->id, $group['item']->id);
        $this->assertSame('1234567-00-A', $group['part_number']);
        $this->assertSame(['1234567-00-A'], $group['part_numbers']->all());
        $this->assertSame(2, $group['count']);
        $this->assertSame(5.0, $group['quantity']);
        $this->assertSame(50.0, $group['total_value_usd']);
        $this->assertSame('10.00 USD', $group['unit_price_text']);
        $this->assertSame(['C1', 'C2'], $group['codes']->all());
        $this->assertSame(['First UA', 'Second UA'], $group['names']->all());
        $this->assertSame(['VIN1'], $group['vins']->all());
        $this->assertSame(["\u{041A}\u{0443}\u{0437}\u{043E}\u{0432}"], $group['categories']->all());

        $secondGroup = $groups->last();
        $this->assertSame($prefixVariant->id, $secondGroup['item']->id);
        $this->assertSame('1234567-01-B', $secondGroup['part_number']);
        $this->assertSame(1, $secondGroup['count']);
        $this->assertSame(4.0, $secondGroup['quantity']);
        $this->assertSame(["\u{041A}\u{0443}\u{0437}\u{043E}\u{0432}"], $secondGroup['categories']->all());
        $this->assertSame(2, app(NikolaCarsInventoryService::class)->uniqueItemsCount(collect([$first, $sameArticle, $prefixVariant])));
    }

    public function test_item_groups_display_russian_category_and_model(): void
    {
        PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'https://example.test/items/1',
            'part_number' => '1034344-20-B',
            'name' => 'Hood latch',
            'model_label' => 'Model S',
            'year_from' => 2012,
            'year_to' => 2016,
            'raw_attributes' => [
                'stock_quantity' => 1,
                'category_display' => 'Body / Closures / Hood',
            ],
        ]);

        $groups = app(NikolaCarsInventoryService::class)->itemGroups(
            PartCatalogItem::query()->where('source', 'nikolacars')->get(),
            ['rate' => 40],
            fn (PartCatalogItem $item): string => (string) $item->name,
        );

        $group = $groups->first();

        $this->assertSame(["\u{041A}\u{0443}\u{0437}\u{043E}\u{0432} / \u{0414}\u{0432}\u{0435}\u{0440}\u{0438}, \u{043A}\u{0430}\u{043F}\u{043E}\u{0442} \u{0438} \u{0431}\u{0430}\u{0433}\u{0430}\u{0436}\u{043D}\u{0438}\u{043A} / \u{041A}\u{0430}\u{043F}\u{043E}\u{0442}"], $group['categories']->all());
        $this->assertSame(['Model S 2012-2016'], $group['models']->all());
    }

    public function test_item_groups_load_customer_order_reservations_in_bulk(): void
    {
        $items = collect();
        for ($index = 1; $index <= 3; $index++) {
            $items->push(PartCatalogItem::query()->create([
                'source' => 'nikolacars',
                'source_url' => 'https://example.test/items/reserved-'.$index,
                'part_number' => '100000'.$index.'-00-A',
                'name' => 'Reserved item '.$index,
                'raw_attributes' => [
                    'stock_quantity' => 1,
                ],
            ]));
        }

        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260608-0001',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'total_amount' => 300,
            'currency' => 'UAH',
        ]);

        foreach ($items as $item) {
            $order->items()->create([
                'part_catalog_item_id' => $item->id,
                'name' => (string) $item->name,
                'part_number' => (string) $item->part_number,
                'quantity' => 1,
                'unit_price' => 100,
                'total_price' => 100,
                'currency' => 'UAH',
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $groups = app(NikolaCarsInventoryService::class)->itemGroups(
            $items,
            ['rate' => 40],
            fn (PartCatalogItem $item): string => (string) $item->name,
        );

        $customerOrderItemQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query): bool => str_contains($query, 'customer_order_items'))
            ->count();

        $this->assertSame([1.0, 1.0, 1.0], $groups->pluck('reserved_quantity')->all());
        $this->assertSame([0.0, 0.0, 0.0], $groups->pluck('quantity')->all());
        $this->assertSame(1, $customerOrderItemQueries);
    }

    public function test_item_groups_subtract_reserved_quantity_from_displayed_stock(): void
    {
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'https://example.test/items/reserved-stock',
            'part_number' => '1234567-00-A',
            'name' => 'Reserved stock item',
            'price_amount' => 25,
            'currency' => 'USD',
            'raw_attributes' => [
                'stock_quantity' => 1,
                'reserved_quantity' => 1,
            ],
        ]);

        $groups = app(NikolaCarsInventoryService::class)->itemGroups(
            collect([$item]),
            ['rate' => 40],
            fn (PartCatalogItem $item): string => (string) $item->name,
        );

        $group = $groups->first();

        $this->assertSame(1.0, $group['stock_quantity']);
        $this->assertSame(1.0, $group['reserved_quantity']);
        $this->assertSame(0.0, $group['quantity']);
        $this->assertSame("0 \u{0448}\u{0442}", $group['quantity_text']);
        $this->assertSame(0.0, $group['total_value_usd']);
    }

    public function test_item_groups_use_linked_product_stock_when_catalog_projection_is_stale(): void
    {
        $product = Product::query()->create([
            'name' => 'Stale projected donor part',
            'slug' => 'stale-projected-donor-part',
            'sku' => 'DON4-0320',
            'external_sku' => '1100087-00-D',
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'is_active' => true,
            'selling_price' => 25,
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
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://donor-product/'.$product->id,
            'part_number' => '1100087-00-D',
            'name' => 'Stale projected donor part',
            'price_amount' => 10,
            'currency' => 'USD',
            'raw_attributes' => [
                'product_id' => $product->id,
                'stock_quantity' => 2,
                'reserved_quantity' => 0,
                'code' => 'DON4-0320',
            ],
        ]);

        $groups = app(NikolaCarsInventoryService::class)->itemGroups(
            collect([$item]),
            ['rate' => 40],
            fn (PartCatalogItem $item): string => (string) $item->name,
        );

        $group = $groups->first();

        $this->assertSame(1.0, app(NikolaCarsInventoryService::class)->inventoryQuantity(collect([$item])));
        $this->assertSame(1.0, $group['stock_quantity']);
        $this->assertSame(1.0, $group['quantity']);
        $this->assertSame("1 \u{0448}\u{0442}", $group['quantity_text']);
        $this->assertSame('25.00 USD', $group['unit_price_text']);
        $this->assertSame(25.0, $group['total_value_usd']);
        $this->assertSame(2, data_get($item->raw_attributes, 'stock_quantity'));
    }

    public function test_item_groups_do_not_fallback_to_catalog_price_for_linked_product_without_price(): void
    {
        $service = new class extends NikolaCarsInventoryService
        {
            public function productPricePayloadsForItems(Collection $items): Collection
            {
                return collect([
                    123 => [
                        'amount' => null,
                        'currency' => 'USD',
                    ],
                ]);
            }
        };
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://inventory-product/123',
            'part_number' => '1100087-01-D',
            'name' => 'Product without price',
            'price_amount' => 99,
            'currency' => 'USD',
            'raw_attributes' => [
                'product_id' => 123,
                'stock_quantity' => 1,
                'code' => 'NC-NO-PRICE',
            ],
        ]);

        $group = $service->itemGroups(
            collect([$item]),
            ['rate' => 40],
            fn (PartCatalogItem $item): string => (string) $item->name,
        )->first();

        $this->assertSame('-', $group['unit_price_text']);
        $this->assertSame('-', $group['unit_price_uah_text']);
        $this->assertNull($group['unit_price_value']);
        $this->assertNull($group['unit_price_uah_value']);
        $this->assertSame(0.0, $group['total_value_usd']);
    }

    public function test_item_groups_prefer_linked_product_main_image_for_preview(): void
    {
        $product = Product::query()->create([
            'name' => 'Photographed donor part',
            'slug' => 'photographed-donor-part',
            'sku' => 'DON4-0330',
            'external_sku' => '1100088-00-D',
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'is_active' => true,
            'main_image' => 'https://example.test/product-main.jpg',
            'images_json' => [
                'https://example.test/product-extra.jpg',
            ],
        ]);
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://donor-product/'.$product->id,
            'part_number' => '1100088-00-D',
            'name' => 'Photographed donor part',
            'raw_attributes' => [
                'product_id' => $product->id,
                'stock_quantity' => 1,
                'image_urls' => ['https://example.test/catalog-old.jpg'],
            ],
        ]);

        $groups = app(NikolaCarsInventoryService::class)->itemGroups(
            collect([$item]),
            ['rate' => 40],
            fn (PartCatalogItem $item): string => (string) $item->name,
        );

        $this->assertSame([
            'https://example.test/product-main.jpg',
            'https://example.test/product-extra.jpg',
        ], $groups->first()['image_urls']->all());
    }

    public function test_item_groups_do_not_count_tesla_schemes_or_rendered_duplicates_as_product_photos(): void
    {
        config([
            'app.url' => 'https://sklad.nikolacars.kiev.ua',
            'filesystems.disks.public.url' => 'https://sklad.nikolacars.kiev.ua/storage',
            'filesystems.public_fallback_url' => null,
        ]);

        $product = Product::query()->create([
            'name' => 'Ultrasonic sensor donor part',
            'slug' => 'ultrasonic-sensor-donor-part',
            'sku' => 'DON6-1976',
            'external_sku' => '1127503-11-D',
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'is_active' => true,
            'main_image' => 'https://sklad.nikolacars.kiev.ua/storage/tesla-official/part-images/112750311D/1127503-11-D_1.jpeg',
            'images_json' => [
                'https://sklad.nikolacars.kiev.ua/storage/tesla-official/part-images/112750311D/1127503-11-D_2.jpeg',
            ],
        ]);
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://donor-product/'.$product->id,
            'part_number' => '1127503-11-D',
            'name' => 'Ultrasonic sensor donor part',
            'raw_attributes' => [
                'product_id' => $product->id,
                'stock_quantity' => 1,
                'image_urls' => [
                    'tesla-official/part-images/112750311D/1127503-11-D_1.jpeg',
                    'https://sklad.nikolacars.kiev.ua/storage/tesla-official/resources-images/parking-sensors.png',
                    'https://epc.tesla.com/resources/images/ModelY/Classic/US/Parking Sensors.svg',
                    'https://epc.tesla.com/resources/images/ModelY/Classic/US/Parking Sensors.png',
                ],
            ],
        ]);

        $groups = app(NikolaCarsInventoryService::class)->itemGroups(
            collect([$item]),
            ['rate' => 40],
            fn (PartCatalogItem $item): string => (string) $item->name,
        );

        $this->assertSame([
            'https://sklad.nikolacars.kiev.ua/storage/tesla-official/part-images/112750311D/1127503-11-D_1.jpeg',
            'https://sklad.nikolacars.kiev.ua/storage/tesla-official/part-images/112750311D/1127503-11-D_2.jpeg',
        ], $groups->first()['image_urls']->all());
    }

    public function test_item_groups_include_exact_official_part_images_for_linked_products(): void
    {
        config([
            'app.url' => 'https://sklad.nikolacars.kiev.ua',
            'filesystems.disks.public.url' => 'https://sklad.nikolacars.kiev.ua/storage',
            'filesystems.public_fallback_url' => null,
        ]);

        $product = Product::query()->create([
            'name' => 'Front fascia donor part',
            'slug' => 'front-fascia-donor-part',
            'sku' => 'DON28-0993',
            'external_sku' => '1081440-E0-C',
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'is_active' => true,
            'main_image' => 'product-photos/local-main.png',
            'images_json' => [
                'product-photos/local-main.png',
                'product-photos/local-extra.jpg',
            ],
        ]);
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://donor-product/'.$product->id,
            'part_number' => '1081440-E0-C',
            'name' => 'Front fascia donor part',
            'raw_attributes' => [
                'product_id' => $product->id,
                'stock_quantity' => 1,
                'image_urls' => [
                    'https://sklad.nikolacars.kiev.ua/storage/product-photos/local-main.png',
                    'tesla-official/resources-images/closure-panels.svg',
                ],
            ],
        ]);
        PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=1081440-E0-C',
            'part_number' => '1081440-E0-C',
            'name' => 'FASCIA ASY, FRONT',
            'raw_attributes' => [
                'part_image_urls' => [
                    'tesla-official/part-images/1081440E0C/1081440-E0-C_1.jpeg',
                    'tesla-official/part-images/1081440E0C/1081440-E0-C_2.jpeg',
                    'tesla-official/part-images/1081440E0C/1081440-E0-C_3.jpeg',
                    'tesla-official/part-images/1081440E0C/1081440-E0-C_4.jpeg',
                ],
            ],
        ]);

        $groups = app(NikolaCarsInventoryService::class)->itemGroups(
            collect([$item]),
            ['rate' => 40],
            fn (PartCatalogItem $item): string => (string) $item->name,
        );

        $this->assertSame([
            'https://sklad.nikolacars.kiev.ua/storage/product-photos/local-main.png',
            'https://sklad.nikolacars.kiev.ua/storage/product-photos/local-extra.jpg',
        ], $groups->first()['image_urls']->all());
        $this->assertSame([
            'https://sklad.nikolacars.kiev.ua/storage/product-photos/local-main.png',
            'https://sklad.nikolacars.kiev.ua/storage/product-photos/local-extra.jpg',
            'https://sklad.nikolacars.kiev.ua/storage/tesla-official/part-images/1081440E0C/1081440-E0-C_1.jpeg',
            'https://sklad.nikolacars.kiev.ua/storage/tesla-official/part-images/1081440E0C/1081440-E0-C_2.jpeg',
            'https://sklad.nikolacars.kiev.ua/storage/tesla-official/part-images/1081440E0C/1081440-E0-C_3.jpeg',
            'https://sklad.nikolacars.kiev.ua/storage/tesla-official/part-images/1081440E0C/1081440-E0-C_4.jpeg',
        ], $groups->first()['gallery_image_urls']->all());
    }

    public function test_item_groups_translate_tesla_closure_hinge_category(): void
    {
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'https://example.test/items/closure-hinge',
            'part_number' => '1034344-20-B',
            'name' => 'Front door hinge',
            'raw_attributes' => [
                'category_display' => 'CLOSURE COMPONENTS / Closure Assist Mechanisms and Hinges / Front Door Hinges and Fittings',
            ],
        ]);

        $category = app(NikolaCarsInventoryService::class)->displayCategory($item);

        $this->assertSame(
            "\u{041A}\u{043E}\u{043C}\u{043F}\u{043E}\u{043D}\u{0435}\u{043D}\u{0442}\u{044B} \u{0437}\u{0430}\u{043A}\u{0440}\u{044B}\u{0442}\u{0438}\u{044F} / \u{041C}\u{0435}\u{0445}\u{0430}\u{043D}\u{0438}\u{0437}\u{043C}\u{044B} \u{0434}\u{043E}\u{0432}\u{043E}\u{0434}\u{0447}\u{0438}\u{043A}\u{043E}\u{0432} \u{0438} \u{043F}\u{0435}\u{0442}\u{043B}\u{0438} / \u{041F}\u{0435}\u{0442}\u{043B}\u{0438} \u{0438} \u{043A}\u{0440}\u{0435}\u{043F}\u{043B}\u{0435}\u{043D}\u{0438}\u{044F} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0435}\u{0439} \u{0434}\u{0432}\u{0435}\u{0440}\u{0438}",
            $category
        );
    }

    public function test_display_category_normalizes_ukrainian_high_voltage_aliases(): void
    {
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'https://example.test/items/hv-battery',
            'part_number' => '1032385-00-C',
            'name' => 'HV battery',
            'raw_attributes' => [
                'category_display' => "\u{0412}\u{0438}\u{0441}\u{043E}\u{043A}\u{043E}\u{0432}\u{043E}\u{043B}\u{044C}\u{0442}\u{043D}\u{0430} \u{0431}\u{0430}\u{0442}\u{0430}\u{0440}\u{0435}\u{044F} / \u{0412}\u{044B}\u{0441}\u{043E}\u{043A}\u{043E}\u{0432}\u{043E}\u{043B}\u{044C}\u{0442}\u{043D}\u{0430}\u{044F} \u{0431}\u{0430}\u{0442}\u{0430}\u{0440}\u{0435}\u{044F}",
            ],
        ]);

        $category = app(NikolaCarsInventoryService::class)->displayCategory($item);

        $this->assertSame(
            "\u{0412}\u{044B}\u{0441}\u{043E}\u{043A}\u{043E}\u{0432}\u{043E}\u{043B}\u{044C}\u{0442}\u{043D}\u{0430}\u{044F} \u{0431}\u{0430}\u{0442}\u{0430}\u{0440}\u{0435}\u{044F}",
            $category
        );
    }

    public function test_display_category_hides_tesla_category_codes(): void
    {
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'https://example.test/items/hv-battery-coded',
            'part_number' => '1032385-00-C',
            'name' => 'HV battery',
            'raw_attributes' => [
                'category_display' => "16 - \u{0412}\u{044B}\u{0441}\u{043E}\u{043A}\u{043E}\u{0432}\u{043E}\u{043B}\u{044C}\u{0442}\u{043D}\u{0430}\u{044F} \u{0431}\u{0430}\u{0442}\u{0430}\u{0440}\u{0435}\u{044F} / 1601 - \u{0412}\u{044B}\u{0441}\u{043E}\u{043A}\u{043E}\u{0432}\u{043E}\u{043B}\u{044C}\u{0442}\u{043D}\u{0430}\u{044F} \u{0431}\u{0430}\u{0442}\u{0430}\u{0440}\u{0435}\u{044F}",
            ],
        ]);

        $category = app(NikolaCarsInventoryService::class)->displayCategory($item);

        $this->assertSame(
            "\u{0412}\u{044B}\u{0441}\u{043E}\u{043A}\u{043E}\u{0432}\u{043E}\u{043B}\u{044C}\u{0442}\u{043D}\u{0430}\u{044F} \u{0431}\u{0430}\u{0442}\u{0430}\u{0440}\u{0435}\u{044F}",
            $category
        );
    }

    public function test_related_items_and_donor_cars_are_resolved_by_part_prefix_and_vin(): void
    {
        DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000001',
            'model' => 'Model 3',
        ]);
        $first = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'https://example.test/items/1',
            'part_number' => '7654321-00-A',
            'name' => 'First',
            'raw_attributes' => ['donor_vin' => '5YJ3E1EA7KF000001'],
        ]);
        $second = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'https://example.test/items/2',
            'part_number' => '7654321-01-B',
            'name' => 'Second',
        ]);
        PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'https://example.test/items/3',
            'part_number' => '9999999-00-A',
            'name' => 'Other',
        ]);

        $service = app(NikolaCarsInventoryService::class);
        $related = $service->relatedItems($first);
        $donorCars = $service->donorCarsByVin($first, $related);

        $this->assertSame([$first->id, $second->id], $related->pluck('id')->all());
        $this->assertTrue($donorCars->has('5YJ3E1EA7KF000001'));
    }

    public function test_part_number_helpers_normalize_and_strip_descriptions(): void
    {
        $service = app(NikolaCarsInventoryService::class);

        $this->assertSame('1234567-00-A', $service->normalizePartNumber('123456700A'));
        $this->assertSame('1234567', $service->partNumberPrefix('1234567-00-A'));
        $this->assertSame("2.5 \u{0448}\u{0442}", $service->availability(2.5));
        $this->assertSame('Front bumper', $service->withoutPartNumber('Front bumper 1234567-00-A', '1234567-00-A'));
    }
}
