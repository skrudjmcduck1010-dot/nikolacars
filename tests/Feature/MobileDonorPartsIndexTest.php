<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DonorCar;
use App\Models\Location;
use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\User;
use App\Services\NikolaCarsProductInventorySyncService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MobileDonorPartsIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_parts_index_lists_all_donors_by_latest_purchase_first(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $inTransitDonor = DonorCar::query()->create([
            'vin' => '5YJTRANSIT0000001',
            'brand' => 'Tesla',
            'model' => 'Model Y',
            'year' => 2026,
            'status' => DonorCar::STATUS_IN_TRANSIT,
            'purchase_date' => '2026-05-01',
        ]);

        $newestDonor = DonorCar::query()->create([
            'vin' => '5YJNEWEST00000001',
            'brand' => 'Tesla',
            'model' => 'Model Y',
            'year' => 2026,
            'status' => DonorCar::STATUS_AT_STO,
            'purchase_date' => '2026-04-15',
        ]);

        $olderDismantlingDonor = DonorCar::query()->create([
            'vin' => '5YJOLDERD00000001',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2025,
            'status' => DonorCar::STATUS_DISMANTLING,
            'purchase_date' => '2026-04-01',
        ]);

        $oldestDonor = null;

        foreach (range(1, 18) as $index) {
            $oldestDonor = DonorCar::query()->create([
                'vin' => sprintf('5YJOLD%011d', $index),
                'brand' => 'Tesla',
                'model' => 'Model S',
                'year' => 2020,
                'status' => DonorCar::STATUS_AT_STO,
                'purchase_date' => now()->subDays(40 + $index)->toDateString(),
            ]);
        }

        $response = $this->actingAs($user)
            ->get(route('admin.mobile.parts.index'))
            ->assertOk()
            ->assertSee(route('admin.mobile.donor-cars.parts.show', $newestDonor), false)
            ->assertSee($oldestDonor->vin)
            ->assertDontSee($inTransitDonor->vin)
            ->assertDontSee('page=2', false);

        $response->assertSeeInOrder([
            $newestDonor->vin,
            $olderDismantlingDonor->vin,
        ]);

        $this->actingAs($user)
            ->get(route('admin.mobile.parts.index', ['q' => 'Model Y']))
            ->assertOk()
            ->assertSee($newestDonor->vin)
            ->assertDontSee($inTransitDonor->vin);
    }

    public function test_mobile_parts_index_counts_checked_and_sold_parts_only(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJCOUNT000000001',
            'brand' => 'Tesla',
            'model' => 'Model Y',
            'year' => 2025,
            'status' => DonorCar::STATUS_AT_STO,
            'purchase_date' => '2026-04-01',
        ]);
        $bodyCatalogCategory = PartCatalogCategory::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.test/categories/body',
            'depth' => 1,
            'code' => '10',
            'name' => 'BODY',
            'name_ru' => 'Кузов',
            'model_name' => 'Model Y',
        ]);
        $electricalCatalogCategory = PartCatalogCategory::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.test/categories/electrical',
            'depth' => 1,
            'code' => '40',
            'name' => 'ELECTRICAL',
            'name_ru' => 'Электрика',
            'model_name' => 'Model Y',
        ]);
        $officialUncheckedItem = PartCatalogItem::query()->create([
            'part_catalog_category_id' => $electricalCatalogCategory->id,
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.test/unchecked',
            'part_number' => 'TESLA-UNCHECKED',
            'name' => 'Unchecked official part',
        ]);
        $officialCheckedItem = PartCatalogItem::query()->create([
            'part_catalog_category_id' => $bodyCatalogCategory->id,
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.test/checked',
            'part_number' => 'TESLA-CHECKED',
            'name' => 'Checked official part',
        ]);
        $officialBrokenItem = PartCatalogItem::query()->create([
            'part_catalog_category_id' => $bodyCatalogCategory->id,
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.test/broken',
            'part_number' => 'TESLA-BROKEN',
            'name' => 'Broken official part',
        ]);
        $bodyCategory = Category::query()->create([
            'name' => 'Кузов',
            'slug' => 'body',
            'is_active' => true,
        ]);
        $electricalCategory = Category::query()->create([
            'name' => 'Электрика',
            'slug' => 'electrical',
            'is_active' => true,
        ]);

        $uncheckedProduct = Product::query()->create([
            'sku' => 'AUTO-UNCHECKED',
            'external_sku' => 'TESLA-UNCHECKED',
            'name' => 'Unchecked official part',
            'slug' => 'auto-unchecked',
            'donor_car_id' => $donorCar->id,
            'category_id' => $electricalCategory->id,
            'source_part_catalog_item_id' => $officialUncheckedItem->id,
            'is_auto_generated' => true,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 10,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $checkedProduct = Product::query()->create([
            'sku' => 'AUTO-CHECKED',
            'external_sku' => 'TESLA-CHECKED',
            'name' => 'Checked official part',
            'slug' => 'auto-checked',
            'donor_car_id' => $donorCar->id,
            'category_id' => $bodyCategory->id,
            'source_part_catalog_item_id' => $officialCheckedItem->id,
            'is_auto_generated' => true,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 300,
            'currency' => 'USD',
            'main_image' => '/nikolacars/prod/checked-main.jpg',
            'images_json' => ['/nikolacars/prod/checked-main.jpg', '/nikolacars/prod/checked-detail.jpg'],
            'notes' => "\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}",
            'is_active' => true,
        ]);
        Product::query()->create([
            'sku' => 'AUTO-BROKEN',
            'external_sku' => 'TESLA-BROKEN',
            'name' => 'Broken official part',
            'slug' => 'auto-broken',
            'donor_car_id' => $donorCar->id,
            'category_id' => $bodyCategory->id,
            'source_part_catalog_item_id' => $officialBrokenItem->id,
            'is_auto_generated' => true,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 200,
            'currency' => 'USD',
            'notes' => "\u{0420}\u{0430}\u{0437}\u{0431}\u{0438}\u{0442}",
            'is_active' => true,
        ]);
        Product::query()->create([
            'sku' => 'MANUAL-CHECKED',
            'external_sku' => 'MANUAL-CHECKED',
            'name' => 'Manual checked part',
            'slug' => 'manual-checked',
            'donor_car_id' => $donorCar->id,
            'category_id' => $electricalCategory->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 150,
            'currency' => 'USD',
            'notes' => "\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}",
            'is_active' => true,
        ]);
        Product::query()->create([
            'sku' => 'MANUAL-UNKNOWN',
            'external_sku' => 'MANUAL-UNKNOWN',
            'name' => 'Manual unknown part',
            'slug' => 'manual-unknown',
            'donor_car_id' => $donorCar->id,
            'category_id' => $electricalCategory->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 25,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $smallItem = PartCatalogItem::query()->create([
            'part_catalog_category_id' => $bodyCatalogCategory->id,
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.test/small',
            'part_number' => 'SMALL-001',
            'name' => 'Small donor part',
            'raw_attributes' => [
                'donor_vin_small_part' => true,
            ],
        ]);
        Product::query()->create([
            'sku' => 'AUTO-SMALL',
            'external_sku' => 'SMALL-001',
            'name' => 'Small donor part',
            'slug' => 'auto-small',
            'donor_car_id' => $donorCar->id,
            'category_id' => $bodyCategory->id,
            'source_part_catalog_item_id' => $smallItem->id,
            'is_auto_generated' => true,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 35,
            'currency' => 'USD',
            'notes' => "\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}",
            'is_active' => true,
        ]);
        Product::query()->create([
            'sku' => 'AUTO-SOLD',
            'external_sku' => 'SOLD-PRODUCT',
            'name' => 'Sold hidden product',
            'slug' => 'auto-sold',
            'donor_car_id' => $donorCar->id,
            'storage_status' => Product::STORAGE_STATUS_SOLD,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 40,
            'currency' => 'USD',
            'notes' => "\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}",
            'is_active' => true,
        ]);

        $donorCar->partSales()->create([
            'source' => 'nikolacars',
            'code' => 'SOLD-001',
            'part_number' => 'SOLD-001',
            'name' => 'Sold part',
            'quantity' => 1,
            'unit_price' => 50,
            'currency' => 'USD',
            'category_path' => 'Sold Category',
            'source_row_hash' => 'mobile-count-sold-001',
        ]);

        $response = $this->actingAs($user)
            ->get(route('admin.mobile.parts.index'))
            ->assertOk()
            ->assertSee($donorCar->vin);

        $donorCard = str($response->getContent())
            ->after($donorCar->vin)
            ->before('</a>')
            ->toString();

        $this->assertStringContainsString('<span class="tag">3 шт.</span>', $donorCard);

        $showResponse = $this->actingAs($user)
            ->get(route('admin.mobile.donor-cars.parts.show', $donorCar))
            ->assertOk()
            ->assertSee(route('admin.donor-cars.show', $donorCar), false)
            ->assertSee(route('admin.mobile.donor-cars.products.create', $donorCar), false)
            ->assertSee('Unchecked official part')
            ->assertSee('Checked official part')
            ->assertSee(route('admin.mobile.donor-cars.products.edit', [$donorCar, $uncheckedProduct]), false)
            ->assertSee(route('admin.mobile.donor-cars.products.edit', [$donorCar, $checkedProduct]), false)
            ->assertSee('0 фото')
            ->assertSee('2 фото')
            ->assertSee('/nikolacars/prod/checked-main.jpg', false)
            ->assertDontSee('/storage//nikolacars/prod/checked-main.jpg', false)
            ->assertSee('Broken official part')
            ->assertSee('Manual checked part')
            ->assertSee('Manual unknown part')
            ->assertSee('Sold part')
            ->assertDontSee('Small donor part')
            ->assertDontSee('Sold hidden product')
            ->assertSee('data-mobile-parts-category', false)
            ->assertSee('data-mobile-parts-category-option', false)
            ->assertSee('type="checkbox"', false)
            ->assertSee('Кузов')
            ->assertSee('Электрика')
            ->assertSee('Sold Category')
            ->assertSee('part-card--danger', false)
            ->assertSee('part-card--success', false)
            ->assertSee('[hidden] { display: none !important; }', false)
            ->assertSeeInOrder([
                '300.00 USD',
                '200.00 USD',
                '150.00 USD',
                '10.00 USD',
            ])
            ->assertDontSee('Добавлена');
        $saleCard = str($showResponse->getContent())
            ->after('data-part-status="sold"')
            ->before('</article>')
            ->toString();

        $this->assertStringContainsString('hidden', $saleCard);
        $this->assertStringContainsString("status !== 'sold'", $showResponse->getContent());
    }

    public function test_mobile_checked_donor_parts_are_sorted_by_latest_check_first(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJCHECKSORT0001',
            'brand' => 'Tesla',
            'model' => 'Model Y',
            'year' => 2025,
            'status' => DonorCar::STATUS_AT_STO,
            'purchase_date' => '2026-04-01',
        ]);
        $checked = NikolaCarsProductInventorySyncService::CHECKED_DAMAGE_STATUSES[0];
        $olderItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://donor-product/mobile-older-check',
            'part_number' => 'MOBILE-OLDER-CHECK',
            'name' => 'Older checked mobile part',
            'raw_attributes' => [
                'donor_damage_checked_at' => '2026-06-20T10:00:00+03:00',
            ],
        ]);
        $newerItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://donor-product/mobile-newer-check',
            'part_number' => 'MOBILE-NEWER-CHECK',
            'name' => 'Newer checked mobile part',
            'raw_attributes' => [
                'donor_damage_checked_at' => '2026-06-21T10:00:00+03:00',
            ],
        ]);

        Product::query()->create([
            'sku' => 'MOBILE-OLDER-CHECK',
            'external_sku' => 'MOBILE-OLDER-CHECK',
            'name' => 'Older checked mobile part',
            'slug' => 'mobile-older-check',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $olderItem->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 999,
            'currency' => 'USD',
            'notes' => $checked,
            'is_active' => true,
        ]);
        Product::query()->create([
            'sku' => 'MOBILE-NEWER-CHECK',
            'external_sku' => 'MOBILE-NEWER-CHECK',
            'name' => 'Newer checked mobile part',
            'slug' => 'mobile-newer-check',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $newerItem->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 1,
            'currency' => 'USD',
            'notes' => $checked,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.mobile.donor-cars.parts.show', [$donorCar, 'status' => 'checked']))
            ->assertOk()
            ->assertDontSee('style="order:', false)
            ->assertSeeInOrder([
                'Newer checked mobile part',
                'Older checked mobile part',
            ]);
    }

    public function test_mobile_donor_parts_show_uses_nikolacars_product_mirror_category_over_product_import_category(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJMOBILECAT0001',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
            'status' => DonorCar::STATUS_AT_STO,
        ]);
        $productCategory = Category::query()->create([
            'name' => 'Donor imports',
            'slug' => 'mobile-donor-imports',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $legacyCatalogItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-official://mobile-legacy-donor-import-category',
            'part_number' => '1101751-S0-B',
            'name' => 'Legacy mobile donor import category item',
        ]);
        $product = Product::query()->create([
            'sku' => 'NC-MOBILE-MIRROR-CATEGORY',
            'external_sku' => '1101751-S0-B',
            'name' => 'Mobile right upper rail',
            'slug' => 'mobile-right-upper-rail',
            'donor_car_id' => $donorCar->id,
            'category_id' => $productCategory->id,
            'source_part_catalog_item_id' => $legacyCatalogItem->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'used_condition' => 'good',
            'condition_grade' => 'A',
            'notes' => 'Без повреждений',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $undefinedCategory = PartCatalogCategory::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://tesla-category/mobile-undefined-import-overridden',
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
            'name' => 'Mobile right upper rail',
            'main_category_name' => 'Не определено',
            'raw_attributes' => [
                'product_id' => $product->id,
                'donor_vin' => $donorCar->vin,
                'category_display' => 'Не определено',
                'category_path' => 'Не определено',
            ],
        ]);

        $response = $this->actingAs($user)
            ->get(route('admin.mobile.donor-cars.parts.show', $donorCar))
            ->assertOk()
            ->assertSeeText('Не определено');
        $card = str($response->getContent())
            ->after('id="part-'.$product->id.'"')
            ->before('</article>')
            ->toString();
        $visibleText = preg_replace('/<[^>]+>/', ' ', $card);

        $this->assertStringContainsString('NC-MOBILE-MIRROR-CATEGORY', $visibleText);
        $this->assertStringContainsString('Не определено', $visibleText);
        $this->assertStringNotContainsString('Donor imports', $visibleText);
    }

    public function test_mobile_donor_parts_show_prefers_localized_nikolacars_category_tree_over_raw_mirror_category(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJMOBILEHV00001',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
            'status' => DonorCar::STATUS_AT_STO,
        ]);
        $root = PartCatalogCategory::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://tesla-category/mobile-model-3',
            'depth' => 0,
            'name' => 'Model 3',
            'name_ru' => 'Model 3',
        ]);
        $battery = PartCatalogCategory::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://tesla-category/mobile-hv-battery',
            'parent_id' => $root->id,
            'depth' => 1,
            'code' => '16',
            'name' => 'HV Battery System',
            'name_ru' => 'Высоковольтная батарея',
        ]);
        $assembly = PartCatalogCategory::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://tesla-category/mobile-hv-battery-assembly',
            'parent_id' => $battery->id,
            'depth' => 2,
            'code' => '1601',
            'name' => 'HV Battery Assembly',
            'name_ru' => 'Высоковольтная батарея',
        ]);
        $leaf = PartCatalogCategory::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://tesla-category/mobile-high-voltage-battery-assembly',
            'parent_id' => $assembly->id,
            'depth' => 3,
            'name' => 'High Voltage Battery Assembly',
            'name_ru' => 'Высоковольтная батарея',
        ]);
        $product = Product::query()->create([
            'sku' => 'NC-MOBILE-HV-BATTERY',
            'external_sku' => '1080000-00-A',
            'name' => 'Основна батарея 75 kWh Long Range',
            'slug' => 'mobile-hv-battery',
            'donor_car_id' => $donorCar->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'used_condition' => 'good',
            'condition_grade' => 'A',
            'notes' => 'Без повреждений',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        PartCatalogItem::query()->create([
            'part_catalog_category_id' => $leaf->id,
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://donor-product/'.$product->id,
            'part_number' => '1080000-00-A',
            'name' => 'Основна батарея 75 kWh Long Range',
            'raw_attributes' => [
                'product_id' => $product->id,
                'donor_vin' => $donorCar->vin,
                'category_display' => 'Hv battery system / Hv battery assembly / High voltage battery assembly',
                'category_path' => 'Hv battery system / Hv battery assembly / High voltage battery assembly',
            ],
        ]);

        $response = $this->actingAs($user)
            ->get(route('admin.mobile.donor-cars.parts.show', $donorCar))
            ->assertOk();
        $card = str($response->getContent())
            ->after('id="part-'.$product->id.'"')
            ->before('</article>')
            ->toString();
        $visibleText = preg_replace('/<[^>]+>/', ' ', $card);

        $this->assertStringContainsString('16 - Высоковольтная батарея / 1601 - Высоковольтная батарея / Высоковольтная батарея', $visibleText);
        $this->assertStringNotContainsString('Hv battery system / Hv battery assembly / High voltage battery assembly', $visibleText);
    }

    public function test_mobile_donor_part_damage_status_can_be_changed(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJSTATUS0000001',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2024,
            'status' => DonorCar::STATUS_AT_STO,
            'purchase_date' => '2026-04-01',
        ]);
        $product = Product::query()->create([
            'sku' => 'STATUS-001',
            'external_sku' => 'STATUS-001',
            'name' => 'Status editable part',
            'slug' => 'status-editable-part',
            'donor_car_id' => $donorCar->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 120,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $broken = "\u{0420}\u{0430}\u{0437}\u{0431}\u{0438}\u{0442}";

        $this->actingAs($user)
            ->patch(route('admin.mobile.donor-cars.products.damage-status.update', [$donorCar, $product]), [
                'damage_note' => $broken,
            ])
            ->assertRedirect(route('admin.mobile.donor-cars.parts.show', $donorCar).'#part-'.$product->id);

        $product->refresh();
        $this->assertSame($broken, $product->notes);
        $this->assertSame('used', $product->condition_type);

        $nonLiquid = "\u{041D}\u{0435}\u{043B}\u{0438}\u{043A}\u{0432}\u{0438}\u{0434}";

        $this->actingAs($user)
            ->patch(route('admin.mobile.donor-cars.products.damage-status.update', [$donorCar, $product]), [
                'damage_note' => $nonLiquid,
            ])
            ->assertRedirect(route('admin.mobile.donor-cars.parts.show', $donorCar).'#part-'.$product->id);

        $product->refresh();
        $this->assertSame($nonLiquid, $product->notes);
        $this->assertSame('used', $product->condition_type);

        $unknown = "\u{041D}\u{0435}\u{0438}\u{0437}\u{0432}\u{0435}\u{0441}\u{0442}\u{043D}\u{043E}";

        $this->actingAs($user)
            ->patch(route('admin.mobile.donor-cars.products.damage-status.update', [$donorCar, $product]), [
                'damage_note' => $unknown,
            ])
            ->assertRedirect(route('admin.mobile.donor-cars.parts.show', $donorCar).'#part-'.$product->id);

        $product->refresh();
        $this->assertSame($unknown, $product->notes);
        $this->assertSame('used', $product->condition_type);

        $this->actingAs($user)
            ->get(route('admin.mobile.donor-cars.parts.show', $donorCar))
            ->assertOk()
            ->assertSee('data-mobile-part-damage-select', false)
            ->assertSee('<option value="'.$unknown.'" selected>Выбрать статус</option>', false)
            ->assertDontSee('value="" disabled', false)
            ->assertSee(route('admin.mobile.donor-cars.products.damage-status.update', [$donorCar, $product]), false)
            ->assertDontSee('Непригодные 1');
    }

    public function test_mobile_donor_part_damage_status_records_checked_timestamp(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJCHECKED000001',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2024,
            'status' => DonorCar::STATUS_AT_STO,
            'purchase_date' => '2026-04-01',
        ]);
        $unknown = "\u{041D}\u{0435}\u{0438}\u{0437}\u{0432}\u{0435}\u{0441}\u{0442}\u{043D}\u{043E}";
        $checked = "\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}";
        $product = Product::query()->create([
            'sku' => 'CHECKED-001',
            'external_sku' => 'CHECKED-001',
            'name' => 'Checked timestamp donor part',
            'slug' => 'checked-timestamp-donor-part',
            'donor_car_id' => $donorCar->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 120,
            'currency' => 'USD',
            'notes' => $unknown,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->patch(route('admin.mobile.donor-cars.products.damage-status.update', [$donorCar, $product]), [
                'damage_note' => $checked,
            ])
            ->assertRedirect(route('admin.mobile.donor-cars.parts.show', $donorCar).'#part-'.$product->id);

        $catalogItem = $product->refresh()->sourcePartCatalogItem;

        $this->assertInstanceOf(PartCatalogItem::class, $catalogItem);
        $this->assertSame($user->id, $product->donor_damage_status_changed_by);
        $this->assertSame($checked, data_get($catalogItem->raw_attributes, 'donor_damage_status'));
        $this->assertNotEmpty(data_get($catalogItem->raw_attributes, 'donor_damage_checked_at'));
        $this->assertSame($user->id, data_get($catalogItem->raw_attributes, 'donor_damage_status_changed_by'));

        $rawAttributes = $catalogItem->raw_attributes instanceof \ArrayObject
            ? $catalogItem->raw_attributes->getArrayCopy()
            : (array) ($catalogItem->raw_attributes ?? []);
        $rawAttributes['donor_damage_checked_at'] = '2026-06-20T10:00:00+03:00';
        $catalogItem->forceFill(['raw_attributes' => $rawAttributes])->save();

        $updatedChecked = NikolaCarsProductInventorySyncService::CHECKED_DAMAGE_STATUSES[1];
        $expectedCheckedAt = Carbon::parse('2026-06-21T11:30:00+03:00');
        Carbon::setTestNow($expectedCheckedAt);

        try {
            $this->actingAs($user)
                ->patch(route('admin.mobile.donor-cars.products.damage-status.update', [$donorCar, $product]), [
                    'damage_note' => $updatedChecked,
                ])
                ->assertRedirect(route('admin.mobile.donor-cars.parts.show', $donorCar).'#part-'.$product->id);
        } finally {
            Carbon::setTestNow();
        }

        $catalogItem = $product->refresh()->sourcePartCatalogItem;

        $this->assertSame($updatedChecked, data_get($catalogItem->raw_attributes, 'donor_damage_status'));
        $this->assertSame($expectedCheckedAt->toIso8601String(), data_get($catalogItem->raw_attributes, 'donor_damage_checked_at'));

        $this->actingAs($user)
            ->patch(route('admin.mobile.donor-cars.products.damage-status.update', [$donorCar, $product]), [
                'damage_note' => $unknown,
            ])
            ->assertRedirect(route('admin.mobile.donor-cars.parts.show', $donorCar).'#part-'.$product->id);

        $catalogItem = $product->refresh()->sourcePartCatalogItem;

        $this->assertNull($product->donor_damage_status_changed_by);
        $this->assertNull($catalogItem);
    }

    public function test_mobile_donor_part_edit_refreshes_checked_timestamp_when_status_is_unchanged(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJEDITCHECK0001',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2024,
            'status' => DonorCar::STATUS_AT_STO,
            'purchase_date' => '2026-04-01',
        ]);
        $checked = NikolaCarsProductInventorySyncService::CHECKED_DAMAGE_STATUSES[0];
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://donor-product/mobile-edit-refresh-check',
            'part_number' => 'MOBILE-EDIT-REFRESH-CHECK',
            'name' => 'Mobile edit refresh check part',
            'raw_attributes' => [
                'donor_damage_status' => $checked,
                'donor_damage_checked_at' => '2026-06-20T10:00:00+03:00',
            ],
        ]);
        $product = Product::query()->create([
            'sku' => 'MOBILE-EDIT-REFRESH-CHECK',
            'external_sku' => 'MOBILE-EDIT-REFRESH-CHECK',
            'name' => 'Mobile edit refresh check part',
            'slug' => 'mobile-edit-refresh-check',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $catalogItem->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 120,
            'currency' => 'USD',
            'notes' => $checked,
            'is_active' => true,
        ]);

        $expectedCheckedAt = Carbon::parse('2026-06-21T12:15:00+03:00');
        Carbon::setTestNow($expectedCheckedAt);

        try {
            $this->actingAs($user)
                ->patch(route('admin.mobile.donor-cars.products.update', [$donorCar, $product]), [
                    'damage_note' => $checked,
                ])
                ->assertRedirect(route('admin.mobile.donor-cars.products.edit', [$donorCar, $product]));
        } finally {
            Carbon::setTestNow();
        }

        $catalogItem = $product->refresh()->sourcePartCatalogItem;

        $this->assertSame($checked, $product->notes);
        $this->assertSame($expectedCheckedAt->toIso8601String(), data_get($catalogItem->raw_attributes, 'donor_damage_checked_at'));
    }

    public function test_mobile_donor_part_damage_status_autofills_missing_names_from_local_catalog(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJAUTONAMES0001',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2024,
            'status' => DonorCar::STATUS_AT_STO,
            'purchase_date' => '2026-04-01',
        ]);
        $officialItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/catalogs/autonames-local',
            'part_number' => '1084174-00-C',
            'name' => 'Front Bumper',
            'name_en' => 'Front Bumper',
        ]);
        $sourceNameRu = "\u{0411}\u{0430}\u{043C}\u{043F}\u{0435}\u{0440} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0438}\u{0439}";
        $sourceNameUa = "\u{0411}\u{0430}\u{043C}\u{043F}\u{0435}\u{0440} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0456}\u{0439}";
        $sourceItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://existing-name-source/1084174-00-C',
            'part_number' => '1084174-00-C',
            'name' => $sourceNameRu,
            'name_ru' => $sourceNameRu,
            'name_ua' => $sourceNameUa,
        ]);
        $product = Product::query()->create([
            'sku' => 'STATUS-AUTONAMES-LOCAL',
            'external_sku' => '1084174-00-C',
            'name' => 'Front Bumper',
            'slug' => 'status-autonames-local',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $officialItem->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'notes' => "\u{041D}\u{0435}\u{0438}\u{0437}\u{0432}\u{0435}\u{0441}\u{0442}\u{043D}\u{043E}",
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 120,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->patch(route('admin.mobile.donor-cars.products.damage-status.update', [$donorCar, $product]), [
                'damage_note' => "\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}",
            ])
            ->assertRedirect(route('admin.mobile.donor-cars.parts.show', $donorCar).'#part-'.$product->id);

        $product->refresh();
        $mirror = PartCatalogItem::query()
            ->where('source', 'nikolacars')
            ->where('source_url', 'nikolacars://donor-product/'.$product->id)
            ->firstOrFail();

        $this->assertSame($mirror->id, $product->source_part_catalog_item_id);
        $this->assertSame($sourceNameRu, $mirror->name_ru);
        $this->assertSame($sourceNameUa, $mirror->name_ua);
        $this->assertSame($sourceItem->id, data_get($mirror->raw_attributes, 'name_source_item_id_ru'));
        $this->assertSame($sourceItem->id, data_get($mirror->raw_attributes, 'name_source_item_id_ua'));
        $this->assertSame('donor_status_catalog_match', data_get($mirror->raw_attributes, 'name_source_type_ru'));
    }

    public function test_mobile_donor_part_damage_status_can_use_google_translate_for_missing_names(): void
    {
        Config::set('services.google_translate.key', 'fake-google-key');
        Config::set('services.google_translate.allow_in_testing', true);
        Http::fake([
            'translation.googleapis.com/*' => Http::sequence()
                ->push([
                    'data' => [
                        'translations' => [
                            ['translatedText' => "\u{041F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0438}\u{0439} \u{0431}\u{0430}\u{043C}\u{043F}\u{0435}\u{0440}"],
                        ],
                    ],
                ])
                ->push([
                    'data' => [
                        'translations' => [
                            ['translatedText' => "\u{041F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0456}\u{0439} \u{0431}\u{0430}\u{043C}\u{043F}\u{0435}\u{0440}"],
                        ],
                    ],
                ]),
        ]);

        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJGOOGLE000001',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2024,
            'status' => DonorCar::STATUS_AT_STO,
            'purchase_date' => '2026-04-01',
        ]);
        $officialItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/catalogs/autonames-google',
            'part_number' => '1084174-00-G',
            'name' => 'Front Bumper',
            'name_en' => 'Front Bumper',
        ]);
        $product = Product::query()->create([
            'sku' => 'STATUS-AUTONAMES-GOOGLE',
            'external_sku' => '1084174-00-G',
            'name' => 'Front Bumper',
            'slug' => 'status-autonames-google',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $officialItem->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'notes' => "\u{041D}\u{0435}\u{0438}\u{0437}\u{0432}\u{0435}\u{0441}\u{0442}\u{043D}\u{043E}",
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 120,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->patch(route('admin.mobile.donor-cars.products.damage-status.update', [$donorCar, $product]), [
                'damage_note' => "\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}",
            ])
            ->assertRedirect(route('admin.mobile.donor-cars.parts.show', $donorCar).'#part-'.$product->id);

        $mirror = PartCatalogItem::query()
            ->where('source', 'nikolacars')
            ->where('source_url', 'nikolacars://donor-product/'.$product->id)
            ->firstOrFail();

        $this->assertSame("\u{041F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0438}\u{0439} \u{0431}\u{0430}\u{043C}\u{043F}\u{0435}\u{0440}", $mirror->name_ru);
        $this->assertSame("\u{041F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0456}\u{0439} \u{0431}\u{0430}\u{043C}\u{043F}\u{0435}\u{0440}", $mirror->name_ua);
        $this->assertSame('Google Translate', data_get($mirror->raw_attributes, 'name_source_site_ru'));
        $this->assertSame('Google Translate', data_get($mirror->raw_attributes, 'name_source_site_ua'));
        $this->assertSame('tesla_official_google_translate', data_get($mirror->raw_attributes, 'name_source_type_ua'));
        Http::assertSentCount(2);
    }

    public function test_mobile_donor_part_damage_status_keeps_google_translate_badge_from_official_source(): void
    {
        Config::set('services.google_translate.key', 'fake-google-key');
        Config::set('services.google_translate.allow_in_testing', true);
        Http::fake([
            'translation.googleapis.com/*' => function ($request) {
                if (($request->data()['target'] ?? null) !== 'uk') {
                    return Http::response([], 500);
                }

                return Http::response([
                    'data' => [
                        'translations' => [
                            ['translatedText' => "\u{041F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0456}\u{0439} \u{0431}\u{0430}\u{043C}\u{043F}\u{0435}\u{0440}"],
                        ],
                    ],
                ]);
            },
        ]);

        $translatedNameRu = "\u{041F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0438}\u{0439} \u{0431}\u{0430}\u{043C}\u{043F}\u{0435}\u{0440}";
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJGOOGLEBADGE01',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2024,
            'status' => DonorCar::STATUS_AT_STO,
            'purchase_date' => '2026-04-01',
        ]);
        $officialItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/catalogs/autonames-google-badge',
            'part_number' => '1084174-00-B',
            'name' => 'Front Bumper',
            'name_en' => 'Front Bumper',
            'name_ru' => $translatedNameRu,
            'raw_attributes' => [
                'name_source_site' => 'Google Translate',
                'name_source_url' => 'https://cloud.google.com/translate',
                'name_source_site_ru' => 'Google Translate',
                'name_source_url_ru' => 'https://cloud.google.com/translate',
                'name_source_type_ru' => 'donor_status_google_translate',
            ],
        ]);
        $product = Product::query()->create([
            'sku' => 'STATUS-AUTONAMES-GOOGLE-BADGE',
            'external_sku' => '1084174-00-B',
            'name' => 'Front Bumper',
            'slug' => 'status-autonames-google-badge',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $officialItem->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'notes' => "\u{041D}\u{0435}\u{0438}\u{0437}\u{0432}\u{0435}\u{0441}\u{0442}\u{043D}\u{043E}",
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 120,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->patch(route('admin.mobile.donor-cars.products.damage-status.update', [$donorCar, $product]), [
                'damage_note' => "\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}",
            ])
            ->assertRedirect(route('admin.mobile.donor-cars.parts.show', $donorCar).'#part-'.$product->id);

        $mirror = PartCatalogItem::query()
            ->where('source', 'nikolacars')
            ->where('source_url', 'nikolacars://donor-product/'.$product->id)
            ->firstOrFail();

        $this->assertSame($translatedNameRu, $mirror->name_ru);
        $this->assertSame("\u{041F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0456}\u{0439} \u{0431}\u{0430}\u{043C}\u{043F}\u{0435}\u{0440}", $mirror->name_ua);
        $this->assertSame('Google Translate', data_get($mirror->raw_attributes, 'name_source_site'));
        $this->assertSame('Google Translate', data_get($mirror->raw_attributes, 'name_source_site_ru'));
        $this->assertSame('Google Translate', data_get($mirror->raw_attributes, 'name_source_site_ua'));
        $this->assertSame('donor_status_google_translate', data_get($mirror->raw_attributes, 'name_source_type_ru'));
        $this->assertSame('tesla_official_google_translate', data_get($mirror->raw_attributes, 'name_source_type_ua'));
        Http::assertSentCount(1);
    }

    public function test_mobile_donor_part_damage_status_translates_missing_name_from_found_local_name(): void
    {
        Config::set('services.google_translate.key', 'fake-google-key');
        Config::set('services.google_translate.allow_in_testing', true);

        $sourceNameUa = "\u{0411}\u{0430}\u{043C}\u{043F}\u{0435}\u{0440} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0456}\u{0439}";
        $translatedNameRu = "\u{0411}\u{0430}\u{043C}\u{043F}\u{0435}\u{0440} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0438}\u{0439}";

        Http::fake([
            'translation.googleapis.com/*' => function ($request) use ($sourceNameUa, $translatedNameRu) {
                if (($request->data()['q'] ?? null) !== $sourceNameUa) {
                    return Http::response([], 500);
                }

                return Http::response([
                    'data' => [
                        'translations' => [
                            ['translatedText' => $translatedNameRu],
                        ],
                    ],
                ]);
            },
        ]);

        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJONELOCAL0001',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2024,
            'status' => DonorCar::STATUS_AT_STO,
            'purchase_date' => '2026-04-01',
        ]);
        $officialItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/catalogs/autonames-one-local',
            'part_number' => '1084174-00-U',
            'name' => 'Front Bumper',
            'name_en' => 'Front Bumper',
        ]);
        PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://existing-name-source/1084174-00-U',
            'part_number' => '1084174-00-U',
            'name' => $sourceNameUa,
            'name_ua' => $sourceNameUa,
        ]);
        $product = Product::query()->create([
            'sku' => 'STATUS-AUTONAMES-ONE-LOCAL',
            'external_sku' => '1084174-00-U',
            'name' => 'Front Bumper',
            'slug' => 'status-autonames-one-local',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $officialItem->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'notes' => "\u{041D}\u{0435}\u{0438}\u{0437}\u{0432}\u{0435}\u{0441}\u{0442}\u{043D}\u{043E}",
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 120,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->patch(route('admin.mobile.donor-cars.products.damage-status.update', [$donorCar, $product]), [
                'damage_note' => "\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}",
            ])
            ->assertRedirect(route('admin.mobile.donor-cars.parts.show', $donorCar).'#part-'.$product->id);

        $mirror = PartCatalogItem::query()
            ->where('source', 'nikolacars')
            ->where('source_url', 'nikolacars://donor-product/'.$product->id)
            ->firstOrFail();

        $this->assertSame($translatedNameRu, $mirror->name_ru);
        $this->assertSame($sourceNameUa, $mirror->name_ua);
        $this->assertSame('Google Translate', data_get($mirror->raw_attributes, 'name_source_site_ru'));
        $this->assertSame('donor_status_catalog_match', data_get($mirror->raw_attributes, 'name_source_type_ua'));
        Http::assertSent(fn ($request): bool => ($request->data()['q'] ?? null) === $sourceNameUa
            && ($request->data()['target'] ?? null) === 'ru');
    }

    public function test_mobile_donor_part_photo_can_be_added_from_card(): void
    {
        Storage::fake('public');

        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJPHOTO00000001',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2024,
            'status' => DonorCar::STATUS_AT_STO,
            'purchase_date' => '2026-04-01',
        ]);
        $product = Product::query()->create([
            'sku' => 'PHOTO-001',
            'external_sku' => 'PHOTO-001',
            'name' => 'Photo editable part',
            'slug' => 'photo-editable-part',
            'donor_car_id' => $donorCar->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 120,
            'currency' => 'USD',
            'main_image' => 'product-photos/old.jpg',
            'images_json' => ['product-photos/old.jpg'],
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('admin.mobile.donor-cars.products.photos.store', [$donorCar, $product]), [
                'photo' => UploadedFile::fake()->image('part-camera.jpg', 800, 600),
            ])
            ->assertRedirect(route('admin.mobile.donor-cars.parts.show', $donorCar).'#part-'.$product->id);

        $product->refresh();
        $this->assertNotSame('product-photos/old.jpg', $product->main_image);
        $this->assertContains('product-photos/old.jpg', (array) $product->images_json);
        $this->assertContains($product->main_image, (array) $product->images_json);
        Storage::disk('public')->assertExists($product->main_image);

        $this->actingAs($user)
            ->get(route('admin.mobile.donor-cars.parts.show', $donorCar))
            ->assertOk()
            ->assertSee('data-mobile-part-photo-input', false)
            ->assertSee('capture="environment"', false)
            ->assertSee('2 фото')
            ->assertSee(route('admin.mobile.donor-cars.products.photos.store', [$donorCar, $product]), false);
    }

    public function test_mobile_donor_part_photo_can_be_deleted_from_edit_page(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('product-photos/main.jpg', 'main');
        Storage::disk('public')->put('product-photos/detail.jpg', 'detail');

        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJDELETEPHOTO001',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2024,
            'status' => DonorCar::STATUS_AT_STO,
            'purchase_date' => '2026-04-01',
        ]);
        $product = Product::query()->create([
            'sku' => 'DELETE-PHOTO-001',
            'external_sku' => 'DELETE-PHOTO-001',
            'name' => 'Delete photo part',
            'slug' => 'delete-photo-part',
            'donor_car_id' => $donorCar->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 120,
            'currency' => 'USD',
            'main_image' => 'product-photos/main.jpg',
            'images_json' => ['product-photos/main.jpg', 'product-photos/detail.jpg', 'tesla-official/part-images/1002032/1002032.jpeg'],
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)
            ->get(route('admin.mobile.donor-cars.products.edit', [$donorCar, $product]))
            ->assertOk()
            ->assertSee(route('admin.mobile.donor-cars.products.photos.destroy', [$donorCar, $product]), false)
            ->assertSee('data-mobile-photo-delete-form', false)
            ->assertSee('aria-label="'.json_decode('"\u0423\u0434\u0430\u043b\u0438\u0442\u044c \u0444\u043e\u0442\u043e"', true, 512, JSON_THROW_ON_ERROR).'"', false)
            ->assertSee('tesla.com')
            ->assertSee('mobile-photo-source-tag', false);
        $html = $response->getContent();
        $this->assertStringNotContainsString('value="tesla-official/part-images/1002032/1002032.jpeg"', $html);

        $this->actingAs($user)
            ->from(route('admin.mobile.donor-cars.products.edit', [$donorCar, $product]))
            ->delete(route('admin.mobile.donor-cars.products.photos.destroy', [$donorCar, $product]), [
                'photo' => 'tesla-official/part-images/1002032/1002032.jpeg',
            ])
            ->assertRedirect(route('admin.mobile.donor-cars.products.edit', [$donorCar, $product]))
            ->assertSessionHasErrors('photo');

        $this->actingAs($user)
            ->delete(route('admin.mobile.donor-cars.products.photos.destroy', [$donorCar, $product]), [
                'photo' => 'product-photos/main.jpg',
            ])
            ->assertRedirect(route('admin.mobile.donor-cars.products.edit', [$donorCar, $product]));

        $product->refresh();
        $this->assertSame('product-photos/detail.jpg', $product->main_image);
        $this->assertSame(['product-photos/detail.jpg', 'tesla-official/part-images/1002032/1002032.jpeg'], (array) $product->images_json);
        Storage::disk('public')->assertMissing('product-photos/main.jpg');
        Storage::disk('public')->assertExists('product-photos/detail.jpg');
    }

    public function test_mobile_donor_part_photos_can_be_reordered_from_edit_page(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJORDERPHOTO001',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2024,
            'status' => DonorCar::STATUS_AT_STO,
            'purchase_date' => '2026-04-01',
        ]);
        $product = Product::query()->create([
            'sku' => 'ORDER-PHOTO-001',
            'external_sku' => 'ORDER-PHOTO-001',
            'name' => 'Order photo part',
            'slug' => 'order-photo-part',
            'donor_car_id' => $donorCar->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 120,
            'currency' => 'USD',
            'main_image' => 'product-photos/first.jpg',
            'images_json' => ['product-photos/first.jpg', 'product-photos/second.jpg', 'tesla-official/part-images/1002032/1002032.jpeg'],
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.mobile.donor-cars.products.edit', [$donorCar, $product]))
            ->assertOk()
            ->assertSee(route('admin.mobile.donor-cars.products.photos.order', [$donorCar, $product]), false)
            ->assertSee('data-mobile-photo-sortable', false)
            ->assertSee('data-mobile-photo-drag-handle', false)
            ->assertSee('data-photo="product-photos/first.jpg"', false);

        $this->actingAs($user)
            ->patch(route('admin.mobile.donor-cars.products.photos.order', [$donorCar, $product]), [
                'photos' => [
                    'tesla-official/part-images/1002032/1002032.jpeg',
                    'product-photos/second.jpg',
                    'product-photos/first.jpg',
                ],
            ])
            ->assertRedirect(route('admin.mobile.donor-cars.products.edit', [$donorCar, $product]));

        $product->refresh();
        $this->assertSame('tesla-official/part-images/1002032/1002032.jpeg', $product->main_image);
        $this->assertSame([
            'tesla-official/part-images/1002032/1002032.jpeg',
            'product-photos/second.jpg',
            'product-photos/first.jpg',
        ], (array) $product->images_json);
    }

    public function test_mobile_donor_part_photo_order_can_be_saved_without_page_reload(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJAJAXPHOTO001',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2024,
            'status' => DonorCar::STATUS_AT_STO,
            'purchase_date' => '2026-04-01',
        ]);
        $product = Product::query()->create([
            'sku' => 'AJAX-PHOTO-001',
            'external_sku' => 'AJAX-PHOTO-001',
            'name' => 'Ajax photo part',
            'slug' => 'ajax-photo-part',
            'donor_car_id' => $donorCar->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 120,
            'currency' => 'USD',
            'main_image' => 'product-photos/first.jpg',
            'images_json' => ['product-photos/first.jpg', 'product-photos/second.jpg'],
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->withHeaders([
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->patch(route('admin.mobile.donor-cars.products.photos.order', [$donorCar, $product]), [
                'photos' => [
                    'product-photos/second.jpg',
                    'product-photos/first.jpg',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('main_image', 'product-photos/second.jpg')
            ->assertJsonPath('photos.0', 'product-photos/second.jpg');

        $product->refresh();
        $this->assertSame('product-photos/second.jpg', $product->main_image);
        $this->assertSame([
            'product-photos/second.jpg',
            'product-photos/first.jpg',
        ], (array) $product->images_json);
    }

    public function test_mobile_donor_parts_show_paginates_large_product_lists(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJPAGE000000001',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2024,
            'status' => DonorCar::STATUS_AT_STO,
            'purchase_date' => '2026-04-01',
        ]);

        foreach (range(1, 85) as $index) {
            Product::query()->create([
                'sku' => sprintf('PAGE-%03d', $index),
                'external_sku' => sprintf('PAGE-%03d', $index),
                'name' => sprintf('Paged donor part %03d', $index),
                'slug' => sprintf('paged-donor-part-%03d', $index),
                'donor_car_id' => $donorCar->id,
                'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
                'condition_type' => 'used',
                'testing_status' => 'not_tested',
                'unit' => 'pcs',
                'selling_price' => $index,
                'currency' => 'USD',
                'is_active' => true,
            ]);
        }

        $this->actingAs($user)
            ->get(route('admin.mobile.donor-cars.parts.show', $donorCar))
            ->assertOk()
            ->assertSee('Paged donor part 085')
            ->assertDontSee('Paged donor part 001')
            ->assertSee('data-mobile-parts-load-more', false)
            ->assertSee('page=2', false);
    }

    public function test_mobile_donor_parts_search_finds_products_beyond_first_page(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJSEARCH0000001',
            'brand' => 'Tesla',
            'model' => 'Model Y',
            'year' => 2025,
            'status' => DonorCar::STATUS_AT_STO,
            'purchase_date' => '2026-04-01',
        ]);

        foreach (range(1, 85) as $index) {
            Product::query()->create([
                'sku' => sprintf('SEARCH-%03d', $index),
                'external_sku' => sprintf('SEARCH-%03d', $index),
                'name' => $index === 1 ? 'Needle second page headlight' : sprintf('Ordinary donor part %03d', $index),
                'slug' => sprintf('search-donor-part-%03d', $index),
                'donor_car_id' => $donorCar->id,
                'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
                'condition_type' => 'used',
                'testing_status' => 'not_tested',
                'unit' => 'pcs',
                'selling_price' => $index,
                'currency' => 'USD',
                'is_active' => true,
            ]);
        }

        $this->actingAs($user)
            ->get(route('admin.mobile.donor-cars.parts.show', [$donorCar, 'q' => 'Needle']))
            ->assertOk()
            ->assertSee('Needle second page headlight')
            ->assertDontSee('Ordinary donor part 085');
    }

    public function test_mobile_donor_part_damage_status_can_be_saved_as_json_with_warehouse_location(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJJSON000000001',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2024,
            'status' => DonorCar::STATUS_AT_STO,
            'purchase_date' => '2026-04-01',
        ]);
        $warehouse = Warehouse::query()->create([
            'name' => 'Mobile Test Warehouse',
            'type' => Warehouse::TYPE_MAIN,
            'floor_count' => 1,
            'is_active' => true,
        ]);
        $location = Location::query()->create([
            'warehouse_id' => $warehouse->id,
            'floor' => 'floor_1',
            'cell' => 'A-01',
            'full_code' => 'WH-MOBILE-A-01',
            'is_active' => true,
        ]);
        $product = Product::query()->create([
            'sku' => 'JSON-001',
            'external_sku' => 'JSON-001',
            'name' => 'Json status part',
            'slug' => 'json-status-part',
            'donor_car_id' => $donorCar->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 120,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $checked = "\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}";

        $this->actingAs($user)
            ->patchJson(route('admin.mobile.donor-cars.products.damage-status.update', [$donorCar, $product]), [
                'damage_note' => $checked,
                'warehouse_id' => $warehouse->id,
                'location_id' => $location->id,
            ])
            ->assertOk()
            ->assertJsonPath('product_id', $product->id)
            ->assertJsonPath('damage_note', $checked)
            ->assertJsonPath('status.key', 'checked')
            ->assertJsonPath('stock_label', 'Mobile Test Warehouse · Этаж 1 · A-01');

        $product->refresh();
        $this->assertSame($checked, $product->notes);
        $this->assertSame(Product::STORAGE_STATUS_IN_STOCK, $product->storage_status);
        $this->assertDatabaseHas('stock_items', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'location_id' => $location->id,
            'quantity' => 1,
        ]);
    }
    public function test_mobile_donor_part_edit_page_saves_checked_status_with_warehouse_location(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJEDITCELL00001',
            'brand' => 'Tesla',
            'model' => 'Model Y',
            'year' => 2025,
            'status' => DonorCar::STATUS_AT_STO,
            'purchase_date' => '2026-04-01',
        ]);
        $warehouse = Warehouse::query()->create([
            'name' => 'Edit Cell Warehouse',
            'type' => Warehouse::TYPE_MAIN,
            'floor_count' => 2,
            'is_active' => true,
        ]);
        $location = Location::query()->create([
            'warehouse_id' => $warehouse->id,
            'floor' => 'floor_2',
            'cell' => 'B-22',
            'full_code' => 'WH-EDIT-B-22',
            'is_active' => true,
        ]);
        $product = Product::query()->create([
            'sku' => 'EDIT-CELL-001',
            'external_sku' => 'EDIT-CELL-001',
            'name' => 'Edit cell donor part',
            'slug' => 'edit-cell-donor-part',
            'donor_car_id' => $donorCar->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 120,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $checked = "\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}";

        $this->actingAs($user)
            ->get(route('admin.mobile.donor-cars.products.edit', [$donorCar, $product]))
            ->assertOk()
            ->assertSee('data-mobile-edit-damage-select', false)
            ->assertSee('data-mobile-edit-placement', false)
            ->assertSee('data-mobile-edit-warehouse-select', false)
            ->assertSee('data-mobile-edit-location-select', false)
            ->assertSee('updateEditPlacement', false);

        $this->actingAs($user)
            ->patch(route('admin.mobile.donor-cars.products.update', [$donorCar, $product]), [
                'external_sku' => 'EDIT-CELL-001',
                'damage_note' => $checked,
                'warehouse_id' => $warehouse->id,
                'floor' => 'floor_2',
                'location_id' => $location->id,
            ])
            ->assertRedirect(route('admin.mobile.donor-cars.products.edit', [$donorCar, $product]));

        $product->refresh();
        $this->assertSame($checked, $product->notes);
        $this->assertSame(Product::STORAGE_STATUS_IN_STOCK, $product->storage_status);
        $this->assertDatabaseHas('stock_items', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'location_id' => $location->id,
            'quantity' => 1,
        ]);

        $this->actingAs($user)
            ->get(route('admin.mobile.donor-cars.products.edit', [$donorCar, $product]))
            ->assertOk()
            ->assertSee('data-selected-warehouse="'.$warehouse->id.'"', false)
            ->assertSee('data-selected-floor="floor_2"', false)
            ->assertSee('data-selected-location="'.$location->id.'"', false);
    }
    public function test_mobile_donor_part_without_edit_suffix_returns_not_found(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJNOEDIT0000001',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2024,
            'status' => DonorCar::STATUS_AT_STO,
            'purchase_date' => '2026-04-01',
        ]);
        $product = Product::query()->create([
            'sku' => 'NOEDIT-001',
            'external_sku' => 'NOEDIT-001',
            'name' => 'No edit suffix part',
            'slug' => 'no-edit-suffix-part',
            'donor_car_id' => $donorCar->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 120,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get('/admin/mobile/donor-cars/'.$donorCar->id.'/parts/'.$product->id)
            ->assertNotFound();

        $this->actingAs($user)
            ->get(route('admin.mobile.donor-cars.products.edit', [$donorCar, $product]))
            ->assertOk();
    }

    public function test_mobile_donor_part_edit_page_updates_catalog_names_article_and_damage(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJEDIT000000001',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2024,
            'status' => DonorCar::STATUS_AT_STO,
            'purchase_date' => '2026-04-01',
        ]);
        $catalogCategory = PartCatalogCategory::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.test/categories/body',
            'preview_image_url' => 'tesla-official/resources-images/node.png',
            'depth' => 1,
            'code' => '10',
            'name' => 'BODY',
            'name_ru' => 'Кузов',
            'model_name' => 'Model 3',
        ]);
        $catalogItem = PartCatalogItem::query()->create([
            'part_catalog_category_id' => $catalogCategory->id,
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.test/edit',
            'part_number' => 'TESLA-EDIT',
            'name' => 'Edit official part',
            'name_ru' => 'Старое RU',
            'name_ua' => 'Старе UA',
            'scheme_number' => '7',
            'raw_attributes' => [
                'system_group_image_urls' => ['tesla-official/resources-images/node-detail.png'],
            ],
        ]);
        $product = Product::query()->create([
            'sku' => 'EDIT-001',
            'external_sku' => 'TESLA-EDIT',
            'name' => 'Edit official part',
            'slug' => 'edit-official-part',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $catalogItem->id,
            'is_auto_generated' => true,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 120,
            'currency' => 'USD',
            'main_image' => '/nikolacars/prod/main.jpg',
            'images_json' => ['/nikolacars/prod/main.jpg', '/nikolacars/prod/detail.jpg'],
            'notes' => "\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}",
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.mobile.donor-cars.products.edit', [$donorCar, $product]))
            ->assertOk()
            ->assertSee('Название RU')
            ->assertSee('Название УКР')
            ->assertSee('Артикул')
            ->assertSee('Статус')
            ->assertSee('Схема узла')
            ->assertSee('Сделать фото')
            ->assertSee('Загрузить')
            ->assertSee('data-mobile-edit-photo-name="photo"', false)
            ->assertDontSee('type="file" name="photo"', false)
            ->assertSee('/nikolacars/prod/main.jpg', false)
            ->assertSee('/nikolacars/prod/detail.jpg', false)
            ->assertSee('data-mobile-photo-open', false)
            ->assertSee('data-mobile-photo-viewer', false)
            ->assertSee('data-mobile-photo-viewer-next', false)
            ->assertDontSee('/storage//nikolacars/prod/main.jpg', false)
            ->assertSee('tesla-official/resources-images/node-detail.png', false)
            ->assertSee(route('admin.mobile.donor-cars.parts.show', $donorCar).'#part-'.$product->id, false)
            ->assertSee(route('admin.mobile.donor-cars.products.update', [$donorCar, $product]), false)
            ->assertSee(route('admin.mobile.donor-cars.products.photos.store', [$donorCar, $product]), false);

        $broken = "\u{0420}\u{0430}\u{0437}\u{0431}\u{0438}\u{0442}";

        $this->actingAs($user)
            ->patch(route('admin.mobile.donor-cars.products.update', [$donorCar, $product]), [
                'name_ru' => 'Новое RU',
                'name_ua' => 'Нове UA',
                'external_sku' => 'TESLA-EDIT-NEW',
                'damage_note' => $broken,
            ])
            ->assertRedirect(route('admin.mobile.donor-cars.products.edit', [$donorCar, $product]));

        $product->refresh();
        $catalogItem->refresh();
        $this->assertSame('TESLA-EDIT-NEW', $product->external_sku);
        $this->assertSame($broken, $product->notes);
        $this->assertSame('used', $product->condition_type);
        $this->assertSame('Новое RU', $catalogItem->name_ru);
        $this->assertSame('Нове UA', $catalogItem->name_ua);
    }
}
