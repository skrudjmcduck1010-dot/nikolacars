<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\PartCatalogController;
use App\Models\DonorCar;
use App\Models\ExchangeRate;
use App\Models\Location;
use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Models\PartCatalogItemOccurrence;
use App\Models\Product;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\NikolaCarsProductInventorySyncService;
use App\Services\NikolaCarsTeslaCategoryResolver;
use App\Services\PartCatalogTranslationBackfiller;
use App\Services\StockTeslaCatalogImporter;
use App\Services\TeslaPartsUkraineCatalogImporter;
use App\Services\TskCatalogImporter;
use App\Support\PartCatalogLocalizedNameCleaner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PartCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function utf8(string $hex): string
    {
        return pack('H*', $hex);
    }

    public function test_legacy_common_tesla_catalog_index_is_not_registered(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-legacy-common-catalog-index@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get('/admin/tesla-catalog?q=1005536-00-J')
            ->assertNotFound();
    }

    public function test_legacy_common_tesla_catalog_item_links_are_not_registered(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-legacy-common-catalog-item-link@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $item = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/catalogs?partNumber=1005536-00-J',
            'part_number' => '1005536-00-J',
            'name' => 'Official part',
        ]);

        $this->actingAs($user)
            ->get('/admin/tesla-catalog/items/'.$item->id)
            ->assertNotFound();
    }

    public function test_legacy_common_tesla_catalog_search_is_not_registered(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-legacy-common-catalog-search@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get('/admin/tesla-catalog/search?q=1005536-00-J')
            ->assertNotFound();
    }

    public function test_nikolacars_manual_purchase_part_stores_localized_names_and_purchase_price(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-nikolacars-manual-purchase@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $warehouse = Warehouse::query()->create([
            'name' => 'Main warehouse',
            'type' => Warehouse::TYPE_MAIN,
            'floor_count' => 1,
            'is_active' => true,
        ]);
        Location::query()->create([
            'warehouse_id' => $warehouse->id,
            'floor' => 'floor_1',
            'zone' => 'A',
            'row' => '1',
            'shelf' => '1',
            'cell' => '7',
            'full_code' => 'A-1-1-7',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('admin.zapchasti.store'), [
                'create_nikolacars_part' => '1',
                'source_type' => 'purchase',
                'name_ua' => 'Кришка багажника',
                'name_ru' => 'Крышка багажника',
                'part_number' => '1034344-20-B',
                'purchase_price_usd' => '125.50',
                'selling_price' => '220',
                'warehouse_id' => $warehouse->id,
                'floor' => 'floor_1',
                'location_cell' => '7',
            ])
            ->assertRedirect(route('admin.zapchasti.index'));

        $product = Product::query()->where('external_sku', '1034344-20-B')->firstOrFail();
        $item = PartCatalogItem::query()
            ->where('source', 'nikolacars')
            ->where('source_url', 'nikolacars://inventory-product/'.$product->id)
            ->firstOrFail();

        $this->assertStringStartsWith('NC-PURCHASE-', $product->sku);
        $this->assertSame('125.50', (string) $product->purchase_price);
        $this->assertSame('Кришка багажника', $item->name_ua);
        $this->assertSame('Крышка багажника', $item->name_ru);
        $this->assertSame('purchase', data_get($item->raw_attributes, 'source_type'));
        $this->assertSame(125.5, data_get($item->raw_attributes, 'purchase_price_usd'));
    }

    public function test_nikolacars_catalog_shows_and_updates_stock_location(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-nikolacars-placement@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $sourceWarehouse = Warehouse::query()->create([
            'name' => 'Shelf A',
            'type' => Warehouse::TYPE_MAIN,
            'floor_count' => 1,
            'is_active' => true,
        ]);
        $targetWarehouse = Warehouse::query()->create([
            'name' => 'Shelf B',
            'type' => Warehouse::TYPE_MAIN,
            'floor_count' => 1,
            'is_active' => true,
        ]);
        $sourceLocation = Location::query()->create([
            'warehouse_id' => $sourceWarehouse->id,
            'floor' => 'floor_1',
            'cell' => 'A-1',
            'full_code' => 'A-1',
            'is_active' => true,
        ]);
        $targetLocation = Location::query()->create([
            'warehouse_id' => $targetWarehouse->id,
            'floor' => 'floor_1',
            'cell' => 'B-2',
            'full_code' => 'B-2',
            'is_active' => true,
        ]);
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://inventory-product/pending',
            'part_number' => 'PLACEMENT-TEST',
            'name' => 'Placement test part',
            'price_amount' => 100,
            'currency' => 'USD',
            'raw_attributes' => [
                'code' => 'PLACE',
                'stock_quantity' => 1,
            ],
        ]);
        $product = Product::query()->create([
            'sku' => 'NC-PLACEMENT-TEST',
            'external_sku' => 'PLACEMENT-TEST',
            'name' => 'Placement test part',
            'slug' => 'placement-test-part',
            'source_part_catalog_item_id' => $item->id,
            'is_auto_generated' => false,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $item->forceFill([
            'source_url' => 'nikolacars://inventory-product/'.$product->id,
            'raw_attributes' => [
                'code' => 'PLACE',
                'stock_quantity' => 1,
                'product_id' => $product->id,
            ],
        ])->save();
        StockItem::query()->create([
            'product_id' => $product->id,
            'warehouse_id' => $sourceWarehouse->id,
            'location_id' => $sourceLocation->id,
            'quantity' => 1,
            'reserved_quantity' => 0,
            'testing_status' => 'not_tested',
            'received_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('admin.zapchasti.index'))
            ->assertOk()
            ->assertSeeText('Склад')
            ->assertSeeText('Shelf A')
            ->assertSeeText('A-1')
            ->assertSee('data-nikolacars-placement-edit-toggle', false);

        $this->actingAs($user)
            ->patch(route('admin.zapchasti.placement.update', $item), [
                'warehouse_id' => $targetWarehouse->id,
                'floor' => 'floor_1',
                'location_id' => $targetLocation->id,
            ])
            ->assertRedirect();

        $this->assertSame(0, (int) StockItem::query()->where('location_id', $sourceLocation->id)->value('quantity'));
        $this->assertSame(1, (int) StockItem::query()->where('location_id', $targetLocation->id)->value('quantity'));

        $this->actingAs($user)
            ->get(route('admin.zapchasti.index'))
            ->assertOk()
            ->assertSeeText('Shelf B')
            ->assertSeeText('B-2');
    }

    public function test_nikolacars_manual_donor_part_uses_ron_sku_sequence(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-nikolacars-manual-donor@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJSA1E19HF202779',
            'brand' => 'Tesla',
            'model' => 'Model S',
            'year' => 2017,
        ]);
        $donorWarehouse = Warehouse::query()->create([
            'name' => Warehouse::DONOR_WAREHOUSE_NAME,
            'type' => Warehouse::TYPE_DONOR,
            'floor_count' => 1,
            'is_active' => true,
        ]);

        Product::query()->create([
            'sku' => 'DON'.$donorCar->id.'-0009',
            'external_sku' => 'AUTO-EXISTING',
            'name' => 'Existing auto donor part',
            'slug' => 'existing-auto-donor-part',
            'donor_car_id' => $donorCar->id,
            'is_auto_generated' => true,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'currency' => 'USD',
            'is_active' => true,
        ]);
        Product::query()->create([
            'sku' => 'RON'.$donorCar->id.'-0003',
            'external_sku' => 'MANUAL-EXISTING',
            'name' => 'Existing manual donor part',
            'slug' => 'existing-manual-donor-part',
            'donor_car_id' => $donorCar->id,
            'is_auto_generated' => false,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('admin.zapchasti.store'), [
                'create_nikolacars_part' => '1',
                'source_type' => 'donor',
                'donor_car_id' => $donorCar->id,
                'name_ua' => 'Manual donor cover',
                'part_number' => 'MANUAL-DONOR-NEW',
                'selling_price' => '220',
                'warehouse_id' => $donorWarehouse->id,
            ])
            ->assertRedirect(route('admin.zapchasti.index'));

        $product = Product::query()->where('external_sku', 'MANUAL-DONOR-NEW')->firstOrFail();
        $item = PartCatalogItem::query()
            ->where('source', 'nikolacars')
            ->where('source_url', 'nikolacars://donor-product/'.$product->id)
            ->firstOrFail();

        $this->assertSame('RON'.$donorCar->id.'-0004', $product->sku);
        $this->assertSame($donorCar->id, $product->donor_car_id);
        $this->assertSame('donor', data_get($item->raw_attributes, 'source_type'));
    }

    public function test_nikolacars_root_search_shows_matching_items_by_code_part_number_or_name(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-nikolacars-root-search@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'https://example.test/items/search-match',
            'part_number' => '1111111-00-A',
            'name' => 'Front Lamp Search Needle',
            'name_ua' => 'Front Lamp Search Needle',
            'raw_attributes' => [
                'code' => 'BOX-ALPHA',
                'stock_quantity' => 1,
            ],
        ]);

        PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'https://example.test/items/search-other',
            'part_number' => '2222222-00-B',
            'name' => 'Rear Door Other',
            'name_ua' => 'Rear Door Other',
            'raw_attributes' => [
                'code' => 'BOX-BETA',
                'stock_quantity' => 1,
            ],
        ]);

        foreach (['BOX-ALPHA', '1111111', 'Needle'] as $query) {
            $this->actingAs($user)
                ->get(route('admin.zapchasti.index', ['q' => $query]))
                ->assertOk()
                ->assertSee('Front Lamp Search Needle')
                ->assertDontSee('Rear Door Other');
        }
    }

    public function test_nikolacars_item_name_suggestions_include_internal_official_and_competitor_items(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-nikolacars-name-suggestions@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/name-suggestion-internal',
            'part_number' => '1111111-00-A',
            'name' => 'Internal Door Suggestion',
            'name_ua' => 'Internal Door Suggestion',
            'raw_attributes' => [
                'stock_quantity' => 1,
            ],
        ]);

        PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/product/2222222-00-B',
            'part_number' => '2222222-00-B',
            'name' => 'Official Door Suggestion',
            'name_en' => 'Official Door Suggestion',
            'price_amount' => 120,
            'currency' => 'USD',
        ]);

        PartCatalogItem::query()->create([
            'source' => 'tsk',
            'source_url' => 'https://tsk.ua/3333333-00-c/',
            'part_number' => '3333333-00-C',
            'name' => 'Competitor Door Suggestion',
            'name_ru' => 'Competitor Door Suggestion',
            'price_amount' => 95,
            'currency' => 'USD',
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('admin.zapchasti.items.name-suggestions', ['q' => 'Suggestion']));

        $response->assertOk();

        $items = collect($response->json());

        $this->assertTrue($items->contains(fn (array $item): bool => $item['source_group'] === 'nikolacars'
            && $item['source_label'] === 'NikolaCars'
            && $item['name'] === 'Internal Door Suggestion'));
        $this->assertTrue($items->contains(fn (array $item): bool => $item['source_group'] === 'tesla_official'
            && $item['source_label'] === 'Tesla.com'
            && $item['name'] === 'Official Door Suggestion'));
        $this->assertTrue($items->contains(fn (array $item): bool => $item['source_group'] === 'competitor'
            && $item['source_label'] === 'TSK'
            && $item['name'] === 'Competitor Door Suggestion'));
    }

    public function test_tsk_importer_does_not_queue_epc_product_urls_as_categories(): void
    {
        Http::fake([
            'https://tsk.ua/katalog-zapchastey296/' => Http::response(<<<'HTML'
                <html>
                    <body>
                        <a href="/katalog-zapchastey296/model-y-parts-catalog3166/">Model Y</a>
                        <a href="/katalog-zapchastey296/front-bumper-carrier-4841/1005536-00-j/">1005536-00-J</a>
                    </body>
                </html>
                HTML),
            'https://tsk.ua/katalog-zapchastey296/model-y-parts-catalog3166/' => Http::response(<<<'HTML'
                <html>
                    <head><title>Model Y Parts Catalog</title></head>
                    <body></body>
                </html>
                HTML),
            'https://tsk.ua/katalog-zapchastey296/front-bumper-carrier-4841/1005536-00-j/' => Http::response(<<<'HTML'
                <html>
                    <head><title>1005536-00-J</title></head>
                    <body></body>
                </html>
                HTML),
        ]);

        $stats = app(TskCatalogImporter::class)->import(['sleep_ms' => 0]);

        $this->assertSame(1, $stats['category_links_found']);
        $this->assertDatabaseHas('part_catalog_categories', [
            'source' => 'tsk',
            'source_url' => 'https://tsk.ua/katalog-zapchastey296/model-y-parts-catalog3166/',
        ]);
        $this->assertDatabaseMissing('part_catalog_categories', [
            'source' => 'tsk',
            'source_url' => 'https://tsk.ua/katalog-zapchastey296/front-bumper-carrier-4841/1005536-00-j/',
        ]);
    }

    public function test_tsk_importer_keeps_root_product_links_from_epc_tables(): void
    {
        Http::fake([
            'https://tsk.ua/katalog-zapchastey296/' => Http::response(<<<'HTML'
                <html>
                    <body>
                        <a href="/katalog-zapchastey296/front-bumper-carrier-4841/">Front Bumper Carrier</a>
                    </body>
                </html>
                HTML),
            'https://tsk.ua/katalog-zapchastey296/front-bumper-carrier-4841/' => Http::response(<<<'HTML'
                <html>
                    <head><title>Front Bumper Carrier</title></head>
                    <body>
                        <table>
                            <tr>
                                <td>1</td>
                                <td><a href="/1005536-00-j/">CARRIER - FRONT BUMPER</a></td>
                                <td>1005536-00-J</td>
                                <td>1</td>
                                <td>In stock</td>
                            </tr>
                        </table>
                    </body>
                </html>
                HTML),
        ]);

        app(TskCatalogImporter::class)->import(['sleep_ms' => 0]);

        $item = PartCatalogItem::query()
            ->where('source', 'tsk')
            ->where('part_number', '1005536-00-J')
            ->firstOrFail();

        $this->assertSame('https://tsk.ua/1005536-00-j/', $item->raw_attributes['product_url']);
    }

    public function test_stock_tesla_importer_keeps_rebuild_variants_as_separate_source_products(): void
    {
        $importer = new class(app(Factory::class)) extends StockTeslaCatalogImporter
        {
            protected function categoryUrls(string $baseUrl): array
            {
                return [];
            }

            protected function siteCatalogCategories(array $categories, array $categoryUrls, int $maxCategories, string $categoryUrl = '', array $modelCategoryUrls = []): array
            {
                return [['name' => 'Model 3', 'url' => 'https://stock-tesla.com/category/3-1/']];
            }

            protected function categoryPageUrls(string $categoryUrl, string $baseUrl, int $maxCategoryPages, array &$stats): iterable
            {
                yield $categoryUrl => '<html></html>';
            }

            protected function productSummariesFromCategoryHtml(string $html, string $baseUrl): array
            {
                return [
                    [
                        'source_url' => 'https://stock-tesla.com/product/1508347-00-c/',
                        'price_amount' => 0,
                        'currency' => 'UAH',
                        'available' => true,
                    ],
                    [
                        'source_url' => 'https://stock-tesla.com/product/1508347-00-c-r/',
                        'price_amount' => 0,
                        'currency' => 'UAH',
                        'available' => true,
                    ],
                ];
            }

            protected function fetch(string $url): ?string
            {
                return '<html></html>';
            }

            protected function siteProductOffer(string $html, string $sourceUrl, string $baseUrl): ?array
            {
                $isRebuild = str_contains($sourceUrl, '-r/');

                return [
                    'feed_id' => null,
                    'available' => true,
                    'name_ua' => $isRebuild ? 'Driver airbag rebuild' : 'Driver airbag',
                    'source_url' => $sourceUrl,
                    'price_amount' => 0,
                    'currency' => 'UAH',
                    'category_id' => null,
                    'category_urls' => [],
                    'part_number' => $isRebuild ? '1508347-00-C R' : '1508347-00-C',
                    'condition' => null,
                    'description_uk' => '',
                    'quantity_in_stock' => 1,
                    'pictures' => [],
                    'raw_attributes' => [],
                ];
            }
        };

        $importer->import([
            'with_russian' => false,
            'sleep_ms' => 0,
            'download_images' => false,
        ]);

        $items = PartCatalogItem::query()
            ->where('source', 'stock-tesla')
            ->orderBy('source_url')
            ->get();

        $this->assertSame(2, $items->count());
        $this->assertSame(['1508347-00-C', '1508347-00-C'], $items->pluck('part_number')->all());
        $this->assertEqualsCanonicalizing([
            'https://stock-tesla.com/product/1508347-00-c/',
            'https://stock-tesla.com/product/1508347-00-c-r/',
        ], $items->pluck('source_url')->all());
    }

    public function test_translation_backfiller_ignores_generic_catalog_names(): void
    {
        $official = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-common://competitor-item/teslahelp/1',
            'part_number' => '1487742-00-F',
            'name' => 'PANEL ASY - REAR',
        ]);

        PartCatalogItem::query()->create([
            'source' => 'teslahelp',
            'source_url' => 'teslahelp:test',
            'part_number' => '1487742-00-F',
            'name' => pack('H*', 'd0bad183d0b7d0bed0b2'),
            'name_ru' => pack('H*', 'd0bad183d0b7d0bed0b2'),
        ]);

        app(PartCatalogTranslationBackfiller::class)->refresh(['part_numbers' => ['1487742-00-F']]);

        $this->assertNull($official->fresh()->name_ru);
    }

    public function test_translation_backfiller_ignores_real_generic_category_names(): void
    {
        $body = pack('H*', 'd0bad183d0b7d0bed0b2');
        $frameAutoTranslated = pack('H*', 'd180d0b0d0bcd0b02028d0b0d0b2d182d0bed0bfd0b5d180d0b5d0b2d0bed0b429');
        $electric = pack('H*', 'd18dd0bbd0b5d0bad182d180d0b8d0bad0b0');
        $wiringAutoTranslated = pack('H*', 'd0bfd180d0bed0b2d0bed0b4d0bad0b02028d0b0d0b2d182d0bed0bfd0b5d180d0b5d0b2d0bed0b429');

        $official = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-official:test-real-generic',
            'part_number' => '1509122-00-C',
            'name' => 'LOCATOR PIN - BACKLITE - RIGHT HAND',
        ]);
        $officialElectric = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-official:test-real-generic-electric',
            'part_number' => '1111111-00-C',
            'name' => 'WIRE HARNESS',
        ]);

        PartCatalogItem::query()->create([
            'source' => 'tsk',
            'source_url' => 'tsk:test-real-generic',
            'part_number' => '1509122-00-A',
            'name' => $body,
            'name_ru' => $body,
        ]);

        PartCatalogItem::query()->create([
            'source' => 'teslapartsukraine',
            'source_url' => 'teslapartsukraine:test-real-generic',
            'part_number' => '1509122-00-A',
            'name' => $frameAutoTranslated,
            'name_ua' => $frameAutoTranslated,
        ]);
        PartCatalogItem::query()->create([
            'source' => 'tsk',
            'source_url' => 'tsk:test-real-generic-electric',
            'part_number' => '1111111-00-A',
            'name' => $electric,
            'name_ru' => $electric,
        ]);
        PartCatalogItem::query()->create([
            'source' => 'teslapartsukraine',
            'source_url' => 'teslapartsukraine:test-real-generic-wiring',
            'part_number' => '1111111-00-A',
            'name' => $wiringAutoTranslated,
            'name_ua' => $wiringAutoTranslated,
        ]);

        app(PartCatalogTranslationBackfiller::class)->refresh(['part_numbers' => ['1509122-00-C', '1111111-00-C']]);

        $official->refresh();
        $officialElectric->refresh();

        $this->assertNull($official->name_ru);
        $this->assertNull($official->name_ua);
        $this->assertNull($officialElectric->name_ru);
        $this->assertNull($officialElectric->name_ua);
    }

    public function test_manual_catalog_name_update_locks_exact_internal_part_matches_only(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'manual-name-admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $official = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/manual-name-main',
            'part_number' => '1234567-00-A',
            'name' => 'Original official',
            'name_ru' => 'Old RU',
            'name_ua' => 'Old UA',
        ]);

        $blankSamePrefix = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/manual-name-blank-prefix',
            'part_number' => '1234567-00-B',
            'name' => 'Blank same prefix official',
            'name_ru' => null,
            'name_ua' => null,
        ]);

        $filledSamePrefix = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/manual-name-filled-prefix',
            'part_number' => '1234567-00-B',
            'name' => 'Filled same prefix official',
            'name_ru' => 'Filled RU',
            'name_ua' => 'Filled UA',
        ]);

        $sameExactDonor = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-common://donor-product/123456700A',
            'part_number' => '1234567-00-A',
            'name' => 'Exact donor official',
            'name_ru' => 'Donor RU',
            'name_ua' => 'Donor UA',
        ]);

        $sameExactNikolaCars = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://inventory/manual-name',
            'part_number' => '1234567-00-A',
            'name' => 'Exact NikolaCars item',
            'name_ru' => 'NikolaCars RU',
            'name_ua' => 'NikolaCars UA',
        ]);

        $commonCompetitorRow = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-common://competitor-item/teslahelp/1',
            'part_number' => '1234567-00-C',
            'name' => 'Common competitor row',
            'name_ru' => null,
            'name_ua' => null,
        ]);

        $competitor = PartCatalogItem::query()->create([
            'source' => 'teslahelp',
            'source_url' => 'https://teslahelp.example/manual-name',
            'part_number' => '1234567-00-A',
            'name' => 'Competitor',
            'name_ru' => 'Competitor RU',
            'name_ua' => 'Competitor UA',
        ]);

        $this->actingAs($user)
            ->patch(route('admin.tesla-official-catalog.update', $official), [
                'name_ru' => 'Manual RU',
                'name_ua' => 'Manual UA',
            ])
            ->assertRedirect();

        $this->assertSame('Manual RU', $official->refresh()->name_ru);
        $this->assertSame('Manual UA', $official->name_ua);
        $this->assertNotNull($official->name_ru_manually_locked_at);
        $this->assertNotNull(data_get($official->raw_attributes, 'manual_name_locks.ua'));

        $this->assertSame('Manual RU', $sameExactDonor->refresh()->name_ru);
        $this->assertSame('Manual UA', $sameExactDonor->name_ua);
        $this->assertNotNull($sameExactDonor->name_ru_manually_locked_at);
        $this->assertNotNull(data_get($sameExactDonor->raw_attributes, 'manual_name_locks.ua'));

        $this->assertSame('Manual RU', $sameExactNikolaCars->refresh()->name_ru);
        $this->assertSame('Manual UA', $sameExactNikolaCars->name_ua);
        $this->assertNotNull($sameExactNikolaCars->name_ru_manually_locked_at);
        $this->assertNotNull(data_get($sameExactNikolaCars->raw_attributes, 'manual_name_locks.ua'));

        $this->assertNull($blankSamePrefix->refresh()->name_ru);
        $this->assertNull($blankSamePrefix->name_ua);
        $this->assertNull($blankSamePrefix->name_ru_manually_locked_at);
        $this->assertNull(data_get($blankSamePrefix->raw_attributes, 'manual_name_locks.ua'));

        $this->assertSame('Filled RU', $filledSamePrefix->refresh()->name_ru);
        $this->assertSame('Filled UA', $filledSamePrefix->name_ua);
        $this->assertNull($filledSamePrefix->name_ru_manually_locked_at);
        $this->assertNull(data_get($filledSamePrefix->raw_attributes, 'manual_name_locks.ua'));
        $this->assertNull($commonCompetitorRow->refresh()->name_ru);
        $this->assertNull($commonCompetitorRow->name_ua);
        $this->assertSame('Competitor RU', $competitor->refresh()->name_ru);
        $this->assertSame('Competitor UA', $competitor->name_ua);

        app(PartCatalogTranslationBackfiller::class)->refresh([
            'part_numbers' => ['1234567-00-A'],
            'only_missing' => false,
        ]);

        $this->assertSame('Manual RU', $official->refresh()->name_ru);
        $this->assertSame('Manual UA', $official->name_ua);
        $this->assertNull($blankSamePrefix->refresh()->name_ru);
        $this->assertNull($blankSamePrefix->name_ua);
        $this->assertSame('Manual RU', $sameExactDonor->refresh()->name_ru);
        $this->assertSame('Manual UA', $sameExactDonor->name_ua);
    }

    public function test_tesla_catalog_manual_name_update_overwrites_exact_internal_matches_only(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-local-manual-name@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $official = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/local-manual-name-main',
            'part_number' => '1466270-60-B',
            'name' => 'WINDSHIELD ASSEMBLY',
            'name_ru' => 'Wrong RU',
            'name_ua' => 'Old UA',
        ]);

        $duplicate = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/local-manual-name-duplicate',
            'part_number' => '1466270-60-B',
            'name' => 'WINDSHIELD ASSEMBLY',
            'name_ru' => 'Duplicate RU',
            'name_ua' => 'Duplicate UA',
        ]);
        $blankPrefix = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/local-manual-name-blank-prefix',
            'part_number' => '1466270-00-J',
            'name' => 'WINDSHIELD ASSEMBLY OTHER',
            'name_ru' => null,
            'name_ua' => null,
        ]);

        $this->actingAs($user)
            ->patch(route('admin.tesla-official-catalog.update', $official), [
                'name_ru' => '?пїЅ????пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ ??????????пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ',
                'name_ua' => 'Old UA',
            ])
            ->assertRedirect();

        $this->assertSame('?пїЅ????пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ ??????????пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ', $official->refresh()->name_ru);
        $this->assertNotNull($official->name_ru_manually_locked_at);
        $this->assertSame($official->name_ru, $duplicate->refresh()->name_ru);
        $this->assertSame('Old UA', $duplicate->name_ua);
        $this->assertNotNull($duplicate->name_ru_manually_locked_at);
        $this->assertNull($blankPrefix->refresh()->name_ru);
        $this->assertNull($blankPrefix->name_ua);
        $this->assertNull($blankPrefix->name_ru_manually_locked_at);
    }

    public function test_nikolacars_manual_name_update_locks_and_propagates_exact_internal_names_only(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-nikolacars-manual-name@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $nikolaCarsItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://inventory/manual-name-route',
            'part_number' => '1104422-10-K',
            'name' => 'NikolaCars HV battery',
            'name_ru' => 'Old NikolaCars RU',
        ]);

        $officialItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=1104422-10-K',
            'part_number' => '1104422-10-K',
            'name' => 'ASY,HVBAT,82KWH',
            'name_ru' => null,
        ]);

        $basePartItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=1104422-10',
            'part_number' => '1104422-10',
            'name' => 'Base battery',
            'name_ru' => null,
        ]);

        $competitorItem = PartCatalogItem::query()->create([
            'source' => 'tcarservice',
            'source_url' => 'https://tcarservice.com/1104422-10-k',
            'part_number' => '1104422-10-K',
            'name' => 'Competitor battery',
            'name_ru' => 'Competitor RU',
        ]);

        $response = $this->actingAs($user)
            ->patchJson(route('admin.zapchasti.update', $nikolaCarsItem), [
                'name_ru' => 'Main battery 82 kWh',
            ])
            ->assertOk()
            ->assertJsonPath('item.name_ru', 'Main battery 82 kWh');

        $freshNikolaCarsItem = PartCatalogItem::query()->findOrFail($response->json('item.id'));

        $this->assertSame('Main battery 82 kWh', $freshNikolaCarsItem->name_ru);
        $this->assertSame('Main battery 82 kWh', $officialItem->refresh()->name_ru);
        $this->assertNull($basePartItem->refresh()->name_ru);
        $this->assertSame('Competitor RU', $competitorItem->refresh()->name_ru);
        $this->assertNotNull(data_get($freshNikolaCarsItem->raw_attributes, 'manual_name_locks.ru'));
        $this->assertNotNull($officialItem->name_ru_manually_locked_at);
        $this->assertNull($basePartItem->name_ru_manually_locked_at);
    }

    public function test_nikolacars_catalog_hides_part_number_from_display_name(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/393',
            'part_number' => '1002295-00-D',
            'name' => 'Rear bumper bracket Tesla MS 2012 - 2015 1002295-00-D',
            'name_ua' => 'Rear bumper bracket Tesla MS 2012 - 2015 1002295-00-D',
            'notes_ua' => 'Prom product note 1002295-00-D',
            'raw_attributes' => [
                'code' => '393',
                'stock_quantity' => 1,
            ],
        ]);

        $this->actingAs($user)
            ->get(route('admin.zapchasti.index'))
            ->assertOk()
            ->assertSeeText('1002295-00-D')
            ->assertSeeText('Rear bumper bracket MS 2012 - 2015')
            ->assertDontSee('Rear bumper bracket MS 2012 - 2015 1002295-00-D');

        $response = $this->actingAs($user)
            ->get(route('admin.zapchasti.show', $item));

        $product = Product::query()
            ->where('source_part_catalog_item_id', $item->id)
            ->firstOrFail();

        $response->assertRedirect(route('admin.products.show', $product));

        $this->actingAs($user)
            ->patchJson(route('admin.zapchasti.update', $item), [
                'notes_ua' => 'Updated product note 1002295-00-D',
            ])
            ->assertOk()
            ->assertJsonPath('notes_ua', 'Updated product note');
    }

    public function test_nikolacars_category_update_marks_manual_category(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-nikolacars-category-update@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $model = PartCatalogCategory::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://tesla-category/model-3',
            'name' => 'Model 3',
            'depth' => 0,
            'model_label' => 'Model 3',
        ]);
        $body = PartCatalogCategory::query()->create([
            'parent_id' => $model->id,
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://tesla-category/body',
            'name' => 'Body',
            'depth' => 1,
            'model_label' => 'Model 3',
        ]);
        $closures = PartCatalogCategory::query()->create([
            'parent_id' => $body->id,
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://tesla-category/closures',
            'name' => 'Closures',
            'depth' => 2,
            'model_label' => 'Model 3',
        ]);
        $hood = PartCatalogCategory::query()->create([
            'parent_id' => $closures->id,
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://tesla-category/hood',
            'name' => 'Hood',
            'depth' => 3,
            'model_label' => 'Model 3',
        ]);
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://inventory/manual-category-update',
            'part_number' => '1234567-00-A',
            'name' => 'Manual category part',
            'raw_attributes' => [
                'stock_quantity' => 1,
            ],
        ]);
        $categoryMain = "\u{041A}\u{0443}\u{0437}\u{043E}\u{0432}";
        $categorySubcategory = "\u{0414}\u{0432}\u{0435}\u{0440}\u{0438}, \u{043A}\u{0430}\u{043F}\u{043E}\u{0442} \u{0438} \u{0431}\u{0430}\u{0433}\u{0430}\u{0436}\u{043D}\u{0438}\u{043A}";
        $categoryNode = "\u{041A}\u{0430}\u{043F}\u{043E}\u{0442}";
        $categoryLabel = "{$categoryMain} / {$categorySubcategory} / {$categoryNode}";

        $this->actingAs($user)
            ->patchJson(route('admin.zapchasti.category.update', $item), [
                'category_id' => $hood->id,
            ])
            ->assertOk()
            ->assertJsonPath('item_id', $item->id)
            ->assertJsonPath('category_id', $hood->id)
            ->assertJsonPath('category', $categoryLabel);

        $item->refresh();

        $this->assertSame($hood->id, $item->part_catalog_category_id);
        $this->assertSame($categoryMain, $item->main_category_name);
        $this->assertSame($categorySubcategory, $item->subcategory_name);
        $this->assertSame($categoryNode, $item->node_name);
        $this->assertNull(data_get($item->raw_attributes, 'category_display'));
        $this->assertNull(data_get($item->raw_attributes, 'category_path'));
        $this->assertTrue((bool) data_get($item->raw_attributes, 'manual_category'));
        $this->assertSame($hood->id, data_get($item->raw_attributes, 'manual_category_id'));
    }

    public function test_nikolacars_category_search_uses_aliases_and_hides_undetermined_category(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-nikolacars-category-search@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $model = PartCatalogCategory::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://tesla-category/search-model-3',
            'name' => 'Model 3',
            'depth' => 0,
            'model_label' => 'Model 3',
        ]);
        $body = PartCatalogCategory::query()->create([
            'parent_id' => $model->id,
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://tesla-category/search-body',
            'name' => 'Body',
            'depth' => 1,
            'model_label' => 'Model 3',
        ]);
        $closures = PartCatalogCategory::query()->create([
            'parent_id' => $body->id,
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://tesla-category/search-closures',
            'name' => 'Closures',
            'depth' => 2,
            'model_label' => 'Model 3',
        ]);
        $hood = PartCatalogCategory::query()->create([
            'parent_id' => $closures->id,
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://tesla-category/search-hood',
            'name' => 'Hood',
            'depth' => 3,
            'model_label' => 'Model 3',
        ]);
        $undeterminedCategory = PartCatalogCategory::query()->create([
            'parent_id' => $model->id,
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://tesla-category/search-undetermined',
            'name' => NikolaCarsTeslaCategoryResolver::UNDETERMINED,
            'name_ru' => "\u{041A}\u{0430}\u{043F}\u{043E}\u{0442}",
            'depth' => 1,
            'model_label' => 'Model 3',
        ]);

        $categoryLabel = "\u{041A}\u{0443}\u{0437}\u{043E}\u{0432} / \u{0414}\u{0432}\u{0435}\u{0440}\u{0438}, \u{043A}\u{0430}\u{043F}\u{043E}\u{0442} \u{0438} \u{0431}\u{0430}\u{0433}\u{0430}\u{0436}\u{043D}\u{0438}\u{043A} / \u{041A}\u{0430}\u{043F}\u{043E}\u{0442}";

        $response = $this->actingAs($user)
            ->getJson(route('admin.zapchasti.categories.search', [
                'q' => "\u{041A}\u{0430}\u{043F}\u{043E}\u{0442}",
            ]))
            ->assertOk();

        $results = collect($response->json());
        $hoodResult = $results->firstWhere('id', $hood->id);

        $this->assertNotNull($hoodResult);
        $this->assertFalse($results->contains('id', $undeterminedCategory->id));
        $this->assertSame($categoryLabel, $hoodResult['name']);
        $this->assertSame('Model 3', $hoodResult['model']);
    }

    public function test_nikolacars_catalog_photos_can_be_added_and_removed(): void
    {
        Storage::fake('public');

        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-nikolacars-photos@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://inventory/photo-update',
            'part_number' => '7654321-00-A',
            'name' => 'Photo test part',
            'raw_attributes' => [
                'stock_quantity' => 1,
                'image_urls' => [],
            ],
        ]);

        $this->actingAs($user)
            ->post(route('admin.zapchasti.photos.store', $item), [
                'photos' => [
                    UploadedFile::fake()->image('hood.jpg'),
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('status', "\u{0424}\u{043E}\u{0442}\u{043E} \u{0434}\u{043E}\u{0431}\u{0430}\u{0432}\u{043B}\u{0435}\u{043D}\u{044B}.");

        $item->refresh();
        $imageUrls = collect((array) data_get($item->raw_attributes, 'image_urls'));
        $uploadedUrl = (string) $imageUrls->first();
        $uploadedPath = ltrim(Str::after($uploadedUrl, '/storage/'), '/');

        $this->assertCount(1, $imageUrls);
        $this->assertStringStartsWith('/storage/nikolacars/catalog/'.$item->id.'/', $uploadedUrl);
        Storage::disk('public')->assertExists($uploadedPath);

        $this->actingAs($user)
            ->delete(route('admin.zapchasti.photos.destroy', $item), [
                'image_url' => $uploadedUrl,
            ])
            ->assertRedirect()
            ->assertSessionHas('status', "\u{0424}\u{043E}\u{0442}\u{043E} \u{0443}\u{0434}\u{0430}\u{043B}\u{0435}\u{043D}\u{043E}.");

        $item->refresh();

        $this->assertSame([], data_get($item->raw_attributes, 'image_urls'));
        Storage::disk('public')->assertMissing($uploadedPath);
    }

    public function test_nikolacars_catalog_stat_shows_parts_count_with_unique_articles_subline(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-nikolacars-parts-count@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        PartCatalogCategory::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://tesla-category/count-test',
            'name' => 'Count Test Category',
            'depth' => 0,
        ]);

        foreach ([
            ['source_url' => 'count-a', 'part_number' => '1002295-00-D'],
            ['source_url' => 'count-b', 'part_number' => '100229500D'],
            ['source_url' => 'count-c', 'part_number' => '1002295-00-E'],
        ] as $row) {
            PartCatalogItem::query()->create([
                'source' => 'nikolacars',
                'source_url' => 'nikolacars://product/'.$row['source_url'],
                'part_number' => $row['part_number'],
                'name' => 'Repeated article count test',
                'price_amount' => 100,
                'currency' => 'USD',
                'raw_attributes' => [
                    'code' => $row['source_url'],
                    'stock_quantity' => 1,
                ],
            ]);
        }

        $response = $this->actingAs($user)
            ->get(route('admin.zapchasti.index'))
            ->assertOk()
            ->assertDontSeeText('Доноров / разделов НиколаКарз');

        $html = $response->getContent();

        $response->assertSeeText("\u{0417}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{0435}\u{0439}");
        $response->assertSeeText("2 \u{0443}\u{043D}\u{0438}\u{043A}\u{0430}\u{043B}\u{044C}\u{043D}\u{044B}\u{0445} \u{0430}\u{0440}\u{0442}\u{0438}\u{043A}\u{0443}\u{043B}\u{043E}\u{0432}");
        $this->assertStringContainsString('data-nikolacars-items-count>3</span>', $html);
        $this->assertStringContainsString('data-nikolacars-unique-articles-count>2</span>', $html);
        $this->assertStringContainsString('data-nikolacars-visible-rows-count>3</span>', $html);
        $this->assertSame(3, substr_count($html, 'data-nikolacars-item-row'));
        $this->assertSame(3, substr_count($html, 'data-nikolacars-parts-count="1"'));
        $this->assertSame(2, substr_count($html, 'nikolacars-adjacent-duplicate-row'));
        $this->assertStringNotContainsString('data-nikolacars-parts-count="2"', $html);
        $this->assertStringNotContainsString('data-nikolacars-group-toggle=', $html);
        $this->assertStringNotContainsString('data-nikolacars-group-child=', $html);
        $this->assertStringNotContainsString('nikolacars-grouped-row', $html);
        $this->assertSame(3, substr_count($html, 'data-nikolacars-cart-add'));
        $this->assertSame(3, substr_count($html, 'data-nikolacars-price-edit-toggle'));
        $this->assertSame(3, substr_count($html, 'data-nikolacars-update-form'));
        $this->assertSame(3, substr_count($html, 'data-nikolacars-delete-form'));
        $this->assertSame(3, substr_count($html, 'data-nikolacars-manual-sold-form'));
        $this->assertSame(3, substr_count($html, '<a class="nikolacars-part-name-link"'));
        $this->assertStringNotContainsString('data-nikolacars-visible-rows-word', $html);
    }

    public function test_nikolacars_catalog_heading_count_uses_total_not_current_page(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-nikolacars-paged-count@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        foreach (range(1, 105) as $index) {
            PartCatalogItem::query()->create([
                'source' => 'nikolacars',
                'source_url' => 'nikolacars://product/paged-count-'.$index,
                'part_number' => sprintf('%07d-00-A', 2000000 + $index),
                'name' => 'Paged NikolaCars count part '.$index,
                'raw_attributes' => [
                    'code' => 'PAGED-'.$index,
                    'stock_quantity' => 1,
                ],
            ]);
        }

        $response = $this->actingAs($user)
            ->get(route('admin.zapchasti.index'))
            ->assertOk();

        $html = $response->getContent();

        $this->assertStringContainsString('data-nikolacars-items-count>105</span>', $html);
        $this->assertStringContainsString('data-nikolacars-unique-articles-count>105</span>', $html);
        $this->assertStringContainsString('data-nikolacars-visible-rows-count>105</span>', $html);
        $this->assertSame(100, substr_count($html, 'data-nikolacars-item-row'));
    }

    public function test_nikolacars_catalog_hides_broken_parts_from_active_list_and_count(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-nikolacars-broken-list@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/active-part',
            'part_number' => '1002295-00-D',
            'name' => 'Active NikolaCars count part',
            'raw_attributes' => [
                'code' => 'ACTIVE-COUNT-PART',
                'stock_quantity' => 1,
            ],
        ]);

        PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/broken-part',
            'part_number' => '1002296-00-D',
            'name' => 'Broken NikolaCars count part',
            'quality' => NikolaCarsProductInventorySyncService::BROKEN_DAMAGE_STATUS,
            'raw_attributes' => [
                'code' => 'BROKEN-COUNT-PART',
                'stock_quantity' => 1,
            ],
        ]);

        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA0LF611660',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
        ]);
        $product = Product::query()->create([
            'sku' => 'DON32-0001',
            'external_sku' => '1084174-00-C',
            'name' => 'Unknown donor projection count part',
            'slug' => 'DON32-0001',
            'donor_car_id' => $donorCar->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'notes' => 'Неизвестно',
            'is_auto_generated' => true,
            'is_active' => true,
        ]);

        PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://donor-product/'.$product->id,
            'part_number' => '1084174-00-C',
            'name' => 'Unknown donor projection count part',
            'quality' => NikolaCarsProductInventorySyncService::CHECKED_DAMAGE_STATUSES[0],
            'raw_attributes' => [
                'code' => $product->sku,
                'product_id' => $product->id,
                'source_type' => 'donor',
                'donor_car_id' => $donorCar->id,
                'donor_damage_status' => NikolaCarsProductInventorySyncService::CHECKED_DAMAGE_STATUSES[0],
                'stock_quantity' => 1,
                'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            ],
        ]);

        $html = $this->actingAs($user)
            ->get(route('admin.zapchasti.index'))
            ->assertOk()
            ->assertSeeText('Active NikolaCars count part')
            ->assertDontSeeText('Broken NikolaCars count part')
            ->assertDontSeeText('Unknown donor projection count part')
            ->getContent();

        $this->assertStringContainsString('data-nikolacars-items-count>1</span>', $html);
        $this->assertStringContainsString('data-nikolacars-visible-rows-count>1</span>', $html);
    }

    public function test_nikolacars_catalog_shows_localized_names_in_name_column(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-nikolacars-localized-names@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/localized-name-column',
            'part_number' => '1002295-00-D',
            'name' => 'Rear bumper bracket Tesla MS 2012 - 2015 1002295-00-D',
            'name_ru' => 'Кронштейн заднего бампера',
            'name_ua' => 'Кронштейн заднього бампера',
            'raw_attributes' => [
                'code' => 'LOCALIZED-NAME',
                'stock_quantity' => 1,
            ],
        ]);

        $this->actingAs($user)
            ->get(route('admin.zapchasti.index'))
            ->assertOk()
            ->assertSeeText('Кронштейн заднього бампера')
            ->assertSeeText('Название РУ: Кронштейн заднего бампера')
            ->assertDontSeeText('Название УКР:');
    }

    public function test_nikolacars_catalog_shows_damage_status_changed_user(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-nikolacars-damage-user@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $checker = User::query()->create([
            'name' => 'Valera Checker',
            'email' => 'valera-checker@example.com',
            'password' => Hash::make('password'),
            'role' => 'warehouse_worker',
            'is_active' => true,
        ]);

        PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://donor-product/damage-user-test',
            'part_number' => 'DAMAGE-USER-TEST',
            'name' => 'Damage user test part',
            'name_ua' => 'Damage user test part',
            'quality' => NikolaCarsProductInventorySyncService::CHECKED_DAMAGE_STATUSES[0],
            'price_amount' => 10,
            'currency' => 'USD',
            'raw_attributes' => [
                'code' => 'DAMAGE-USER-TEST',
                'source_type' => 'donor',
                'donor_damage_status' => NikolaCarsProductInventorySyncService::CHECKED_DAMAGE_STATUSES[0],
                'donor_damage_status_changed_by' => $checker->id,
                'stock_quantity' => 1,
                'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            ],
        ]);

        $this->actingAs($user)
            ->get(route('admin.zapchasti.index', ['q' => 'DAMAGE-USER-TEST']))
            ->assertOk()
            ->assertSeeText(NikolaCarsProductInventorySyncService::CHECKED_DAMAGE_STATUSES[0])
            ->assertSeeText('Valera Checker');
    }

    public function test_nikolacars_catalog_shows_donor_model_and_year_under_donor_vin(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-donor-meta@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $donorCar = DonorCar::query()->create([
            'vin' => '7SAYGDEE1NF000001',
            'status' => DonorCar::STATUS_DISMANTLING,
            'brand' => 'Tesla',
            'model' => 'Model Y',
            'year' => 2022,
        ]);

        PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/donor-meta',
            'part_number' => '1498774-00-A',
            'name' => 'Door trim donor metadata test',
            'price_amount' => 100,
            'currency' => 'USD',
            'raw_attributes' => [
                'code' => 'DONOR-META',
                'donor_vin' => $donorCar->vin,
                'stock_quantity' => 1,
            ],
        ]);

        $this->actingAs($user)
            ->get(route('admin.zapchasti.index'))
            ->assertOk()
            ->assertSeeText('Донор')
            ->assertSeeText($donorCar->vin)
            ->assertSeeText('Model Y / 2022');
    }

    public function test_nikolacars_catalog_highlights_zero_sale_price(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-zero-sale-price@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/zero-sale-price',
            'part_number' => 'ZERO-PRICE',
            'name' => 'Zero sale price NikolaCars part',
            'price_amount' => 0,
            'currency' => 'USD',
            'raw_attributes' => [
                'code' => 'ZERO-PRICE',
                'stock_quantity' => 1,
            ],
        ]);
        PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/normal-sale-price',
            'part_number' => 'NORMAL-PRICE',
            'name' => 'Normal sale price NikolaCars part',
            'price_amount' => 100,
            'currency' => 'USD',
            'raw_attributes' => [
                'code' => 'NORMAL-PRICE',
                'stock_quantity' => 1,
            ],
        ]);
        PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/no-sale-price',
            'part_number' => 'NO-PRICE',
            'name' => 'No sale price NikolaCars part',
            'price_amount' => null,
            'currency' => null,
            'raw_attributes' => [
                'code' => 'NO-PRICE',
                'stock_quantity' => 1,
            ],
        ]);

        $html = $this->actingAs($user)
            ->get(route('admin.zapchasti.index'))
            ->assertOk()
            ->assertSeeText('0.00 USD')
            ->getContent();

        $zeroPriceRow = $this->tableRowContaining($html, 'Zero sale price NikolaCars part');
        $normalPriceRow = $this->tableRowContaining($html, 'Normal sale price NikolaCars part');
        $noPriceRow = $this->tableRowContaining($html, 'No sale price NikolaCars part');

        $this->assertStringContainsString('nikolacars-zero-price', $zeroPriceRow);
        $this->assertStringNotContainsString('nikolacars-zero-price', $normalPriceRow);
        $this->assertStringContainsString('data-nikolacars-cart-add', $zeroPriceRow);
        $this->assertMatchesRegularExpression('/<button[^>]*hidden[^>]*data-nikolacars-cart-add/s', $zeroPriceRow);
        $this->assertStringContainsString('data-nikolacars-cart-placeholder', $zeroPriceRow);
        $this->assertStringContainsString('data-nikolacars-cart-add', $noPriceRow);
        $this->assertMatchesRegularExpression('/<button[^>]*hidden[^>]*data-nikolacars-cart-add/s', $noPriceRow);
        $this->assertStringContainsString('data-nikolacars-cart-add', $normalPriceRow);
        $this->assertDoesNotMatchRegularExpression('/<button[^>]*hidden[^>]*data-nikolacars-cart-add/s', $normalPriceRow);
    }

    public function test_nikolacars_catalog_ajax_price_update_survives_missing_nbu_rate(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-price-update-no-nbu@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Http::fake([
            'bank.gov.ua/*' => fn () => throw new \RuntimeException('NBU unavailable'),
            'api.monobank.ua/*' => fn () => throw new \RuntimeException('Monobank unavailable'),
        ]);

        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/ajax-price-update',
            'part_number' => 'PRICE-UPDATE',
            'name' => 'Ajax price update NikolaCars part',
            'price_amount' => 0,
            'currency' => 'USD',
            'raw_attributes' => [
                'code' => 'PRICE-UPDATE',
                'stock_quantity' => 1,
            ],
        ]);

        $this->actingAs($user)
            ->patchJson(route('admin.zapchasti.update', $item), [
                'price_amount' => 120,
            ])
            ->assertOk()
            ->assertJsonPath('price_amount', 120)
            ->assertJsonPath('price_amount_uah', 5160);

        $this->assertSame('120.00', $item->refresh()->price_amount);
    }

    public function test_nikolacars_group_price_update_uses_exact_article_not_prefix(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-group-price-update-exact@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Http::fake([
            'bank.gov.ua/*' => fn () => throw new \RuntimeException('NBU unavailable'),
            'api.monobank.ua/*' => fn () => throw new \RuntimeException('Monobank unavailable'),
        ]);

        $first = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/group-price-first',
            'part_number' => '1234567-00-A',
            'name' => 'Grouped price first',
            'price_amount' => 80,
            'currency' => 'USD',
            'raw_attributes' => [
                'stock_quantity' => 1,
            ],
        ]);
        $sameExactArticle = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/group-price-same',
            'part_number' => '123456700A',
            'name' => 'Grouped price same exact article',
            'price_amount' => 90,
            'currency' => 'USD',
            'raw_attributes' => [
                'stock_quantity' => 1,
            ],
        ]);
        $samePrefixVariant = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/group-price-variant',
            'part_number' => '1234567-01-B',
            'name' => 'Grouped price same prefix variant',
            'price_amount' => 70,
            'currency' => 'USD',
            'raw_attributes' => [
                'stock_quantity' => 1,
            ],
        ]);

        $this->actingAs($user)
            ->patchJson(route('admin.zapchasti.update', $first), [
                'price_amount' => 120,
                'apply_to_part_number' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('price_amount', 120);

        $this->assertSame('120.00', $first->refresh()->price_amount);
        $this->assertSame('120.00', $sameExactArticle->refresh()->price_amount);
        $this->assertSame('70.00', $samePrefixVariant->refresh()->price_amount);
    }

    public function test_nikolacars_catalog_default_sort_prioritizes_recent_part_changes(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-recent-part-changes-sort@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Carbon::setTestNow('2026-06-16 10:00:00');
        $recentlyCheckedItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://donor-product/recently-checked-sort',
            'part_number' => 'RECENTLY-CHECKED-SORT',
            'name' => 'Recently checked donor NikolaCars part',
            'quality' => NikolaCarsProductInventorySyncService::CHECKED_DAMAGE_STATUSES[0],
            'raw_attributes' => [
                'code' => 'RECENTLY-CHECKED-SORT',
                'source_type' => 'donor',
                'donor_damage_status' => NikolaCarsProductInventorySyncService::CHECKED_DAMAGE_STATUSES[0],
                'donor_damage_checked_at' => '2026-06-16T12:00:00+03:00',
                'stock_quantity' => 1,
            ],
        ]);

        Carbon::setTestNow('2026-06-16 11:00:00');
        PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/newer-created-sort',
            'part_number' => 'NEWER-CREATED-SORT',
            'name' => 'Newer created NikolaCars part',
            'raw_attributes' => [
                'code' => 'NEWER-CREATED-SORT',
                'stock_quantity' => 1,
            ],
        ]);
        Carbon::setTestNow('2026-06-16 09:00:00');
        $updatedItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/recently-updated-sort',
            'part_number' => 'RECENTLY-UPDATED-SORT',
            'name' => 'Recently updated NikolaCars part',
            'raw_attributes' => [
                'code' => 'RECENTLY-UPDATED-SORT',
                'stock_quantity' => 1,
            ],
        ]);
        Carbon::setTestNow('2026-06-16 13:00:00');
        $updatedItem->forceFill(['price_amount' => 125, 'currency' => 'USD'])->save();
        Carbon::setTestNow();

        $html = $this->actingAs($user)
            ->get(route('admin.zapchasti.index'))
            ->assertOk()
            ->assertSeeText('Recently updated NikolaCars part')
            ->assertSeeText('Recently checked donor NikolaCars part')
            ->assertSeeText('Newer created NikolaCars part')
            ->getContent();

        $this->assertLessThan(
            strpos($html, 'Recently checked donor NikolaCars part'),
            strpos($html, 'Recently updated NikolaCars part')
        );
        $this->assertLessThan(
            strpos($html, 'Newer created NikolaCars part'),
            strpos($html, 'Recently checked donor NikolaCars part')
        );
        $this->assertSame('2026-06-16T12:00:00+03:00', data_get($recentlyCheckedItem->refresh()->raw_attributes, 'donor_damage_checked_at'));
    }

    public function test_nikolacars_zero_stock_rows_are_at_bottom_without_sale_actions(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-zero-stock@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $zeroItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/zero-stock',
            'part_number' => 'ZERO-STOCK',
            'name' => 'Zero stock NikolaCars part',
            'price_amount' => 100,
            'currency' => 'USD',
            'raw_attributes' => [
                'code' => 'ZERO',
                'stock_quantity' => 0,
            ],
        ]);
        $reservedItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/fully-reserved-stock',
            'part_number' => 'FULLY-RESERVED-STOCK',
            'name' => 'Fully reserved NikolaCars part',
            'price_amount' => 100,
            'currency' => 'USD',
            'raw_attributes' => [
                'code' => 'RESERVED',
                'stock_quantity' => 1,
                'reserved_quantity' => 1,
            ],
        ]);
        $availableItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/available-stock',
            'part_number' => 'AVAILABLE-STOCK',
            'name' => 'Available NikolaCars part',
            'price_amount' => 100,
            'currency' => 'USD',
            'raw_attributes' => [
                'code' => 'AVAILABLE',
                'stock_quantity' => 1,
            ],
        ]);

        $response = $this->actingAs($user)
            ->get(route('admin.zapchasti.index'))
            ->assertOk()
            ->assertSeeText('Available NikolaCars part')
            ->assertSeeText('Fully reserved NikolaCars part')
            ->assertSeeText('Zero stock NikolaCars part')
            ->assertSee('data-cart-id="'.$availableItem->id.'"', false)
            ->assertSee('nikolacars-update-'.$availableItem->id, false)
            ->assertDontSee('data-cart-id="'.$reservedItem->id.'"', false)
            ->assertDontSee('data-cart-id="'.$zeroItem->id.'"', false)
            ->assertDontSee('nikolacars-update-'.$zeroItem->id, false);

        $html = $response->getContent();
        $reservedRow = $this->tableRowContaining($html, 'Fully reserved NikolaCars part');
        $zeroRow = $this->tableRowContaining($html, 'Zero stock NikolaCars part');
        $availableRow = $this->tableRowContaining($html, 'Available NikolaCars part');

        $this->assertStringContainsString('nikolacars-reserved-row', $reservedRow);
        $this->assertStringContainsString('nikolacars-zero-stock-row', $reservedRow);
        $this->assertStringContainsString('nikolacars-reserved-note', $reservedRow);
        $this->assertStringContainsString('1 шт', $reservedRow);
        $this->assertStringContainsString('в резерве 1 шт', $reservedRow);
        $this->assertStringNotContainsString('data-nikolacars-cart-add', $reservedRow);

        $this->assertStringContainsString('nikolacars-zero-stock-row', $zeroRow);
        $this->assertStringNotContainsString('data-nikolacars-cart-add', $zeroRow);
        $this->assertStringNotContainsString('data-nikolacars-update-form', $zeroRow);
        $this->assertStringNotContainsString('data-nikolacars-delete-form', $zeroRow);
        $this->assertStringNotContainsString('data-nikolacars-manual-sold-form', $zeroRow);
        $this->assertStringNotContainsString('nikolacars-sold-button', $zeroRow);

        $this->assertStringContainsString('data-nikolacars-cart-add', $availableRow);
        $this->assertStringContainsString('data-nikolacars-update-form', $availableRow);
        $this->assertStringContainsString('data-nikolacars-delete-form', $availableRow);
        $this->assertStringContainsString('data-nikolacars-manual-sold-form', $availableRow);

        $this->assertLessThan(
            strpos($html, 'Available NikolaCars part'),
            strpos($html, 'Fully reserved NikolaCars part')
        );
        $this->assertLessThan(
            strpos($html, 'Zero stock NikolaCars part'),
            strpos($html, 'Available NikolaCars part')
        );
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

    public function test_model_catalog_index_shows_model_preview_without_code_column(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        PartCatalogCategory::query()->create([
            'source' => 'tcarservice',
            'source_url' => 'https://tcarservice.com/zapchasty/model-3-326',
            'preview_image_url' => 'https://tcarservice.com/storage/editor/fotos/530x0/996a68040be17e96ac4a367d3fd16f32_1713959319.webp',
            'depth' => 0,
            'code' => '326',
            'name' => 'Model 3 06.2017 - 12.2023',
            'model_label' => 'Model 3 06.2017 - 12.2023',
            'model_name' => 'Model 3',
            'year_from' => 2017,
            'year_to' => 2023,
        ]);

        $this->actingAs($user)
            ->get(route('admin.part-catalog.index'))
            ->assertOk()
            ->assertSee('table-preview')
            ->assertSee('https://tcarservice.com/storage/editor/fotos/530x0/996a68040be17e96ac4a367d3fd16f32_1713959319.webp', false)
            ->assertDontSee('>326</td>', false);
    }

    public function test_model_category_page_groups_subcategories_by_top_category(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $model = PartCatalogCategory::query()->create([
            'source' => 'tcarservice',
            'source_url' => 'https://tcarservice.com/zapchasty/model-3-326',
            'depth' => 0,
            'name' => 'Model 3 06.2017 - 12.2023',
            'model_label' => 'Model 3 06.2017 - 12.2023',
            'model_name' => 'Model 3',
        ]);

        $body = PartCatalogCategory::query()->create([
            'parent_id' => $model->id,
            'source' => 'tcarservice',
            'source_url' => 'https://tcarservice.com/zapchasty/model-3-326/body',
            'preview_image_url' => 'https://tcarservice.com/storage/editor/fotos/530x0/49b591f4d5d59376e6b5f5923f389839_1713535375.jpg',
            'depth' => 1,
            'code' => '10',
            'name' => '?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ??????????пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ??????пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ??????пїЅ???пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?????пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ??????????',
            'model_label' => 'Model 3 06.2017 - 12.2023',
            'model_name' => 'Model 3',
        ]);

        PartCatalogCategory::query()->create([
            'parent_id' => $body->id,
            'source' => 'tcarservice',
            'source_url' => 'https://tcarservice.com/zapchasty/model-3-326/body/front-bumper',
            'depth' => 2,
            'code' => '1001',
            'name' => '?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ???????пїЅ?пїЅ?пїЅ?пїЅ?пїЅ??пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ???пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ??пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?????пїЅ???пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ???пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?????пїЅ???пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ???пїЅ?пїЅ???пїЅ??? ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ??пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ??пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ???пїЅ???пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?????пїЅ????пїЅ?пїЅ?пїЅ?пїЅ?пїЅ??пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ???пїЅ?пїЅ',
            'model_label' => 'Model 3 06.2017 - 12.2023',
            'model_name' => 'Model 3',
        ]);

        $this->actingAs($user)
            ->get(route('admin.part-catalog.category', ['catalogPath' => 'model-3-326']))
            ->assertOk()
            ->assertSee('?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ??????????пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ??????пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ??????пїЅ???пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?????пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ??????????')
            ->assertSee('?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ???????пїЅ?пїЅ?пїЅ?пїЅ?пїЅ??пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ???пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ??пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?????пїЅ???пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ???пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?????пїЅ???пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ???пїЅ?пїЅ???пїЅ??? ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ??пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ??пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ???пїЅ???пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?????пїЅ????пїЅ?пїЅ?пїЅ?пїЅ?пїЅ??пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ???пїЅ?пїЅ')
            ->assertSee('https://tcarservice.com/storage/editor/fotos/530x0/49b591f4d5d59376e6b5f5923f389839_1713535375.jpg', false)
            ->assertSee(route('admin.part-catalog.category', ['catalogPath' => 'model-3-326/body/front-bumper']), false)
            ->assertDontSee('?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ????пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?????пїЅ????пїЅ?пїЅ?пїЅ?пїЅ?пїЅ??пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ???пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ??????пїЅ?пїЅ?пїЅ?пїЅ?пїЅ??пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ???пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ???пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ??????????пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ??пїЅ?пїЅ?пїЅ ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ??????пїЅ??пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?????пїЅ???пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ???пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ??пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ???пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ??????????пїЅ');
    }

    public function test_leaf_category_shows_parsed_items(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $category = PartCatalogCategory::query()->create([
            'source' => 'tcarservice',
            'source_url' => 'https://tcarservice.com/zapchasty/model-3-326/body/front-bumper',
            'depth' => 2,
            'code' => '1001',
            'name' => 'Front Bumper',
            'model_label' => 'Model 3 2017-2023',
            'model_name' => 'Model 3',
        ]);

        PartCatalogItem::query()->create([
            'part_catalog_category_id' => $category->id,
            'source' => 'tcarservice',
            'source_url' => 'https://tcarservice.com/parts/front-bumper-carrier',
            'part_number' => '1084171-00-E',
            'name' => 'Front Bumper Carrier',
            'scheme_number' => 12,
            'price_amount' => 125.00,
            'currency' => 'USD',
            'availability' => 'In stock',
        ]);

        $this->actingAs($user)
            ->get(route('admin.part-catalog.category', ['catalogPath' => 'model-3-326/body/front-bumper']))
            ->assertOk()
            ->assertSee('Front Bumper Carrier')
            ->assertSee('1084171-00-E')
            ->assertSee('125.00 USD');
    }

    public function test_tesla_official_item_page_shows_scheme_number_near_name(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $category = PartCatalogCategory::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-official://model-3/body/body-panels/front-inner-panels',
            'depth' => 3,
            'name' => 'Front Inner Panels',
            'model_label' => 'Model 3 06.2017 - 12.2023',
            'model_name' => 'Model 3',
        ]);

        $item = PartCatalogItem::query()->create([
            'part_catalog_category_id' => $category->id,
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/product/1978118-S0-A',
            'part_number' => '1978118-S0-A',
            'name' => 'ASSEMBLY- FRONT RAIL',
            'scheme_number' => 12,
        ]);

        $this->actingAs($user)
            ->get(route('admin.tesla-official-catalog.show', $item))
            ->assertOk()
            ->assertSee('ASSEMBLY- FRONT RAIL')
            ->assertSee('На схеме 12');
    }

    public function test_tesla_official_item_page_does_not_duplicate_scheme_number_from_name(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-no-duplicate-scheme@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $category = PartCatalogCategory::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-official://model-s/body/glass',
            'depth' => 3,
            'name' => 'Glass',
            'model_label' => 'Model S Feb 2012 - Mar 2016',
            'model_name' => 'Model S',
        ]);

        $item = PartCatalogItem::query()->create([
            'part_catalog_category_id' => $category->id,
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/product/6005898-00-E',
            'part_number' => '6005898-00-E',
            'name' => 'BACKLITE GLASS ASSEMBLY',
            'name_ru' => '3 Rear glass',
            'scheme_number' => 3,
            'raw_attributes' => ['annotation' => '3'],
        ]);

        $this->actingAs($user)
            ->get(route('admin.tesla-official-catalog.show', $item))
            ->assertOk()
            ->assertSee('3. Rear glass')
            ->assertDontSee('3. 3 Rear glass');
    }

    public function test_tesla_official_item_page_shows_non_numeric_scheme_annotation_near_name(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $category = PartCatalogCategory::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-official://model-3/body/body-panels/front-inner-panels',
            'depth' => 3,
            'name' => 'Front Inner Panels',
            'model_label' => 'Model 3 06.2017 - 12.2023',
            'model_name' => 'Model 3',
        ]);

        $item = PartCatalogItem::query()->create([
            'part_catalog_category_id' => $category->id,
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/product/1978118-S0-A',
            'part_number' => '1978118-S0-A',
            'name' => 'ASSEMBLY- FRONT RAIL',
            'raw_attributes' => ['annotation' => '*'],
        ]);

        $this->actingAs($user)
            ->get(route('admin.tesla-official-catalog.show', $item))
            ->assertOk()
            ->assertSee('ASSEMBLY- FRONT RAIL')
            ->assertSee('На схеме *');
    }

    public function test_competitor_leaf_categories_without_codes_keep_source_names(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        PartCatalogCategory::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/front-bumper-fascia-carrier-dual-motor',
            'depth' => 3,
            'name' => 'Front Bumper (Fascia) Carrier - Dual Motor',
            'name_en' => 'Front Bumper (Fascia) Carrier - Dual Motor',
            'model_label' => 'Model S 02.2012-03.2016',
            'model_name' => 'Model S',
        ]);

        $parent = PartCatalogCategory::query()->create([
            'source' => 'tsk',
            'source_url' => 'https://tsk.ua/katalog-zapchastey296/1010-body-panels-1156/',
            'depth' => 2,
            'code' => '1010',
            'name' => 'Body Panels',
            'model_label' => 'Model S 02.2012-03.2016',
            'model_name' => 'Model S',
        ]);

        PartCatalogCategory::query()->create([
            'parent_id' => $parent->id,
            'source' => 'tsk',
            'source_url' => 'https://tsk.ua/katalog-zapchastey296/body-side-panels-3407/',
            'depth' => 3,
            'code' => '1020',
            'name' => 'Body Side Panels',
            'name_en' => 'Body Side Panels',
            'model_label' => 'Model S 02.2012-03.2016',
            'model_name' => 'Model S',
        ]);

        PartCatalogCategory::query()->create([
            'parent_id' => $parent->id,
            'source' => 'tsk',
            'source_url' => 'https://tsk.ua/katalog-zapchastey296/closure-panels-3408/',
            'depth' => 3,
            'name' => 'Closure Panels',
            'name_en' => 'Closure Panels',
            'model_label' => 'Model S 02.2012-03.2016',
            'model_name' => 'Model S',
        ]);

        $this->actingAs($user)
            ->get(route('admin.tsk-catalog.index', ['category_id' => $parent->id]))
            ->assertOk()
            ->assertSee('Body Side Panels')
            ->assertSee('Closure Panels')
            ->assertDontSee('Front Bumper (Fascia) Carrier - Dual Motor');
    }

    public function test_competitor_category_names_prefer_official_english_catalog_names(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        PartCatalogCategory::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/body',
            'depth' => 1,
            'code' => '10',
            'name' => 'BODY',
            'name_en' => 'BODY',
            'model_label' => 'Model S 02.2012-03.2016',
            'model_name' => 'Model S',
        ]);

        $category = PartCatalogCategory::query()->create([
            'source' => 'tsk',
            'source_url' => 'https://tsk.ua/katalog-zapchastey296/?group=12964-10',
            'depth' => 1,
            'code' => '10',
            'name' => 'KUZOV',
            'name_en' => 'KUZOV',
            'name_ru' => 'KUZOV',
            'model_label' => 'Model S 02.2012-03.2016',
            'model_name' => 'Model S',
        ]);

        $this->actingAs($user)
            ->get(route('admin.tsk-catalog.index', ['category_id' => $category->id]))
            ->assertOk()
            ->assertSee('10', false)
            ->assertSee('BODY')
            ->assertDontSee('KUZOV');
    }

    public function test_competitor_site_link_points_to_selected_tsk_category(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $category = PartCatalogCategory::query()->create([
            'source' => 'tsk',
            'source_url' => 'https://tsk.ua/katalog-zapchastey296/front-bumper-carrier-13680/',
            'depth' => 3,
            'name' => 'Front Bumper Carrier',
            'model_label' => 'Model S 02.2012-03.2016',
            'model_name' => 'Model S',
        ]);

        $this->actingAs($user)
            ->get(route('admin.tsk-catalog.index', ['category_id' => $category->id]))
            ->assertOk()
            ->assertSee('href="https://tsk.ua/katalog-zapchastey296/front-bumper-carrier-13680/"', false);
    }

    public function test_tsk_category_shows_items_from_epc_occurrences(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $selectedCategory = PartCatalogCategory::query()->create([
            'source' => 'tsk',
            'source_url' => 'https://tsk.ua/katalog-zapchastey296/front-bumper-fascia-carrier-dual-motor-2352/',
            'depth' => 3,
            'name' => 'Front Bumper Fascia Carrier Dual Motor',
            'model_label' => 'Model S 02.2012-03.2016',
            'model_name' => 'Model S',
        ]);

        $canonicalCategory = PartCatalogCategory::query()->create([
            'source' => 'tsk',
            'source_url' => 'https://tsk.ua/katalog-zapchastey296/front-bumper-carrier-2457/',
            'depth' => 3,
            'name' => 'Front Bumper Carrier',
            'model_label' => 'Model S 02.2012-03.2016',
            'model_name' => 'Model S',
        ]);

        $item = PartCatalogItem::query()->create([
            'part_catalog_category_id' => $canonicalCategory->id,
            'source' => 'tsk',
            'source_url' => 'https://tsk.ua/1061950-98-e/',
            'part_number' => '1061950-98-E',
            'name' => 'Front End Carrier',
            'price_amount' => 120,
            'currency' => 'USD',
        ]);

        PartCatalogItem::query()->create([
            'part_catalog_category_id' => $canonicalCategory->id,
            'source' => 'tsk',
            'source_url' => 'https://tsk.ua/1006201-00-b/',
            'part_number' => '1006201-00-B',
            'name' => 'Condenser Bracket',
        ]);

        PartCatalogItemOccurrence::query()->create([
            'part_catalog_item_id' => $item->id,
            'part_catalog_category_id' => $selectedCategory->id,
            'source' => 'tsk',
            'occurrence_key' => 'tsk-test-front-bumper-fascia-carrier',
            'page_url' => $selectedCategory->source_url,
            'product_url' => $item->source_url,
            'part_number' => $item->part_number,
            'name' => 'FRONT END CARRIER, MS2',
            'scheme_number' => 1,
        ]);

        $this->actingAs($user)
            ->get(route('admin.tsk-catalog.index', ['category_id' => $selectedCategory->id]))
            ->assertOk()
            ->assertSee('Front End Carrier')
            ->assertSee('1061950-98-E')
            ->assertSee('120.00 USD')
            ->assertDontSee('Condenser Bracket');
    }

    public function test_dkparts_category_shows_items_from_listing_occurrences(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $selectedCategory = PartCatalogCategory::query()->create([
            'source' => 'dkparts',
            'source_url' => 'https://dk-parts.com.ua/ru/model-s-before-2016/',
            'depth' => 0,
            'name' => 'Model S before 2016',
            'model_label' => 'Model S before 2016',
            'model_name' => 'Model S',
        ]);

        $canonicalCategory = PartCatalogCategory::query()->create([
            'source' => 'dkparts',
            'source_url' => 'https://dk-parts.com.ua/ru/model-s-after-2016/10-body/',
            'depth' => 1,
            'name' => 'Body',
            'model_label' => 'Model S after 2016',
            'model_name' => 'Model S',
        ]);

        $item = PartCatalogItem::query()->create([
            'part_catalog_category_id' => $canonicalCategory->id,
            'source' => 'dkparts',
            'source_url' => 'https://dk-parts.com.ua/ru/1061950-98-e/',
            'part_number' => '1061950-98-E',
            'name' => 'Front End Carrier',
            'price_amount' => 120,
            'currency' => 'USD',
        ]);

        PartCatalogItem::query()->create([
            'part_catalog_category_id' => $canonicalCategory->id,
            'source' => 'dkparts',
            'source_url' => 'https://dk-parts.com.ua/ru/1006201-00-b/',
            'part_number' => '1006201-00-B',
            'name' => 'Condenser Bracket',
        ]);

        PartCatalogItemOccurrence::query()->create([
            'part_catalog_item_id' => $item->id,
            'part_catalog_category_id' => $selectedCategory->id,
            'source' => 'dkparts',
            'occurrence_key' => 'dkparts-test-model-s-before-2016',
            'page_url' => $selectedCategory->source_url,
            'product_url' => $item->source_url,
            'part_number' => $item->part_number,
            'name' => 'Front End Carrier',
        ]);

        $this->actingAs($user)
            ->get(route('admin.dkparts-catalog.index', [
                'category_id' => $selectedCategory->id,
                'competitor_sort' => 'id',
            ]))
            ->assertOk()
            ->assertSee('Front End Carrier')
            ->assertSee('1061950-98-E')
            ->assertSee('120.00 USD')
            ->assertSee('catalog-count-badge">1</span>', false)
            ->assertDontSee('Condenser Bracket');
    }

    public function test_catalog_price_is_shown_as_usd_base_with_uah_conversion(): void
    {
        Carbon::setTestNow('2026-05-05 10:00:00');

        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_date' => now()->toDateString(),
            'rate' => 40,
            'source' => 'nbu',
            'fetched_at' => now(),
        ]);

        $category = PartCatalogCategory::query()->create([
            'source' => 'tcarservice',
            'source_url' => 'https://tcarservice.com/zapchasty/model-3-326/body/front-bumper',
            'depth' => 2,
            'code' => '1001',
            'name' => 'Front Bumper',
            'model_label' => 'Model 3 06.2017 - 12.2023',
            'model_name' => 'Model 3',
        ]);

        PartCatalogItem::query()->create([
            'part_catalog_category_id' => $category->id,
            'source' => 'tcarservice',
            'source_url' => 'https://tcarservice.com/parts/front-bumper-carrier',
            'part_number' => '1084171-00-E',
            'name' => 'Front Bumper Carrier',
            'price_amount' => 100,
            'currency' => 'USD',
        ]);

        $this->actingAs($user)
            ->get(route('admin.part-catalog.category', ['catalogPath' => 'model-3-326/body/front-bumper']))
            ->assertOk()
            ->assertSee('100.00 USD')
            ->assertSee('4 000.00 UAH');

        Carbon::setTestNow();
    }

    public function test_catalog_item_external_url_prefers_product_url_over_category_url(): void
    {
        $item = PartCatalogItem::query()->create([
            'source' => 'driveparts',
            'source_url' => 'https://drive-parts.com.ua/ru/1127267-00-e-product/',
            'part_number' => '1127267-00-E',
            'name' => 'Carpet',
            'raw_attributes' => [
                'category_source_url' => 'https://drive-parts.com.ua/ru/model-3/interior/carpet/',
            ],
        ]);

        $controller = app(PartCatalogController::class);
        $method = new \ReflectionMethod($controller, 'displayableSourceUrl');
        $method->setAccessible(true);

        $this->assertSame(
            'https://drive-parts.com.ua/ru/1127267-00-e-product/',
            $method->invoke($controller, $item)
        );
    }

    public function test_tsk_item_show_fetches_missing_price_from_product_url(): void
    {
        Carbon::setTestNow('2026-05-05 10:00:00');

        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_date' => now()->toDateString(),
            'rate' => 40,
            'source' => 'nbu',
            'fetched_at' => now(),
        ]);

        Http::fake([
            'https://tsk.ua/1031595-00-d/' => Http::response(<<<'HTML'
                <!doctype html>
                <html>
                    <head><meta property="og:image" content="https://tsk.ua/image.jpg"></head>
                    <body>
                        <div class="tovar-anons__price"><span class="tovar-anons__price-old">400</span><span>300 USD</span></div>
                        <div class="tovar-anons__nonal">In stock</div>
                    </body>
                </html>
                HTML),
        ]);

        $item = PartCatalogItem::query()->create([
            'source' => 'tsk',
            'source_url' => 'tsk-epc:032b2c6cbd1848ebc72d9e914be86df0',
            'part_number' => '1031595-00-D',
            'name' => '12VOLT FUSEBOX BRACKET - DUAL MOTOR',
            'price_amount' => null,
            'currency' => null,
            'raw_attributes' => [
                'product_url' => 'https://tsk.ua/1031595-00-d/',
            ],
        ]);

        $this->actingAs($user)
            ->get(route('admin.tsk-catalog.show', $item))
            ->assertOk()
            ->assertSee('300.00 USD')
            ->assertSee('12 000.00 UAH');

        $this->assertDatabaseHas('part_catalog_items', [
            'id' => $item->id,
            'price_amount' => 300,
            'currency' => 'USD',
            'availability' => 'In stock',
        ]);

        Carbon::setTestNow();
    }

    public function test_tsk_product_details_ignores_promotional_product_prices(): void
    {
        Http::fake([
            'https://tsk.ua/1006201-00-b/' => Http::response(<<<'HTML'
                <!doctype html>
                <html>
                    <head><meta property="og:image" content="https://tsk.ua/image.jpg"></head>
                    <body>
                        <main>
                            <div class="tovar-anons__price"><span>10 USD</span></div>
                            <div class="tovar-anons__nonal">In stock</div>
                        </main>
                        <section class="promo">
                            <h2>Similar products</h2>
                            <article><strong>300 USD</strong></article>
                        </section>
                    </body>
                </html>
                HTML),
        ]);

        $details = app(TskCatalogImporter::class)->productDetails('https://tsk.ua/1006201-00-b/');

        $this->assertSame(10.0, $details['price_amount']);
        $this->assertSame('USD', $details['currency']);
        $this->assertSame('In stock', $details['availability']);
    }

    public function test_tsk_leaf_import_fetches_price_from_product_url_without_using_promo_prices(): void
    {
        $category = PartCatalogCategory::query()->create([
            'source' => 'tsk',
            'source_url' => 'https://tsk.ua/katalog-zapchastey296/front-bumper-carrier-4841/',
            'depth' => 1,
            'name' => 'Front bumper carrier',
            'model_label' => 'Model Y',
            'products_scanned_at' => null,
        ]);

        Http::fake([
            'https://tsk.ua/katalog-zapchastey296/front-bumper-carrier-4841/' => Http::response(<<<'HTML'
                <!doctype html>
                <html>
                    <body>
                        <table>
                            <tr>
                                <td>1</td>
                                <td>CONDENSER BRACKET</td>
                                <td>1006201-00-B</td>
                                <td>4</td>
                                <td><a href="/1006201-00-b/">Condenser bracket</a></td>
                            </tr>
                        </table>
                    </body>
                </html>
                HTML),
            'https://tsk.ua/1006201-00-b/' => Http::response(<<<'HTML'
                <!doctype html>
                <html>
                    <body>
                        <main>
                            <div class="tovar-anons__price"><span>10 USD</span></div>
                            <div class="tovar-anons__nonal">In stock</div>
                        </main>
                        <section class="promo">
                            <h2>Similar products</h2>
                            <article><strong>300 USD</strong></article>
                        </section>
                    </body>
                </html>
                HTML),
        ]);

        $stats = app(TskCatalogImporter::class)->importLeafProducts([
            'sleep_ms' => 0,
        ]);

        $this->assertSame(1, $stats['products_saved']);
        $this->assertDatabaseHas('part_catalog_items', [
            'source' => 'tsk',
            'source_url' => 'tsk-epc:'.md5(collect([
                $category->source_url,
                '1',
                '1006201-00-B',
                'CONDENSER BRACKET',
            ])->implode('|')),
            'part_number' => '1006201-00-B',
            'price_amount' => 10,
            'currency' => 'USD',
            'availability' => 'In stock',
        ]);
    }

    public function test_teslapartsukraine_catalog_uses_separate_navigation(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $tcarsModel = PartCatalogCategory::query()->create([
            'source' => 'tcarservice',
            'source_url' => 'https://tcarservice.com/zapchasty/model-3-326',
            'depth' => 0,
            'name' => 'Model 3 06.2017 - 12.2023',
            'model_label' => 'Model 3 06.2017 - 12.2023',
            'model_name' => 'Model 3',
        ]);

        PartCatalogCategory::query()->create([
            'parent_id' => $tcarsModel->id,
            'source' => 'tcarservice',
            'source_url' => 'https://tcarservice.com/zapchasty/model-3-326/10-body-2124',
            'depth' => 1,
            'code' => '10',
            'name' => 'Body',
            'model_label' => 'Model 3 06.2017 - 12.2023',
            'model_name' => 'Model 3',
        ]);

        $model = PartCatalogCategory::query()->create([
            'source' => 'teslapartsukraine',
            'source_url' => 'https://teslapartsukraine.com.ua/index.php?model_id=2&route=tesla/catalog/model',
            'depth' => 0,
            'name' => 'Model 3 06.2017 - 12.2023',
            'model_label' => 'Model 3 06.2017 - 12.2023',
            'model_name' => 'Model 3',
        ]);

        $body = PartCatalogCategory::query()->create([
            'parent_id' => $model->id,
            'source' => 'teslapartsukraine',
            'source_url' => 'https://teslapartsukraine.com.ua/index.php?model_id=2&route=tesla/catalog/model#10',
            'depth' => 1,
            'code' => '10',
            'name' => 'Body',
            'model_label' => 'Model 3 06.2017 - 12.2023',
            'model_name' => 'Model 3',
        ]);

        $this->actingAs($user)
            ->get(route('admin.teslapartsukraine-catalog.index', ['category_id' => $model->id]))
            ->assertOk()
            ->assertSee('Body')
            ->assertSee(route('admin.teslapartsukraine-catalog.category', [
                'catalogPath' => 'model-3-326/10-body-2124',
            ]), false);

        $this->actingAs($user)
            ->get(route('admin.teslapartsukraine-catalog.category', ['catalogPath' => 'model-3-326/10-body-2124']))
            ->assertOk()
            ->assertSee('Body');
    }

    public function test_tesla_official_catalog_shows_only_real_tesla_com_records(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-tesla-official@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $tcarsModel = PartCatalogCategory::query()->create([
            'source' => 'tcarservice',
            'source_url' => 'https://tcarservice.com/zapchasty/model-y-327',
            'depth' => 0,
            'name' => 'Model Y 01.2020 - 01.2025',
            'model_label' => 'Model Y 01.2020 - 01.2025',
            'model_name' => 'Model Y',
        ]);

        PartCatalogCategory::query()->create([
            'parent_id' => $tcarsModel->id,
            'source' => 'tcarservice',
            'source_url' => 'https://tcarservice.com/zapchasty/model-y-327/10---body-1842',
            'depth' => 1,
            'code' => '10',
            'name' => 'BODY',
            'model_label' => 'Model Y 01.2020 - 01.2025',
            'model_name' => 'Model Y',
        ]);

        $model = PartCatalogCategory::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/catalogs?catalogExternalReference=model-y-official',
            'depth' => 0,
            'name' => 'Model Y 01.2020 - 01.2025',
            'model_label' => 'Model Y 01.2020 - 01.2025',
            'model_name' => 'Model Y',
        ]);

        $body = PartCatalogCategory::query()->create([
            'parent_id' => $model->id,
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/catalogs?catalogExternalReference=model-y-official&categoryExternalReference=body',
            'depth' => 1,
            'code' => '10',
            'name' => 'BODY',
            'model_label' => 'Model Y 01.2020 - 01.2025',
            'model_name' => 'Model Y',
        ]);

        $officialItem = PartCatalogItem::query()->create([
            'part_catalog_category_id' => $body->id,
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/catalogs?catalogExternalReference=model-y-official&partNumber=1234567-00-A',
            'part_number' => '1234567-00-A',
            'name' => 'Official Tesla panel',
            'name_en' => 'Official Tesla panel',
        ]);

        $commonItem = PartCatalogItem::query()->create([
            'part_catalog_category_id' => $body->id,
            'source' => 'tesla_official',
            'source_url' => 'tesla-common://competitor-item/teslahelp/134231',
            'part_number' => '7654321-00-A',
            'name' => 'Competitor common panel',
            'name_en' => 'Competitor common panel',
        ]);

        $officialPath = Str::slug($model->name).'-'.$model->id.'/'.Str::slug($body->code.' '.$body->name).'-'.$body->id;

        $this->actingAs($user)
            ->get(route('admin.tesla-official-catalog.index', ['category_id' => $model->id]))
            ->assertOk()
            ->assertSee(route('admin.tesla-official-catalog.category', ['catalogPath' => $officialPath]), false)
            ->assertDontSee('Official Tesla panel')
            ->assertDontSee('Competitor common panel')
            ->assertDontSee('tesla-common://competitor-item/teslahelp/134231')
            ->assertDontSee('model-y-327/10---body-1842');

        $this->actingAs($user)
            ->get(route('admin.tesla-official-catalog.category', ['catalogPath' => $officialPath]))
            ->assertOk()
            ->assertSee('Official Tesla panel')
            ->assertSee(route('admin.tesla-official-catalog.show', $officialItem), false)
            ->assertDontSee('Competitor common panel')
            ->assertDontSee('/admin/tesla-catalog/items/'.$commonItem->id, false);

        $this->actingAs($user)
            ->get(route('admin.tesla-official-catalog.index', [
                'q' => '7654321-00-A',
                'model_filter' => 1,
            ]))
            ->assertOk()
            ->assertDontSee('Competitor common panel')
            ->assertDontSee('/admin/tesla-catalog/items/'.$commonItem->id, false);

        $this->actingAs($user)
            ->get(route('admin.tesla-official-catalog.category', ['catalogPath' => 'model-y-327/10---body-1842']))
            ->assertNotFound();

        $this->actingAs($user)
            ->get(route('admin.tesla-official-catalog.show', $commonItem))
            ->assertNotFound();
    }

    public function test_catalog_item_opened_through_wrong_source_redirects_to_real_catalog(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-wrong-catalog-item-source@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $item = PartCatalogItem::query()->create([
            'source' => 'toprazborka',
            'source_url' => 'https://toprazborka.example/catalog/1116121-00-c',
            'part_number' => '1116121-00-C',
            'name' => 'TopRazborka item',
        ]);

        $this->actingAs($user)
            ->get(route('admin.tesla-official-catalog.show', $item))
            ->assertRedirect(route('admin.toprazborka-catalog.show', $item));
    }

    public function test_tesla_official_catalog_shows_merged_part_in_each_model_occurrence(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-tesla-official-merged@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $model3 = PartCatalogCategory::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/catalogs?catalogExternalReference=model-3-official',
            'depth' => 0,
            'name' => 'Model 3 06.2017 - 12.2023',
            'model_label' => 'Model 3 06.2017 - 12.2023',
            'model_name' => 'Model 3',
        ]);

        $modelY = PartCatalogCategory::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/catalogs?catalogExternalReference=model-y-official',
            'depth' => 0,
            'name' => 'Model Y 01.2020 - 01.2025',
            'model_label' => 'Model Y 01.2020 - 01.2025',
            'model_name' => 'Model Y',
        ]);

        $model3Glass = PartCatalogCategory::query()->create([
            'parent_id' => $model3->id,
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/catalogs?catalogExternalReference=model-3-official&systemGroupExternalReference=glass',
            'depth' => 1,
            'name' => 'Glass',
            'model_label' => 'Model 3 06.2017 - 12.2023',
            'model_name' => 'Model 3',
        ]);

        $modelYGlass = PartCatalogCategory::query()->create([
            'parent_id' => $modelY->id,
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/catalogs?catalogExternalReference=model-y-official&systemGroupExternalReference=glass',
            'depth' => 1,
            'name' => 'Glass',
            'model_label' => 'Model Y 01.2020 - 01.2025',
            'model_name' => 'Model Y',
        ]);

        $item = PartCatalogItem::query()->create([
            'part_catalog_category_id' => $model3Glass->id,
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/catalogs?catalogExternalReference=model-3-official&systemGroupExternalReference=glass&partNumber=1466270-60-B',
            'part_number' => '1466270-60-B',
            'name' => 'WINDSHIELD ASSEMBLY',
            'name_en' => 'WINDSHIELD ASSEMBLY',
            'model_label' => 'Model 3 06.2017 - 12.2023',
            'model_name' => 'Model 3',
            'compatibility_text' => 'Model 3 06.2017 - 12.2023, Model Y 01.2020 - 01.2025',
            'raw_attributes' => [
                'official_catalog_occurrences' => [
                    [
                        'category_id' => $model3Glass->id,
                        'model_label' => 'Model 3 06.2017 - 12.2023',
                    ],
                    [
                        'category_id' => $modelYGlass->id,
                        'model_label' => 'Model Y 01.2020 - 01.2025',
                    ],
                ],
            ],
        ]);

        $vinSpecificDuplicate = PartCatalogItem::query()->create([
            'part_catalog_category_id' => $modelYGlass->id,
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/catalogs?catalogExternalReference=model-y-official&systemGroupExternalReference=glass&partNumber=1466270-60-B&vin=5YJ3E1EB8JF091651',
            'part_number' => '1466270-60-B',
            'name' => 'WINDSHIELD ASSEMBLY',
            'name_en' => 'WINDSHIELD ASSEMBLY',
            'model_label' => 'Model Y 01.2020 - 01.2025',
            'model_name' => 'Model Y',
        ]);

        $modelYPath = Str::slug($modelY->name).'-'.$modelY->id.'/'.Str::slug($modelYGlass->name).'-'.$modelYGlass->id;

        $this->actingAs($user)
            ->get(route('admin.tesla-official-catalog.category', ['catalogPath' => $modelYPath]))
            ->assertOk()
            ->assertSee('WINDSHIELD ASSEMBLY')
            ->assertSee(route('admin.tesla-official-catalog.show', $item), false)
            ->assertSee('Model Y 01.2020 - 01.2025');

        $response = $this->actingAs($user)
            ->get(route('admin.tesla-official-catalog.index', [
                'q' => '1466270-60-B',
                'model_filter' => 1,
                'models' => ['Model Y 01.2020 - 01.2025'],
            ]))
            ->assertOk()
            ->assertSee('WINDSHIELD ASSEMBLY')
            ->assertSee('Model Y 01.2020 - 01.2025')
            ->assertDontSee(route('admin.tesla-official-catalog.show', $vinSpecificDuplicate), false);

    }

    public function test_dkparts_catalog_uses_separate_navigation(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $tcarsModel = PartCatalogCategory::query()->create([
            'source' => 'tcarservice',
            'source_url' => 'https://tcarservice.com/zapchasty/model-3-326',
            'depth' => 0,
            'name' => 'Model 3 06.2017 - 12.2023',
            'model_label' => 'Model 3 06.2017 - 12.2023',
            'model_name' => 'Model 3',
        ]);

        PartCatalogCategory::query()->create([
            'parent_id' => $tcarsModel->id,
            'source' => 'tcarservice',
            'source_url' => 'https://tcarservice.com/zapchasty/model-3-326/10-body-2124',
            'depth' => 1,
            'code' => '10',
            'name' => 'Body',
            'model_label' => 'Model 3 06.2017 - 12.2023',
            'model_name' => 'Model 3',
        ]);

        $model = PartCatalogCategory::query()->create([
            'source' => 'dkparts',
            'source_url' => 'https://dk-parts.com.ua/ru/model-3/',
            'depth' => 0,
            'name' => 'Model 3 06.2017 - 12.2023',
            'model_label' => 'Model 3 06.2017 - 12.2023',
            'model_name' => 'Model 3',
        ]);

        $body = PartCatalogCategory::query()->create([
            'parent_id' => $model->id,
            'source' => 'dkparts',
            'source_url' => 'https://dk-parts.com.ua/ru/model-3/10-body-model-3-parts/',
            'depth' => 1,
            'code' => '10',
            'name' => 'Body',
            'model_label' => 'Model 3 06.2017 - 12.2023',
            'model_name' => 'Model 3',
        ]);

        PartCatalogCategory::query()->create([
            'parent_id' => $body->id,
            'source' => 'dkparts',
            'source_url' => 'https://dk-parts.com.ua/ru/model-3/10-body-model-3-parts/1001-bumper-and-fascia-model-3-parts/',
            'depth' => 2,
            'code' => '41001',
            'name' => 'Bumper and Fascia',
            'model_label' => 'Model 3 06.2017 - 12.2023',
            'model_name' => 'Model 3',
        ]);

        $this->actingAs($user)
            ->get(route('admin.dkparts-catalog.index', ['category_id' => $model->id]))
            ->assertOk()
            ->assertSee('Body')
            ->assertSee(route('admin.dkparts-catalog.category', [
                'catalogPath' => 'model-3-326/10-body-2124',
            ]), false);

        $this->actingAs($user)
            ->get(route('admin.dkparts-catalog.category', ['catalogPath' => 'model-3-326/10-body-2124']))
            ->assertOk()
            ->assertSee('Body');
    }

    public function test_tesla_official_show_hides_ru_name_source_when_name_is_manual(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-manual-source@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $item = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=238322',
            'part_number' => '238322',
            'name' => 'ASY,HVBAT,82KWH',
            'name_ru' => 'Основная батарея 82 кВт',
            'name_ru_manually_locked_at' => now(),
            'raw_attributes' => [
                'name_source_site_ru' => 'erazborka.com',
                'name_source_url_ru' => 'https://erazborka.com/ua/catalog/main-battery',
            ],
        ]);

        $this->actingAs($user)
            ->get(route('admin.tesla-official-catalog.show', $item))
            ->assertOk()
            ->assertSee('Основная батарея 82 кВт')
            ->assertSee('Вручную')
            ->assertDontSee('erazborka.com')
            ->assertDontSee('https://erazborka.com/ua/catalog/main-battery', false);
    }

    public function test_tesla_official_show_links_each_localized_name_to_one_language_source(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-erazborka-localized-source@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $sourceItem = PartCatalogItem::query()->create([
            'source' => 'erazborka',
            'source_url' => 'https://erazborka.com.ua/catalog/front-cover',
            'part_number' => '240080',
            'name' => "\u{041A}\u{0440}\u{044B}\u{0448}\u{043A}\u{0430}",
            'name_ru' => "\u{041A}\u{0440}\u{044B}\u{0448}\u{043A}\u{0430}",
            'name_ua' => "\u{041A}\u{0440}\u{0438}\u{0448}\u{043A}\u{0430}",
            'raw_attributes' => [
                'source_url_ru' => 'https://erazborka.com.ua/catalog/front-cover',
                'source_url_ua' => 'https://erazborka.com.ua/ua/catalog/front-cover',
            ],
        ]);

        $item = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=240080',
            'part_number' => '240080',
            'name' => 'FRONT COVER',
            'name_ru' => $sourceItem->name_ru,
            'name_ua' => $sourceItem->name_ua,
            'raw_attributes' => [
                'name_source_site_ru' => 'erazborka.com',
                'name_source_url_ru' => 'https://erazborka.com.ua/catalog/front-cover',
                'name_source_item_id_ru' => $sourceItem->id,
                'name_source_site_ua' => 'erazborka.com',
                'name_source_url_ua' => 'https://erazborka.com.ua/ua/catalog/front-cover',
                'name_source_item_id_ua' => $sourceItem->id,
            ],
        ]);

        $response = $this->actingAs($user)
            ->get(route('admin.tesla-official-catalog.show', $item))
            ->assertOk()
            ->assertSee('<a class="tag" href="https://erazborka.com.ua/catalog/front-cover" target="_blank" rel="noopener">erazborka.com.ua</a>', false)
            ->assertSee('<a class="tag" href="https://erazborka.com.ua/ua/catalog/front-cover" target="_blank" rel="noopener">erazborka.com.ua</a>', false);

        $this->assertSame(1, substr_count($response->getContent(), 'href="https://erazborka.com.ua/catalog/front-cover"'));
        $this->assertSame(1, substr_count($response->getContent(), 'href="https://erazborka.com.ua/ua/catalog/front-cover"'));
    }

    public function test_translation_backfill_does_not_copy_russian_fallback_into_ukrainian_name(): void
    {
        $official = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/1044343-00-l',
            'part_number' => '1044343-00-L',
            'name' => 'BUSHING',
            'name_en' => 'BUSHING',
            'name_ru' => null,
            'name_ua' => null,
        ]);

        $ruName = $this->utf8('d0a1d0b0d0b9d0bbd0b5d0bdd182d0b1d0bbd0bed0ba20d180d18bd187d0b0d0b3d0b02028d0bdd0b8d0b6d0bdd0b5d0b3d0be29');
        $ukAuto = $ruName.' '.pack('H*', '28d0b0d0b2d182d0bed0bfd0b5d180d0b5d0b2d0bed0b429');

        PartCatalogItem::query()->create([
            'source' => 'dkparts',
            'source_url' => 'https://dk-parts.com.ua/ru/104434300l',
            'part_number' => '1044343-00-L',
            'name' => $ruName,
            'name_ru' => $ruName,
            'name_ua' => $ukAuto,
        ]);

        app(PartCatalogTranslationBackfiller::class)->refresh([
            'part_numbers' => ['1044343-00-L'],
        ]);

        $official->refresh();

        $this->assertSame($ruName, $official->name_ru);
        $this->assertNull($official->name_ua);
    }

    public function test_translation_backfill_uses_russian_source_priority(): void
    {
        $official = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/1044343-00-l',
            'part_number' => '1044343-00-L',
            'name' => 'BUSHING',
            'name_en' => 'BUSHING',
            'name_ru' => null,
            'name_ua' => null,
        ]);

        $shortName = $this->utf8('d0a1d0b0d0b9d0bbd0b5d0bdd182d0b1d0bbd0bed0ba20d180d18bd187d0b0d0b3d0b02028d0bdd0b8d0b6d0bdd0b5d0b3d0be29');
        $completeName = $this->utf8('d0a1d0b0d0b9d0bbd0b5d0bdd182d0b1d0bbd0bed0ba20d0b2d0bdd183d182d180d0b5d0bdd0bdd0b8d0b920d0b7d0b0d0b4d0bdd0b5d0b3d0be20d180d18bd187d0b0d0b3d0b02028d0bdd0b8d0b6d0bdd0b5d0b3d0be29205465736c61204d6f64656c205920313034343334332d30302d4c20d0bed180d0b8d0b3d0b8d0bdd0b0d0bb');

        PartCatalogItem::query()->create([
            'source' => 'dkparts',
            'source_url' => 'https://dk-parts.com.ua/ru/104434300l',
            'part_number' => '1044343-00-L',
            'name' => $shortName,
            'name_ru' => $shortName,
        ]);

        PartCatalogItem::query()->create([
            'source' => 'teslacompany',
            'source_url' => 'https://teslacompany.com.ua/104434300l',
            'part_number' => '1044343-00-L',
            'name' => $completeName,
            'name_ru' => $completeName,
        ]);

        app(PartCatalogTranslationBackfiller::class)->refresh([
            'part_numbers' => ['1044343-00-L'],
        ]);

        $this->assertSame(PartCatalogLocalizedNameCleaner::clean($completeName), $official->refresh()->name_ru);
    }

    public function test_translation_backfill_respects_source_locale_policy(): void
    {
        $teslaPartsUkraineOfficial = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/1492614-00-c',
            'part_number' => '1492614-00-C',
            'name' => 'LINER',
            'name_en' => 'LINER',
        ]);

        $ukName = $this->utf8('d09fd196d0b4d0bad180d0b8d0bbd0bed0ba20d0bfd0b5d180d0b5d0b4d0bdd196d0b920d0bbd196d0b2d0b8d0b9205465736c61204d6f64656c2059');
        $ruName = $this->utf8('d09fd0bed0b4d0bad180d18bd0bbd0bed0ba20d0bfd0b5d180d0b5d0b4d0bdd0b8d0b920d0bbd0b5d0b2d18bd0b9205465736c61204d6f64656c20592028d0b0d0b2d182d0bed0bfd0b5d180d0b5d0b2d0bed0b429');

        PartCatalogItem::query()->create([
            'source' => 'teslapartsukraine',
            'source_url' => 'https://teslapartsukraine.com.ua/1492614-00-c',
            'part_number' => '1492614-00-C',
            'name' => $ukName,
            'name_ua' => $ukName,
        ]);

        $teslaWestOfficial = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/1492615-00-c',
            'part_number' => '1492615-00-C',
            'name' => 'LINER',
            'name_en' => 'LINER',
        ]);

        PartCatalogItem::query()->create([
            'source' => 'teslawestparts',
            'source_url' => 'https://teslawestparts.com.ua/1492615-00-c',
            'part_number' => '1492615-00-C',
            'name' => $ukName,
            'name_ru' => $ruName,
            'name_ua' => $ukName,
        ]);

        app(PartCatalogTranslationBackfiller::class)->refresh([
            'part_numbers' => ['1492614-00-C', '1492615-00-C'],
        ]);

        $this->assertSame(PartCatalogLocalizedNameCleaner::clean($ukName), $teslaPartsUkraineOfficial->refresh()->name_ua);
        $this->assertNull($teslaPartsUkraineOfficial->name_ru);
        $this->assertSame(PartCatalogLocalizedNameCleaner::clean($ukName), $teslaWestOfficial->refresh()->name_ua);
        $this->assertNull($teslaWestOfficial->name_ru);
    }

    public function test_translation_backfill_uses_tsk_mixed_language_names_by_detected_language(): void
    {
        $ukOfficial = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/1111111-00-a',
            'part_number' => '1111111-00-A',
            'name' => 'BRACKET',
            'name_en' => 'BRACKET',
        ]);

        $ukName = $this->utf8('d09ad180d0bed0bdd188d182d0b5d0b9d0bd20d0bfd0b5d180d0b5d0b4d0bdd196d0b920d0bbd196d0b2d0b8d0b9205465736c61204d6f64656c2059');
        $ruName = $this->utf8('d09ad180d0b5d0bfd0bbd0b5d0bdd0b8d0b520d0bfd0b5d180d0b5d0b4d0bdd0b5d0b520d0bbd0b5d0b2d0bed0b5205465736c61204d6f64656c2059');

        PartCatalogItem::query()->create([
            'source' => 'tsk',
            'source_url' => 'https://tsk.ua/1111111-00-a',
            'part_number' => '1111111-00-A',
            'name' => $ukName,
            'name_ru' => $ukName,
        ]);

        $ruOfficial = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/2222222-00-b',
            'part_number' => '2222222-00-B',
            'name' => 'BRACKET',
            'name_en' => 'BRACKET',
        ]);

        PartCatalogItem::query()->create([
            'source' => 'tsk',
            'source_url' => 'https://tsk.ua/2222222-00-b',
            'part_number' => '2222222-00-B',
            'name' => $ruName,
            'name_ru' => $ruName,
        ]);

        app(PartCatalogTranslationBackfiller::class)->refresh([
            'part_numbers' => ['1111111-00-A', '2222222-00-B'],
        ]);

        $this->assertSame(PartCatalogLocalizedNameCleaner::clean($ukName), $ukOfficial->refresh()->name_ua);
        $this->assertNull($ukOfficial->name_ru);
        $this->assertSame(PartCatalogLocalizedNameCleaner::clean($ruName), $ruOfficial->refresh()->name_ru);
        $this->assertNull($ruOfficial->name_ua);
    }

    public function test_catalog_translation_backfill_ignores_tsk_sorting_labels(): void
    {
        $topSales = $this->utf8('d0a2d0bed0bf20d0bfd180d0bed0b4d0b0d0b6');
        $clipName = $this->utf8('d09cd0bdd0bed0b3d0bed184d183d0bdd0bad186d0b8d0bed0bdd0b0d0bbd18cd0bdd0b0d18f20d0bad0bbd0b8d0bfd181d0b0');

        PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/catalogs?partNumber=1003209-00-A',
            'part_number' => '1003209-00-A',
            'name' => 'GROM - SCREW - M5 SELF TAP - 0.8 - 2.5 - 7MM SQ',
            'name_en' => 'GROM - SCREW - M5 SELF TAP - 0.8 - 2.5 - 7MM SQ',
            'model_label' => 'Model 3 06.2017 - 12.2023',
            'model_name' => 'Model 3',
        ]);

        PartCatalogItem::query()->create([
            'source' => 'tsk',
            'source_url' => 'https://tsk.ua/mehanizmy-zakrytiya-i-petli1682/topsales-desc/',
            'part_number' => '1003209-00-A',
            'name' => $topSales,
            'name_ru' => $topSales,
        ]);

        PartCatalogItem::query()->create([
            'source' => 'tsk',
            'source_url' => 'https://tsk.ua/1003209-00-a/',
            'part_number' => '1003209-00-A',
            'name' => $clipName,
            'name_ru' => $clipName,
        ]);

        app(PartCatalogTranslationBackfiller::class)->refresh(['only_missing' => false]);

        $this->assertDatabaseHas('part_catalog_items', [
            'source' => 'tesla_official',
            'part_number' => '1003209-00-A',
            'name_ru' => $clipName,
        ]);

        $this->assertDatabaseMissing('part_catalog_items', [
            'source' => 'tesla_official',
            'part_number' => '1003209-00-A',
            'name_ru' => $topSales,
        ]);
    }

    public function test_catalog_translation_backfill_ignores_teslahelp_russian_names(): void
    {
        $ruName = $this->utf8('d09ad180d0bed0bdd188d182d0b5d0b9d0bd20d0bad180d0b5d0bfd0bbd0b5d0bdd0b8d18f20d0b2d0b5d180d185d0bdd0b5d0b920d0bfd0bbd0b0d181d182d0b8d0bdd18b20d0b0d0bad0bad183d0bcd183d0bbd18fd182d0bed180d0b020313256');

        PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/catalogs?partNumber=1118599-00-C',
            'part_number' => '1118599-00-C',
            'name' => '12V BATTERY, UPPER TIE-DOWN BRACKET',
            'name_en' => '12V BATTERY, UPPER TIE-DOWN BRACKET',
            'model_label' => 'Model 3 06.2017 - 12.2023',
            'model_name' => 'Model 3',
        ]);

        PartCatalogItem::query()->create([
            'source' => 'teslahelp',
            'source_url' => 'teslahelp:e1466fb4623596b99441baec77bc1f5a',
            'part_number' => '1118599-00-C',
            'name' => $ruName,
            'name_ru' => $ruName,
        ]);

        app(PartCatalogTranslationBackfiller::class)->refresh(['only_missing' => false]);

        $this->assertDatabaseMissing('part_catalog_items', [
            'source' => 'tesla_official',
            'part_number' => '1118599-00-C',
            'name_ru' => $ruName,
        ]);
    }

    public function test_teslahelp_name_source_is_not_used_for_translation_backfill(): void
    {
        $ruName = $this->utf8('d09ad180d0bed0bdd188d182d0b5d0b9d0bd20d0bad180d0b5d0bfd0bbd0b5d0bdd0b8d18f20d0b2d0b5d180d185d0bdd0b5d0b920d0bfd0bbd0b0d181d182d0b8d0bdd18b20d0b0d0bad0bad183d0bcd183d0bbd18fd182d0bed180d0b020313256');

        PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/catalogs?partNumber=1118599-00-C',
            'part_number' => '1118599-00-C',
            'name' => '12V BATTERY, UPPER TIE-DOWN BRACKET',
            'name_en' => '12V BATTERY, UPPER TIE-DOWN BRACKET',
            'model_label' => 'Model 3 06.2017 - 12.2023',
            'model_name' => 'Model 3',
        ]);

        PartCatalogItem::query()->create([
            'source' => 'teslahelp',
            'source_url' => 'teslahelp:e1466fb4623596b99441baec77bc1f5a',
            'part_number' => '1118599-00-C',
            'name' => $ruName,
            'name_ru' => $ruName,
            'raw_attributes' => [
                'teslashop_url' => 'https://teslashop.ru/auto-parts/mark_tesla?number=1118599',
                'teslahelp_page_url' => 'https://teslahelp.ru/catalog/12v-battery/index.html',
            ],
        ]);

        app(PartCatalogTranslationBackfiller::class)->refresh(['only_missing' => false]);

        $officialItem = PartCatalogItem::query()
            ->where('source', 'tesla_official')
            ->where('part_number', '1118599-00-C')
            ->firstOrFail();

        $this->assertNull($officialItem->name_ru);
        $this->assertArrayNotHasKey('name_source_site_ru', $officialItem->raw_attributes ?? []);
        $this->assertArrayNotHasKey('name_source_url_ru', $officialItem->raw_attributes ?? []);
    }

    public function test_teslahelp_item_source_link_prefers_teslashop_url(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $teslaHelpItem = PartCatalogItem::query()->create([
            'source' => 'teslahelp',
            'source_url' => 'teslahelp:e1466fb4623596b99441baec77bc1f5a',
            'part_number' => '6007610-00-A',
            'name' => 'Air conditioning condenser',
            'name_ru' => 'Air conditioning condenser RU',
            'raw_attributes' => [
                'teslashop_url' => 'https://teslashop.ru/auto-parts/mark_tesla?number=6007610',
                'teslahelp_page_url' => 'https://teslahelp.ru/catalog/air-conditioning-condenser/index.html',
            ],
        ]);

        $this->actingAs($user)
            ->get(route('admin.competitors-ru.show', $teslaHelpItem))
            ->assertOk()
            ->assertSee('https://teslashop.ru/auto-parts/mark_tesla?number=6007610', false)
            ->assertDontSee('https://teslahelp.ru/catalog/air-conditioning-condenser/index.html', false);
    }

    public function test_catalog_translation_backfill_uses_teslawestparts_ukrainian_names(): void
    {
        $ukName = $this->utf8('d09fd0b5d180d0b5d0b4d0bdd196d0b920d0bfd196d0b4d0bad180d0b8d0bbd0bed0ba20d0bfd180d0b0d0b2d0b8d0b9205465736c61204d6f64656c20592c20313439323631342d30302d43');

        PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/catalogs?partNumber=1492614-00-C',
            'part_number' => '1492614-00-C',
            'name' => 'FRONT WHEEL ARCH LINER',
            'name_en' => 'FRONT WHEEL ARCH LINER',
            'name_ru' => null,
            'name_ua' => null,
            'model_label' => 'Model Y 01.2020 - 01.2025',
            'model_name' => 'Model Y',
        ]);

        PartCatalogItem::query()->create([
            'source' => 'teslawestparts',
            'source_url' => 'https://teslawestparts.com.ua/goods/podkrylok-peredniy-tesla-model-y-1492614-00-c/',
            'part_number' => '1492614-00-C',
            'name' => $ukName,
            'name_ua' => $ukName,
        ]);

        app(PartCatalogTranslationBackfiller::class)->refresh();

        $this->assertDatabaseHas('part_catalog_items', [
            'source' => 'tesla_official',
            'part_number' => '1492614-00-C',
            'name_ua' => PartCatalogLocalizedNameCleaner::clean($ukName),
            'name_ru' => null,
        ]);

        $officialItem = PartCatalogItem::query()
            ->where('source', 'tesla_official')
            ->where('part_number', '1492614-00-C')
            ->firstOrFail();

        $this->assertSame('teslawestparts.com.ua', $officialItem->raw_attributes['name_source_site_ua']);
        $this->assertSame(
            'https://teslawestparts.com.ua/goods/podkrylok-peredniy-tesla-model-y-1492614-00-c/',
            $officialItem->raw_attributes['name_source_url_ua'],
        );
    }

    public function test_catalog_translation_backfill_uses_base_part_number_when_revision_differs(): void
    {
        $ukName = $this->utf8('d09fd0b5d180d0b5d0b4d0bdd18f20d0bfd0b0d0bdd0b5d0bbd18c20d0b1d0b0d0bcd0bfd0b5d180d0b0205465736c61204d6f64656c205920363030373730362d30302d43');

        PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/catalogs?partNumber=6007706-00-D',
            'part_number' => '6007706-00-D',
            'name' => 'FRONT FASCIA ASSEMBLY',
            'name_en' => 'FRONT FASCIA ASSEMBLY',
            'name_ru' => null,
            'name_ua' => null,
            'model_label' => 'Model Y 01.2020 - 01.2025',
            'model_name' => 'Model Y',
        ]);

        PartCatalogItem::query()->create([
            'source' => 'teslawestparts',
            'source_url' => 'https://teslawestparts.com.ua/goods/front-fascia-6007706-00-c/',
            'part_number' => '6007706-00-C',
            'name' => $ukName,
            'name_ua' => $ukName,
        ]);

        app(PartCatalogTranslationBackfiller::class)->refresh();

        $this->assertDatabaseHas('part_catalog_items', [
            'source' => 'tesla_official',
            'part_number' => '6007706-00-D',
            'name_ua' => PartCatalogLocalizedNameCleaner::clean($ukName),
        ]);
    }

    public function test_catalog_translation_backfill_uses_root_part_number_when_base_is_missing(): void
    {
        $ruName = $this->utf8('d09fd0b5d180d0b5d0b4d0bdd0b8d0b920d0b1d0b0d0bcd0bfd0b5d180205465736c61204d6f64656c2059');

        PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/catalogs?partNumber=6007706-00-D',
            'part_number' => '6007706-00-D',
            'name' => 'FRONT FASCIA ASSEMBLY',
            'name_en' => 'FRONT FASCIA ASSEMBLY',
            'name_ru' => null,
            'name_ua' => null,
            'model_label' => 'Model Y 01.2020 - 01.2025',
            'model_name' => 'Model Y',
        ]);

        PartCatalogItem::query()->create([
            'source' => 'tsk',
            'source_url' => 'https://tsk.ua/6007706/',
            'part_number' => '6007706',
            'name' => $ruName,
            'name_ru' => $ruName,
        ]);

        app(PartCatalogTranslationBackfiller::class)->refresh();

        $this->assertDatabaseHas('part_catalog_items', [
            'source' => 'tesla_official',
            'part_number' => '6007706-00-D',
            'name_ru' => PartCatalogLocalizedNameCleaner::clean($ruName),
        ]);
    }

    public function test_stock_tesla_importer_backfills_missing_ru_name_from_product_page(): void
    {
        $ruName = $this->utf8('3620d09ad180d0b5d0bfd0bbd0b5d0bdd0b8d0b520d0b2d0b5d180d185d0bdd0b5d0b920d0bfd0bbd0b0d181d182d0b8d0bdd18b20d0b0d0bad0bad183d0bcd183d0bbd18fd182d0bed180d0b0205465736c61206d6f64656c203320313131383539392d30302d43');

        PartCatalogItem::query()->create([
            'source' => 'stock-tesla',
            'source_url' => 'https://stock-tesla.com/product/1118599-00-c/',
            'part_number' => '1118599-00-C',
            'name' => '12V BATTERY, UPPER TIE-DOWN BRACKET',
        ]);

        Http::fake([
            'https://stock-tesla.test/ru/product/1118599-00-c/' => Http::response(<<<HTML
                <!doctype html>
                <html>
                    <head>
                        <meta property="og:title" content="{$ruName} | Stock Tesla">
                    </head>
                    <body></body>
                </html>
                HTML),
        ]);

        $stats = app(StockTeslaCatalogImporter::class)->backfillMissingRussianNames([
            'base_url' => 'https://stock-tesla.test',
            'sleep_ms' => 0,
        ]);

        $this->assertSame(1, $stats['russian_pages_fetched']);
        $this->assertSame(1, $stats['names_updated']);
        $this->assertDatabaseHas('part_catalog_items', [
            'source' => 'stock-tesla',
            'part_number' => '1118599-00-C',
        ]);

        $stockItem = PartCatalogItem::query()
            ->where('source', 'stock-tesla')
            ->where('part_number', '1118599-00-C')
            ->firstOrFail();

        $this->assertStringStartsWith('6 ', $stockItem->name_ru);
        $this->assertStringNotContainsString('Tesla model 3', $stockItem->name_ru);
        $this->assertStringNotContainsString('1118599-00-C', $stockItem->name_ru);
        $this->assertStringNotContainsString('| Stock Tesla', $stockItem->name_ru);
    }

    public function test_stock_tesla_importer_ignores_trailing_standalone_x_after_tesla_part_numbers(): void
    {
        $importer = app(StockTeslaCatalogImporter::class);
        $method = new \ReflectionMethod($importer, 'canonicalPartNumber');
        $method->setAccessible(true);

        $this->assertSame('1188711-00-A', $method->invoke($importer, '1188711-00-A x'));
        $this->assertSame('1007821-00-X', $method->invoke($importer, '1007821-00-X X'));
        $this->assertSame('1008856-00-C CARBON X', $method->invoke($importer, '1008856-00-C CARBON X'));
    }

    public function test_teslapartsukraine_importer_normalizes_model_generations(): void
    {
        $importer = app(TeslaPartsUkraineCatalogImporter::class);
        $method = new \ReflectionMethod($importer, 'canonicalModel');
        $method->setAccessible(true);

        $this->assertSame(
            ['Model S Apr 2016 - Jan 2021', 'Model S', 2016, 2021],
            $method->invoke($importer, 'Model S Apr 2016 - Jan 2021')
        );

        $this->assertSame(
            ['Model X Mar 2021', 'Model X', 2021, null],
            $method->invoke($importer, 'Model X Mar 2021')
        );
    }

    public function test_teslapartsukraine_catalog_item_prefers_buy_url_over_schematic_url(): void
    {
        $item = new PartCatalogItem([
            'source' => 'teslapartsukraine',
            'source_url' => 'https://teslapartsukraine.com.ua/index.php?route=tesla/catalog/product&tesla_category_id=3#row',
            'part_number' => '2007021',
            'raw_attributes' => [
                'buy_url' => 'https://teslapartsukraine.com.ua/index.php?route=product/search&search=2007021',
            ],
        ]);

        $controller = app(PartCatalogController::class);
        $method = new \ReflectionMethod($controller, 'displayableSourceUrl');
        $method->setAccessible(true);

        $this->assertSame(
            'https://teslapartsukraine.com.ua/index.php?route=product/search&search=2007021',
            $method->invoke($controller, $item)
        );
    }

    public function test_teslapartsukraine_importer_reuses_existing_item_by_product_url_when_row_hash_changes(): void
    {
        $oldItem = PartCatalogItem::query()->create([
            'source' => 'teslapartsukraine',
            'source_url' => 'https://teslapartsukraine.com.ua/index.php?route=tesla/catalog/product&tesla_category_id=3#e2d5c53defe4a6a06883cc6be4fb31dd',
            'part_number' => '1006201-00-B',
            'name' => 'CONDENSER BRACKET',
            'scheme_number' => 7,
            'raw_attributes' => [
                'buy_url' => 'https://teslapartsukraine.com.ua/tesla?product_id=7454',
                'product_url' => 'https://teslapartsukraine.com.ua/tesla?product_id=7454',
                'quantity' => '4',
            ],
        ]);

        $importer = app(TeslaPartsUkraineCatalogImporter::class);
        $method = new \ReflectionMethod($importer, 'persistPartRow');
        $method->setAccessible(true);

        $item = $method->invoke(
            $importer,
            null,
            [
                'model_label' => 'Model S 02.2012-03.2016',
                'model_name' => 'Model S',
                'year_from' => 2012,
                'year_to' => 2016,
                'main_category_code' => '10',
                'main_category_name' => '?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ??пїЅ?пїЅ?пїЅ?пїЅ??пїЅ',
                'subcategory_code' => '1001',
                'subcategory_name' => '?пїЅ????пїЅ?пїЅ?пїЅ???пїЅ???пїЅ????пїЅ?пїЅ ????пїЅ ?пїЅ?пїЅ?пїЅ????пїЅ????пїЅ???пїЅ?пїЅ?пїЅ?пїЅ?пїЅ??пїЅ?пїЅ?пїЅ?пїЅ???пїЅ???пїЅ?пїЅ ?пїЅ????пїЅ?пїЅ?пїЅ???пїЅ???пїЅ????пїЅ?пїЅ?пїЅ?пїЅ',
            ],
            '?пїЅ???пїЅ?пїЅ???пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ????пїЅ?пїЅ?пїЅпїЅ ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ???пїЅ?пїЅ?пїЅ?пїЅ???пїЅ (?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ????пїЅ???пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ????) ????????пїЅ?пїЅ?пїЅ?пїЅ???пїЅ?пїЅ????? - ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ???пїЅ?пїЅ?пїЅ ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ????пїЅ?пїЅ?пїЅпїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅпїЅ',
            'https://teslapartsukraine.com.ua/index.php?route=tesla/catalog/product&tesla_category_id=3',
            [
                'scheme_number' => 7,
                'name' => '?пїЅ?пїЅ???пїЅ?пїЅ?пїЅ?пїЅ?пїЅ???пїЅпїЅ??????пїЅ?пїЅ?пїЅ?пїЅпїЅ?пїЅ?пїЅ ?пїЅ?пїЅ???пїЅ????пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ???? ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ??????пїЅ?пїЅ?пїЅ?пїЅ??????????пїЅ?пїЅ???пїЅ?пїЅ?пїЅ ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ????пїЅ????пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ???пїЅ?пїЅ?пїЅ Tesla Model S, SR 1006201-00-B ?пїЅ?пїЅ???пїЅ?пїЅ?пїЅ?пїЅ?пїЅ????пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ',
                'name_ua' => '?пїЅ?пїЅ???пїЅ?пїЅ?пїЅ?пїЅ?пїЅ???пїЅпїЅ??????пїЅ?пїЅ?пїЅ?пїЅпїЅ?пїЅ?пїЅ ?пїЅ?пїЅ???пїЅ????пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ???? ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ??????пїЅ?пїЅ?пїЅ?пїЅ??????????пїЅ?пїЅ???пїЅ?пїЅ?пїЅ ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ????пїЅ????пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ???пїЅ?пїЅ?пїЅ Tesla Model S, SR 1006201-00-B ?пїЅ?пїЅ???пїЅ?пїЅ?пїЅ?пїЅ?пїЅ????пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ?пїЅ',
                'part_number' => '1006201-00-B',
                'quantity' => '4',
                'buy_url' => 'https://teslapartsukraine.com.ua/tesla?product_id=7454',
                'product_url' => 'https://teslapartsukraine.com.ua/tesla?product_id=7454',
                'schematic_name' => 'CONDENSER BRACKET',
            ],
        );

        $this->assertSame($oldItem->id, $item->id);
        $this->assertSame(1, PartCatalogItem::query()->where('source', 'teslapartsukraine')->count());
        $this->assertStringStartsWith('https://teslapartsukraine.com.ua/index.php?route=tesla/catalog/product&tesla_category_id=3#', $item->fresh()->source_url);
        $this->assertMatchesRegularExpression('/#[a-f0-9]{32}$/', $item->fresh()->source_url);

    }

    public function test_teslapartsukraine_importer_reuses_existing_item_by_search_url_when_row_hash_changes(): void
    {
        $oldItem = PartCatalogItem::query()->create([
            'source' => 'teslapartsukraine',
            'source_url' => 'https://teslapartsukraine.com.ua/index.php?route=tesla/catalog/product&tesla_category_id=80#old',
            'part_number' => '1118556-10-B',
            'name' => '10-30 Gen II Smart Adapter',
            'scheme_number' => 2,
            'raw_attributes' => [
                'buy_url' => 'https://teslapartsukraine.com.ua/index.php?route=product/search&search=1118556-10-B',
                'quantity' => '1',
            ],
        ]);

        $importer = app(TeslaPartsUkraineCatalogImporter::class);
        $method = new \ReflectionMethod($importer, 'persistPartRow');
        $method->setAccessible(true);

        $item = $method->invoke(
            $importer,
            null,
            [
                'model_label' => 'Model 3 06.2017-12.2023',
                'model_name' => 'Model 3',
                'year_from' => 2017,
                'year_to' => 2023,
                'main_category_code' => '18',
                'main_category_name' => 'ELECTRICAL',
                'subcategory_code' => '1801',
                'subcategory_name' => 'CHARGING',
            ],
            'Charging accessories',
            'https://teslapartsukraine.com.ua/index.php?route=tesla/catalog/product&tesla_category_id=80',
            [
                'scheme_number' => 2,
                'name' => '10-30 Gen II Smart Adapter updated',
                'part_number' => '1118556-10-B',
                'quantity' => '1',
                'buy_url' => 'https://teslapartsukraine.com.ua/index.php?route=product/search&search=1118556-10-B',
            ],
        );

        $this->assertSame($oldItem->id, $item->id);
        $this->assertSame(1, PartCatalogItem::query()->where('source', 'teslapartsukraine')->count());
    }

    public function test_teslapartsukraine_importer_handles_rows_without_scheme_number(): void
    {
        $importer = app(TeslaPartsUkraineCatalogImporter::class);
        $method = new \ReflectionMethod($importer, 'persistPartRow');
        $method->setAccessible(true);

        $item = $method->invoke(
            $importer,
            null,
            [
                'model_label' => 'Model 3 06.2017-12.2023',
                'model_name' => 'Model 3',
                'year_from' => 2017,
                'year_to' => 2023,
                'main_category_code' => '18',
                'main_category_name' => 'ELECTRICAL',
                'subcategory_code' => '1801',
                'subcategory_name' => 'CHARGING',
            ],
            'Charging accessories',
            'https://teslapartsukraine.com.ua/index.php?route=tesla/catalog/product&tesla_category_id=80',
            [
                'name' => 'Adapter without scheme number',
                'part_number' => '1118556-10-B',
                'quantity' => '1',
                'buy_url' => 'https://teslapartsukraine.com.ua/index.php?route=product/search&search=1118556-10-B',
            ],
        );

        $this->assertSame('1118556-10-B', $item->part_number);
        $this->assertNull($item->scheme_number);
    }

    public function test_model_catalog_uses_canonical_generation_order(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        foreach ([
            'Model Y Juniper 02.2025 -',
            'Model 3 06.2017 - 12.2023',
            'Model S2 04.2016-01.2021',
            'Model S 02.2012-03.2016',
        ] as $label) {
            PartCatalogCategory::query()->create([
                'source' => 'tcarservice',
                'source_url' => 'https://tcarservice.com/zapchasty/'.str($label)->slug(),
                'depth' => 0,
                'name' => $label,
                'model_label' => $label,
                'model_name' => preg_replace('/\s+\d{2}\.\d{4}.*$/', '', $label),
            ]);
        }

        $this->actingAs($user)
            ->get(route('admin.part-catalog.index'))
            ->assertOk()
            ->assertSeeInOrder([
                'Model S 02.2012-03.2016',
                'Model S2 04.2016-01.2021',
                'Model 3 06.2017 - 12.2023',
                'Model Y Juniper 02.2025 -',
            ]);
    }
}
