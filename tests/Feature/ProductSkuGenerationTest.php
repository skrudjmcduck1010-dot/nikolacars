<?php

namespace Tests\Feature;

use App\Models\DonorCar;
use App\Models\Location;
use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Models\Product;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DonorProductLocalizedNameAutofillService;
use App\Services\NikolaCarsProductInventorySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductSkuGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_product_creation_ignores_submitted_sku(): void
    {
        $user = $this->adminUser();

        $response = $this->actingAs($user)->post(route('admin.products.store'), [
            'sku' => '1044341-00-D',
            'external_sku' => '1044341-00-D',
            'name' => '   ()',
            'slug' => 'front-control-arm',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'currency' => 'USD',
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('admin.products.index'));

        $product = Product::query()->firstOrFail();

        $this->assertSame('PRD-000001', $product->sku);
        $this->assertSame('1044341-00-D', $product->external_sku);
        $this->assertSame('PRD-000001', $product->barcode);
        $this->assertSame('PRD-000001', $product->qr_code);
    }

    public function test_product_update_keeps_existing_sku(): void
    {
        $user = $this->adminUser();
        $product = Product::query()->create([
            'sku' => 'PRD-000123',
            'external_sku' => 'OLD-ARTICLE',
            'name' => 'Old name',
            'slug' => 'old-name',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->put(route('admin.products.update', $product), [
            'sku' => '1044341-00-D',
            'external_sku' => '1044341-00-D',
            'name' => '   ()',
            'slug' => 'front-control-arm',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'currency' => 'USD',
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('admin.products.show', $product));

        $product->refresh();

        $this->assertSame('PRD-000123', $product->sku);
        $this->assertSame('1044341-00-D', $product->external_sku);
    }

    public function test_donor_product_update_relinks_catalog_source_when_article_changes(): void
    {
        $user = $this->adminUser();
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF702820',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
        ]);
        $oldItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=1000000-00-A',
            'part_number' => '1000000-00-A',
            'name' => 'Old Tesla part',
        ]);
        $newItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=1044341-00-D',
            'part_number' => '1044341-00-D',
            'name' => 'New Tesla part',
        ]);
        $product = Product::query()->create([
            'sku' => 'DON70282-0001',
            'external_sku' => '1000000-00-A',
            'name' => 'Donor article change product',
            'slug' => 'donor-article-change-product',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $oldItem->id,
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->put(route('admin.products.update', $product), [
            'external_sku' => '1044341-00-D',
            'name' => 'Donor article change product',
            'slug' => 'donor-article-change-product',
            'donor_car_id' => $donorCar->id,
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'currency' => 'USD',
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('admin.products.show', $product));

        $product->refresh();

        $this->assertSame('1044341-00-D', $product->external_sku);
        $this->assertSame($newItem->id, $product->source_part_catalog_item_id);
    }

    public function test_product_update_refreshes_nikolacars_catalog_projection(): void
    {
        $user = $this->adminUser();
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EB8JF091651',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2018,
        ]);
        $staleCatalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://donor-product/70282',
            'part_number' => '1081439-S0-A',
            'name' => 'PANEL - FRONT DOOR - LEFT HAND',
            'name_ru' => null,
            'name_ua' => "\u{0414}\u{0432}\u{0435}\u{0440}\u{0456} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0456} \u{043B}\u{0456}\u{0432}\u{0456}",
        ]);
        $product = Product::query()->create([
            'id' => 70282,
            'sku' => 'DON28-0973',
            'external_sku' => '1081439-S0-A',
            'name' => 'PANEL - FRONT DOOR - LEFT HAND',
            'slug' => 'panel-front-door-left-hand',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $staleCatalogItem->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'tested',
            'unit' => 'pcs',
            'selling_price' => 450,
            'currency' => 'USD',
            'notes' => NikolaCarsProductInventorySyncService::CHECKED_DAMAGE_STATUSES[1],
            'is_active' => true,
        ]);
        $staleCatalogItem->forceFill([
            'source_url' => 'nikolacars://donor-product/'.$product->id,
        ])->save();

        $response = $this->actingAs($user)->put(route('admin.products.update', $product), [
            'external_sku' => '1081421-E0-C',
            'name' => 'PANEL - FRONT DOOR - LEFT HAND',
            'slug' => 'panel-front-door-left-hand',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $staleCatalogItem->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'tested',
            'unit' => 'pcs',
            'selling_price' => 450,
            'currency' => 'USD',
            'notes' => NikolaCarsProductInventorySyncService::CHECKED_DAMAGE_STATUSES[1],
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('admin.products.show', $product));

        $product->refresh();
        $projection = $product->sourcePartCatalogItem;

        $this->assertSame('1081421-E0-C', $product->external_sku);
        $this->assertNotNull($projection);
        $this->assertSame('nikolacars', $projection->source);
        $this->assertSame('nikolacars://donor-product/'.$product->id, $projection->source_url);
        $this->assertSame($projection->id, $product->source_part_catalog_item_id);
        $this->assertDatabaseHas('part_catalog_items', [
            'id' => $projection->id,
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://donor-product/'.$product->id,
            'part_number' => '1081421-E0-C',
            'name_ru' => null,
            'price_amount' => 450,
        ]);
    }

    public function test_product_edit_records_damage_status_changed_user_for_donor_part(): void
    {
        $user = $this->adminUser();
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJPRODUCTUSER001',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2024,
            'status' => DonorCar::STATUS_AT_STO,
            'purchase_date' => '2026-04-01',
        ]);
        $unknown = "\u{041D}\u{0435}\u{0438}\u{0437}\u{0432}\u{0435}\u{0441}\u{0442}\u{043D}\u{043E}";
        $checked = NikolaCarsProductInventorySyncService::CHECKED_DAMAGE_STATUSES[0];
        $product = Product::query()->create([
            'sku' => 'DON-PRODUCT-USER',
            'external_sku' => 'PRODUCT-USER-001',
            'name' => 'Product edit status user part',
            'slug' => 'product-edit-status-user-part',
            'donor_car_id' => $donorCar->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'notes' => $unknown,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->put(route('admin.products.update', $product), [
                'external_sku' => 'PRODUCT-USER-001',
                'name' => 'Product edit status user part',
                'slug' => 'product-edit-status-user-part',
                'donor_car_id' => $donorCar->id,
                'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
                'condition_type' => 'used',
                'testing_status' => 'not_tested',
                'unit' => 'pcs',
                'selling_price' => 100,
                'currency' => 'USD',
                'notes' => $checked,
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.products.show', $product));

        $projection = $product->refresh()->sourcePartCatalogItem;

        $this->assertSame($user->id, $product->donor_damage_status_changed_by);
        $this->assertInstanceOf(PartCatalogItem::class, $projection);
        $this->assertSame($user->id, data_get($projection->raw_attributes, 'donor_damage_status_changed_by'));
    }

    public function test_manual_donor_don_sku_backfill_renames_only_manual_donor_codes_to_ron(): void
    {
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJSA1E19HF202779',
            'brand' => 'Tesla',
            'model' => 'Model S',
            'year' => 2017,
        ]);
        $manualDon = Product::query()->create([
            'sku' => 'DON'.$donorCar->id.'-1684-2',
            'barcode' => 'DON'.$donorCar->id.'-1684-2',
            'qr_code' => 'KEEP-CUSTOM-QR',
            'external_sku' => 'MANUAL-DONOR',
            'name' => 'Manual donor part',
            'slug' => 'manual-donor-part',
            'donor_car_id' => $donorCar->id,
            'is_auto_generated' => false,
            'generated_at' => null,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $autoDon = Product::query()->create([
            'sku' => 'DON'.$donorCar->id.'-0001',
            'external_sku' => 'AUTO-DONOR',
            'name' => 'Auto donor part',
            'slug' => 'auto-donor-part',
            'donor_car_id' => $donorCar->id,
            'is_auto_generated' => true,
            'generated_at' => now(),
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $ncProduct = Product::query()->create([
            'sku' => 'NC-580',
            'external_sku' => 'NC-ARTICLE',
            'name' => 'NC donor part',
            'slug' => 'nc-donor-part',
            'donor_car_id' => $donorCar->id,
            'is_auto_generated' => false,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $numericProduct = Product::query()->create([
            'sku' => '580',
            'external_sku' => 'NUMERIC-ARTICLE',
            'name' => 'Numeric donor part',
            'slug' => 'numeric-donor-part',
            'donor_car_id' => $donorCar->id,
            'is_auto_generated' => false,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->artisan('products:rename-manual-donor-skus-to-ron', ['--json' => true])
            ->assertSuccessful();

        $this->assertSame('DON'.$donorCar->id.'-1684-2', $manualDon->refresh()->sku);

        $this->artisan('products:rename-manual-donor-skus-to-ron', ['--apply' => true, '--json' => true])
            ->assertSuccessful();

        $this->assertSame('RON'.$donorCar->id.'-1684', $manualDon->refresh()->sku);
        $this->assertSame('RON'.$donorCar->id.'-1684', $manualDon->barcode);
        $this->assertSame('KEEP-CUSTOM-QR', $manualDon->qr_code);
        $this->assertSame('DON'.$donorCar->id.'-0001', $autoDon->refresh()->sku);
        $this->assertSame('NC-580', $ncProduct->refresh()->sku);
        $this->assertSame('580', $numericProduct->refresh()->sku);
    }

    public function test_product_show_page_renders_all_product_photos_and_upload_form(): void
    {
        $user = $this->adminUser();
        $product = Product::query()->create([
            'sku' => 'PRD-000124',
            'name' => 'Front bumper',
            'slug' => 'front-bumper',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'currency' => 'USD',
            'main_image' => 'product-photos/main.jpg',
            'images_json' => ['product-photos/main.jpg', 'product-photos/detail.jpg'],
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->get(route('admin.products.show', $product));

        $response
            ->assertOk()
            ->assertSee(Storage::url('product-photos/main.jpg'), false)
            ->assertSee(Storage::url('product-photos/detail.jpg'), false)
            ->assertSee(route('admin.products.photos.destroy', $product), false)
            ->assertSee(route('admin.products.photos.order', $product), false)
            ->assertSee('data-product-photo-delete-form', false)
            ->assertSee('data-product-photo-drag-handle', false)
            ->assertSee('data-photo="product-photos/main.jpg"', false)
            ->assertSee('action="'.route('admin.products.photos.store', $product).'"', false)
            ->assertSee('name="photos[]"', false);
    }

    public function test_product_show_page_renders_ru_name_source_badge(): void
    {
        $user = $this->adminUser();
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/catalogs/model-3/source-badge',
            'part_number' => 'SOURCE-BADGE-001',
            'name' => 'Source badge part',
            'name_ru' => 'Source badge RU',
            'raw_attributes' => [
                'name_source_site_ru' => 'aleto.ua',
                'name_source_url_ru' => 'https://aleto.ua/detail/source-badge/',
            ],
        ]);
        $product = Product::query()->create([
            'sku' => 'PRD-SOURCE-BADGE',
            'name' => 'Source badge product',
            'slug' => 'source-badge-product',
            'source_part_catalog_item_id' => $catalogItem->id,
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.products.show', $product))
            ->assertOk()
            ->assertSee('Source badge RU')
            ->assertSee('<a class="tag" href="https://aleto.ua/detail/source-badge/" target="_blank" rel="noopener">aleto.ua</a>', false);
    }

    public function test_product_show_page_renders_nikolacars_name_source_badges_from_nomenclature_urls(): void
    {
        $user = $this->adminUser();
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://donor-product/64497',
            'part_number' => '1494185-00-B',
            'name' => 'Wheel arch front left Tesla MY 2020 - 2023 1494185-00-B',
            'name_ru' => 'Wheel arch front left RU',
            'name_ua' => 'Wheel arch front left UA',
            'raw_attributes' => [
                'code' => '369',
                'nikolacars_source_url_ru' => 'https://nikolacars.com.ua/p2986669224-arka-kryla-perednyaya.html',
                'nikolacars_source_url_ua' => 'https://nikolacars.com.ua/ua/p2986669224-arka-kryla-perednyaya.html',
            ],
        ]);
        $product = Product::query()->create([
            'sku' => 'NC-369',
            'name' => 'Wheel arch front left Tesla MY 2020 - 2023 1494185-00-B',
            'slug' => 'wheel-arch-front-left',
            'source_part_catalog_item_id' => $catalogItem->id,
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.products.show', $product))
            ->assertOk()
            ->assertSee('<a class="tag" href="https://nikolacars.com.ua/p2986669224-arka-kryla-perednyaya.html" target="_blank" rel="noopener">nikolacars</a>', false)
            ->assertSee('<a class="tag" href="https://nikolacars.com.ua/ua/p2986669224-arka-kryla-perednyaya.html" target="_blank" rel="noopener">nikolacars</a>', false);
    }

    public function test_product_show_page_renders_nikolacars_tesla_official_enrichment(): void
    {
        $user = $this->adminUser();
        $category = PartCatalogCategory::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-official://category/body',
            'depth' => 1,
            'name' => 'Body',
            'name_ru' => $this->u('\\u041a\\u0443\\u0437\\u043e\\u0432'),
            'model_label' => 'Model S',
        ]);
        $officialItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/catalogs?part=1002066-00-A',
            'part_number' => '1002066-00-A',
            'name' => 'ASSEMBLY - FRONT DOOR',
            'name_en' => 'ASSEMBLY - FRONT DOOR',
            'scheme_number' => 12,
            'part_catalog_category_id' => $category->id,
            'model_label' => 'Model S 2012-2016',
            'main_category_name' => 'Body',
            'subcategory_name' => 'Closures',
            'node_name' => 'Front door',
            'raw_attributes' => [
                'part_image_urls' => ['/storage/tesla-official/part-images/1002066.jpeg'],
                'system_group_image_urls' => ['https://epc.tesla.com/resources/images/body.png'],
                'official_catalog_occurrences' => [[
                    'model_label' => 'Model S 2012-2016',
                    'main_category_name' => 'Body',
                    'subcategory_name' => 'Closures',
                    'node_name' => 'Front door',
                ]],
            ],
        ]);
        $nikolaCarsItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://inventory-product/1002066',
            'part_number' => '1002066-00-A',
            'name' => 'NikolaCars front door',
        ]);
        $product = Product::query()->create([
            'sku' => 'NC-1002066',
            'external_sku' => '1002066-00-A',
            'name' => 'NikolaCars front door',
            'slug' => 'nikolacars-front-door',
            'main_image' => 'product-photos/local-front-door.jpg',
            'images_json' => ['product-photos/local-front-door.jpg'],
            'source_part_catalog_item_id' => $nikolaCarsItem->id,
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.products.show', $product))
            ->assertOk()
            ->assertSee($this->u('\\u0414\\u0430\\u043d\\u043d\\u044b\\u0435 Tesla.com'))
            ->assertSee($this->u('\\u0422\\u043e\\u0447\\u043d\\u043e\\u0435 \\u0441\\u043e\\u0432\\u043f\\u0430\\u0434\\u0435\\u043d\\u0438\\u0435'))
            ->assertSee(route('admin.tesla-official-catalog.show', $officialItem), false)
            ->assertSee('1002066-00-A')
            ->assertSee('ASSEMBLY - FRONT DOOR')
            ->assertSee($this->u('\\u041a\\u0443\\u0437\\u043e\\u0432'))
            ->assertSee('Model S 2012-2016')
            ->assertSee('12')
            ->assertSee('data-photo="/storage/tesla-official/part-images/1002066.jpeg"', false)
            ->assertSee('product-photo-manager__source-tag">tesla.com', false)
            ->assertSee('/storage/tesla-official/part-images/1002066.jpeg', false)
            ->assertSee('https://epc.tesla.com/resources/images/body.png', false);
    }

    public function test_product_show_heading_prefers_product_name_over_catalog_name(): void
    {
        $user = $this->adminUser();
        PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/catalogs?part=1002067-00-A',
            'part_number' => '1002067-00-A',
            'name' => 'Official catalog name',
            'name_en' => 'Official catalog name',
        ]);
        $nikolaCarsItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://inventory-product/1002067',
            'part_number' => '1002067-00-A',
            'name' => 'NikolaCars projection name',
        ]);
        $product = Product::query()->create([
            'sku' => 'NC-1002067',
            'external_sku' => '1002067-00-A',
            'name' => 'Local warehouse product',
            'slug' => 'local-warehouse-product',
            'source_part_catalog_item_id' => $nikolaCarsItem->id,
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->get(route('admin.products.show', $product));

        $response->assertOk();

        $html = $response->getContent();

        $this->assertMatchesRegularExpression('/<h1[^>]*>\s*Local warehouse product\s*<\/h1>/u', $html);
        $this->assertDoesNotMatchRegularExpression('/<h1[^>]*>\s*Official catalog name\s*<\/h1>/u', $html);
    }

    public function test_product_show_page_allows_editing_stock_placement(): void
    {
        $user = $this->adminUser();
        $sourceWarehouse = Warehouse::query()->create([
            'name' => 'Product Shelf A',
            'type' => Warehouse::TYPE_MAIN,
            'floor_count' => 1,
            'is_active' => true,
        ]);
        $targetWarehouse = Warehouse::query()->create([
            'name' => 'Product Shelf B',
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
        $product = Product::query()->create([
            'sku' => 'NC-PRODUCT-PLACEMENT',
            'external_sku' => 'PRODUCT-PLACEMENT',
            'name' => 'Product placement test part',
            'slug' => 'product-placement-test-part',
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 50,
            'currency' => 'USD',
            'is_active' => true,
        ]);

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
            ->get(route('admin.products.show', $product))
            ->assertOk()
            ->assertSee('data-product-placement-edit-toggle', false)
            ->assertSee('data-product-placement-editor', false)
            ->assertSee(route('admin.products.placement.update', $product), false);

        $this->actingAs($user)
            ->patch(route('admin.products.placement.update', $product), [
                'warehouse_id' => $targetWarehouse->id,
                'floor' => 'floor_1',
                'location_id' => $targetLocation->id,
            ])
            ->assertRedirect(route('admin.products.show', $product));

        $this->assertSame(0, (int) StockItem::query()->where('location_id', $sourceLocation->id)->value('quantity'));
        $this->assertSame(1, (int) StockItem::query()->where('location_id', $targetLocation->id)->value('quantity'));
        $this->assertSame(Product::STORAGE_STATUS_IN_STOCK, $product->refresh()->storage_status);
    }

    public function test_product_show_page_hides_stock_placement_edit_for_unknown_donor_damage_and_shows_damage_edit(): void
    {
        $user = $this->adminUser();
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJPRODUCTDAMAGE01',
            'brand' => 'Tesla',
            'model' => 'Model Y',
            'year' => 2022,
            'status' => DonorCar::STATUS_AT_STO,
        ]);
        $warehouse = Warehouse::query()->create([
            'name' => 'Unknown Damage Shelf',
            'type' => Warehouse::TYPE_MAIN,
            'floor_count' => 1,
            'is_active' => true,
        ]);
        $location = Location::query()->create([
            'warehouse_id' => $warehouse->id,
            'floor' => 'floor_1',
            'cell' => 'U-1',
            'full_code' => 'U-1',
            'is_active' => true,
        ]);
        $targetLocation = Location::query()->create([
            'warehouse_id' => $warehouse->id,
            'floor' => 'floor_1',
            'cell' => 'U-2',
            'full_code' => 'U-2',
            'is_active' => true,
        ]);
        $product = Product::query()->create([
            'sku' => 'DON-DAMAGE-UNKNOWN',
            'external_sku' => 'DAMAGE-UNKNOWN-001',
            'name' => 'Unknown damage donor part',
            'slug' => 'unknown-damage-donor-part',
            'donor_car_id' => $donorCar->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'currency' => 'USD',
            'notes' => "\u{041D}\u{0435}\u{0438}\u{0437}\u{0432}\u{0435}\u{0441}\u{0442}\u{043D}\u{043E}",
            'is_active' => true,
        ]);
        StockItem::query()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'location_id' => $location->id,
            'quantity' => 1,
            'reserved_quantity' => 0,
            'testing_status' => 'not_tested',
            'received_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('admin.products.show', $product))
            ->assertOk()
            ->assertSee("\u{0421}\u{0442}\u{0430}\u{0442}\u{0443}\u{0441}:")
            ->assertDontSee("\u{041F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{044F}:")
            ->assertSee('data-product-damage-edit-toggle', false)
            ->assertSee('data-product-damage-select', false)
            ->assertSee(route('admin.donor-cars.products.official-fields.update', [$donorCar, $product]), false)
            ->assertDontSee(route('admin.products.placement.update', $product), false);

        $this->actingAs($user)
            ->from(route('admin.products.show', $product))
            ->patch(route('admin.products.placement.update', $product), [
                'warehouse_id' => $warehouse->id,
                'floor' => 'floor_1',
                'location_id' => $targetLocation->id,
            ])
            ->assertRedirect(route('admin.products.show', $product))
            ->assertSessionHasErrors('warehouse_id');

        $this->assertSame(1, (int) StockItem::query()->where('location_id', $location->id)->value('quantity'));
        $this->assertSame(0, (int) StockItem::query()->where('location_id', $targetLocation->id)->value('quantity'));
    }

    public function test_product_show_page_hides_auto_generated_badge_for_nomenclature_products(): void
    {
        $user = $this->adminUser();
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://donor-product/100',
            'part_number' => 'NOMENCLATURE-001',
            'name' => 'Imported nomenclature part',
            'raw_attributes' => [
                'source_type' => 'donor',
                'product_id' => 100,
            ],
        ]);
        $product = Product::query()->create([
            'sku' => 'NC-100',
            'name' => 'Imported nomenclature product',
            'slug' => 'imported-nomenclature-product',
            'source_part_catalog_item_id' => $catalogItem->id,
            'is_auto_generated' => true,
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.products.show', $product))
            ->assertOk()
            ->assertDontSee('<span class="auto-generated-badge"', false)
            ->assertDontSee($this->u('\\u0410\\u0432\\u0442\\u043e\\u043c\\u0430\\u0442\\u0438\\u0447\\u0435\\u0441\\u043a\\u0438 \\u0441\\u0433\\u0435\\u043d\\u0435\\u0440\\u0438\\u0440\\u043e\\u0432\\u0430\\u043d\\u043e \\u0438\\u0437 \\u043a\\u0430\\u0442\\u0430\\u043b\\u043e\\u0433\\u0430 \\u0437\\u0430\\u043f\\u0447\\u0430\\u0441\\u0442\\u0435\\u0439'));
    }

    public function test_product_show_page_keeps_auto_generated_badge_for_external_catalog_products(): void
    {
        $user = $this->adminUser();
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/catalogs/external-auto-generated',
            'part_number' => 'EXTERNAL-AUTO-001',
            'name' => 'External catalog part',
        ]);
        $product = Product::query()->create([
            'sku' => 'PRD-AUTO-GENERATED',
            'name' => 'External auto generated product',
            'slug' => 'external-auto-generated-product',
            'source_part_catalog_item_id' => $catalogItem->id,
            'is_auto_generated' => true,
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.products.show', $product))
            ->assertOk()
            ->assertSee('<span class="auto-generated-badge"', false);
    }

    public function test_tesla_official_generated_product_shows_source_badge_and_cannot_be_deleted(): void
    {
        $user = $this->adminUser();
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=TESLA-PRODUCT-DELETE-001',
            'part_number' => 'TESLA-PRODUCT-DELETE-001',
            'name' => 'Tesla protected product',
        ]);
        $product = Product::query()->create([
            'sku' => 'PRD-TESLA-PROTECTED',
            'external_sku' => 'TESLA-PRODUCT-DELETE-001',
            'name' => 'Tesla protected product',
            'slug' => 'tesla-protected-product',
            'source_part_catalog_item_id' => $catalogItem->id,
            'is_auto_generated' => true,
            'generated_at' => now(),
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.products.show', $product))
            ->assertOk()
            ->assertSee('product-source-tag', false)
            ->assertSee('tesla.com');

        $this->actingAs($user)
            ->get(route('admin.products.index'))
            ->assertOk()
            ->assertSee('tesla.com')
            ->assertDontSee('action="'.route('admin.products.destroy', $product).'"', false);

        $this->actingAs($user)
            ->from(route('admin.products.index'))
            ->delete(route('admin.products.destroy', $product))
            ->assertRedirect(route('admin.products.index'))
            ->assertSessionHasErrors('product');

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_product_show_page_links_ru_name_to_referenced_erazborka_source(): void
    {
        $user = $this->adminUser();
        $sourceItem = PartCatalogItem::query()->create([
            'source' => 'erazborka',
            'source_url' => 'https://erazborka.com.ua/catalog/zapchasti-tesla-model-y/provodka-perednego-levogo-sidenya-tesla-model-y-1489060-06-f/',
            'part_number' => '1489060-06-F',
            'name' => "\u{041F}\u{0440}\u{043E}\u{0432}\u{043E}\u{0434}\u{043A}\u{0430} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0435}\u{0433}\u{043E} \u{043B}\u{0435}\u{0432}\u{043E}\u{0433}\u{043E} \u{0441}\u{0438}\u{0434}\u{0435}\u{043D}\u{044C}\u{044F}",
            'name_ru' => "\u{041F}\u{0440}\u{043E}\u{0432}\u{043E}\u{0434}\u{043A}\u{0430} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0435}\u{0433}\u{043E} \u{043B}\u{0435}\u{0432}\u{043E}\u{0433}\u{043E} \u{0441}\u{0438}\u{0434}\u{0435}\u{043D}\u{044C}\u{044F}",
            'raw_attributes' => [
                'source_url_ru' => 'https://erazborka.com.ua/catalog/zapchasti-tesla-model-y/provodka-perednego-levogo-sidenya-tesla-model-y-1489060-06-f/',
            ],
        ]);
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=1489060-06-F',
            'part_number' => '1489060-06-F',
            'name' => 'FRONT LEFT SEAT HARNESS',
            'name_ru' => $sourceItem->name_ru,
            'raw_attributes' => [
                'name_source_site_ru' => 'erazborka.com',
                'name_source_url_ru' => 'https://erazborka.com.ua/catalog/zapchasti-tesla-model-y/provodka-perednego-levogo-sidenya-tesla-model-y-1489060-06-f/',
                'name_source_item_id_ru' => $sourceItem->id,
            ],
        ]);
        $product = Product::query()->create([
            'sku' => 'PRD-ERAZBORKA-BADGE',
            'name' => 'Harness product',
            'slug' => 'harness-product',
            'source_part_catalog_item_id' => $catalogItem->id,
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.products.show', $product))
            ->assertOk()
            ->assertSee('<a class="tag" href="https://erazborka.com.ua/catalog/zapchasti-tesla-model-y/provodka-perednego-levogo-sidenya-tesla-model-y-1489060-06-f/" target="_blank" rel="noopener">erazborka.com.ua</a>', false);
    }

    public function test_product_show_page_links_driveparts_ru_name_to_russian_product_page(): void
    {
        $user = $this->adminUser();
        $sourceItem = PartCatalogItem::query()->create([
            'source' => 'driveparts',
            'source_url' => 'https://drive-parts.com.ua/1109796-00-c-bolt-kriplennia-mekhanizmu-rehuliuvannia-vysoty-sydinnia-1-ho-riadu-tesla-model-3-3r-sr-sp-x-xp-y-yr-cybertruck/',
            'part_number' => '1109796-00-C',
            'name' => 'DriveParts bolt',
            'name_ru' => "\u{0411}\u{043E}\u{043B}\u{0442} FL S 14x7 M10x15 109 ZN PIL ADH",
        ]);
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=1109796-98-B',
            'part_number' => '1109796-98-B',
            'name' => 'BOLT,FL,SHLDR[14X6.2],M10X15,STL109,PIL',
            'name_ru' => $sourceItem->name_ru,
            'raw_attributes' => [
                'name_source_site_ru' => 'drive-parts.com.ua',
                'name_source_url_ru' => 'https://drive-parts.com.ua/1109796-00-c-bolt-kriplennia-mekhanizmu-rehuliuvannia-vysoty-sydinnia-1-ho-riadu-tesla-model-3-3r-sr-sp-x-xp-y-yr-cybertruck/',
                'name_source_item_id_ru' => $sourceItem->id,
            ],
        ]);
        $product = Product::query()->create([
            'sku' => 'PRD-DRIVEPARTS-BADGE',
            'name' => 'Bolt product',
            'slug' => 'bolt-product',
            'source_part_catalog_item_id' => $catalogItem->id,
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.products.show', $product))
            ->assertOk()
            ->assertSee('<a class="tag" href="https://drive-parts.com.ua/ru/1109796-00-c-bolt-kriplennia-mekhanizmu-rehuliuvannia-vysoty-sydinnia-1-ho-riadu-tesla-model-3-3r-sr-sp-x-xp-y-yr-cybertruck/" target="_blank" rel="noopener">drive-parts.com.ua</a>', false);
    }

    public function test_product_show_page_renders_manual_ru_name_badge_instead_of_source(): void
    {
        $user = $this->adminUser();
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/catalogs/model-3/manual-name',
            'part_number' => 'MANUAL-NAME-001',
            'name' => 'Manual name part',
            'name_ru' => 'Manual badge RU',
            'raw_attributes' => [
                'manual_name_locks' => [
                    'ru' => now()->toDateTimeString(),
                ],
                'name_source_site_ru' => 'parts.tesla.com',
                'name_source_url_ru' => 'https://parts.tesla.com/detail/manual-name/',
            ],
        ]);
        $product = Product::query()->create([
            'sku' => 'PRD-MANUAL-BADGE',
            'name' => 'Manual badge product',
            'slug' => 'manual-badge-product',
            'source_part_catalog_item_id' => $catalogItem->id,
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.products.show', $product))
            ->assertOk()
            ->assertSee('Manual badge RU')
            ->assertSee('data-product-catalog-name-edit', false)
            ->assertSee($this->u('\\u0412\\u0440\\u0443\\u0447\\u043d\\u0443\\u044e'), false)
            ->assertDontSee('https://parts.tesla.com/detail/manual-name/', false);
    }

    public function test_product_show_page_does_not_render_manual_badge_for_blank_locked_name(): void
    {
        $user = $this->adminUser();
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://donor-product/blank-manual-name',
            'part_number' => 'BLANK-MANUAL-001',
            'name' => 'Blank manual name part',
            'name_ru' => null,
            'raw_attributes' => [
                'manual_name_locks' => [
                    'ru' => now()->toDateTimeString(),
                ],
            ],
        ]);
        $product = Product::query()->create([
            'sku' => 'PRD-BLANK-MANUAL-BADGE',
            'name' => 'Blank manual badge product',
            'slug' => 'blank-manual-badge-product',
            'source_part_catalog_item_id' => $catalogItem->id,
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.products.show', $product))
            ->assertOk()
            ->assertDontSee('&#1042;&#1088;&#1091;&#1095;&#1085;&#1091;&#1102;', false);
    }

    public function test_donor_product_autofill_leaves_localized_names_static(): void
    {
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF702821',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
        ]);
        $targetItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://donor-product/blank-lock-fill',
            'part_number' => '1081421-E0-C',
            'name' => 'ASSEMBLY - FRONT DOOR - LEFT HAND',
            'name_ru' => null,
            'raw_attributes' => [
                'manual_name_locks' => [
                    'ru' => now()->toDateTimeString(),
                ],
            ],
        ]);
        $sourceItem = PartCatalogItem::query()->create([
            'source' => 'tcarservice',
            'source_url' => 'https://tcarservice.com/parts/front-door-left',
            'part_number' => '1081421-E0-C',
            'name' => "\u{0414}\u{0432}\u{0435}\u{0440}\u{044C} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{044F}\u{044F} \u{043B}\u{0435}\u{0432}\u{0430}\u{044F}",
            'name_ru' => "\u{0414}\u{0432}\u{0435}\u{0440}\u{044C} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{044F}\u{044F} \u{043B}\u{0435}\u{0432}\u{0430}\u{044F}",
        ]);
        $product = Product::query()->create([
            'sku' => 'DON28-0973',
            'external_sku' => '1081421-E0-C',
            'name' => 'ASSEMBLY - FRONT DOOR - LEFT HAND',
            'slug' => 'assembly-front-door-left-hand',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $targetItem->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'currency' => 'USD',
            'notes' => NikolaCarsProductInventorySyncService::CHECKED_DAMAGE_STATUSES[1],
            'is_active' => true,
        ]);
        $stats = app(DonorProductLocalizedNameAutofillService::class)->fillMissingNames($product);

        $targetItem->refresh();

        $this->assertSame(0, $stats['name_ru_updated']);
        $this->assertNull($targetItem->name_ru);
        $this->assertNotNull(data_get($targetItem->raw_attributes, 'manual_name_locks.ru'));
        $this->assertNull($targetItem->name_ru_manually_locked_at);
    }

    public function test_product_update_syncs_nikolacars_names_from_tesla_official(): void
    {
        $user = $this->adminUser();
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF702822',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
        ]);
        $lockedAt = now()->subMinute();
        $nikolaCarsItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://donor-product/manual-ru-preserve',
            'part_number' => '1081439-S0-A',
            'name' => 'ASSEMBLY - FRONT DOOR - LEFT HAND',
            'name_ru' => "\u{0414}\u{0432}\u{0435}\u{0440}\u{044C} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{044F}\u{044F} \u{043B}\u{0435}\u{0432}\u{0430}\u{044F}",
            'name_ua' => "\u{0414}\u{0432}\u{0435}\u{0440}\u{0456} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0456} (\u{0447}\u{0435}\u{0440}\u{0432}\u{043E}\u{043D}\u{0456} PPMR) \u{043B}\u{0456}\u{0432}\u{0456}",
            'raw_attributes' => [
                'manual_name_locks' => [
                    'ru' => $lockedAt->toDateTimeString(),
                    'ua' => $lockedAt->toDateTimeString(),
                ],
            ],
        ]);
        $officialItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=1081421-E0-C',
            'part_number' => '1081421-E0-C',
            'name' => '3. ASSEMBLY - FRONT DOOR - LEFT HAND',
            'name_ru' => "\u{0414}\u{0432}\u{0435}\u{0440}\u{044C} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{044F}\u{044F} \u{043B}\u{0435}\u{0432}\u{0430}\u{044F} \u{043F}\u{043E}\u{0434} \u{0440}\u{0438}\u{0445}\u{0442}\u{043E}\u{0432}\u{043A}\u{0443}",
            'name_ua' => null,
        ]);
        $product = Product::query()->create([
            'sku' => 'DON28-0973',
            'external_sku' => '1081439-S0-A',
            'name' => 'ASSEMBLY - FRONT DOOR - LEFT HAND',
            'slug' => 'manual-ru-preserve',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $nikolaCarsItem->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'currency' => 'USD',
            'notes' => NikolaCarsProductInventorySyncService::CHECKED_DAMAGE_STATUSES[1],
            'is_active' => true,
        ]);
        $nikolaCarsItem->forceFill([
            'source_url' => 'nikolacars://donor-product/'.$product->id,
        ])->save();

        $response = $this->actingAs($user)->put(route('admin.products.update', $product), [
            'external_sku' => '1081421-E0-C',
            'name' => 'ASSEMBLY - FRONT DOOR - LEFT HAND',
            'slug' => 'manual-ru-preserve',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $nikolaCarsItem->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'currency' => 'USD',
            'notes' => NikolaCarsProductInventorySyncService::CHECKED_DAMAGE_STATUSES[1],
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('admin.products.show', $product));

        $nikolaCarsItem->refresh();

        $this->assertSame($nikolaCarsItem->id, $product->refresh()->source_part_catalog_item_id);
        $this->assertSame($officialItem->id, data_get($nikolaCarsItem->raw_attributes, 'source_catalog_item_id'));
        $this->assertSame($officialItem->name_ru, $nikolaCarsItem->name_ru);
        $this->assertSame("\u{0414}\u{0432}\u{0435}\u{0440}\u{0456} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0456} (\u{0447}\u{0435}\u{0440}\u{0432}\u{043E}\u{043D}\u{0456} PPMR) \u{043B}\u{0456}\u{0432}\u{0456}", $nikolaCarsItem->name_ua);
        $this->assertNotNull(data_get($nikolaCarsItem->raw_attributes, 'manual_name_locks.ru'));
        $this->assertNotNull(data_get($nikolaCarsItem->raw_attributes, 'manual_name_locks.ua'));
        $this->assertSame($nikolaCarsItem->name_ua, $officialItem->refresh()->name_ua);
    }

    public function test_product_show_page_catalog_name_edit_locks_and_propagates_name(): void
    {
        $user = $this->adminUser();
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/product-manual-001',
            'part_number' => 'PRODUCT-MANUAL-001',
            'name' => 'Product manual part',
            'name_ru' => 'Old RU',
        ]);
        $duplicate = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/product-manual-001-duplicate',
            'part_number' => 'PRODUCT-MANUAL-001',
            'name' => 'Product manual duplicate',
            'name_ru' => 'Old duplicate RU',
        ]);
        $product = Product::query()->create([
            'sku' => 'PRD-PRODUCT-MANUAL',
            'name' => 'Product manual product',
            'slug' => 'product-manual-product',
            'source_part_catalog_item_id' => $catalogItem->id,
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->patch(route('admin.products.catalog-name.update', $product), [
                'name_type' => 'name_ru',
                'name' => 'New manual RU',
            ])
            ->assertRedirect(route('admin.products.show', $product));

        $this->assertSame('New manual RU', $catalogItem->refresh()->name_ru);
        $this->assertSame('New manual RU', $duplicate->refresh()->name_ru);
        $this->assertNotNull(data_get($catalogItem->raw_attributes, 'manual_name_locks.ru'));
    }

    public function test_product_show_page_uses_product_article_for_canonical_tesla_official_data(): void
    {
        $user = $this->adminUser();
        $vinSpecific = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=1127503-11-D&vin=5YJYGDED4MF109750',
            'part_number' => '1127503-11-D',
            'name' => 'VIN specific official row',
            'raw_attributes' => [
                'donor_vin' => '5YJYGDED4MF109750',
                'recommendation_type' => 'RECOMMENDED',
            ],
        ]);
        $canonical = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=1127503-11-D',
            'part_number' => '1127503-11-D',
            'name' => 'Canonical official row',
            'name_en' => 'Canonical official row',
            'name_ru' => 'Canonical RU name',
        ]);
        $product = Product::query()->create([
            'sku' => 'PRD-CANONICAL-OFFICIAL',
            'external_sku' => '1127503-11-D',
            'name' => 'Parking sensor product',
            'slug' => 'parking-sensor-product',
            'source_part_catalog_item_id' => $vinSpecific->id,
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.products.show', $product))
            ->assertOk()
            ->assertSee('Canonical official row')
            ->assertSee('Canonical RU name')
            ->assertSee(route('admin.tesla-official-catalog.show', $canonical), false);
    }

    public function test_product_show_page_uses_matching_catalog_item_as_ua_name_source(): void
    {
        $user = $this->adminUser();
        $officialItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/catalogs/model-3/1511000-s0-a',
            'part_number' => '1511000-S0-A',
            'name' => 'ASSEMBLY - BODY SIDE OUTER LEFT HAND SERVICE E-COATED',
            'name_ua' => $this->u('\\u0411\\u0456\\u0447\\u043d\\u0430 \\u043f\\u0430\\u043d\\u0435\\u043b\\u044c \\u043b\\u0456\\u0432\\u0430'),
        ]);
        PartCatalogItem::query()->create([
            'source' => 'teslapartsukraine',
            'source_url' => 'https://teslapartsukraine.com.ua/source-that-should-not-win',
            'part_number' => '1511000-S0-A',
            'name' => 'ASSEMBLY - BODY SIDE OUTER LEFT HAND SERVICE E-COATED',
            'name_ua' => $this->u('\\u0411\\u0456\\u0447\\u043d\\u0430 \\u043f\\u0430\\u043d\\u0435\\u043b\\u044c \\u043b\\u0456\\u0432\\u0430'),
        ]);
        PartCatalogItem::query()->create([
            'source' => 'tsk',
            'source_url' => 'https://tsk.ua/1511000-s0-a/',
            'part_number' => '1511000-S0-A',
            'name' => $this->u('\\u0411\\u0456\\u0447\\u043d\\u0430 \\u043f\\u0430\\u043d\\u0435\\u043b\\u044c \\u043b\\u0456\\u0432\\u0430'),
            'name_ua' => $this->u('\\u0411\\u0456\\u0447\\u043d\\u0430 \\u043f\\u0430\\u043d\\u0435\\u043b\\u044c \\u043b\\u0456\\u0432\\u0430'),
        ]);
        $product = Product::query()->create([
            'sku' => 'PRD-TSK-UA-SOURCE',
            'name' => 'ASSEMBLY - BODY SIDE OUTER LEFT HAND SERVICE E-COATED',
            'slug' => 'tsk-ua-source-product',
            'source_part_catalog_item_id' => $officialItem->id,
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->get(route('admin.products.show', $product));

        $response
            ->assertOk()
            ->assertSee($this->u('\\u0411\\u0456\\u0447\\u043d\\u0430 \\u043f\\u0430\\u043d\\u0435\\u043b\\u044c \\u043b\\u0456\\u0432\\u0430'))
            ->assertSee('<a class="tag" href="https://tsk.ua/1511000-s0-a/" target="_blank" rel="noopener">tsk.ua</a>', false)
            ->assertDontSee('teslapartsukraine.com.ua/source-that-should-not-win');
    }

    public function test_product_photos_can_be_added_from_show_page(): void
    {
        Storage::fake('public');

        $user = $this->adminUser();
        $product = Product::query()->create([
            'sku' => 'PRD-000125',
            'name' => 'Rear bumper',
            'slug' => 'rear-bumper',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'currency' => 'USD',
            'main_image' => 'product-photos/existing.jpg',
            'images_json' => ['product-photos/existing.jpg'],
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->post(route('admin.products.photos.store', $product), [
            'photos' => [
                UploadedFile::fake()->image('new-front.jpg'),
                UploadedFile::fake()->image('new-side.jpg'),
            ],
        ]);

        $response->assertRedirect(route('admin.products.show', $product));

        $product->refresh();
        $photos = collect($product->images_json);

        $this->assertSame('product-photos/existing.jpg', $product->main_image);
        $this->assertCount(3, $photos);
        $this->assertTrue($photos->contains('product-photos/existing.jpg'));

        $photos
            ->reject(fn (string $path) => $path === 'product-photos/existing.jpg')
            ->each(fn (string $path) => Storage::disk('public')->assertExists($path));
    }

    public function test_product_photos_can_be_deleted_from_show_page(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('product-photos/main.jpg', 'main');
        Storage::disk('public')->put('product-photos/detail.jpg', 'detail');

        $user = $this->adminUser();
        $product = Product::query()->create([
            'sku' => 'PRD-000126',
            'name' => 'Left fender',
            'slug' => 'left-fender',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'currency' => 'USD',
            'main_image' => 'product-photos/main.jpg',
            'images_json' => ['product-photos/main.jpg', 'product-photos/detail.jpg'],
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->delete(route('admin.products.photos.destroy', $product), [
                'photo' => 'product-photos/main.jpg',
            ])
            ->assertRedirect(route('admin.products.show', $product));

        $product->refresh();

        $this->assertSame('product-photos/detail.jpg', $product->main_image);
        $this->assertSame(['product-photos/detail.jpg'], (array) $product->images_json);
        Storage::disk('public')->assertMissing('product-photos/main.jpg');
        Storage::disk('public')->assertExists('product-photos/detail.jpg');
    }

    public function test_product_photos_can_be_reordered_from_show_page(): void
    {
        $user = $this->adminUser();
        $product = Product::query()->create([
            'sku' => 'PRD-000127',
            'name' => 'Right fender',
            'slug' => 'right-fender',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'currency' => 'USD',
            'main_image' => 'product-photos/first.jpg',
            'images_json' => ['product-photos/first.jpg', 'product-photos/second.jpg', 'product-photos/third.jpg'],
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->withHeaders([
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->patch(route('admin.products.photos.order', $product), [
                'photos' => [
                    'product-photos/third.jpg',
                    'product-photos/first.jpg',
                    'product-photos/second.jpg',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('main_image', 'product-photos/third.jpg')
            ->assertJsonPath('photos.0', 'product-photos/third.jpg');

        $product->refresh();

        $this->assertSame('product-photos/third.jpg', $product->main_image);
        $this->assertSame([
            'product-photos/third.jpg',
            'product-photos/first.jpg',
            'product-photos/second.jpg',
        ], (array) $product->images_json);
    }

    protected function adminUser(): User
    {
        return User::query()->create([
            'name' => 'Admin',
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    protected function u(string $value): string
    {
        return json_decode('"'.$value.'"', true, 512, JSON_THROW_ON_ERROR);
    }
}
