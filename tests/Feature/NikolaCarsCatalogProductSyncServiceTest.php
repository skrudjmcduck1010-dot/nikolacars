<?php

namespace Tests\Feature;

use App\Models\DonorCar;
use App\Models\PartCatalogItem;
use App\Models\Product;
use App\Services\NikolaCarsCatalogProductSyncService;
use App\Services\NikolaCarsProductInventorySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NikolaCarsCatalogProductSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_nikolacars_catalog_donor_item_is_linked_as_donor_product(): void
    {
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJSA1H1XEFP59563',
            'brand' => 'Tesla',
            'model' => 'Model S',
            'year' => 2014,
        ]);
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/581',
            'part_number' => '5YJSA1H1XEFP59563',
            'name' => 'HV battery',
            'name_ru' => "\u{0410}\u{043A}\u{043A}\u{0443}\u{043C}\u{0443}\u{043B}\u{044F}\u{0442}\u{043E}\u{0440}\u{043D}\u{0430}\u{044F} \u{0431}\u{0430}\u{0442}\u{0430}\u{0440}\u{0435}\u{044F}",
            'price_amount' => 3600,
            'currency' => 'USD',
            'model_label' => 'Model S 02.2012-03.2016',
            'compatibility_text' => '5YJSA1H1XEFP59563',
            'raw_attributes' => [
                'code' => '581',
                'donor_vin' => '5YJSA1H1XEFP59563',
                'image_urls' => ['/nikolacars/prod/581_1.jpg'],
            ],
        ]);

        $result = app(NikolaCarsCatalogProductSyncService::class)->syncItem($item);

        $this->assertTrue($result['saved']);
        $this->assertDatabaseHas('products', [
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $item->id,
            'sku' => 'NC-581',
            'external_sku' => '5YJSA1H1XEFP59563',
            'notes' => NikolaCarsProductInventorySyncService::CHECKED_DAMAGE_STATUSES[0],
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
        ]);

        $product = Product::query()->where('source_part_catalog_item_id', $item->id)->firstOrFail();
        $freshItem = $item->fresh();

        $this->assertSame($product->id, data_get($freshItem->raw_attributes, 'product_id'));
        $this->assertSame(NikolaCarsProductInventorySyncService::CHECKED_DAMAGE_STATUSES[0], $freshItem->quality);
    }

    public function test_nikolacars_catalog_donor_item_accepts_case_insensitive_checked_damage_status(): void
    {
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJYGDEE3MF214952',
            'brand' => 'Tesla',
            'model' => 'Model Y',
            'year' => 2021,
        ]);
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/786',
            'part_number' => '1501462-12-A',
            'name' => 'Front right door',
            'price_amount' => 750,
            'currency' => 'USD',
            'compatibility_text' => '5YJYGDEE3MF214952',
            'quality' => mb_strtolower(NikolaCarsProductInventorySyncService::CHECKED_DAMAGE_STATUSES[1]),
            'raw_attributes' => [
                'code' => '786',
                'donor_vin' => '5YJYGDEE3MF214952',
            ],
        ]);

        $result = app(NikolaCarsCatalogProductSyncService::class)->syncItem($item);

        $this->assertTrue($result['saved']);
        $this->assertDatabaseHas('products', [
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $item->id,
            'sku' => 'NC-786',
            'external_sku' => '1501462-12-A',
            'notes' => NikolaCarsProductInventorySyncService::CHECKED_DAMAGE_STATUSES[1],
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
        ]);
    }

    public function test_nikolacars_catalog_item_can_link_to_known_pseudo_vin_donor(): void
    {
        $pseudoVin = "TESLA \u{041C}S 2015 - 2021 \u{0437}\u{0430}\u{043B}\u{0438}\u{0448}\u{043A}\u{0438}";
        $donorCar = DonorCar::query()->create([
            'vin' => $pseudoVin,
            'brand' => 'Tesla',
            'model' => 'Model S',
            'year' => 2015,
        ]);
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/28',
            'part_number' => '1004808-00-D',
            'name' => 'FM antenna Tesla MS',
            'price_amount' => 30,
            'currency' => 'USD',
            'compatibility_text' => $pseudoVin,
            'raw_attributes' => [
                'code' => '28',
                'donor_vin' => $pseudoVin,
                'category_display' => $pseudoVin,
            ],
        ]);

        $result = app(NikolaCarsCatalogProductSyncService::class)->syncItem($item);

        $this->assertTrue($result['saved']);
        $this->assertDatabaseHas('products', [
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $item->id,
            'sku' => 'NC-28',
            'external_sku' => '1004808-00-D',
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
        ]);
    }

    public function test_nikolacars_catalog_item_without_vin_is_linked_as_manual_stock_product(): void
    {
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/458',
            'part_number' => '1081460-11-D',
            'name' => 'Trunk door Tesla M3 2018 - 2021 1081460-11-D',
            'price_amount' => 300,
            'currency' => 'USD',
            'compatibility_text' => 'Tesla; Model 3 leftovers; Exterior / Body',
            'raw_attributes' => [
                'code' => '458',
                'category_display' => 'Tesla; Model 3 leftovers; Exterior / Body',
            ],
        ]);

        $result = app(NikolaCarsCatalogProductSyncService::class)->syncItem($item);

        $this->assertTrue($result['saved']);
        $this->assertDatabaseHas('products', [
            'donor_car_id' => null,
            'source_part_catalog_item_id' => $item->id,
            'sku' => 'NC-458',
            'external_sku' => '1081460-11-D',
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'testing_status' => 'not_tested',
            'is_auto_generated' => false,
        ]);

        $product = Product::query()->where('source_part_catalog_item_id', $item->id)->firstOrFail();
        $freshItem = $item->fresh();

        $this->assertSame($product->id, data_get($freshItem->raw_attributes, 'product_id'));
        $this->assertSame('warehouse', data_get($freshItem->raw_attributes, 'source_type'));
        $this->assertSame('Tesla; Model 3 leftovers; Exterior / Body', $product->compatibility);
    }

    public function test_nikolacars_catalog_product_keeps_full_source_row_name(): void
    {
        $sourceName = "\u{041D}\u{0430}\u{043A}\u{043B}\u{0430}\u{0434}\u{043A}\u{0430} \u{0434}\u{0432}\u{0435}\u{0440}\u{0435}\u{0439} \u{0432}\u{043D}\u{0443}\u{0442}\u{0440}\u{0456}\u{0448}\u{043D}\u{044F} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{044F} \u{043B}\u{0456}\u{0432}\u{0430}";
        $sourceRowName = $sourceName.' Tesla MS 2012 - 2015 1002458-00-F/H';

        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/145',
            'part_number' => '1002458-00-F',
            'name' => $sourceName,
            'name_ua' => $sourceName,
            'price_amount' => 10,
            'currency' => 'USD',
            'raw_attributes' => [
                'code' => '145',
                'source_row' => [
                    'code' => '145',
                    'name' => $sourceRowName,
                    'part_number' => '1002458-00-F/H',
                ],
            ],
        ]);

        $result = app(NikolaCarsCatalogProductSyncService::class)->syncItem($item);

        $this->assertTrue($result['saved']);
        $this->assertDatabaseHas('products', [
            'source_part_catalog_item_id' => $item->id,
            'name' => $sourceRowName,
        ]);
        $this->assertSame($sourceName, $item->fresh()->name_ua);
    }

    public function test_nikolacars_donor_product_mirror_updates_source_product_without_creating_duplicate(): void
    {
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EB8JF091651',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2018,
        ]);
        $officialItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=1044831-99-E',
            'part_number' => '1044831-99-E',
            'name' => 'STEERING GEAR ASSEMBLY - LEFT HAND DRIVE',
        ]);
        $sourceProduct = Product::query()->create([
            'sku' => 'DON28-1260',
            'external_sku' => '1044831-99-E',
            'name' => 'STEERING GEAR ASSEMBLY - LEFT HAND DRIVE',
            'slug' => 'DON28-1260',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $officialItem->id,
            'is_auto_generated' => true,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 2260,
            'currency' => 'USD',
            'notes' => NikolaCarsProductInventorySyncService::CHECKED_DAMAGE_STATUSES[0],
            'is_active' => true,
        ]);

        app(NikolaCarsProductInventorySyncService::class)->syncProduct($sourceProduct);

        $mirrorItem = PartCatalogItem::query()
            ->where('source', 'nikolacars')
            ->where('source_url', 'nikolacars://donor-product/'.$sourceProduct->id)
            ->firstOrFail();

        $result = app(NikolaCarsCatalogProductSyncService::class)->syncItem($mirrorItem);

        $this->assertTrue($result['saved']);
        $this->assertSame($sourceProduct->id, $result['product']->id);
        $this->assertSame(1, Product::query()->where('donor_car_id', $donorCar->id)->where('external_sku', '1044831-99-E')->count());

        $sourceProduct->refresh();
        $mirrorItem->refresh();

        $this->assertSame('DON28-1260', $sourceProduct->sku);
        $this->assertFalse($sourceProduct->is_auto_generated);
        $this->assertSame($mirrorItem->id, $sourceProduct->source_part_catalog_item_id);
        $this->assertSame($sourceProduct->id, data_get($mirrorItem->raw_attributes, 'product_id'));
    }

    public function test_nikolacars_product_sync_preserves_existing_localized_names_when_source_name_is_not_localized(): void
    {
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA6MF048163',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2021,
        ]);
        $officialItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=1104428-00-W',
            'part_number' => '1104428-00-W',
            'name' => 'ASY,HVBAT,E1P,RWD,1PH',
        ]);
        $sourceProduct = Product::query()->create([
            'sku' => 'DON27-0159',
            'external_sku' => '1104428-00-W',
            'name' => 'ASY,HVBAT,E1P,RWD,1PH',
            'slug' => 'DON27-0159',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $officialItem->id,
            'is_auto_generated' => true,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 0,
            'currency' => 'USD',
            'notes' => NikolaCarsProductInventorySyncService::CHECKED_DAMAGE_STATUSES[0],
            'is_active' => true,
        ]);
        $ruName = "\u{0411}\u{0430}\u{0442}\u{0430}\u{0440}\u{0435}\u{044F} \u{0432} \u{0441}\u{0431}\u{043E}\u{0440}\u{0435} (55 kWh E1,RWD,1-PHASE)";
        $uaName = "\u{0411}\u{0430}\u{0442}\u{0430}\u{0440}\u{0435}\u{044F} \u{0443} \u{0437}\u{0431}\u{043E}\u{0440}\u{0456} (55 kWh E1, RWD, 1 - PHASE )";
        $mirrorItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://donor-product/'.$sourceProduct->id,
            'part_number' => '1104428-00-W',
            'name' => 'ASY,HVBAT,E1P,RWD,1PH',
            'name_ru' => $ruName,
            'name_ua' => $uaName,
            'raw_attributes' => [
                'product_id' => $sourceProduct->id,
                'name_source_site_ru' => 'erazborka.com',
                'name_source_url_ru' => 'https://erazborka.com/zapchasti-tesla-model-3/batareya-v-sbore-55-kwh-e1-rwd-1-phase-1104428-00-w/',
                'name_source_site_ua' => 'erazborka.com',
                'name_source_url_ua' => 'https://erazborka.com/ua/zapchasti-tesla-model-3/batareya-v-sbore-55-kwh-e1-rwd-1-phase-1104428-00-w/',
            ],
        ]);

        app(NikolaCarsProductInventorySyncService::class)->syncProduct($sourceProduct->refresh());

        $mirrorItem->refresh();

        $this->assertSame($ruName, $mirrorItem->name_ru);
        $this->assertSame($uaName, $mirrorItem->name_ua);
        $this->assertSame('erazborka.com', data_get($mirrorItem->raw_attributes, 'name_source_site_ua'));
        $this->assertSame($mirrorItem->id, $sourceProduct->refresh()->source_part_catalog_item_id);
    }

    public function test_nikolacars_imported_donor_item_uses_existing_donor_product_with_same_part_number(): void
    {
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EB8JF091651',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2018,
        ]);
        $officialItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=1077828-00-E',
            'part_number' => '1077828-00-E',
            'name' => 'BODY CONTROLLER MODULE',
        ]);
        $sourceProduct = Product::query()->create([
            'sku' => 'DON2-0709',
            'external_sku' => '1077828-00-E',
            'name' => 'BODY CONTROLLER MODULE',
            'slug' => 'DON2-0709',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $officialItem->id,
            'is_auto_generated' => true,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 120,
            'currency' => 'USD',
            'notes' => NikolaCarsProductInventorySyncService::CHECKED_DAMAGE_STATUSES[0],
            'is_active' => true,
        ]);
        $nikolaCarsItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/209',
            'part_number' => '1077828-00-E',
            'name' => 'BODY CONTROLLER MODULE',
            'price_amount' => 130,
            'currency' => 'USD',
            'compatibility_text' => $donorCar->vin,
            'quality' => NikolaCarsProductInventorySyncService::CHECKED_DAMAGE_STATUSES[0],
            'raw_attributes' => [
                'code' => '209',
                'donor_vin' => $donorCar->vin,
            ],
        ]);

        $result = app(NikolaCarsCatalogProductSyncService::class)->syncItem($nikolaCarsItem);

        $this->assertTrue($result['saved']);
        $this->assertSame($sourceProduct->id, $result['product']->id);
        $this->assertSame(1, Product::query()->where('donor_car_id', $donorCar->id)->where('external_sku', '1077828-00-E')->count());

        $sourceProduct->refresh();
        $nikolaCarsItem->refresh();

        $this->assertSame('DON2-0709', $sourceProduct->sku);
        $this->assertFalse($sourceProduct->is_auto_generated);
        $this->assertSame(
            PartCatalogItem::query()
                ->where('source', 'nikolacars')
                ->where('source_url', 'nikolacars://donor-product/'.$sourceProduct->id)
                ->value('id'),
            $sourceProduct->source_part_catalog_item_id
        );
        $this->assertSame($sourceProduct->id, data_get($nikolaCarsItem->raw_attributes, 'product_id'));
    }
}
