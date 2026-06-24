<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CustomerOrder;
use App\Models\DonorCar;
use App\Models\Location;
use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Models\PartCatalogItemOccurrence;
use App\Models\PartSale;
use App\Models\Product;
use App\Models\StoWorkOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DonorProductGenerationService;
use App\Services\DonorProductTcarsNameBackfiller;
use App\Services\ExchangeRateService;
use App\Services\OfficialCatalogDownloadStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DonorCarProductCatalogCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_donor_part_category_is_resolved_from_competitor_catalog_article(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000001',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2019,
        ]);

        $warehouse = Warehouse::query()->create([
            'name' => 'Main',
            'floor_count' => 1,
            'is_active' => true,
        ]);

        $model = PartCatalogCategory::query()->create([
            'source_url' => 'https://tcarservice.com/zapchasty/model-3-326',
            'depth' => 0,
            'name' => 'Model 3',
            'model_label' => 'Model 3',
        ]);

        $body = PartCatalogCategory::query()->create([
            'parent_id' => $model->id,
            'source_url' => 'https://tcarservice.com/zapchasty/model-3-326/body',
            'depth' => 1,
            'code' => '10',
            'name' => 'Body',
            'model_label' => 'Model 3',
        ]);

        $bumper = PartCatalogCategory::query()->create([
            'parent_id' => $body->id,
            'source_url' => 'https://tcarservice.com/zapchasty/model-3-326/body/front-bumper',
            'depth' => 2,
            'code' => '1001',
            'name' => 'Front bumper',
            'model_label' => 'Model 3',
        ]);

        PartCatalogItem::query()->create([
            'part_catalog_category_id' => $bumper->id,
            'source_url' => 'https://tcarservice.com/product/front-bumper',
            'part_number' => '1084174-00-C',
            'name' => 'Front bumper',
        ]);

        $response = $this->actingAs($user)->post(route('admin.donor-cars.products.store', $donorCar), [
            'name' => 'Front bumper',
            'damage_note' => "\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}",
            'selling_price' => '120',
            'external_sku' => '1084174-00-C',
            'warehouse_id' => $warehouse->id,
        ]);

        $response->assertRedirect(route('admin.donor-cars.show', $donorCar));

        $category = Category::query()->where('slug', 'tcars-10-body-1001-front-bumper')->firstOrFail();
        $product = Product::query()->where('external_sku', '1084174-00-C')->firstOrFail();

        $this->assertSame('RON'.$donorCar->id.'-0001', $product->sku);
        $this->assertSame($product->sku, $product->barcode);
        $this->assertSame($product->sku, $product->qr_code);
        $this->assertSame($category->id, $product->category_id);
        $this->assertSame('used', $product->condition_type);
        $this->assertSame("\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}", $product->notes);
        $this->assertSame('0.00', $product->purchase_price);
    }

    public function test_donor_product_names_are_backfilled_from_tcars_by_article(): void
    {
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000031',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2019,
        ]);

        $officialItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-common://donor-product/108417400C',
            'part_number' => '1084174-00-C',
            'name' => 'Front Bumper',
        ]);

        PartCatalogItem::withoutEvents(fn () => PartCatalogItem::query()->create([
            'source' => 'tcarservice',
            'source_url' => 'https://tcarservice.com/zapchasty/front-bumper',
            'part_number' => '1084174-00-C',
            'name' => "\u{0411}\u{0430}\u{043C}\u{043F}\u{0435}\u{0440} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0438}\u{0439}",
            'name_ru' => "\u{0411}\u{0430}\u{043C}\u{043F}\u{0435}\u{0440} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0438}\u{0439}",
            'name_ua' => "\u{0411}\u{0430}\u{043C}\u{043F}\u{0435}\u{0440} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0456}\u{0439}",
        ]));

        Product::query()->create([
            'sku' => 'DONOR-TCARS-NAME-001',
            'external_sku' => '1084174-00-C',
            'name' => 'Front Bumper',
            'slug' => 'front-bumper-tcars-name',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $officialItem->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $stats = app(DonorProductTcarsNameBackfiller::class)->run();

        $this->assertSame(1, $stats['donor_products_seen']);
        $this->assertSame(1, $stats['tcars_matches_found']);
        $this->assertSame(1, $stats['catalog_items_updated']);
        $this->assertSame(1, $stats['name_ru_updated']);
        $this->assertSame(1, $stats['name_ua_updated']);

        $officialItem->refresh();
        $this->assertSame("\u{0411}\u{0430}\u{043C}\u{043F}\u{0435}\u{0440} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0438}\u{0439}", $officialItem->name_ru);
        $this->assertSame("\u{0411}\u{0430}\u{043C}\u{043F}\u{0435}\u{0440} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0456}\u{0439}", $officialItem->name_ua);
        $this->assertSame('tcarservice.com', data_get($officialItem->raw_attributes, 'name_source_site_ru'));
        $this->assertSame('https://tcarservice.com/zapchasty/front-bumper', data_get($officialItem->raw_attributes, 'name_source_url_ua'));
    }

    public function test_tcars_backfill_keeps_manually_locked_donor_names(): void
    {
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000032',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2019,
        ]);

        $officialItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-common://donor-product/108417400D',
            'part_number' => '1084174-00-D',
            'name' => 'Front Bumper',
            'name_ru' => "\u{0420}\u{0443}\u{0447}\u{043D}\u{043E}\u{0435} \u{043D}\u{0430}\u{0437}\u{0432}\u{0430}\u{043D}\u{0438}\u{0435}",
            'name_ru_manually_locked_at' => now(),
        ]);

        PartCatalogItem::withoutEvents(fn () => PartCatalogItem::query()->create([
            'source' => 'tcarservice',
            'source_url' => 'https://tcarservice.com/zapchasty/front-bumper-d',
            'part_number' => '1084174-00-D',
            'name' => "\u{0411}\u{0430}\u{043C}\u{043F}\u{0435}\u{0440} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0438}\u{0439}",
            'name_ru' => "\u{0411}\u{0430}\u{043C}\u{043F}\u{0435}\u{0440} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0438}\u{0439}",
            'name_ua' => "\u{0411}\u{0430}\u{043C}\u{043F}\u{0435}\u{0440} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0456}\u{0439}",
        ]));

        Product::query()->create([
            'sku' => 'DONOR-TCARS-NAME-002',
            'external_sku' => '1084174-00-D',
            'name' => 'Front Bumper',
            'slug' => 'front-bumper-tcars-name-d',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $officialItem->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $stats = app(DonorProductTcarsNameBackfiller::class)->run(['overwrite' => true]);

        $this->assertSame(1, $stats['manual_locked_skipped']);

        $officialItem->refresh();
        $this->assertSame("\u{0420}\u{0443}\u{0447}\u{043D}\u{043E}\u{0435} \u{043D}\u{0430}\u{0437}\u{0432}\u{0430}\u{043D}\u{0438}\u{0435}", $officialItem->name_ru);
        $this->assertSame("\u{0411}\u{0430}\u{043C}\u{043F}\u{0435}\u{0440} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0456}\u{0439}", $officialItem->name_ua);
    }

    public function test_donor_product_localized_names_can_be_edited_from_donor_show_page(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000111',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2019,
        ]);

        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://example.test/front-bumper',
            'part_number' => '1084174-00-C',
            'name' => 'Front Bumper',
            'name_ru' => 'Передний бампер',
            'name_ua' => 'Передній бампер',
        ]);

        $product = Product::query()->create([
            'sku' => 'DON1-0001',
            'name' => 'Front Bumper',
            'slug' => 'front-bumper',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $catalogItem->id,
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'purchase_price' => 0,
            'selling_price' => 100,
            'currency' => 'USD',
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'is_auto_generated' => true,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.donor-cars.show', $donorCar))
            ->assertOk()
            ->assertSee('data-donor-product-name-edit', false)
            ->assertSee('donor-product-name-form', false)
            ->assertDontSee('name="name_type" value="product"', false);
        $this->actingAs($user)
            ->patchJson(route('admin.donor-cars.products.name.update', [$donorCar, $product]), [
                'name_type' => 'name_ru',
                'name' => 'Бампер передний',
            ])
            ->assertOk()
            ->assertJson([
                'catalog_item_id' => $catalogItem->id,
                'name_type' => 'name_ru',
                'manual' => true,
            ]);

        $this->actingAs($user)
            ->patch(route('admin.donor-cars.products.name.update', [$donorCar, $product]), [
                'name_type' => 'name_ua',
                'name' => 'Бампер передній',
            ])
            ->assertRedirect(route('admin.donor-cars.show', $donorCar));

        $catalogItem->refresh();
        $this->assertSame('Бампер передний', $catalogItem->name_ru);
        $this->assertSame('Бампер передній', $catalogItem->name_ua);
        $this->actingAs($user)
            ->get(route('admin.donor-cars.show', $donorCar))
            ->assertOk()
            ->assertSee('&#1042;&#1088;&#1091;&#1095;&#1085;&#1091;&#1102;', false)
            ->assertDontSee('example.test', false);
    }

    public function test_generated_donor_parts_use_unique_catalog_items_without_competitor_prices(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-generated-donor-stock-label@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000021',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2019,
        ]);

        foreach ([
            ['tcarservice', 'https://tcarservice.com/product/front-bumper', 'Front bumper', '250.00'],
            ['dkparts', 'https://dk-parts.com.ua/ru/front-bumper', 'Front bumper cover', '300.00'],
        ] as [$source, $url, $name, $price]) {
            PartCatalogItem::query()->create([
                'source' => $source,
                'source_url' => $url,
                'part_number' => '1084174-00-C',
                'name' => $name,
                'model_label' => 'Model 3',
                'model_name' => 'Model 3',
                'price_amount' => $price,
                'currency' => 'USD',
            ]);
        }

        $generator = app(DonorProductGenerationService::class);
        $preview = $generator->preview($donorCar, []);

        $this->assertSame(1, $preview['summary']['total']);
        $this->assertSame(1, $preview['summary']['creatable']);
        $this->assertNull($preview['items'][0]['price_amount']);

        $stats = $generator->generate($donorCar, [], [$preview['items'][0]['id']]);

        $this->assertSame(1, $stats['created']);
        $product = Product::query()
            ->with('stockItems.warehouse', 'stockItems.location')
            ->where('donor_car_id', $donorCar->id)
            ->firstOrFail();
        $this->assertSame('1084174-00-C', $product->external_sku);
        $this->assertSame('DON'.$donorCar->id.'-0001', $product->sku);
        $this->assertSame('0.00', $product->selling_price);
        $this->assertSame(Product::STORAGE_STATUS_ON_DONOR, $product->storage_status);

        $stockItem = $product->stockItems->first();
        $this->assertNotNull($stockItem);
        $this->assertSame(1, (int) $stockItem->quantity);
        $this->assertSame(1, (int) $stockItem->available_quantity);
        $this->assertSame(Warehouse::DONOR_WAREHOUSE_NAME, $stockItem->warehouse->name);
        $this->assertSame(Warehouse::TYPE_DONOR, $stockItem->warehouse->type);
        $this->assertSame('ON-DONOR-'.$donorCar->id, $stockItem->location->full_code);

        $response = $this->actingAs($user)
            ->get(route('admin.donor-cars.show', $donorCar))
            ->assertOk();
        $productRow = str($response->getContent())
            ->after('1084174-00-C')
            ->before('</tr>')
            ->toString();

        $this->assertStringContainsString(Warehouse::DONOR_WAREHOUSE_NAME, $productRow);
        $this->assertStringNotContainsString('ON-DONOR-'.$donorCar->id, $productRow);
        $this->assertStringNotContainsString('Этаж', $productRow);
    }

    public function test_generated_official_donor_parts_are_marked_damaged_or_whole_from_damage_zones(): void
    {
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000022',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2019,
        ]);

        $frontBumper = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/catalogs/model-3/front-bumper',
            'part_number' => '1084174-00-C',
            'name' => 'Front bumper',
            'model_label' => 'Model 3',
            'model_name' => 'Model 3',
            'price_amount' => 777.33,
            'currency' => 'USD',
        ]);

        $touchscreen = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/catalogs/model-3/touchscreen',
            'part_number' => '1089543-00-G',
            'name' => 'Touchscreen display',
            'model_label' => 'Model 3',
            'model_name' => 'Model 3',
        ]);

        $generator = app(DonorProductGenerationService::class);
        $preview = $generator->preview($donorCar, ['front']);

        $this->assertSame(1, $preview['summary']['creatable']);
        $this->assertSame(1, $preview['summary']['damaged']);
        $this->assertSame(2, $preview['summary']['selectable']);

        $stats = $generator->generate($donorCar, ['front'], [$frontBumper->id, $touchscreen->id]);

        $this->assertSame(2, $stats['created']);
        $this->assertSame(1, $stats['created_damaged']);
        $this->assertSame(1, $stats['created_whole']);

        $damagedProduct = Product::query()->where('external_sku', '1084174-00-C')->firstOrFail();
        $wholeProduct = Product::query()->where('external_sku', '1089543-00-G')->firstOrFail();

        $this->assertSame('used', $damagedProduct->condition_type);
        $this->assertSame('0.00', $damagedProduct->selling_price);
        $this->assertStringContainsString('', $damagedProduct->description);
        $this->assertSame('used', $wholeProduct->condition_type);
        $this->assertSame('0.00', $wholeProduct->selling_price);
        $this->assertStringContainsString('Автоматически сгенерировано из каталога запчастей.', $wholeProduct->description);
    }

    public function test_generated_official_donor_part_autofills_missing_names_from_local_catalog(): void
    {
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF900122',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2019,
        ]);

        $officialItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/catalogs/model-3/autofill-generated',
            'part_number' => '1084174-00-H',
            'name' => 'Front bumper',
            'name_en' => 'Front bumper',
            'model_label' => 'Model 3',
            'model_name' => 'Model 3',
            'price_amount' => 777.33,
            'currency' => 'USD',
        ]);
        $sourceNameRu = "\u{0411}\u{0430}\u{043C}\u{043F}\u{0435}\u{0440} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0438}\u{0439}";
        $sourceNameUa = "\u{0411}\u{0430}\u{043C}\u{043F}\u{0435}\u{0440} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0456}\u{0439}";
        PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://existing-name-source/1084174-00-H',
            'part_number' => '1084174-00-H',
            'name' => $sourceNameRu,
            'name_ru' => $sourceNameRu,
            'name_ua' => $sourceNameUa,
        ]);

        $stats = app(DonorProductGenerationService::class)->generate($donorCar, [], [$officialItem->id]);

        $this->assertSame(1, $stats['created']);

        $officialItem->refresh();
        $product = Product::query()->where('external_sku', '1084174-00-H')->firstOrFail();

        $this->assertSame($officialItem->id, $product->source_part_catalog_item_id);
        $this->assertSame($sourceNameRu, $officialItem->name_ru);
        $this->assertSame($sourceNameUa, $officialItem->name_ua);
        $this->assertSame('donor_status_catalog_match', data_get($officialItem->raw_attributes, 'name_source_type_ru'));
    }

    public function test_generated_official_donor_part_sale_price_defaults_to_zero(): void
    {
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000222',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2019,
        ]);

        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/catalogs/model-3/hood',
            'part_number' => '1081401-E0-D',
            'name' => 'Hood assembly',
            'model_label' => 'Model 3',
            'model_name' => 'Model 3',
            'price_amount' => 777.33,
            'currency' => 'USD',
        ]);

        $stats = app(DonorProductGenerationService::class)->generate($donorCar, [], [$catalogItem->id]);

        $this->assertSame(1, $stats['created']);
        $product = Product::query()
            ->where('donor_car_id', $donorCar->id)
            ->where('external_sku', '1081401-E0-D')
            ->firstOrFail();

        $this->assertTrue((bool) $product->is_auto_generated);
        $this->assertSame('0.00', $product->selling_price);
        $this->assertSame('777.33', $catalogItem->refresh()->price_amount);
    }

    public function test_generated_donor_part_color_is_copied_only_for_body_parts(): void
    {
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000122',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2019,
            'color' => 'White',
        ]);

        $model = PartCatalogCategory::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/catalogs/model-3',
            'depth' => 0,
            'name' => 'Model 3',
            'model_label' => 'Model 3',
        ]);

        $body = PartCatalogCategory::query()->create([
            'parent_id' => $model->id,
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/catalogs/model-3/body',
            'depth' => 1,
            'code' => '10',
            'name' => 'Body',
            'model_label' => 'Model 3',
        ]);

        $electrical = PartCatalogCategory::query()->create([
            'parent_id' => $model->id,
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/catalogs/model-3/electrical',
            'depth' => 1,
            'code' => '17',
            'name' => 'Electrical',
            'model_label' => 'Model 3',
        ]);

        PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/catalogs/model-3/body/front-bumper',
            'part_catalog_category_id' => $body->id,
            'part_number' => '1084174-00-C',
            'name' => 'Front bumper',
            'model_label' => 'Model 3',
            'model_name' => 'Model 3',
        ]);

        PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/catalogs/model-3/electrical/touchscreen',
            'part_catalog_category_id' => $electrical->id,
            'part_number' => '1089543-00-G',
            'name' => 'Touchscreen display',
            'model_label' => 'Model 3',
            'model_name' => 'Model 3',
        ]);

        $generator = app(DonorProductGenerationService::class);
        $preview = $generator->preview($donorCar, []);
        $generator->generate($donorCar, [], collect($preview['items'])->pluck('id')->all());

        $bodyProduct = Product::query()->where('external_sku', '1084174-00-C')->firstOrFail();
        $electricalProduct = Product::query()->where('external_sku', '1089543-00-G')->firstOrFail();

        $this->assertSame('White', $bodyProduct->color);
        $this->assertNull($electricalProduct->color);
    }

    public function test_existing_generated_official_donor_parts_are_reclassified_from_damage_zones(): void
    {
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000023',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2019,
        ]);

        PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/catalogs/model-3/front-bumper',
            'part_number' => '1084174-00-C',
            'name' => 'Front bumper',
            'model_label' => 'Model 3',
            'model_name' => 'Model 3',
        ]);

        $generator = app(DonorProductGenerationService::class);
        $firstRun = $generator->preview($donorCar, []);
        $generator->generate($donorCar, [], collect($firstRun['items'])->pluck('id')->all());

        $product = Product::query()
            ->with('stockItems')
            ->where('external_sku', '1084174-00-C')
            ->firstOrFail();

        $this->assertSame('used', $product->condition_type);

        $stats = $generator->generate($donorCar, ['front'], []);

        $this->assertSame(0, $stats['created']);
        $this->assertSame(1, $stats['updated_existing']);

        $product->refresh()->load('stockItems');
        $this->assertSame('used', $product->condition_type);
        $this->assertStringContainsString('', $product->description);
    }

    public function test_model_y_standard_range_rear_wheel_drive_filters_explicit_long_range_and_awd_parts(): void
    {
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJYGDED4MF109750',
            'brand' => 'Tesla',
            'model' => 'Model Y 01.2020 - 01.2025',
            'year' => 2021,
            'drive_type' => DonorCar::DRIVE_TYPE_REAR,
            'battery_type' => DonorCar::BATTERY_TYPE_STANDARD_RANGE,
            'is_performance' => false,
        ]);

        foreach ([
            ['https://parts.tesla.com/catalogs/model-y/standard-rwd-battery', '1990305-00-B', 'HIGH VOLTAGE BATTERY - STANDARD RANGE - REAR WHEEL DRIVE - 1 PHASE'],
            ['https://parts.tesla.com/catalogs/model-y/rwd-damper', '1188463-00-E', 'DAMPER ASSEMBLY COIL - REAR WHEEL DRIVE - REAR'],
            ['https://parts.tesla.com/catalogs/model-y/neutral-display', '1089543-00-G', 'Touchscreen display'],
            ['https://parts.tesla.com/catalogs/model-y/long-range-awd-battery', '1700012-99-C', 'ASSEMBLY - HIGH VOLTAGE BATTERY - LONG RANGE - ALL WHEEL DRIVE - 3 PHASE'],
            ['https://parts.tesla.com/catalogs/model-y/dual-motor-harness', '2489058-01-B', 'ASSEMBLY - HARNESS - FRONT - ALL WHEEL DRIVE'],
            ['https://parts.tesla.com/catalogs/model-y/performance-caliper', '1188641-00-B', 'BRAKE CALIPER - FRONT LEFT HAND - PERFORMANCE'],
        ] as [$url, $partNumber, $name]) {
            PartCatalogItem::query()->create([
                'source' => 'tesla_official',
                'source_url' => $url,
                'part_number' => $partNumber,
                'name' => $name,
                'model_label' => 'Model Y 01.2020 - 01.2025',
                'model_name' => 'Model Y',
            ]);
        }

        $preview = app(DonorProductGenerationService::class)->preview($donorCar, []);
        $names = collect($preview['items'])->pluck('name')->all();

        $this->assertContains('HIGH VOLTAGE BATTERY - STANDARD RANGE - REAR WHEEL DRIVE - 1 PHASE', $names);
        $this->assertContains('DAMPER ASSEMBLY COIL - REAR WHEEL DRIVE - REAR', $names);
        $this->assertContains('Touchscreen display', $names);
        $this->assertNotContains('ASSEMBLY - HIGH VOLTAGE BATTERY - LONG RANGE - ALL WHEEL DRIVE - 3 PHASE', $names);
        $this->assertNotContains('ASSEMBLY - HARNESS - FRONT - ALL WHEEL DRIVE', $names);
        $this->assertNotContains('BRAKE CALIPER - FRONT LEFT HAND - PERFORMANCE', $names);
    }

    public function test_model_y_long_range_awd_filters_explicit_standard_range_and_rwd_parts(): void
    {
        $donorCar = DonorCar::query()->create([
            'vin' => '7SAYGDEE1PF838028',
            'brand' => 'Tesla',
            'model' => 'Model Y 01.2020 - 01.2025',
            'year' => 2023,
            'drive_type' => DonorCar::DRIVE_TYPE_ALL,
            'battery_type' => DonorCar::BATTERY_TYPE_LONG_RANGE,
            'is_performance' => false,
        ]);

        foreach ([
            ['https://parts.tesla.com/catalogs/model-y/lr-awd-battery', '1234422-00-B', 'HIGH VOLTAGE BATTERY - LONG RANGE - AWD - 1PH'],
            ['https://parts.tesla.com/catalogs/model-y/dual-motor-damper', '1188363-00-F', 'DAMPER ASSEMBLY E3 - DUAL MOTOR - FRONT LEFT HAND'],
            ['https://parts.tesla.com/catalogs/model-y/common-bolt', '1089657-00-C', 'BOLT - M12x35'],
            ['https://parts.tesla.com/catalogs/model-y/sr-rwd-battery', '1990305-00-B', 'HIGH VOLTAGE BATTERY - STANDARD RANGE - REAR WHEEL DRIVE - 1 PHASE'],
            ['https://parts.tesla.com/catalogs/model-y/rwd-harness', '3489058-02-D', 'ASSEMBLY - HARNESS - FRONT - REAR WHEEL DRIVE'],
            ['https://parts.tesla.com/catalogs/model-y/performance-rotor', '1188636-99-A', 'BRAKE ROTOR - REAR - PERFORMANCE'],
        ] as [$url, $partNumber, $name]) {
            PartCatalogItem::query()->create([
                'source' => 'tesla_official',
                'source_url' => $url,
                'part_number' => $partNumber,
                'name' => $name,
                'model_label' => 'Model Y 01.2020 - 01.2025',
                'model_name' => 'Model Y',
            ]);
        }

        $preview = app(DonorProductGenerationService::class)->preview($donorCar, []);
        $names = collect($preview['items'])->pluck('name')->all();

        $this->assertContains('HIGH VOLTAGE BATTERY - LONG RANGE - AWD - 1PH', $names);
        $this->assertContains('DAMPER ASSEMBLY E3 - DUAL MOTOR - FRONT LEFT HAND', $names);
        $this->assertContains('BOLT - M12x35', $names);
        $this->assertNotContains('HIGH VOLTAGE BATTERY - STANDARD RANGE - REAR WHEEL DRIVE - 1 PHASE', $names);
        $this->assertNotContains('ASSEMBLY - HARNESS - FRONT - REAR WHEEL DRIVE', $names);
        $this->assertNotContains('BRAKE ROTOR - REAR - PERFORMANCE', $names);
    }

    public function test_preview_counts_damaged_existing_generated_parts_as_damaged(): void
    {
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000024',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2019,
        ]);

        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/catalogs/model-3/front-bumper',
            'part_number' => '1084174-00-C',
            'name' => 'Front bumper',
            'model_label' => 'Model 3',
            'model_name' => 'Model 3',
        ]);

        $generator = app(DonorProductGenerationService::class);
        $generator->generate($donorCar, [], [$catalogItem->id]);

        $preview = $generator->preview($donorCar, ['front']);

        $this->assertSame(1, $preview['summary']['damaged']);
        $this->assertSame(0, $preview['summary']['selectable']);
        $this->assertSame(1, $preview['summary']['updatable']);
        $this->assertTrue($preview['items'][0]['already_generated']);
        $this->assertSame('', $preview['items'][0]['condition_label']);
    }

    public function test_donor_car_show_page_renders_stored_photos(): void
    {
        Storage::fake('public');

        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000002',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
            'purchase_date' => '2026-04-15',
            'photos' => ['donor-cars/front.jpg', 'donor-cars/rear.jpg'],
        ]);

        $response = $this->actingAs($user)->get(route('admin.donor-cars.show', $donorCar));

        $response->assertOk();
        $response->assertSee('15.04.2026');
        $response->assertSee('/storage/donor-cars/front.jpg', false);
        $response->assertSee('/storage/donor-cars/rear.jpg', false);
        $response->assertSee(route('admin.donor-cars.photos.destroy', $donorCar), false);
        $response->assertSee('data-donor-photo-delete-form', false);
        $response->assertSee('data-donor-photo-dropzone', false);
    }

    public function test_donor_car_photos_can_be_deleted_from_show_page(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('donor-cars/front.jpg', 'front');
        Storage::disk('public')->put('donor-cars/rear.jpg', 'rear');

        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000003',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
            'purchase_date' => '2026-04-15',
            'photos' => ['donor-cars/front.jpg', 'donor-cars/rear.jpg'],
        ]);

        $this->actingAs($user)
            ->get(route('admin.donor-cars.show', $donorCar))
            ->assertOk()
            ->assertSee(route('admin.donor-cars.photos.destroy', $donorCar), false)
            ->assertSee('data-donor-photo-delete-form', false);

        $response = $this->actingAs($user)
            ->from(route('admin.donor-cars.show', $donorCar))
            ->delete(route('admin.donor-cars.photos.destroy', $donorCar), [
                'photo' => 'donor-cars/front.jpg',
            ]);

        $response
            ->assertRedirect(route('admin.donor-cars.show', $donorCar))
            ->assertSessionHasNoErrors();

        $this->assertSame(['donor-cars/rear.jpg'], $donorCar->refresh()->photos);
        Storage::disk('public')->assertMissing('donor-cars/front.jpg');
        Storage::disk('public')->assertExists('donor-cars/rear.jpg');
    }

    public function test_donor_car_show_uses_product_condition_for_generated_official_parts(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-donor-official-condition@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000060',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
        ]);

        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/catalogs/model-3/official-condition',
            'part_number' => '1084174-00-C',
            'name' => 'Front bumper official',
        ]);

        Product::query()->create([
            'sku' => 'DONOR-OFFICIAL-CONDITION-001',
            'external_sku' => '1084174-00-C',
            'name' => 'Front bumper official',
            'slug' => 'front-bumper-official-condition',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $catalogItem->id,
            'is_auto_generated' => true,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'notes' => "\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}",
            'selling_price' => 100,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)
            ->get(route('admin.donor-cars.show', $donorCar))
            ->assertOk();

        $productRow = str($response->getContent())
            ->after('DONOR-OFFICIAL-CONDITION-001')
            ->before('</tr>')
            ->toString();

        $this->assertStringNotContainsString("\u{041F}\u{0440}\u{043E}\u{0432}\u{0435}\u{0440}\u{0435}\u{043D}\u{0430}", $productRow);
        $this->assertStringContainsString("\u{0410}\u{0432}\u{0442}\u{043E}\u{043C}\u{0430}\u{0442}\u{0438}\u{0447}\u{0435}\u{0441}\u{043A}\u{0438} \u{0441}\u{0433}\u{0435}\u{043D}\u{0435}\u{0440}\u{0438}\u{0440}\u{043E}\u{0432}\u{0430}\u{043D}\u{043E}", $productRow);
        $this->assertStringContainsString('>A</span>', $productRow);
        $this->assertStringContainsString('Б/У', $productRow);
    }

    public function test_donor_car_show_allows_editing_damage_note_for_manual_checked_products(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-donor-manual-damage-edit@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000062',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
        ]);
        $product = Product::query()->create([
            'sku' => 'DONOR-MANUAL-DAMAGE-001',
            'external_sku' => '1084174-00-C',
            'name' => 'Manual checked bumper',
            'slug' => 'manual-checked-bumper',
            'donor_car_id' => $donorCar->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'notes' => "\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}",
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)
            ->get(route('admin.donor-cars.show', $donorCar))
            ->assertOk();

        $productRow = str($response->getContent())
            ->after('DONOR-MANUAL-DAMAGE-001')
            ->before('</tr>')
            ->toString();

        $this->assertStringContainsString('data-donor-damage-select', $productRow);
        $this->assertStringNotContainsString('Проверена', $productRow);
        $this->assertStringContainsString('Добавлено вручну', $productRow);
        $this->assertStringContainsString('>Р </span>', $productRow);
        $englishNameRow = str($productRow)
            ->after('Manual checked bumper')
            ->before('</div>')
            ->toString();
        $this->assertStringContainsString('>Р </span>', $englishNameRow);
        $damageCell = str($productRow)
            ->after('data-donor-damage-form')
            ->before('</td>')
            ->toString();
        $this->assertStringNotContainsString('>Р </span>', $damageCell);

        $this->actingAs($user)
            ->patch(route('admin.donor-cars.products.official-fields.update', [$donorCar, $product]), [
                'damage_note' => '',
            ])
            ->assertRedirect();

        $product->refresh();

        $this->assertNull($product->notes);
        $this->assertSame('used', $product->condition_type);
    }

    public function test_checked_donor_damage_update_places_product_in_selected_location(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-donor-damage-placement@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000063',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
        ]);
        $warehouse = Warehouse::query()->create([
            'name' => 'Parts Warehouse',
            'floor_count' => 2,
            'is_active' => true,
        ]);
        Location::query()->create([
            'warehouse_id' => $warehouse->id,
            'floor' => 'floor_1',
            'full_code' => 'PW-F1-A1',
            'cell' => 'A1',
            'is_active' => true,
        ]);
        $targetLocation = Location::query()->create([
            'warehouse_id' => $warehouse->id,
            'floor' => 'floor_2',
            'full_code' => 'PW-F2-B7',
            'cell' => 'B7',
            'is_active' => true,
        ]);
        $product = Product::query()->create([
            'sku' => 'DONOR-DAMAGE-PLACEMENT-001',
            'external_sku' => '1084174-00-P',
            'name' => 'Placement checked bumper',
            'slug' => 'placement-checked-bumper',
            'donor_car_id' => $donorCar->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'notes' => null,
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.donor-cars.show', $donorCar))
            ->assertOk()
            ->assertSee('data-donor-placement-dialog', false)
            ->assertSee('data-donor-placement-warehouse', false)
            ->assertSee('Parts Warehouse')
            ->assertSee('data-previous-damage-note', false);

        $response = $this->actingAs($user)
            ->patchJson(route('admin.donor-cars.products.official-fields.update', [$donorCar, $product]), [
                'damage_note' => "\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}",
                'warehouse_id' => $warehouse->id,
                'floor' => 'floor_2',
                'location_id' => $targetLocation->id,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Данные запчасти обновлены.')
            ->assertJsonPath('destination', 'checked')
            ->assertJsonPath('damage_note', "\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}");

        $this->assertStringContainsString('Parts Warehouse', (string) $response->json('stock_label'));
        $this->assertStringContainsString('B7', (string) $response->json('stock_label'));

        $product->refresh();
        $this->assertSame(Product::STORAGE_STATUS_IN_STOCK, $product->storage_status);
        $this->assertSame("\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}", $product->notes);
        $this->assertDatabaseHas('stock_items', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'location_id' => $targetLocation->id,
            'quantity' => 1,
        ]);

        $this->actingAs($user)
            ->get(route('admin.donor-cars.show', $donorCar))
            ->assertOk()
            ->assertSee('Parts Warehouse')
            ->assertSee('Этаж 2')
            ->assertSee('B7')
            ->assertSee('data-donor-placement-edit', false);

        $nextLocation = Location::query()->create([
            'warehouse_id' => $warehouse->id,
            'floor' => 'floor_2',
            'full_code' => 'PW-F2-C9',
            'cell' => 'C9',
            'is_active' => true,
        ]);

        $updateResponse = $this->actingAs($user)
            ->patchJson(route('admin.donor-cars.products.official-fields.update', [$donorCar, $product]), [
                'warehouse_id' => $warehouse->id,
                'floor' => 'floor_2',
                'location_id' => $nextLocation->id,
            ]);

        $updateResponse
            ->assertOk()
            ->assertJsonPath('destination', 'checked')
            ->assertJsonPath('stock_location.location_id', $nextLocation->id);

        $this->assertStringContainsString('Parts Warehouse', (string) $updateResponse->json('stock_label'));
        $this->assertStringContainsString('Этаж 2', (string) $updateResponse->json('stock_label'));
        $this->assertStringContainsString('C9', (string) $updateResponse->json('stock_label'));
        $this->assertDatabaseHas('stock_items', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'location_id' => $nextLocation->id,
            'quantity' => 1,
        ]);

        $resetResponse = $this->actingAs($user)
            ->patchJson(route('admin.donor-cars.products.official-fields.update', [$donorCar, $product]), [
                'damage_note' => '',
            ]);

        $resetResponse
            ->assertOk()
            ->assertJsonPath('destination', 'all')
            ->assertJsonPath('stock_label', Warehouse::DONOR_WAREHOUSE_NAME);

        $donorStockItem = $product->refresh()
            ->stockItems()
            ->whereHas('warehouse', fn ($query) => $query->where('type', Warehouse::TYPE_DONOR))
            ->where('quantity', '>', 0)
            ->with('location')
            ->first();
        $this->assertNotNull($donorStockItem);
        $this->assertSame('ON-DONOR-'.$donorCar->id, $donorStockItem->location?->full_code);
    }

    public function test_donor_damage_note_update_keeps_missing_localized_names_static(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-donor-autonames@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF900062',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
        ]);
        $warehouse = Warehouse::query()->create([
            'name' => 'Autoname placement',
            'floor_count' => 1,
            'is_active' => true,
        ]);
        $location = Location::query()->create([
            'warehouse_id' => $warehouse->id,
            'full_code' => 'AUTONAME-A1',
            'cell' => 'A1',
            'is_active' => true,
        ]);
        $officialItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/catalogs/donor-autonames',
            'part_number' => '1084174-00-E',
            'name' => 'Front Bumper',
            'name_en' => 'Front Bumper',
        ]);
        $sourceNameRu = "\u{0411}\u{0430}\u{043C}\u{043F}\u{0435}\u{0440} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0438}\u{0439}";
        $sourceNameUa = "\u{0411}\u{0430}\u{043C}\u{043F}\u{0435}\u{0440} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0456}\u{0439}";
        PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://existing-name-source/1084174-00-E',
            'part_number' => '1084174-00-E',
            'name' => $sourceNameRu,
            'name_ru' => $sourceNameRu,
            'name_ua' => $sourceNameUa,
        ]);
        $product = Product::query()->create([
            'sku' => 'DONOR-AUTONAMES-DESKTOP',
            'external_sku' => '1084174-00-E',
            'name' => 'Front Bumper',
            'slug' => 'donor-autonames-desktop',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $officialItem->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'notes' => null,
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->patch(route('admin.donor-cars.products.official-fields.update', [$donorCar, $product]), [
                'damage_note' => "\u{041B}\u{0435}\u{0433}\u{043A}\u{0438}\u{0435} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{044F}",
                'warehouse_id' => $warehouse->id,
                'location_id' => $location->id,
            ])
            ->assertRedirect();

        $mirror = PartCatalogItem::query()
            ->where('source', 'nikolacars')
            ->where('source_url', 'nikolacars://donor-product/'.$product->id)
            ->firstOrFail();

        $this->assertNull($mirror->name_ru);
        $this->assertNull($mirror->name_ua);
        $this->assertNull(data_get($mirror->raw_attributes, 'name_source_type_ua'));
    }

    public function test_donor_damage_note_update_preserves_manual_nikolacars_category(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-donor-manual-category-damage-edit@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA0LF611657',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
        ]);
        $category = PartCatalogCategory::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://manual-category/hv-battery',
            'depth' => 0,
            'name' => '16 - Высоковольтная батарея / 1601 - Высоковольтная батарея / Высоковольтная батарея',
            'name_ru' => '16 - Высоковольтная батарея / 1601 - Высоковольтная батарея / Высоковольтная батарея',
            'name_ua' => '16 - Высоковольтная батарея / 1601 - Высоковольтная батарея / Высоковольтная батарея',
        ]);
        $product = Product::query()->create([
            'sku' => 'NC-582',
            'external_sku' => '5YJ3E1EA0LF611657',
            'name' => 'Акумуляторна батарея ВВБ в зборі 52 кВт 5YJ3E1EA0LF611657',
            'slug' => 'nc-582',
            'donor_car_id' => $donorCar->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'notes' => "\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}",
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        PartCatalogItem::query()->create([
            'part_catalog_category_id' => $category->id,
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://donor-product/'.$product->id,
            'part_number' => '5YJ3E1EA0LF611657',
            'name' => $product->name,
            'main_category_name' => '16 - Высоковольтная батарея',
            'subcategory_name' => '1601 - Высоковольтная батарея',
            'node_name' => 'Высоковольтная батарея',
            'raw_attributes' => [
                'product_id' => $product->id,
                'donor_vin' => $donorCar->vin,
                'category_display' => $category->name,
                'category_path' => $category->name,
                'manual_category' => true,
                'manual_category_id' => $category->id,
            ],
        ]);

        $this->actingAs($user)
            ->patch(route('admin.donor-cars.products.official-fields.update', [$donorCar, $product]), [
                'damage_note' => "\u{041B}\u{0435}\u{0433}\u{043A}\u{0438}\u{0435} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{044F}",
            ])
            ->assertRedirect();

        $item = PartCatalogItem::query()
            ->where('source_url', 'nikolacars://donor-product/'.$product->id)
            ->firstOrFail();

        $this->assertSame($category->id, $item->part_catalog_category_id);
        $this->assertSame($category->name, data_get($item->raw_attributes, 'category_display'));
        $this->assertTrue((bool) data_get($item->raw_attributes, 'manual_category'));
        $this->assertSame("\u{041B}\u{0435}\u{0433}\u{043A}\u{0438}\u{0435} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{044F}", $item->quality);
    }

    public function test_donor_product_sale_price_can_be_updated_from_donor_card(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-donor-sale-price-edit@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA0LF611659',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
        ]);
        $product = Product::query()->create([
            'sku' => 'DONOR-PRICE-EDIT-001',
            'external_sku' => '1034344-20-B',
            'name' => 'Donor price editable part',
            'slug' => 'donor-price-edit-001',
            'donor_car_id' => $donorCar->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'notes' => "\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}",
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://donor-product/'.$product->id,
            'part_number' => $product->external_sku,
            'name' => $product->name,
            'price_amount' => 100,
            'currency' => 'USD',
            'quality' => $product->notes,
            'raw_attributes' => [
                'product_id' => $product->id,
                'donor_vin' => $donorCar->vin,
                'stock_quantity' => 1,
            ],
        ]);
        $product->forceFill(['source_part_catalog_item_id' => $item->id])->save();

        $response = $this->actingAs($user)
            ->get(route('admin.donor-cars.show', $donorCar))
            ->assertOk();

        $productRow = str($response->getContent())
            ->after('1034344-20-B')
            ->before('</tr>')
            ->toString();
        $usdRate = app(ExchangeRateService::class)->displayUsdRate();
        $salePriceUah = app(ExchangeRateService::class)
            ->productSellingPriceUahRoundedToTen(100, 'USD', $usdRate);
        $salePriceUahText = number_format($salePriceUah, 0, '.', ' ').' грн';

        $this->assertStringContainsString($salePriceUahText, $productRow);
        $this->assertStringContainsString('100.00 USD', $productRow);
        $this->assertStringContainsString('data-donor-price-edit-toggle', $productRow);
        $this->assertStringContainsString('data-donor-price-editor hidden', $productRow);
        $this->assertStringContainsString('name="selling_price"', $productRow);
        $this->assertStringContainsString('value="100.00"', $productRow);

        $this->actingAs($user)
            ->patch(route('admin.donor-cars.products.official-fields.update', [$donorCar, $product]), [
                'selling_price' => 245.75,
            ])
            ->assertRedirect();

        $this->assertSame('245.75', $product->refresh()->selling_price);
        $this->assertSame('245.75', $item->refresh()->price_amount);
        $this->assertSame('USD', $item->currency);
        $this->assertSame("\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}", $product->notes);
    }

    public function test_donor_card_keeps_tesla_price_separate_from_sale_price(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-donor-tesla-price-visible@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA0LF611660',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
        ]);
        $officialItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/catalogs/1034344-20-b',
            'part_number' => '1034344-20-B',
            'name' => 'Official donor price part',
            'price_amount' => 777.33,
            'currency' => 'USD',
        ]);
        $product = Product::query()->create([
            'sku' => 'DONOR-TESLA-PRICE-001',
            'external_sku' => '1034344-20-B',
            'name' => 'Official donor price part',
            'slug' => 'donor-tesla-price-001',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $officialItem->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'notes' => "\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}",
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)
            ->get(route('admin.donor-cars.show', $donorCar))
            ->assertOk();

        $productRow = str($response->getContent())
            ->after('1034344-20-B')
            ->before('</tr>')
            ->toString();
        $usdRate = app(ExchangeRateService::class)->displayUsdRate();
        $salePriceUah = app(ExchangeRateService::class)
            ->productSellingPriceUahRoundedToTen(100, 'USD', $usdRate);
        $salePriceUahText = number_format($salePriceUah, 0, '.', ' ').' грн';

        $response->assertSee('Цена <span>tesla.com</span>', false);
        $this->assertStringContainsString('777.33 USD', $productRow);
        $this->assertStringContainsString($salePriceUahText, $productRow);
        $this->assertStringContainsString('100.00 USD', $productRow);
        $this->assertStringContainsString('data-donor-price-edit-toggle', $productRow);
        $this->assertStringContainsString('data-donor-price-editor hidden', $productRow);
        $this->assertStringContainsString('name="selling_price"', $productRow);
        $this->assertStringContainsString('value="100.00"', $productRow);

        $this->actingAs($user)
            ->patch(route('admin.donor-cars.products.official-fields.update', [$donorCar, $product]), [
                'selling_price' => 245.75,
            ])
            ->assertRedirect();

        $this->assertSame('245.75', $product->refresh()->selling_price);
        $this->assertSame('777.33', $officialItem->refresh()->price_amount);
    }

    public function test_donor_card_shows_tesla_price_for_generated_product_mirrored_to_nikolacars(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-donor-mirrored-tesla-price-visible@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA0LF611661',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
        ]);
        $officialItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=1034344-20-B',
            'part_number' => '1034344-20-B',
            'name' => 'Official mirrored donor price part',
            'price_amount' => 777.33,
            'currency' => 'USD',
        ]);
        $product = Product::query()->create([
            'sku' => 'DONOR-TESLA-PRICE-002',
            'external_sku' => '1034344-20-B',
            'name' => 'Official mirrored donor price part',
            'slug' => 'donor-tesla-price-002',
            'donor_car_id' => $donorCar->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'notes' => "\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}",
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 0,
            'currency' => 'USD',
            'is_auto_generated' => true,
            'generated_at' => now(),
            'is_active' => true,
        ]);
        $nikolaCarsItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://donor-product/'.$product->id,
            'part_number' => $product->external_sku,
            'name' => $product->name,
            'price_amount' => 0,
            'currency' => 'USD',
            'raw_attributes' => [
                'product_id' => $product->id,
                'donor_vin' => $donorCar->vin,
                'source_catalog_item_id' => $officialItem->id,
                'source_catalog_source' => 'tesla_official',
            ],
        ]);
        $product->forceFill(['source_part_catalog_item_id' => $nikolaCarsItem->id])->save();

        $response = $this->actingAs($user)
            ->get(route('admin.donor-cars.show', $donorCar))
            ->assertOk();

        $productRow = str($response->getContent())
            ->after('1034344-20-B')
            ->before('</tr>')
            ->toString();

        $this->assertStringContainsString('777.33 USD', $productRow);
        $this->assertStringContainsString('0.00 USD', $productRow);
    }

    public function test_donor_card_uses_official_annotation_context_price_when_catalog_price_is_empty(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-donor-annotation-tesla-price-visible@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA0LF611657',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
        ]);
        $officialItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=1978118-S0-A',
            'part_number' => '1978118-S0-A',
            'name' => 'Assembly front rail complete left hand',
            'price_amount' => null,
            'currency' => null,
            'raw_attributes' => [
                'tesla_scheme_annotation_contexts' => [
                    ['price' => 3045, 'currency' => 'CAD'],
                    ['price' => 2200, 'currency' => 'USD'],
                ],
            ],
        ]);
        Product::query()->create([
            'sku' => 'DONOR-TESLA-PRICE-003',
            'external_sku' => '1978118-S0-A',
            'name' => 'Assembly front rail complete left hand',
            'slug' => 'donor-tesla-price-003',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $officialItem->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 0,
            'currency' => 'USD',
            'is_auto_generated' => true,
            'generated_at' => now(),
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)
            ->get(route('admin.donor-cars.show', $donorCar))
            ->assertOk();

        $productRow = str($response->getContent())
            ->after('1978118-S0-A')
            ->before('</tr>')
            ->toString();

        $this->assertStringContainsString('2 200.00 USD', $productRow);
        $this->assertStringContainsString('0.00 USD', $productRow);
    }

    public function test_donor_card_does_not_use_generated_sale_price_as_tesla_price_when_official_price_is_missing(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-donor-legacy-generated-tesla-price-visible@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA0LF611658',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
        ]);
        $officialItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=1978119-S0-A',
            'part_number' => '1978119-S0-A',
            'name' => 'Assembly front rail complete right hand',
            'price_amount' => null,
            'currency' => null,
        ]);
        Product::query()->create([
            'sku' => 'DONOR-TESLA-PRICE-004',
            'external_sku' => '1978119-S0-A',
            'name' => 'Assembly front rail complete right hand',
            'slug' => 'donor-tesla-price-004',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $officialItem->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 2200,
            'currency' => 'USD',
            'is_auto_generated' => true,
            'generated_at' => Carbon::parse('2026-05-13 23:05:52'),
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)
            ->get(route('admin.donor-cars.show', $donorCar))
            ->assertOk();

        $productRow = str($response->getContent())
            ->after('1978119-S0-A')
            ->before('</tr>')
            ->toString();

        $this->assertMatchesRegularExpression('/<td>\s*-\s*<\/td>\s*<td class="donor-product-sale-price-cell"/', $productRow);
        $this->assertStringContainsString('2 200.00 USD', $productRow);
    }

    public function test_donor_car_show_page_renders_live_product_search(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-donor-product-search@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $donorCar = DonorCar::query()->create([
            'vin' => '5YJYGDED4MF109750',
            'brand' => 'Tesla',
            'model' => 'Model Y',
            'year' => 2021,
        ]);

        Product::query()->create([
            'sku' => 'DONOR-SEARCH-001',
            'external_sku' => '1494770-00-A',
            'name' => 'Front bumper carrier',
            'slug' => 'front-bumper-carrier-search',
            'donor_car_id' => $donorCar->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->get(route('admin.donor-cars.show', $donorCar));

        $response->assertOk();
        $response->assertSee('data-donor-products-search', false);
        $response->assertSee('placeholder="Артикул или название"', false);
        $response->assertSee('data-donor-product-row', false);
        $response->assertSee('1494770-00-A', false);
        $response->assertSee('Front bumper carrier');
        $response->assertSee('applyDonorProductsSearch', false);
    }

    public function test_donor_car_show_page_does_not_group_remaining_products_by_article_prefix(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-donor-product-prefix@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $donorCar = DonorCar::query()->create([
            'vin' => '5YJYGDEE1MF123456',
            'brand' => 'Tesla',
            'model' => 'Model Y',
            'year' => 2021,
        ]);

        foreach ([
            ['sku' => 'DONOR-PREFIX-003', 'external_sku' => '1494771-00-A', 'name' => 'Rear bumper carrier'],
            ['sku' => 'DONOR-PREFIX-001', 'external_sku' => '1494770-00-A', 'name' => 'Front bumper carrier'],
            ['sku' => 'DONOR-PREFIX-002', 'external_sku' => '1494770-00-B', 'name' => 'Front bumper carrier revision'],
        ] as $index => $product) {
            $catalogItem = PartCatalogItem::query()->create([
                'source' => 'tesla_official',
                'source_url' => 'https://example.test/donor-prefix-product-'.$index,
                'part_number' => $product['external_sku'],
                'name' => $product['name'],
            ]);

            Product::query()->create([
                'sku' => $product['sku'],
                'external_sku' => $product['external_sku'],
                'name' => $product['name'],
                'slug' => 'donor-prefix-product-'.$index,
                'donor_car_id' => $donorCar->id,
                'source_part_catalog_item_id' => $catalogItem->id,
                'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
                'condition_type' => 'used',
                'testing_status' => 'not_tested',
                'unit' => 'pcs',
                'selling_price' => 100,
                'currency' => 'USD',
                'is_auto_generated' => true,
                'is_active' => true,
            ]);
        }

        $response = $this->actingAs($user)->get(route('admin.donor-cars.show', $donorCar));

        $response->assertOk();
        $response->assertDontSee('class="variant-inline-group-header"', false);
        $response->assertDontSee('data-donor-variant-key="article-prefix:1494770"', false);
        $response->assertDontSee('data-donor-variant-key="article-prefix:1494771"', false);
        $response->assertSeeInOrder([
            '1494770-00-A',
            '1494770-00-B',
            '1494771-00-A',
        ]);
    }

    public function test_donor_car_show_uses_specific_localized_catalog_categories_for_display_and_filter(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-donor-product-specific-categories@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $donorCar = DonorCar::query()->create([
            'vin' => '5YJYGDEE1MF654321',
            'brand' => 'Tesla',
            'model' => 'Model Y',
            'year' => 2021,
        ]);

        $model = PartCatalogCategory::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/catalogs/model-y',
            'depth' => 0,
            'name' => 'Model Y',
            'model_label' => 'Model Y',
        ]);
        $battery = PartCatalogCategory::query()->create([
            'parent_id' => $model->id,
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/catalogs/model-y/battery',
            'depth' => 1,
            'code' => '16',
            'name' => 'High Voltage Battery',
            'name_ru' => "16 - \u{0412}\u{044B}\u{0441}\u{043E}\u{043A}\u{043E}\u{0432}\u{043E}\u{043B}\u{044C}\u{0442}\u{043D}\u{0430}\u{044F} \u{0411}\u{0430}\u{0442}\u{0430}\u{0440}\u{0435}\u{044F}",
            'name_ua' => "16 - \u{0412}\u{0438}\u{0441}\u{043E}\u{043A}\u{043E}\u{0432}\u{043E}\u{043B}\u{044C}\u{0442}\u{043D}\u{0430} \u{0411}\u{0430}\u{0442}\u{0430}\u{0440}\u{0435}\u{044F}",
            'model_label' => 'Model Y',
        ]);
        $driveUnit = PartCatalogCategory::query()->create([
            'parent_id' => $model->id,
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/catalogs/model-y/drive-unit',
            'depth' => 1,
            'code' => '40',
            'name' => 'Drive Unit',
            'name_ru' => "40 - \u{041F}\u{0440}\u{0438}\u{0432}\u{043E}\u{0434} / \u{043C}\u{043E}\u{0442}\u{043E}\u{0440}\u{044B}",
            'model_label' => 'Model Y',
        ]);
        $rearMotor = PartCatalogCategory::query()->create([
            'parent_id' => $driveUnit->id,
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/catalogs/model-y/rear-motor',
            'depth' => 2,
            'name' => 'Rear Motor',
            'name_ru' => "\u{0417}\u{0430}\u{0434}\u{043D}\u{0438}\u{0439} \u{041C}\u{043E}\u{0442}\u{043E}\u{0440}",
            'model_label' => 'Model Y',
        ]);
        $frontMotor = PartCatalogCategory::query()->create([
            'parent_id' => $driveUnit->id,
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/catalogs/model-y/front-motor',
            'depth' => 2,
            'name' => 'Front Motor',
            'name_ua' => "\u{041F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0456}\u{0439} \u{041C}\u{043E}\u{0442}\u{043E}\u{0440}",
            'model_label' => 'Model Y',
        ]);
        $modelS = PartCatalogCategory::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/catalogs/model-s',
            'depth' => 0,
            'name' => 'Model S',
            'model_label' => 'Model S',
        ]);
        $modelSDriveUnit = PartCatalogCategory::query()->create([
            'parent_id' => $modelS->id,
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/catalogs/model-s/drive-unit',
            'depth' => 1,
            'code' => '40',
            'name' => 'Drive Unit',
            'name_ru' => "40 - \u{041F}\u{0440}\u{0438}\u{0432}\u{043E}\u{0434} / \u{043C}\u{043E}\u{0442}\u{043E}\u{0440}\u{044B}",
            'model_label' => 'Model S',
            'model_name' => 'Model S',
        ]);
        $modelSRearMotor = PartCatalogCategory::query()->create([
            'parent_id' => $modelSDriveUnit->id,
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/catalogs/model-s/rear-motor',
            'depth' => 2,
            'name' => 'Rear Motor',
            'name_ru' => "\u{0417}\u{0430}\u{0434}\u{043D}\u{0438}\u{0439} \u{041C}\u{043E}\u{0442}\u{043E}\u{0440} Model S",
            'model_label' => 'Model S',
            'model_name' => 'Model S',
        ]);
        $duplicateDriveUnit = PartCatalogCategory::query()->create([
            'parent_id' => $model->id,
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/catalogs/model-y/drive-unit-duplicate',
            'depth' => 1,
            'code' => '40',
            'name' => 'Drive Unit',
            'name_ru' => "40 - \u{041F}\u{0440}\u{0438}\u{0432}\u{043E}\u{0434} / \u{043C}\u{043E}\u{0442}\u{043E}\u{0440}\u{044B}",
            'model_label' => 'Model Y',
        ]);
        $duplicateRearMotor = PartCatalogCategory::query()->create([
            'parent_id' => $duplicateDriveUnit->id,
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/catalogs/model-y/rear-motor-duplicate',
            'depth' => 2,
            'name' => 'Rear Motor Duplicate',
            'name_ru' => "\u{0417}\u{0430}\u{0434}\u{043D}\u{0438}\u{0439} \u{041C}\u{043E}\u{0442}\u{043E}\u{0440}",
            'model_label' => 'Model Y',
        ]);

        foreach ([
            ['sku' => 'DONOR-CAT-BATTERY', 'part_number' => 'BATTERY-001', 'name' => 'HV Battery', 'category' => $battery],
            ['sku' => 'DONOR-CAT-REAR', 'part_number' => 'REAR-MOTOR-001', 'name' => 'Rear Motor', 'category' => $modelSRearMotor, 'occurrence_category' => $rearMotor],
            ['sku' => 'DONOR-CAT-FRONT', 'part_number' => 'FRONT-MOTOR-001', 'name' => 'Front Motor', 'category' => $frontMotor],
            ['sku' => 'DONOR-CAT-REAR-DUP', 'part_number' => 'REAR-MOTOR-002', 'name' => 'Rear Motor Duplicate', 'category' => $duplicateRearMotor],
        ] as $index => $part) {
            $catalogItem = PartCatalogItem::query()->create([
                'part_catalog_category_id' => $part['category']->id,
                'source' => 'tesla_official',
                'source_url' => 'https://parts.tesla.com/catalogs/model-y/category-test-'.$index,
                'part_number' => $part['part_number'],
                'name' => $part['name'],
            ]);
            PartCatalogItemOccurrence::query()->create([
                'part_catalog_item_id' => $catalogItem->id,
                'part_catalog_category_id' => ($part['occurrence_category'] ?? $part['category'])->id,
                'source' => 'tesla_official',
                'occurrence_key' => 'donor-category-test-'.$index,
                'part_number' => $part['part_number'],
                'name' => $part['name'],
            ]);

            Product::query()->create([
                'sku' => $part['sku'],
                'external_sku' => $part['part_number'],
                'name' => $part['name'],
                'slug' => 'donor-category-product-'.$index,
                'donor_car_id' => $donorCar->id,
                'source_part_catalog_item_id' => $catalogItem->id,
                'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
                'condition_type' => 'used',
                'testing_status' => 'not_tested',
                'unit' => 'pcs',
                'selling_price' => 100,
                'currency' => 'USD',
                'is_auto_generated' => true,
                'is_active' => true,
            ]);
        }

        $response = $this->actingAs($user)->get(route('admin.donor-cars.show', $donorCar));

        $response->assertOk();
        $response->assertSee("16 - \u{0412}\u{044B}\u{0441}\u{043E}\u{043A}\u{043E}\u{0432}\u{043E}\u{043B}\u{044C}\u{0442}\u{043D}\u{0430}\u{044F} \u{0411}\u{0430}\u{0442}\u{0430}\u{0440}\u{0435}\u{044F}");
        $response->assertSee("40 - \u{041F}\u{0440}\u{0438}\u{0432}\u{043E}\u{0434} / \u{043C}\u{043E}\u{0442}\u{043E}\u{0440}\u{044B} / \u{0417}\u{0430}\u{0434}\u{043D}\u{0438}\u{0439} \u{041C}\u{043E}\u{0442}\u{043E}\u{0440}");
        $response->assertSee("40 - \u{041F}\u{0440}\u{0438}\u{0432}\u{043E}\u{0434} / \u{043C}\u{043E}\u{0442}\u{043E}\u{0440}\u{044B} / \u{041F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0456}\u{0439} \u{041C}\u{043E}\u{0442}\u{043E}\u{0440}");
        $batteryFilterKey = 'label:'.md5(mb_strtolower("\u{0412}\u{044B}\u{0441}\u{043E}\u{043A}\u{043E}\u{0432}\u{043E}\u{043B}\u{044C}\u{0442}\u{043D}\u{0430}\u{044F} \u{0411}\u{0430}\u{0442}\u{0430}\u{0440}\u{0435}\u{044F}"));
        $driveUnitFilterKey = 'label:'.md5(mb_strtolower("\u{041F}\u{0440}\u{0438}\u{0432}\u{043E}\u{0434} / \u{043C}\u{043E}\u{0442}\u{043E}\u{0440}\u{044B}"));
        $response->assertSee('value="'.$batteryFilterKey.'"', false);
        $response->assertSee('value="'.$driveUnitFilterKey.'"', false);
        $response->assertSee('data-donor-product-category="'.$driveUnitFilterKey.'"', false);
        $this->assertSame(1, substr_count($response->getContent(), "data-category-label=\"\u{041F}\u{0440}\u{0438}\u{0432}\u{043E}\u{0434} / \u{043C}\u{043E}\u{0442}\u{043E}\u{0440}\u{044B}\""));
        $response->assertDontSee("16 - 16 - \u{0412}\u{044B}\u{0441}\u{043E}\u{043A}\u{043E}\u{0432}\u{043E}\u{043B}\u{044C}\u{0442}\u{043D}\u{0430}\u{044F}", false);
        $response->assertDontSee("40 - 40 - \u{041F}\u{0440}\u{0438}\u{0432}\u{043E}\u{0434}", false);
        $response->assertDontSee('>Батарея / HV</option>', false);
        $response->assertDontSee('ru:', false);
        $response->assertDontSee('ua:', false);
    }

    public function test_donor_car_category_filter_prefers_product_top_category_path(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-donor-product-local-category@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJSA1H1XEFP59563',
            'brand' => 'Tesla',
            'model' => 'Model S',
            'year' => 2015,
        ]);
        $bodyCategory = PartCatalogCategory::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-official://body',
            'depth' => 1,
            'name' => 'BODY',
            'name_ru' => "\u{041A}\u{0423}\u{0417}\u{041E}\u{0412}",
            'model_label' => 'Model S',
        ]);
        $catalogItem = PartCatalogItem::query()->create([
            'part_catalog_category_id' => $bodyCategory->id,
            'source' => 'tesla_official',
            'source_url' => 'tesla-official://1005817-00-A',
            'part_number' => '1005817-00-A',
            'name' => 'Rear drive unit',
        ]);
        $localRearMotor = Category::query()->create([
            'name' => '40 - REAR DRIVE UNIT / 4001 - Rear Drive Unit Assembly / Motor - Large Rear Drive Unit',
            'slug' => 'rear-drive-unit',
        ]);
        Product::query()->create([
            'sku' => 'DON22-0001',
            'external_sku' => '1005817-00-A',
            'name' => 'Rear drive unit',
            'slug' => 'don22-0001',
            'donor_car_id' => $donorCar->id,
            'category_id' => $localRearMotor->id,
            'source_part_catalog_item_id' => $catalogItem->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'is_auto_generated' => true,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->get(route('admin.donor-cars.show', $donorCar));
        $rearMotorFilterLabel = 'Rear drive unit';
        $rearMotorFilterKey = 'label:'.md5(mb_strtolower($rearMotorFilterLabel));

        $response->assertOk();
        $response->assertSee('data-category-label="'.$rearMotorFilterLabel.'"', false);
        $response->assertSee('value="'.$rearMotorFilterKey.'"', false);
        $response->assertSee('data-donor-product-category="'.$rearMotorFilterKey.'"', false);
        $response->assertDontSee('data-category-label="Кузов"', false);
    }

    public function test_donor_car_show_displays_undefined_category_when_part_has_no_category(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-donor-product-undefined-category@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJSA1E26HF000111',
            'brand' => 'Tesla',
            'model' => 'Model S',
            'year' => 2017,
        ]);
        Product::query()->create([
            'sku' => 'DON17-0001',
            'external_sku' => 'UNKNOWN-CAT-001',
            'name' => 'Part without category',
            'slug' => 'don17-0001',
            'donor_car_id' => $donorCar->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'is_auto_generated' => false,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->get(route('admin.donor-cars.show', $donorCar));
        $undefinedCategoryLabel = "\u{041D}\u{0435} \u{043E}\u{043F}\u{0440}\u{0435}\u{0434}\u{0435}\u{043B}\u{0435}\u{043D}\u{043E}";
        $undefinedCategoryKey = 'label:'.md5(mb_strtolower($undefinedCategoryLabel));

        $response->assertOk();
        $response->assertSee('data-category-label="'.$undefinedCategoryLabel.'"', false);
        $response->assertSee('value="'.$undefinedCategoryKey.'"', false);
        $response->assertSee('data-donor-product-category="'.$undefinedCategoryKey.'"', false);
        $this->assertMatchesRegularExpression('/<td>\s*'.preg_quote($undefinedCategoryLabel, '/').'\s*<\/td>/u', $response->getContent());
        $response->assertDontSee('data-category-label="Part without category"', false);
    }

    public function test_donor_car_sold_part_filter_uses_linked_catalog_category_instead_of_legacy_sale_path(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-donor-sale-linked-category@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA0LF611657',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2019,
        ]);
        $undefinedCategory = PartCatalogCategory::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://tesla-category/undefined',
            'depth' => 0,
            'name' => "\u{041D}\u{0435} \u{043E}\u{043F}\u{0440}\u{0435}\u{0434}\u{0435}\u{043B}\u{0435}\u{043D}\u{043E}",
            'name_ru' => "\u{041D}\u{0435} \u{043E}\u{043F}\u{0440}\u{0435}\u{0434}\u{0435}\u{043B}\u{0435}\u{043D}\u{043E}",
            'name_ua' => "\u{041D}\u{0435} \u{043E}\u{043F}\u{0440}\u{0435}\u{0434}\u{0435}\u{043B}\u{0435}\u{043D}\u{043E}",
        ]);
        $catalogItem = PartCatalogItem::query()->create([
            'part_catalog_category_id' => $undefinedCategory->id,
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/590',
            'part_number' => '1101751-S0-B',
            'name' => 'Legacy sale part',
            'name_ua' => "\u{041B}\u{043E}\u{043D}\u{0436}\u{0435}\u{0440}\u{043E}\u{043D} \u{043F}\u{0440}\u{0430}\u{0432}\u{0438}\u{0439}",
            'raw_attributes' => [
                'donor_vin' => '5YJ3E1EA0LF611657',
                'category_display' => "\u{041D}\u{0435} \u{043E}\u{043F}\u{0440}\u{0435}\u{0434}\u{0435}\u{043B}\u{0435}\u{043D}\u{043E}",
                'category_path' => "\u{041D}\u{0435} \u{043E}\u{043F}\u{0440}\u{0435}\u{0434}\u{0435}\u{043B}\u{0435}\u{043D}\u{043E}",
            ],
        ]);
        PartSale::query()->create([
            'part_catalog_item_id' => $catalogItem->id,
            'donor_car_id' => $donorCar->id,
            'source' => 'nikolacars',
            'code' => '590',
            'part_number' => '1101751-S0-B',
            'name' => "\u{041B}\u{043E}\u{043D}\u{0436}\u{0435}\u{0440}\u{043E}\u{043D} \u{043F}\u{0440}\u{0430}\u{0432}\u{0438}\u{0439} 1101751-S0-B",
            'quantity' => 1,
            'unit_price' => 38.10,
            'currency' => 'USD',
            'sold_at' => now(),
            'document_number' => 'TEST-001',
            'counterparty' => "\u{041F}\u{043E}\u{043A}\u{0443}\u{043F}\u{0430}\u{0442}\u{0435}\u{043B}\u{044C}",
            'donor_vin' => '5YJ3E1EA0LF611657',
            'category_path' => 'Tesla; Tesla M3; Legacy donor group; Legacy sale category',
            'source_file' => 'legacy-sales.xls',
            'source_row_number' => 42,
            'source_row_hash' => 'legacy-sale-linked-category',
        ]);

        $response = $this->actingAs($user)->get(route('admin.donor-cars.show', $donorCar));
        $undefinedCategoryLabel = "\u{041D}\u{0435} \u{043E}\u{043F}\u{0440}\u{0435}\u{0434}\u{0435}\u{043B}\u{0435}\u{043D}\u{043E}";
        $undefinedCategoryKey = 'label:'.md5(mb_strtolower($undefinedCategoryLabel));

        $response->assertOk();
        $response->assertSee('data-category-label="'.$undefinedCategoryLabel.'"', false);
        $response->assertSee('data-donor-product-category="'.$undefinedCategoryKey.'"', false);
        $response->assertDontSee('Legacy donor group', false);
        $response->assertDontSee('Tesla; Tesla M3', false);
    }

    public function test_donor_car_show_hides_sold_part_sale_products_from_active_tabs(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-donor-hide-sold-products@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000061',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
        ]);
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/991',
            'part_number' => '1084174-00-C',
            'name' => 'Sold catalog bumper',
            'raw_attributes' => [
                'donor_vin' => '5YJ3E1EA7KF000061',
            ],
        ]);
        Product::query()->create([
            'sku' => 'DONOR-PARTSALE-SOLD-001',
            'external_sku' => '1084174-00-C',
            'name' => 'Sold catalog bumper product',
            'slug' => 'sold-catalog-bumper-product',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $catalogItem->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'notes' => "\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}",
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        Product::query()->create([
            'sku' => 'NC-582',
            'external_sku' => '5YJ3E1EA0LF611657',
            'name' => 'Sold donor battery without catalog item',
            'slug' => 'sold-donor-battery-without-catalog-item',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => 999999,
            'storage_status' => Product::STORAGE_STATUS_SOLD,
            'condition_type' => 'used',
            'notes' => "\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}",
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'is_active' => false,
        ]);
        PartSale::query()->create([
            'part_catalog_item_id' => $catalogItem->id,
            'donor_car_id' => $donorCar->id,
            'source' => 'nikolacars',
            'code' => '991',
            'part_number' => '1084174-00-C',
            'name' => 'Sold catalog bumper sale',
            'quantity' => 1,
            'unit_price' => 100,
            'currency' => 'USD',
            'sold_at' => now(),
            'source_row_hash' => 'donor-hide-sold-part-sale-product',
        ]);

        $response = $this->actingAs($user)->get(route('admin.donor-cars.show', $donorCar));

        $response->assertOk();
        $response->assertDontSee('DONOR-PARTSALE-SOLD-001');
        $response->assertDontSee('Sold donor battery without catalog item');
        $response->assertSee('Sold catalog bumper sale');
    }

    public function test_donor_car_show_hides_duplicate_manual_sold_orphan_sale(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-donor-hide-duplicate-manual-sale@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA0LF611657',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
        ]);
        $category = PartCatalogCategory::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://manual-category/hv-battery',
            'depth' => 0,
            'name' => '16 - Высоковольтная батарея / 1601 - Высоковольтная батарея / Высоковольтная батарея',
            'name_ru' => '16 - Высоковольтная батарея / 1601 - Высоковольтная батарея / Высоковольтная батарея',
        ]);
        $catalogItem = PartCatalogItem::query()->create([
            'part_catalog_category_id' => $category->id,
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://donor-product/battery',
            'part_number' => '5YJ3E1EA0LF611657',
            'name' => 'Sold donor battery',
            'raw_attributes' => [
                'manual_sold_at' => '2026-05-31',
                'category_display' => $category->name,
                'category_path' => $category->name,
                'manual_category' => true,
                'manual_category_id' => $category->id,
            ],
        ]);
        $product = Product::query()->create([
            'sku' => 'NC-582',
            'external_sku' => '5YJ3E1EA0LF611657',
            'name' => 'Sold donor battery',
            'slug' => 'sold-donor-battery',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $catalogItem->id,
            'storage_status' => Product::STORAGE_STATUS_SOLD,
            'condition_type' => 'used',
            'notes' => "\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}",
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 3400,
            'currency' => 'USD',
            'is_active' => false,
        ]);
        $rawAttributes = $catalogItem->raw_attributes->getArrayCopy();
        $rawAttributes['product_id'] = $product->id;
        $catalogItem->forceFill(['raw_attributes' => $rawAttributes])->save();

        PartSale::query()->create([
            'part_catalog_item_id' => $catalogItem->id,
            'donor_car_id' => $donorCar->id,
            'source' => 'nikolacars',
            'code' => 'NC-582',
            'part_number' => '5YJ3E1EA0LF611657',
            'name' => 'Sold donor battery',
            'quantity' => 1,
            'unit_price' => 3400,
            'currency' => 'USD',
            'sold_at' => '2026-05-31',
            'document_number' => 'manual-sold-before-june-2026',
            'raw_attributes' => [
                'product_id' => $product->id,
                'manual_sold_at' => '2026-05-31',
            ],
            'source_file' => 'manual-zapchasti-cleanup',
            'source_row_number' => $catalogItem->id,
            'source_row_hash' => 'manual-sold-before-june-2026-'.$catalogItem->id,
        ]);
        PartSale::query()->create([
            'part_catalog_item_id' => null,
            'donor_car_id' => $donorCar->id,
            'source' => 'nikolacars',
            'code' => 'NC-582',
            'part_number' => '5YJ3E1EA0LF611657',
            'name' => 'Sold donor battery',
            'quantity' => 1,
            'unit_price' => 3400,
            'currency' => 'USD',
            'sold_at' => '2026-05-31',
            'document_number' => 'manual-sold-before-june-2026',
            'raw_attributes' => [
                'product_id' => $product->id,
                'manual_sold_at' => '2026-05-31',
                'missing_part_catalog_item_id' => 155102,
            ],
            'source_file' => 'manual-zapchasti-cleanup',
            'source_row_number' => $product->id,
            'source_row_hash' => 'manual-sold-before-june-2026-product-'.$product->id,
        ]);

        $response = $this->actingAs($user)->get(route('admin.donor-cars.show', $donorCar));

        $response->assertOk();
        $response->assertSeeText('Высоковольтная батарея');
        $this->assertSame(1, substr_count($response->getContent(), route('admin.products.show', $product)));
    }

    public function test_donor_car_show_recovers_category_for_orphan_manual_sold_sale(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-donor-recover-orphan-manual-sale-category@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA0LF611657',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
        ]);
        $category = PartCatalogCategory::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://manual-category/hv-battery-orphan',
            'depth' => 0,
            'name' => '16 - Высоковольтная батарея / 1601 - Высоковольтная батарея / Высоковольтная батарея',
            'name_ru' => '16 - Высоковольтная батарея / 1601 - Высоковольтная батарея / Высоковольтная батарея',
        ]);
        $product = Product::query()->create([
            'sku' => 'NC-582',
            'external_sku' => '5YJ3E1EA0LF611657',
            'name' => 'Sold orphan donor battery',
            'slug' => 'sold-orphan-donor-battery',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => 155102,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'notes' => "\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}",
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 3400,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        PartCatalogItem::query()->create([
            'part_catalog_category_id' => $category->id,
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://donor-product/'.$product->id,
            'part_number' => '5YJ3E1EA0LF611657',
            'name' => 'Sold orphan donor battery',
            'raw_attributes' => [
                'product_id' => $product->id,
                'manual_sold_at' => '2026-05-31',
                'category_display' => $category->name,
                'category_path' => $category->name,
                'manual_category' => true,
                'manual_category_id' => $category->id,
            ],
        ]);
        PartSale::query()->create([
            'part_catalog_item_id' => null,
            'donor_car_id' => $donorCar->id,
            'source' => 'nikolacars',
            'code' => 'NC-582',
            'part_number' => '5YJ3E1EA0LF611657',
            'name' => 'Sold orphan donor battery',
            'quantity' => 1,
            'unit_price' => 3400,
            'currency' => 'USD',
            'sold_at' => '2026-05-31',
            'document_number' => 'manual-sold-before-june-2026',
            'raw_attributes' => [
                'product_id' => $product->id,
                'manual_sold_at' => '2026-05-31',
                'missing_part_catalog_item_id' => 155102,
            ],
            'source_file' => 'manual-zapchasti-cleanup',
            'source_row_number' => $product->id,
            'source_row_hash' => 'manual-sold-before-june-2026-product-'.$product->id,
        ]);

        $response = $this->actingAs($user)->get(route('admin.donor-cars.show', $donorCar));

        $response->assertOk();
        $response->assertSeeText('Высоковольтная батарея');
        $response->assertDontSeeText('Не определено');
        $this->assertSame(1, substr_count($response->getContent(), route('admin.products.show', $product)));
    }

    public function test_donor_car_show_recovers_category_for_product_with_stale_catalog_link(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-donor-recover-stale-product-category@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA0LF611657',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
        ]);
        $category = PartCatalogCategory::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://manual-category/hv-battery-stale-product',
            'depth' => 0,
            'name' => '16 - Высоковольтная батарея / 1601 - Высоковольтная батарея / Высоковольтная батарея',
            'name_ru' => '16 - Высоковольтная батарея / 1601 - Высоковольтная батарея / Высоковольтная батарея',
        ]);
        $product = Product::query()->create([
            'sku' => 'NC-582',
            'external_sku' => '5YJ3E1EA0LF611657',
            'name' => 'Active donor battery with stale catalog link',
            'slug' => 'active-donor-battery-with-stale-catalog-link',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => 155102,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'notes' => "\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}",
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 3400,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        PartCatalogItem::query()->create([
            'part_catalog_category_id' => $category->id,
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://donor-product/'.$product->id,
            'part_number' => '5YJ3E1EA0LF611657',
            'name' => 'Active donor battery with stale catalog link',
            'raw_attributes' => [
                'product_id' => $product->id,
                'category_display' => $category->name,
                'category_path' => $category->name,
                'manual_category' => true,
                'manual_category_id' => $category->id,
            ],
        ]);

        $response = $this->actingAs($user)->get(route('admin.donor-cars.show', $donorCar));

        $response->assertOk();
        $response->assertSeeText('Высоковольтная батарея');
        $response->assertDontSeeText('Не определено');
    }

    public function test_donor_car_show_uses_nikolacars_product_mirror_category_over_product_import_category(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-donor-mirror-category-over-import@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA0LF611657',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
        ]);
        $productCategory = Category::query()->create([
            'name' => 'Donor imports',
            'slug' => 'donor-imports',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $legacyCatalogItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-official://legacy-donor-import-category',
            'part_number' => '1101751-S0-B',
            'name' => 'Legacy donor import category item',
        ]);
        $product = Product::query()->create([
            'sku' => 'NC-590',
            'external_sku' => '1101751-S0-B',
            'name' => 'Right upper rail',
            'slug' => 'right-upper-rail',
            'donor_car_id' => $donorCar->id,
            'category_id' => $productCategory->id,
            'source_part_catalog_item_id' => $legacyCatalogItem->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'notes' => 'Без повреждений',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $undefinedCategory = PartCatalogCategory::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://tesla-category/undefined-import-overridden',
            'depth' => 0,
            'name' => 'Не определено',
            'name_ru' => 'Не определено',
            'name_ua' => 'Не определено',
        ]);
        PartCatalogItem::query()->create([
            'part_catalog_category_id' => $undefinedCategory->id,
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://donor-product/'.$product->id,
            'part_number' => '1101751-S0-B',
            'name' => 'Right upper rail',
            'main_category_name' => 'Не определено',
            'raw_attributes' => [
                'product_id' => $product->id,
                'donor_vin' => $donorCar->vin,
                'category_display' => 'Не определено',
                'category_path' => 'Не определено',
                'tesla_category_match' => [
                    'status' => 'not_found',
                    'match_type' => 'none',
                    'part_number' => '1101751-S0-B',
                    'part_prefix' => '1101751',
                    'category' => 'Не определено',
                ],
            ],
        ]);

        $response = $this->actingAs($user)->get(route('admin.donor-cars.show', $donorCar));

        $response->assertOk();
        $response->assertSeeText('Не определено');
        $response->assertDontSeeText('Donor imports');
    }

    public function test_donor_car_show_page_renders_photo_dropzone_without_photos(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000012',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
        ]);

        $response = $this->actingAs($user)->get(route('admin.donor-cars.show', $donorCar));

        $response->assertOk();
        $response->assertSee('data-donor-photo-dropzone', false);
        $response->assertSee('data-donor-photos-input', false);
    }

    public function test_donor_car_edit_page_renders_photo_dropzone(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000013',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
            'color' => 'White',
            'mileage' => 10000,
        ]);

        $response = $this->actingAs($user)->get(route('admin.donor-cars.edit', $donorCar));

        $response->assertOk();
        $response->assertSee('data-donor-form-photo-dropzone', false);
        $response->assertSee('data-donor-form-photos', false);
    }

    public function test_donor_car_edit_page_does_not_render_editable_identity_fields(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000014',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
            'color' => 'White',
            'mileage' => 10000,
        ]);

        $response = $this->actingAs($user)->get(route('admin.donor-cars.edit', $donorCar));

        $response->assertOk();
        $response->assertSee($donorCar->vin);
        $response->assertSee($donorCar->brand);
        $response->assertSee($donorCar->model);
        $response->assertDontSee('name="vin"', false);
        $response->assertDontSee('name="brand"', false);
        $response->assertDontSee('name="model"', false);
    }

    public function test_donor_car_update_keeps_identity_fields_unchanged(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000015',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
            'color' => 'White',
            'mileage' => 10000,
        ]);

        $response = $this->actingAs($user)->put(route('admin.donor-cars.update', $donorCar), [
            'vin' => 'BADVIN',
            'brand' => 'Other',
            'model' => 'Other Model',
            'status' => DonorCar::STATUS_DISMANTLING,
            'drive_type' => DonorCar::DRIVE_TYPE_REAR,
            'year' => 2021,
            'color' => 'Black',
            'paint_code' => 'PBSB',
            'mileage' => 12000,
        ]);

        $response->assertRedirect(route('admin.donor-cars.show', $donorCar));

        $donorCar->refresh();

        $this->assertSame('5YJ3E1EA7KF000015', $donorCar->vin);
        $this->assertSame('Tesla', $donorCar->brand);
        $this->assertSame('Model 3', $donorCar->model);
        $this->assertSame(DonorCar::STATUS_IN_TRANSIT, $donorCar->status);
        $this->assertSame(DonorCar::DRIVE_TYPE_REAR, $donorCar->drive_type);
        $this->assertSame(2021, $donorCar->year);
        $this->assertSame('Black', $donorCar->color);
        $this->assertSame('PBSB', $donorCar->paint_code);
        $this->assertSame(12000, $donorCar->mileage);
    }

    public function test_donor_car_edit_hides_cashbook_filled_finance_fields(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-locked-donor-field@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000016',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
            'color' => 'White',
            'mileage' => 10000,
            'estimated_cost_usd' => 15000,
            'donor_expense_sources' => [
                'estimated_cost_usd' => DonorCar::DONOR_EXPENSE_SOURCE_CASHBOOK,
            ],
        ]);

        $this->actingAs($user)
            ->get(route('admin.donor-cars.edit', $donorCar))
            ->assertOk()
            ->assertSee('Заполнено из Касса и работы')
            ->assertDontSee('name="estimated_cost_usd"', false)
            ->assertSee('name="usa_delivery_price_usd"', false);
    }

    public function test_donor_car_update_preserves_cashbook_filled_finance_fields(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-preserve-donor-field@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000017',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
            'color' => 'White',
            'mileage' => 10000,
            'estimated_cost_usd' => 15000,
            'usa_delivery_price_usd' => 900,
            'donor_expense_sources' => [
                'estimated_cost_usd' => DonorCar::DONOR_EXPENSE_SOURCE_VALERA_CASHBOOK,
            ],
        ]);

        $this->actingAs($user)
            ->put(route('admin.donor-cars.update', $donorCar), [
                'status' => DonorCar::STATUS_DISMANTLING,
                'drive_type' => DonorCar::DRIVE_TYPE_ALL,
                'year' => 2021,
                'color' => 'Black',
                'mileage' => 12000,
                'estimated_cost_usd' => 1,
                'usa_delivery_price_usd' => 1000,
            ])
            ->assertRedirect(route('admin.donor-cars.show', $donorCar));

        $donorCar->refresh();

        $this->assertSame('15000.00', $donorCar->estimated_cost_usd);
        $this->assertSame('1000.00', $donorCar->usa_delivery_price_usd);
        $this->assertSame('Black', $donorCar->color);
    }

    public function test_donor_car_allows_up_to_thirty_photos(): void
    {
        Storage::fake('public');

        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000005',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
            'purchase_date' => '2026-04-15',
            'photos' => collect(range(1, 29))->map(fn (int $index): string => "donor-cars/existing-{$index}.jpg")->all(),
        ]);

        $response = $this->actingAs($user)->post(route('admin.donor-cars.photos.store', $donorCar), [
            'photos' => [UploadedFile::fake()->image('new.jpg')],
        ]);

        $response->assertSessionHasNoErrors();

        $this->assertCount(30, $donorCar->refresh()->photos);
    }

    public function test_donor_car_rejects_more_than_thirty_photos(): void
    {
        Storage::fake('public');

        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000006',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
            'purchase_date' => '2026-04-15',
            'photos' => collect(range(1, 29))->map(fn (int $index): string => "donor-cars/existing-{$index}.jpg")->all(),
        ]);

        $response = $this->actingAs($user)->from(route('admin.donor-cars.show', $donorCar))->post(route('admin.donor-cars.photos.store', $donorCar), [
            'photos' => [
                UploadedFile::fake()->image('new-1.jpg'),
                UploadedFile::fake()->image('new-2.jpg'),
            ],
        ]);

        $response
            ->assertRedirect(route('admin.donor-cars.show', $donorCar))
            ->assertSessionHasErrors('photos');

        $this->assertCount(29, $donorCar->refresh()->photos);
    }

    public function test_donor_car_create_autofills_details_from_tesla_vin(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->post(route('admin.donor-cars.store'), [
            'vin' => '5yjygdee3mf214952',
            'color' => 'Белый',
            'paint_code' => 'PPSW',
            'mileage' => 12345,
            'purchase_date' => '2026-04-20',
            'estimated_cost_usd' => 10000,
        ]);

        $response->assertRedirect(route('admin.donor-cars.show', DonorCar::query()->where('vin', '5YJYGDEE3MF214952')->firstOrFail()));

        $this->assertDatabaseHas('donor_cars', [
            'vin' => '5YJYGDEE3MF214952',
            'brand' => 'Tesla',
            'status' => DonorCar::STATUS_IN_TRANSIT,
            'model' => 'Model Y 01.2020 - 01.2025',
            'year' => 2021,
            'color' => 'Белый',
            'paint_code' => 'PPSW',
            'mileage' => 12345,
            'estimated_cost_usd' => 10000,
        ]);

        $donorCar = DonorCar::query()->where('vin', '5YJYGDEE3MF214952')->firstOrFail();

        $this->assertSame('2026-04-20', $donorCar->purchase_date->format('Y-m-d'));
    }

    public function test_donor_car_create_ignores_submitted_status(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->post(route('admin.donor-cars.store'), [
            'vin' => '5yjygdee3mf214950',
            'status' => DonorCar::STATUS_DISMANTLING,
            'drive_type' => DonorCar::DRIVE_TYPE_ALL,
            'color' => 'White',
            'mileage' => 12345,
            'purchase_date' => '2026-04-20',
        ]);

        $donorCar = DonorCar::query()->where('vin', '5YJYGDEE3MF214950')->firstOrFail();

        $response->assertRedirect(route('admin.donor-cars.show', $donorCar));

        $this->assertDatabaseHas('donor_cars', [
            'vin' => '5YJYGDEE3MF214950',
            'status' => DonorCar::STATUS_IN_TRANSIT,
            'drive_type' => DonorCar::DRIVE_TYPE_ALL,
        ]);
    }

    public function test_donor_car_create_does_not_allow_submitted_status_to_arrive_donor(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-create-arrived-drive@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('admin.donor-cars.store'), [
                'vin' => '5yjygdee3mf214949',
                'status' => DonorCar::STATUS_DISMANTLING,
                'color' => 'White',
                'mileage' => 12345,
                'purchase_date' => '2026-04-20',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('donor_cars', [
            'vin' => '5YJYGDEE3MF214949',
            'status' => DonorCar::STATUS_IN_TRANSIT,
        ]);
    }

    public function test_donor_car_index_renders_readonly_status_label(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $donorCar = DonorCar::query()->create([
            'vin' => '5YJYGDEE3MF214951',
            'brand' => 'Tesla',
            'model' => 'Model Y',
            'year' => 2021,
            'status' => DonorCar::STATUS_DISMANTLING,
            'drive_type' => DonorCar::DRIVE_TYPE_ALL,
        ]);

        $response = $this->actingAs($user)->get(route('admin.donor-cars.index'));

        $response->assertOk();
        $response->assertDontSee('data-donor-status-select', false);
        $response->assertDontSee('donor-cars/'.$donorCar->id.'/status', false);
        $response->assertSee('donor-status--dismantling', false);
        $response->assertSee($donorCar->status_label, false);
    }

    public function test_donor_car_index_shows_thirty_donors_and_sold_parts_amount(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-donor-sales@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        for ($i = 1; $i <= 31; $i++) {
            DonorCar::query()->create([
                'vin' => '5YJ3E1EA7KF'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'brand' => 'Tesla',
                'model' => 'Model 3',
                'year' => 2020,
                'purchase_date' => now()->subDays(31 - $i)->toDateString(),
            ]);
        }

        $latestDonor = DonorCar::query()->latest('purchase_date')->firstOrFail();

        PartSale::query()->create([
            'donor_car_id' => $latestDonor->id,
            'source' => 'nikolacars',
            'name' => 'Headlight',
            'quantity' => 2.5,
            'unit_price' => 50,
            'currency' => 'USD',
            'source_row_hash' => 'donor-index-sale-amount',
        ]);

        $response = $this->actingAs($user)->get(route('admin.donor-cars.index'));

        $response->assertOk();
        $this->assertSame(30, substr_count($response->getContent(), 'class="donor-status '));
        $response->assertSee('$125.00');
        $response->assertSee('sort=sold_parts_amount', false);
    }

    public function test_donor_car_index_does_not_eager_load_all_donor_products(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-donor-products-performance@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000099',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
            'purchase_date' => '2026-04-21',
            'drive_type' => DonorCar::DRIVE_TYPE_ALL,
            'battery_type' => DonorCar::BATTERY_TYPE_LONG_RANGE,
            'is_performance' => false,
        ]);
        $officialCatalogItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-official://index-performance-test',
            'part_number' => 'TESLA-INDEX-001',
            'name' => 'Official catalog part',
        ]);

        Product::query()->create([
            'sku' => 'DONOR-INDEX-OFFICIAL',
            'external_sku' => 'TESLA-INDEX-001',
            'name' => 'Official generated index part',
            'slug' => 'official-generated-index-part',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $officialCatalogItem->id,
            'is_auto_generated' => true,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        DB::enableQueryLog();

        $response = $this->actingAs($user)->get(route('admin.donor-cars.index'));

        $response->assertOk();
        $productTableLoads = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query): bool => preg_match('/^select \* from "products" where /i', $query) === 1);

        $this->assertCount(0, $productTableLoads);
    }

    public function test_donor_car_show_sorts_products_by_price_desc_by_default(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-donor-products-default-sort@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000199',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
        ]);

        $expensiveCatalogItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/catalogs/default-sort/expensive',
            'part_number' => 'EXPENSIVE-001',
            'name' => 'Expensive catalog part',
        ]);
        $cheapCatalogItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/catalogs/default-sort/cheap',
            'part_number' => 'CHEAP-001',
            'name' => 'Cheap catalog part',
        ]);

        Product::query()->create([
            'sku' => 'DONOR-CHEAP',
            'external_sku' => 'CHEAP-001',
            'name' => 'Cheap default sort part',
            'slug' => 'cheap-default-sort-part',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $cheapCatalogItem->id,
            'is_auto_generated' => true,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        Product::query()->create([
            'sku' => 'DONOR-EXPENSIVE',
            'external_sku' => 'EXPENSIVE-001',
            'name' => 'Expensive default sort part',
            'slug' => 'expensive-default-sort-part',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $expensiveCatalogItem->id,
            'is_auto_generated' => true,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 500,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->get(route('admin.donor-cars.show', $donorCar));

        $response->assertOk();
        $response->assertSeeInOrder([
            'Expensive default sort part',
            'Cheap default sort part',
        ]);
        $response->assertSee('Цена продажи <span>USD v</span>', false);
    }

    public function test_donor_car_manual_status_route_is_not_registered(): void
    {
        $this->assertFalse(app('router')->getRoutes()->hasNamedRoute('admin.donor-cars.status.update'));
    }

    public function test_donor_car_status_is_readonly_after_dismantling(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-dismantling-options@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $donorCar = DonorCar::query()->create([
            'vin' => '5YJYGDEE3MF214958',
            'brand' => 'Tesla',
            'model' => 'Model Y',
            'year' => 2021,
            'color' => 'White',
            'mileage' => 12345,
            'status' => DonorCar::STATUS_DISMANTLING,
        ]);

        $this
            ->actingAs($user)
            ->get(route('admin.donor-cars.index'))
            ->assertOk()
            ->assertDontSee('data-donor-status-select', false)
            ->assertSee('donor-status--dismantling', false)
            ->assertSee($donorCar->status_label, false);

        $this
            ->actingAs($user)
            ->get(route('admin.donor-cars.edit', $donorCar))
            ->assertOk()
            ->assertDontSee('<select name="status"', false)
            ->assertSee($donorCar->status_label, false);
    }

    public function test_donor_car_update_ignores_submitted_status(): void
    {
        $this->travelTo('2026-05-04 12:00:00');

        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-arrival-date@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $donorCar = DonorCar::query()->create([
            'vin' => '5YJYGDEE3MF214955',
            'brand' => 'Tesla',
            'model' => 'Model Y',
            'year' => 2021,
            'color' => 'White',
            'mileage' => 12345,
            'status' => DonorCar::STATUS_IN_TRANSIT,
        ]);

        $this->actingAs($user)
            ->put(route('admin.donor-cars.update', $donorCar), [
                'status' => DonorCar::STATUS_DISMANTLING,
                'drive_type' => DonorCar::DRIVE_TYPE_ALL,
                'year' => 2021,
                'color' => 'White',
                'mileage' => 12345,
                'purchase_date' => null,
                'warehouse_arrival_date' => null,
            ])
            ->assertRedirect(route('admin.donor-cars.show', $donorCar));

        $donorCar->refresh();

        $this->assertSame(DonorCar::STATUS_IN_TRANSIT, $donorCar->status);
        $this->assertSame(DonorCar::DRIVE_TYPE_ALL, $donorCar->drive_type);
        $this->assertNull($donorCar->warehouse_arrival_date);
    }

    public function test_donor_car_update_does_not_require_drive_type_for_submitted_status(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-update-drive-required@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $donorCar = DonorCar::query()->create([
            'vin' => '5YJYGDEE3MF214960',
            'brand' => 'Tesla',
            'model' => 'Model Y',
            'year' => 2021,
            'color' => 'White',
            'mileage' => 12345,
            'status' => DonorCar::STATUS_IN_TRANSIT,
        ]);

        $this->actingAs($user)
            ->put(route('admin.donor-cars.update', $donorCar), [
                'status' => DonorCar::STATUS_DISMANTLING,
                'year' => 2021,
                'color' => 'White',
                'mileage' => 12345,
                'purchase_date' => null,
                'warehouse_arrival_date' => null,
            ])
            ->assertRedirect(route('admin.donor-cars.show', $donorCar));

        $this->assertSame(DonorCar::STATUS_IN_TRANSIT, $donorCar->refresh()->status);
        $this->assertNull($donorCar->drive_type);
    }

    public function test_donor_car_warehouse_arrival_date_can_be_edited(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-edit-arrival-date@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $donorCar = DonorCar::query()->create([
            'vin' => '5YJYGDEE3MF214956',
            'brand' => 'Tesla',
            'model' => 'Model Y',
            'year' => 2021,
            'color' => 'White',
            'mileage' => 12345,
            'status' => DonorCar::STATUS_AT_STO,
            'warehouse_arrival_date' => '2026-05-01',
        ]);

        $this->actingAs($user)
            ->put(route('admin.donor-cars.update', $donorCar), [
                'status' => DonorCar::STATUS_AT_STO,
                'year' => 2021,
                'color' => 'White',
                'mileage' => 12345,
                'purchase_date' => null,
                'warehouse_arrival_date' => '2026-05-03',
            ])
            ->assertRedirect(route('admin.donor-cars.show', $donorCar));

        $this->assertSame('2026-05-03', $donorCar->refresh()->warehouse_arrival_date->format('Y-m-d'));
    }

    public function test_donor_car_create_rejects_duplicate_vin(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        DonorCar::query()->create([
            'vin' => '5YJYGDEE3MF214952',
            'brand' => 'Tesla',
            'model' => 'Model Y 01.2020 - 01.2025',
            'year' => 2021,
            'color' => 'White',
            'mileage' => 1000,
            'purchase_date' => '2026-04-20',
        ]);

        $response = $this->actingAs($user)->from(route('admin.donor-cars.create'))->post(route('admin.donor-cars.store'), [
            'vin' => '5yjygdee3mf214952',
            'color' => 'White',
            'mileage' => 12345,
            'purchase_date' => '2026-04-21',
        ]);

        $response
            ->assertRedirect(route('admin.donor-cars.create'))
            ->assertSessionHasErrors('vin');

        $this->assertSame(1, DonorCar::query()->where('vin', '5YJYGDEE3MF214952')->count());
    }

    public function test_donor_car_create_allows_empty_purchase_price_with_fees(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->post(route('admin.donor-cars.store'), [
            'vin' => '5yjygdee3mf214953',
            'color' => 'White',
            'mileage' => 12345,
            'purchase_date' => '2026-04-20',
            'estimated_cost_usd' => null,
        ]);

        $response->assertRedirect(route('admin.donor-cars.show', DonorCar::query()->where('vin', '5YJYGDEE3MF214953')->firstOrFail()));

        $this->assertDatabaseHas('donor_cars', [
            'vin' => '5YJYGDEE3MF214953',
            'estimated_cost_usd' => null,
        ]);
    }

    public function test_donor_car_index_uses_full_total_cost(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000003',
            'brand' => 'Tesla',
            'status' => DonorCar::STATUS_DISMANTLING,
            'model' => 'Model 3',
            'year' => 2019,
            'color' => 'White',
            'mileage' => 10000,
            'purchase_date' => '2026-04-20',
            'estimated_cost_usd' => 10000,
            'usa_delivery_price_usd' => 1000,
            'klaipeda_ukraine_delivery_price_usd' => 500,
            'customs_clearance_price_usd' => 1250,
        ]);

        DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000004',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
            'color' => 'Black',
            'mileage' => 20000,
            'purchase_date' => '2026-04-21',
            'estimated_cost_usd' => 20000,
            'usa_delivery_price_usd' => 1500,
            'klaipeda_ukraine_delivery_price_usd' => 750,
            'customs_clearance_price_usd' => 2000,
        ]);

        $response = $this->actingAs($user)->get(route('admin.donor-cars.index'));

        $response->assertOk();
        $response->assertSee('Полная стоимость');
        $response->assertSee('$12 750.00');
        $response->assertSee('В разборке');
        $response->assertSee('$24 250.00');
    }

    public function test_donor_car_index_marks_2026_donors_without_customs_clearance_as_incomplete(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-incomplete-2026-donor-cost@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000031',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
            'purchase_date' => '2026-04-20',
            'estimated_cost_usd' => 10000,
            'usa_delivery_price_usd' => 1000,
            'klaipeda_ukraine_delivery_price_usd' => 500,
            'customs_clearance_price_usd' => null,
        ]);

        DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000032',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
            'purchase_date' => '2025-12-31',
            'estimated_cost_usd' => 9000,
            'usa_delivery_price_usd' => 900,
            'klaipeda_ukraine_delivery_price_usd' => 400,
            'customs_clearance_price_usd' => null,
        ]);

        $response = $this->actingAs($user)->get(route('admin.donor-cars.index'));

        $response->assertOk();

        $newDonorRow = str($response->getContent())
            ->after('5YJ3E1EA7KF000031')
            ->before('</tr>')
            ->toString();
        $oldDonorRow = str($response->getContent())
            ->after('5YJ3E1EA7KF000032')
            ->before('</tr>')
            ->toString();

        $newDonorRow = html_entity_decode($newDonorRow);
        $oldDonorRow = html_entity_decode($oldDonorRow);

        $this->assertStringContainsString('$11 500.00', $newDonorRow);
        $this->assertStringContainsString('Не все расходы', $newDonorRow);
        $this->assertStringContainsString('$10 300.00', $oldDonorRow);
        $this->assertStringNotContainsString('Не все расходы', $oldDonorRow);
    }

    public function test_donor_car_index_displays_model_without_year_range(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        DonorCar::query()->create([
            'vin' => '5YJYGDEE3MF214954',
            'brand' => 'Tesla',
            'model' => 'Model Y 01.2020 - 01.2025',
            'year' => 2021,
            'color' => 'White',
            'mileage' => 12345,
            'purchase_date' => '2026-04-20',
        ]);

        $response = $this->actingAs($user)->get(route('admin.donor-cars.index'));

        $response->assertOk();
        $response->assertSee('Model Y');
        $response->assertDontSee('01.2020 - 01.2025');
    }

    public function test_official_catalog_download_requires_drive_type_and_battery_type(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-official-download-requirements@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000024',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
        ]);

        $this->actingAs($user)
            ->post(route('admin.donor-cars.products.download-official', $donorCar))
            ->assertRedirect(route('admin.donor-cars.show', $donorCar))
            ->assertSessionHasErrors(['drive_type', 'battery_type', 'is_performance']);
    }

    public function test_official_catalog_download_status_allows_only_one_running_download(): void
    {
        Cache::flush();

        $firstDonor = DonorCar::query()->create([
            'vin' => '5YJYGDED4MF109750',
            'brand' => 'Tesla',
            'model' => 'Model Y',
            'year' => 2021,
        ]);
        $secondDonor = DonorCar::query()->create([
            'vin' => '5YJYGDEE3MF214954',
            'brand' => 'Tesla',
            'model' => 'Model Y',
            'year' => 2021,
        ]);

        $statuses = app(OfficialCatalogDownloadStatus::class);
        $firstStatus = $statuses->tryStart($firstDonor, 'first-token');

        $this->assertNotNull($firstStatus);
        $this->assertSame($firstDonor->id, $statuses->running()['donor_car_id'] ?? null);
        $this->assertNull($statuses->tryStart($secondDonor, 'second-token'));

        $statuses->complete($firstDonor, []);

        $secondStatus = $statuses->tryStart($secondDonor, 'second-token');

        $this->assertNotNull($secondStatus);
        $this->assertSame($secondDonor->id, $statuses->running()['donor_car_id'] ?? null);
    }

    public function test_admin_cannot_delete_donor_car_older_than_24_hours(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-old-donor-delete@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000014',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
        ]);
        $donorCar->forceFill([
            'created_at' => now()->subDay()->subSecond(),
            'updated_at' => now()->subDay()->subSecond(),
        ])->save();

        $this->actingAs($user)
            ->delete(route('admin.donor-cars.destroy', $donorCar))
            ->assertRedirect(route('admin.donor-cars.index'));

        $this->assertDatabaseHas('donor_cars', [
            'id' => $donorCar->id,
            'vin' => '5YJ3E1EA7KF000014',
        ]);
    }

    public function test_donor_car_index_hides_delete_button_for_old_donor_cars(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-donor-delete-button@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $oldDonorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000015',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
        ]);
        $oldDonorCar->forceFill([
            'created_at' => now()->subDay()->subSecond(),
            'updated_at' => now()->subDay()->subSecond(),
        ])->save();

        $freshDonorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000016',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
        ]);

        $response = $this->actingAs($user)->get(route('admin.donor-cars.index'));

        $response->assertOk();
        $response->assertDontSee('action="'.route('admin.donor-cars.destroy', $oldDonorCar).'"', false);
        $response->assertSee('action="'.route('admin.donor-cars.destroy', $freshDonorCar).'"', false);
    }

    public function test_admin_can_delete_part_from_donor_car(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-delete-donor-part@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000017',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
        ]);
        $warehouse = Warehouse::query()->create([
            'name' => 'Main',
            'floor_count' => 1,
            'is_active' => true,
        ]);
        $location = Location::query()->create([
            'warehouse_id' => $warehouse->id,
            'full_code' => 'MAIN-A1',
            'cell' => 'A1',
            'is_active' => true,
        ]);
        $product = Product::query()->create([
            'sku' => 'DONOR-DELETE-001',
            'name' => 'Door mirror',
            'slug' => 'door-mirror-delete',
            'donor_car_id' => $donorCar->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $product->stockItems()->create([
            'warehouse_id' => $warehouse->id,
            'location_id' => $location->id,
            'quantity' => 1,
            'reserved_quantity' => 0,
            'testing_status' => 'not_tested',
        ]);

        $this->actingAs($user)
            ->get(route('admin.donor-cars.show', $donorCar))
            ->assertOk()
            ->assertSee('action="'.route('admin.donor-cars.products.destroy', [$donorCar, $product]).'"', false);

        $this->actingAs($user)
            ->delete(route('admin.donor-cars.products.destroy', [$donorCar, $product]))
            ->assertRedirect(route('admin.donor-cars.show', $donorCar));

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('stock_items', ['product_id' => $product->id]);
    }

    public function test_tesla_official_generated_donor_part_shows_badge_and_cannot_be_deleted(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-delete-tesla-official-donor-part@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000TES',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
        ]);
        $officialItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=TESLA-DELETE-001',
            'part_number' => 'TESLA-DELETE-001',
            'name' => 'Tesla official protected part',
        ]);
        $product = Product::query()->create([
            'sku' => 'DON-TESLA-DELETE-001',
            'external_sku' => 'TESLA-DELETE-001',
            'name' => 'Tesla official protected part',
            'slug' => 'tesla-official-protected-part',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $officialItem->id,
            'is_auto_generated' => true,
            'generated_at' => now(),
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 0,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)
            ->get(route('admin.donor-cars.show', $donorCar))
            ->assertOk()
            ->assertSee('tesla.com')
            ->assertDontSee('action="'.route('admin.donor-cars.products.destroy', [$donorCar, $product]).'"', false);

        $this->assertStringContainsString('donor-product-source-tag', $response->getContent());

        $this->actingAs($user)
            ->from(route('admin.donor-cars.show', $donorCar))
            ->delete(route('admin.donor-cars.products.destroy', [$donorCar, $product]))
            ->assertRedirect(route('admin.donor-cars.show', $donorCar))
            ->assertSessionHasErrors('product');

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_auto_generated_checked_donor_part_cannot_be_deleted(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-delete-auto-generated-checked-donor-part@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000AUG',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
        ]);
        $product = Product::query()->create([
            'sku' => 'DON-AUTO-DELETE-001',
            'external_sku' => 'AUTO-DELETE-001',
            'name' => 'Auto generated checked part',
            'slug' => 'auto-generated-checked-part',
            'donor_car_id' => $donorCar->id,
            'is_auto_generated' => true,
            'generated_at' => now(),
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 0,
            'currency' => 'USD',
            'notes' => "\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}",
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.donor-cars.show', $donorCar))
            ->assertOk()
            ->assertDontSee('action="'.route('admin.donor-cars.products.destroy', [$donorCar, $product]).'"', false);

        $this->actingAs($user)
            ->from(route('admin.donor-cars.show', $donorCar))
            ->delete(route('admin.donor-cars.products.destroy', [$donorCar, $product]))
            ->assertRedirect(route('admin.donor-cars.show', $donorCar))
            ->assertSessionHasErrors('product');

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_legacy_generated_checked_donor_part_cannot_be_deleted_when_flag_is_missing(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-delete-legacy-generated-checked-donor-part@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF00LEG',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
        ]);
        $product = Product::query()->create([
            'sku' => 'DON27-0008',
            'external_sku' => '1755533-00-A',
            'name' => 'Legacy generated checked part',
            'slug' => 'legacy-generated-checked-part',
            'donor_car_id' => $donorCar->id,
            'is_auto_generated' => false,
            'generated_at' => now(),
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 0,
            'currency' => 'USD',
            'notes' => "\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}",
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.donor-cars.show', $donorCar))
            ->assertOk()
            ->assertDontSee('action="'.route('admin.donor-cars.products.destroy', [$donorCar, $product]).'"', false);

        $this->actingAs($user)
            ->from(route('admin.donor-cars.show', $donorCar))
            ->delete(route('admin.donor-cars.products.destroy', [$donorCar, $product]))
            ->assertRedirect(route('admin.donor-cars.show', $donorCar))
            ->assertSessionHasErrors('product');

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_donor_part_used_in_work_order_cannot_be_deleted(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-delete-used-donor-part@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000018',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
        ]);
        $product = Product::query()->create([
            'sku' => 'DONOR-USED-001',
            'name' => 'Used mirror',
            'slug' => 'used-mirror-delete',
            'donor_car_id' => $donorCar->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $order = StoWorkOrder::query()->create([
            'number' => 'ZN-20260504-USED',
            'status' => StoWorkOrder::STATUS_IN_WORK,
            'client_name' => 'Service Client',
            'opened_at' => '2026-05-04',
        ]);
        $order->parts()->create([
            'product_id' => $product->id,
            'name' => 'Used mirror',
            'quantity' => 1,
            'unit_price_uah' => 100,
            'total_price_uah' => 100,
        ]);

        $this->actingAs($user)
            ->from(route('admin.donor-cars.show', $donorCar))
            ->delete(route('admin.donor-cars.products.destroy', [$donorCar, $product]))
            ->assertRedirect(route('admin.donor-cars.show', $donorCar))
            ->assertSessionHasErrors('product');

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_donor_car_show_marks_paid_work_order_part_as_sold(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-sold-donor-part@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000019',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
        ]);
        $warehouse = Warehouse::query()->create([
            'name' => ' ',
            'floor_count' => 1,
            'is_active' => true,
        ]);
        $location = Location::query()->create([
            'warehouse_id' => $warehouse->id,
            'full_code' => 'DIS-A1',
            'cell' => 'A1',
            'is_active' => true,
        ]);
        $product = Product::query()->create([
            'sku' => 'DONOR-SOLD-001',
            'name' => 'Sold mirror',
            'slug' => 'sold-mirror',
            'donor_car_id' => $donorCar->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $product->stockItems()->create([
            'warehouse_id' => $warehouse->id,
            'location_id' => $location->id,
            'quantity' => 0,
            'reserved_quantity' => 0,
            'testing_status' => 'not_tested',
        ]);
        $order = StoWorkOrder::query()->create([
            'number' => 'ZN-20260504-SOLD',
            'status' => StoWorkOrder::STATUS_PAID,
            'client_name' => 'Service Client',
            'opened_at' => '2026-05-04',
        ]);
        $order->parts()->create([
            'product_id' => $product->id,
            'name' => 'Sold mirror',
            'quantity' => 1,
            'unit_price_uah' => 100,
            'total_price_uah' => 100,
        ]);

        $response = $this->actingAs($user)
            ->get(route('admin.donor-cars.show', $donorCar))
            ->assertOk()
            ->assertSee('Продан')
            ->assertSee('Заказ-наряд:')
            ->assertSee('href="'.route('admin.sto-work-orders.show', $order).'">ZN-20260504-SOLD</a>', false)
            ->assertDontSee('action="'.route('admin.donor-cars.products.destroy', [$donorCar, $product]).'"', false);

        $soldPartRow = str($response->getContent())
            ->after('DONOR-SOLD-001')
            ->before('</tr>')
            ->toString();

        $this->assertStringContainsString('Продан', $soldPartRow);
        $this->assertStringNotContainsString('action="'.route('admin.donor-cars.products.destroy', [$donorCar, $product]).'"', $soldPartRow);
    }

    public function test_donor_car_show_hides_damage_generation_button(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-generate-damaged-donor-parts@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $withoutOfficialDownload = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000021',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
        ]);
        $withOfficialDownload = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000022',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
        ]);
        $competitorCatalogItem = PartCatalogItem::query()->create([
            'source' => 'tcarservice',
            'source_url' => 'https://tcarservice.test/catalog/TCAR-GEN-001',
            'part_number' => 'TCAR-GEN-001',
            'name' => 'Competitor catalog part',
        ]);
        $officialCatalogItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/catalogs/123',
            'part_number' => 'TESLA-GEN-001',
            'name' => 'Official catalog part',
        ]);

        Product::query()->create([
            'sku' => 'DONOR-GEN-COMPETITOR',
            'external_sku' => 'TCAR-GEN-001',
            'name' => 'Competitor generated part',
            'slug' => 'competitor-generated-part',
            'donor_car_id' => $withoutOfficialDownload->id,
            'source_part_catalog_item_id' => $competitorCatalogItem->id,
            'is_auto_generated' => true,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        Product::query()->create([
            'sku' => 'DONOR-GEN-OFFICIAL',
            'external_sku' => 'TESLA-GEN-001',
            'name' => 'Official generated part',
            'slug' => 'official-generated-part',
            'donor_car_id' => $withOfficialDownload->id,
            'source_part_catalog_item_id' => $officialCatalogItem->id,
            'is_auto_generated' => true,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.donor-cars.show', $withoutOfficialDownload))
            ->assertOk()
            ->assertDontSee('<button type="button" class="btn btn-secondary" data-open-generate-dialog>', false);

        $this->actingAs($user)
            ->get(route('admin.donor-cars.show', $withOfficialDownload))
            ->assertOk()
            ->assertDontSee('<button type="button" class="btn btn-secondary" data-open-generate-dialog>', false);
    }

    public function test_donor_car_show_marks_in_work_and_completed_work_order_parts_as_reserved(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-reserved-donor-part@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000020',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
        ]);
        $warehouse = Warehouse::query()->create([
            'name' => ' ',
            'floor_count' => 1,
            'is_active' => true,
        ]);
        $location = Location::query()->create([
            'warehouse_id' => $warehouse->id,
            'full_code' => 'DIS-A1',
            'cell' => 'A1',
            'is_active' => true,
        ]);

        foreach ([StoWorkOrder::STATUS_IN_WORK, StoWorkOrder::STATUS_COMPLETED] as $index => $status) {
            $product = Product::query()->create([
                'sku' => "DONOR-RESERVED-00{$index}",
                'name' => "Reserved mirror {$index}",
                'slug' => "reserved-mirror-{$index}",
                'donor_car_id' => $donorCar->id,
                'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
                'condition_type' => 'used',
                'testing_status' => 'not_tested',
                'unit' => 'pcs',
                'selling_price' => 100,
                'currency' => 'USD',
                'is_active' => true,
            ]);
            $product->stockItems()->create([
                'warehouse_id' => $warehouse->id,
                'location_id' => $location->id,
                'quantity' => 0,
                'reserved_quantity' => 0,
                'testing_status' => 'not_tested',
            ]);
            $order = StoWorkOrder::query()->create([
                'number' => "ZN-20260504-RES-{$index}",
                'status' => $status,
                'client_name' => 'Service Client',
                'opened_at' => '2026-05-04',
            ]);
            $order->parts()->create([
                'product_id' => $product->id,
                'name' => "Reserved mirror {$index}",
                'quantity' => 1,
                'unit_price_uah' => 100,
                'total_price_uah' => 100,
            ]);
        }

        $response = $this->actingAs($user)
            ->get(route('admin.donor-cars.show', $donorCar))
            ->assertOk();

        foreach ([0, 1] as $index) {
            $reservedPartRow = str($response->getContent())
                ->after("DONOR-RESERVED-00{$index}")
                ->before('</tr>')
                ->toString();

            $this->assertStringContainsString("\u{0412} \u{0440}\u{0435}\u{0437}\u{0435}\u{0440}\u{0432}\u{0435}", $reservedPartRow);
            $this->assertStringContainsString("ZN-20260504-RES-{$index}", $reservedPartRow);
        }
    }

    public function test_donor_car_show_marks_customer_order_reserved_parts(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-donor-customer-order-reserved@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000021',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
        ]);
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/donor-reserved-customer-order',
            'part_number' => '1002295-00-D',
            'name' => 'Customer reserved donor mirror',
            'raw_attributes' => [
                'donor_vin' => $donorCar->vin,
                'stock_quantity' => 1,
            ],
        ]);
        $product = Product::query()->create([
            'sku' => 'DONOR-CUSTOMER-RESERVED',
            'external_sku' => '1002295-00-D',
            'name' => 'Customer reserved donor mirror',
            'slug' => 'donor-customer-reserved',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $catalogItem->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260603-0021',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'client_first_name' => 'Reserve',
            'currency' => 'UAH',
            'total_amount' => 1000,
        ]);
        $order->items()->create([
            'part_catalog_item_id' => $catalogItem->id,
            'name' => $product->name,
            'part_number' => $product->external_sku,
            'quantity' => 1,
            'unit_price' => 1000,
            'total_price' => 1000,
            'currency' => 'UAH',
        ]);

        $response = $this->actingAs($user)
            ->get(route('admin.donor-cars.show', $donorCar))
            ->assertOk();

        $reservedPartRow = str($response->getContent())
            ->after('DONOR-CUSTOMER-RESERVED')
            ->before('</tr>')
            ->toString();

        $this->assertStringContainsString('Customer reserved donor mirror', $reservedPartRow);
        $this->assertStringContainsString('&#1074; &#1088;&#1077;&#1079;&#1077;&#1088;&#1074;&#1077;', $reservedPartRow);
        $this->assertStringContainsString('ORD-20260603-0021', $reservedPartRow);
        $this->assertStringContainsString('href="'.route('admin.customer-orders.show', $order).'"', $reservedPartRow);
    }

    public function test_customer_order_reserved_donor_part_cannot_be_changed_from_donor_card(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-donor-reserved-actions@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000022',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
        ]);
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/donor-reserved-actions',
            'part_number' => '1002295-00-E',
            'name' => 'Reserved donor action part',
            'name_ru' => 'Reserved donor action part RU',
            'name_ua' => 'Reserved donor action part UA',
            'raw_attributes' => [
                'donor_vin' => $donorCar->vin,
                'stock_quantity' => 1,
            ],
        ]);
        $product = Product::query()->create([
            'sku' => 'DONOR-RESERVED-ACTIONS',
            'external_sku' => '1002295-00-E',
            'name' => 'Reserved donor action part',
            'slug' => 'reserved-donor-action-part',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $catalogItem->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260603-0022',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'delivery_method' => CustomerOrder::DELIVERY_METHOD_PICKUP,
            'client_first_name' => 'Reserve',
            'currency' => 'UAH',
            'total_amount' => 1000,
        ]);
        $order->items()->create([
            'part_catalog_item_id' => $catalogItem->id,
            'name' => $product->name,
            'part_number' => $product->external_sku,
            'quantity' => 1,
            'unit_price' => 1000,
            'total_price' => 1000,
            'currency' => 'UAH',
        ]);

        $response = $this->actingAs($user)
            ->get(route('admin.donor-cars.show', $donorCar))
            ->assertOk();
        $reservedPartRow = str($response->getContent())
            ->after('DONOR-RESERVED-ACTIONS')
            ->before('</tr>')
            ->toString();

        $this->assertStringNotContainsString(route('admin.products.edit', $product), $reservedPartRow);
        $this->assertStringNotContainsString(route('admin.donor-cars.products.destroy', [$donorCar, $product]), $reservedPartRow);
        $this->assertStringNotContainsString(route('admin.donor-cars.products.name.update', [$donorCar, $product]), $reservedPartRow);
        $this->assertStringNotContainsString(route('admin.donor-cars.products.official-fields.update', [$donorCar, $product]), $reservedPartRow);

        $this->patch(route('admin.donor-cars.products.name.update', [$donorCar, $product]), [
            'name_type' => 'name_ru',
            'name' => 'Changed reserved name',
        ])->assertSessionHasErrors('product');

        $this->patch(route('admin.donor-cars.products.official-fields.update', [$donorCar, $product]), [
            'selling_price' => 250,
        ])->assertSessionHasErrors('product');

        $this->delete(route('admin.donor-cars.products.destroy', [$donorCar, $product]))
            ->assertSessionHasErrors('product');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'selling_price' => 100,
        ]);
        $this->assertSame('Reserved donor action part RU', $catalogItem->refresh()->name_ru);
    }

    public function test_sto_reserved_donor_part_cannot_be_changed_from_donor_card(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-donor-sto-reserved-actions@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000023',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
        ]);
        $product = Product::query()->create([
            'sku' => 'DONOR-STO-RESERVED-ACTIONS',
            'external_sku' => '1002295-00-F',
            'name' => 'STO reserved donor action part',
            'slug' => 'sto-reserved-donor-action-part',
            'donor_car_id' => $donorCar->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $order = StoWorkOrder::query()->create([
            'number' => 'ZN-20260603-RESERVED',
            'status' => StoWorkOrder::STATUS_IN_WORK,
            'client_name' => 'Service Client',
            'opened_at' => '2026-06-03',
        ]);
        $order->parts()->create([
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => 1,
            'unit_price_uah' => 100,
            'total_price_uah' => 100,
        ]);

        $response = $this->actingAs($user)
            ->get(route('admin.donor-cars.show', $donorCar))
            ->assertOk();
        $reservedPartRow = str($response->getContent())
            ->after('DONOR-STO-RESERVED-ACTIONS')
            ->before('</tr>')
            ->toString();

        $this->assertStringNotContainsString(route('admin.products.edit', $product), $reservedPartRow);

        $this->patch(route('admin.donor-cars.products.official-fields.update', [$donorCar, $product]), [
            'selling_price' => 250,
        ])->assertSessionHasErrors('product');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'selling_price' => 100,
        ]);
    }

    public function test_donor_show_renders_catalog_name_sources_without_eloquent_collection_merge_error(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-donor-name-sources@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000059',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
        ]);
        $sourceItem = PartCatalogItem::query()->create([
            'source' => 'erazborka',
            'source_url' => 'https://erazborka.example/1234567-00-a',
            'part_number' => '1234567-00-A',
            'name' => 'Mirror source',
            'name_ru' => 'Зеркало RU',
            'name_ua' => 'Дзеркало UA',
        ]);
        $officialItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/1234567-00-a',
            'part_number' => '1234567-00-A',
            'name' => 'Mirror',
            'name_ru' => 'Зеркало RU',
            'name_ua' => 'Дзеркало UA',
            'raw_attributes' => [
                'name_source_item_id_ru' => $sourceItem->id,
                'name_source_item_id_ua' => $sourceItem->id,
            ],
        ]);

        Product::query()->create([
            'sku' => 'DONOR-NAME-SOURCE-001',
            'external_sku' => '1234567-00-A',
            'name' => 'Mirror',
            'slug' => 'donor-name-source-001',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $officialItem->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.donor-cars.show', $donorCar))
            ->assertOk();
    }

    public function test_donor_show_table_headers_link_to_sortable_columns(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-donor-sort-links@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000060',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
        ]);

        Product::query()->create([
            'sku' => 'DONOR-SORT-001',
            'external_sku' => '7654321-00-A',
            'name' => 'Sortable donor part',
            'slug' => 'donor-sort-001',
            'donor_car_id' => $donorCar->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'is_active' => true,
            'main_image' => 'donor-sort.jpg',
        ]);
        $donorCar->partSales()->create([
            'source' => 'nikolacars',
            'source_file' => 'test',
            'source_row_hash' => 'donor-sort-sale',
            'sold_at' => now(),
            'part_number' => '7654321-00-A',
            'name' => 'Sold sortable donor part',
            'quantity' => 1,
            'unit_price' => 100,
            'total_amount' => 100,
            'currency' => 'USD',
            'document_number' => 'SALE-SORT',
            'counterparty' => 'Client',
        ]);

        $response = $this->actingAs($user)->get(route('admin.donor-cars.show', $donorCar));

        $response
            ->assertOk()
            ->assertSee(route('admin.donor-cars.show', [
                'donorCar' => $donorCar,
                'product_sort' => 'photo',
                'product_direction' => 'asc',
                'sale_sort' => 'sold_at',
                'sale_direction' => 'desc',
            ]))
            ->assertSee(route('admin.donor-cars.show', [
                'donorCar' => $donorCar,
                'product_sort' => 'damage_note',
                'product_direction' => 'asc',
                'sale_sort' => 'sold_at',
                'sale_direction' => 'desc',
            ]))
            ->assertSee(route('admin.donor-cars.show', [
                'donorCar' => $donorCar,
                'product_sort' => 'tesla_price',
                'product_direction' => 'asc',
                'sale_sort' => 'sold_at',
                'sale_direction' => 'desc',
            ]))
            ->assertSee(route('admin.donor-cars.show', [
                'donorCar' => $donorCar,
                'product_sort' => 'price',
                'product_direction' => 'desc',
                'sale_sort' => 'total_amount',
                'sale_direction' => 'asc',
            ]));
    }

    public function test_donor_show_limits_initial_products_and_ajax_search_returns_all_matches(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-donor-products-table@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF999981',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2019,
        ]);

        foreach (range(1, 81) as $index) {
            Product::query()->create([
                'sku' => sprintf('DON%d-%04d', $donorCar->id, $index),
                'external_sku' => sprintf('UNIQUE-%03d', $index),
                'name' => sprintf('Donor searchable part %03d', $index),
                'slug' => sprintf('donor-searchable-part-%03d', $index),
                'donor_car_id' => $donorCar->id,
                'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
                'condition_type' => 'used',
                'testing_status' => 'not_tested',
                'unit' => 'pcs',
                'selling_price' => 200 - $index,
                'currency' => 'USD',
                'is_active' => true,
            ]);
        }
        foreach (range(1, 17) as $index) {
            $smallItem = PartCatalogItem::query()->create([
                'source' => 'tesla_official',
                'source_url' => sprintf('tesla://small-hidden-%03d', $index),
                'part_number' => sprintf('SMALL-%03d', $index),
                'name' => sprintf('Small hidden part %03d', $index),
                'raw_attributes' => [
                    'donor_vin_small_part' => true,
                ],
            ]);

            Product::query()->create([
                'sku' => sprintf('DON%d-SMALL-%04d', $donorCar->id, $index),
                'external_sku' => sprintf('SMALL-%03d', $index),
                'name' => sprintf('Small hidden part %03d', $index),
                'slug' => sprintf('small-hidden-part-%03d', $index),
                'donor_car_id' => $donorCar->id,
                'source_part_catalog_item_id' => $smallItem->id,
                'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
                'condition_type' => 'used',
                'testing_status' => 'not_tested',
                'unit' => 'pcs',
                'selling_price' => 500 - $index,
                'currency' => 'USD',
                'is_active' => true,
            ]);
        }

        $this->actingAs($user)
            ->get(route('admin.donor-cars.show', $donorCar))
            ->assertOk()
            ->assertSee('Все запчасти <span data-donor-products-tab-count="all">81</span>', false)
            ->assertSee('Показано 1-80 из 81')
            ->assertSee('UNIQUE-080')
            ->assertDontSee('UNIQUE-081')
            ->assertDontSee('SMALL-001')
            ->assertSee(route('admin.donor-cars.products.table', $donorCar), false);

        $pageResponse = $this->actingAs($user)
            ->getJson(route('admin.donor-cars.products.table', [
                'donorCar' => $donorCar,
                'page' => 2,
            ]));
        $pageResponse
            ->assertOk()
            ->assertJsonPath('total', 81)
            ->assertJsonPath('page', 2)
            ->assertSee('UNIQUE-081')
            ->assertDontSee('UNIQUE-080');
        $this->assertStringContainsString('Показано 81-81 из 81', $pageResponse->json('pagination_html'));

        $this->actingAs($user)
            ->getJson(route('admin.donor-cars.products.table', [
                'donorCar' => $donorCar,
                'q' => 'UNIQUE-081',
            ]))
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonFragment([
                'limited' => false,
            ])
            ->assertSee('UNIQUE-081')
            ->assertDontSee('UNIQUE-080');
    }

    public function test_donor_products_ajax_search_matches_words_with_text_between_them(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-donor-products-word-search@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF999982',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2019,
        ]);

        Product::query()->create([
            'sku' => 'DON-WORD-SEARCH-001',
            'external_sku' => 'GLASS-DOOR-R',
            'name' => 'Стекло передней двери правое',
            'slug' => 'steklo-peredney-dveri-pravoe',
            'donor_car_id' => $donorCar->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 120,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        Product::query()->create([
            'sku' => 'DON-WORD-SEARCH-002',
            'external_sku' => 'OTHER-DOOR-R',
            'name' => 'Молдинг передней двери правый',
            'slug' => 'molding-peredney-dveri-praviy',
            'donor_car_id' => $donorCar->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 80,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('admin.donor-cars.products.table', [
                'donorCar' => $donorCar,
                'q' => 'стекло двери',
            ]));

        $response
            ->assertOk()
            ->assertJsonPath('total', 1);
        $this->assertStringContainsString('Стекло передней двери правое', $response->json('html'));
        $this->assertStringNotContainsString('Молдинг передней двери правый', $response->json('html'));
    }
}
