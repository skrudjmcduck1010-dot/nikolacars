<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DonorCar;
use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Models\Product;
use App\Services\NikolaCarsDonorInventorySyncService;
use App\Services\NikolaCarsInventoryService;
use App\Services\NikolaCarsProductInventorySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NikolaCarsDonorInventorySyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_checked_donor_products_are_mirrored_to_nikolacars_catalog(): void
    {
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EB8JF091651',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2018,
        ]);
        $officialItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/1044831-99-e',
            'part_number' => '1044831-99-E',
            'name' => 'STEERING GEAR ASSEMBLY - LEFT HAND DRIVE',
            'name_ru' => 'Р СѓР»РµРІР°СЏ СЂРµР№РєР°',
            'name_ua' => 'Р СѓР»СЊРѕРІР° СЂРµР№РєР°',
            'main_category_name' => 'Steering',
        ]);
        $checkedProduct = Product::query()->create([
            'sku' => 'DON28-1260',
            'external_sku' => '1044831-99-E',
            'name' => 'STEERING GEAR ASSEMBLY - LEFT HAND DRIVE',
            'slug' => 'DON28-1260',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $officialItem->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 250,
            'currency' => 'USD',
            'notes' => 'Р‘РµР· РїРѕРІСЂРµР¶РґРµРЅРёР№',
            'is_active' => true,
        ]);
        Product::query()->create([
            'sku' => 'DON28-0708',
            'external_sku' => '1120960-00-E',
            'name' => 'FRONT DRIVE UNIT ASSEMBLY- 3DU',
            'slug' => 'DON28-0708',
            'donor_car_id' => $donorCar->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'notes' => 'РќРµРёР·РІРµСЃС‚РЅРѕ',
            'is_active' => true,
        ]);

        $stats = app(NikolaCarsDonorInventorySyncService::class)->syncAll();

        $this->assertSame(2, $stats['products_seen']);
        $this->assertSame(1, $stats['items_saved']);
        $this->assertDatabaseHas('part_catalog_items', [
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://donor-product/'.$checkedProduct->id,
            'part_number' => '1044831-99-E',
            'quality' => 'Р‘РµР· РїРѕРІСЂРµР¶РґРµРЅРёР№',
        ]);

        $item = PartCatalogItem::query()
            ->where('source', 'nikolacars')
            ->where('source_url', 'nikolacars://donor-product/'.$checkedProduct->id)
            ->firstOrFail();

        $this->assertSame($donorCar->vin, data_get($item->raw_attributes, 'donor_vin'));
        $this->assertSame(1, data_get($item->raw_attributes, 'stock_quantity'));
        $this->assertSame($item->id, $checkedProduct->refresh()->source_part_catalog_item_id);
        $this->assertSame('Р СѓР»РµРІР°СЏ СЂРµР№РєР°', $item->name_ru);
    }

    public function test_donor_product_sync_fills_non_cyrillic_ua_name_from_local_catalog(): void
    {
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA6MF048163',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2021,
        ]);
        $sourceCatalogItem = PartCatalogItem::query()->create([
            'source' => 'stock-tesla',
            'source_url' => 'https://stock-tesla.example/1104428-00-W',
            'part_number' => '1104428-00-W',
            'name' => 'ASY,HVBAT,E1P,RWD,1PH',
            'name_ru' => "\u{0411}\u{0430}\u{0442}\u{0430}\u{0440}\u{0435}\u{044F} \u{0432} \u{0441}\u{0431}\u{043E}\u{0440}\u{0435}",
            'name_ua' => 'ASY,HVBAT,E1P,RWD,1PH',
        ]);
        $product = Product::query()->create([
            'sku' => 'DON27-0159',
            'external_sku' => '1104428-00-W',
            'name' => 'ASY,HVBAT,E1P,RWD,1PH',
            'slug' => 'don27-0159',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $sourceCatalogItem->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 12500,
            'currency' => 'USD',
            'notes' => NikolaCarsProductInventorySyncService::CHECKED_DAMAGE_STATUSES[0],
            'is_active' => true,
        ]);

        app(NikolaCarsProductInventorySyncService::class)->syncProduct($product);

        $mirror = PartCatalogItem::query()
            ->where('source', 'nikolacars')
            ->where('source_url', 'nikolacars://donor-product/'.$product->id)
            ->firstOrFail();

        $this->assertNull($mirror->name_ua);

        $sourceNameUa = "\u{0411}\u{0430}\u{0442}\u{0430}\u{0440}\u{0435}\u{044F} \u{0443} \u{0437}\u{0431}\u{043E}\u{0440}\u{0456}";
        $sourceItem = PartCatalogItem::query()->create([
            'source' => 'erazborka',
            'source_url' => 'https://erazborka.example/1104428-00-W',
            'part_number' => '1104428-00-W',
            'name' => $sourceNameUa,
            'name_ua' => $sourceNameUa,
        ]);

        app(NikolaCarsProductInventorySyncService::class)->syncProduct($product->refresh());
        $mirror->refresh();

        $this->assertSame($sourceNameUa, $mirror->name_ua);
        $this->assertSame($sourceItem->id, data_get($mirror->raw_attributes, 'name_source_item_id_ua'));
        $this->assertSame('donor_status_catalog_match', data_get($mirror->raw_attributes, 'name_source_type_ua'));
    }

    public function test_official_vin_generated_products_keep_auto_flag_when_mirrored_and_restored(): void
    {
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA0LF611657',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
        ]);
        $officialItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=1044831-00-F',
            'part_number' => '1044831-00-F',
            'name' => 'STEERING GEAR ASSEMBLY - LEFT HAND DRIVE',
            'price_amount' => 812.34,
            'currency' => 'USD',
            'main_category_name' => 'CHASSIS',
            'subcategory_name' => 'Steering Rack',
        ]);
        $product = Product::query()->create([
            'sku' => 'DON4-1592',
            'external_sku' => '1044831-00-F',
            'name' => 'STEERING GEAR ASSEMBLY - LEFT HAND DRIVE',
            'slug' => 'don4-1592',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $officialItem->id,
            'is_auto_generated' => true,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'generated_at' => now(),
            'description' => implode(PHP_EOL, [
                "\u{0410}\u{0432}\u{0442}\u{043E}\u{043C}\u{0430}\u{0442}\u{0438}\u{0447}\u{0435}\u{0441}\u{043A}\u{0438} \u{0441}\u{0433}\u{0435}\u{043D}\u{0435}\u{0440}\u{0438}\u{0440}\u{043E}\u{0432}\u{0430}\u{043D}\u{043E} \u{0438}\u{0437} \u{043A}\u{0430}\u{0442}\u{0430}\u{043B}\u{043E}\u{0433}\u{0430} \u{0437}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{0435}\u{0439}.",
                'РСЃС‚РѕС‡РЅРёРє: tesla_official',
                'РЎСЃС‹Р»РєР°: https://parts.tesla.com/en-US/find-part?searchTerm=1044831-00-F',
            ]),
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 250,
            'currency' => 'USD',
            'notes' => "\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}",
            'is_active' => true,
        ]);

        app(NikolaCarsProductInventorySyncService::class)->syncProduct($product);

        $mirrorItem = PartCatalogItem::query()
            ->where('source', 'nikolacars')
            ->where('source_url', 'nikolacars://donor-product/'.$product->id)
            ->firstOrFail();
        $product->refresh();

        $this->assertTrue($product->is_auto_generated);
        $this->assertSame($mirrorItem->id, $product->source_part_catalog_item_id);
        $this->assertSame($officialItem->id, data_get($mirrorItem->raw_attributes, 'source_catalog_item_id'));
        $this->assertSame('tesla_official', data_get($mirrorItem->raw_attributes, 'source_catalog_source'));
        $this->assertSame(812.34, data_get($mirrorItem->raw_attributes, 'source_catalog_price_amount'));

        $mirrorRawAttributes = $mirrorItem->raw_attributes;
        $mirrorRawAttributes['source_catalog_item_id'] = $mirrorItem->id;
        $mirrorRawAttributes['source_catalog_source'] = 'nikolacars';
        unset($mirrorRawAttributes['source_catalog_price_amount'], $mirrorRawAttributes['source_catalog_currency']);
        $mirrorItem->forceFill(['raw_attributes' => $mirrorRawAttributes])->save();
        $product->forceFill(['is_auto_generated' => false])->save();

        app(NikolaCarsProductInventorySyncService::class)->syncProduct($product->refresh());
        $mirrorItem->refresh();

        $this->assertTrue($product->refresh()->is_auto_generated);
        $this->assertSame($officialItem->id, data_get($mirrorItem->raw_attributes, 'source_catalog_item_id'));
        $this->assertSame('tesla_official', data_get($mirrorItem->raw_attributes, 'source_catalog_source'));
        $this->assertSame(812.34, data_get($mirrorItem->raw_attributes, 'source_catalog_price_amount'));

        $product->forceFill(['notes' => null])->save();

        $result = app(NikolaCarsProductInventorySyncService::class)->syncProduct($product->refresh());

        $this->assertFalse($result['saved']);
        $this->assertTrue($result['deleted']);
        $this->assertDatabaseMissing('part_catalog_items', [
            'id' => $mirrorItem->id,
        ]);
        $this->assertTrue($product->refresh()->is_auto_generated);
        $this->assertSame($officialItem->id, $product->source_part_catalog_item_id);
    }

    public function test_nikolacars_mirror_uses_tesla_official_category_by_part_prefix(): void
    {
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA0LF611657',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2019,
        ]);
        $category = PartCatalogCategory::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-official://category/body',
            'depth' => 0,
            'name' => 'Body',
            'model_label' => 'Model 3 06.2017 - 12.2023',
        ]);
        PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-official://1034344-20-B',
            'part_number' => '1034344-20-B',
            'name' => 'Tesla official part',
            'part_catalog_category_id' => $category->id,
            'main_category_name' => 'Body',
            'subcategory_name' => 'Closures',
            'node_name' => 'Hood',
        ]);
        $localCategory = Category::query()->create([
            'name' => 'Old NikolaCars category',
            'slug' => 'old-nikolacars-category',
        ]);
        $product = Product::query()->create([
            'sku' => 'DON4-0001',
            'external_sku' => '1034344-99-C',
            'name' => 'NikolaCars hood part',
            'slug' => 'DON4-0001',
            'donor_car_id' => $donorCar->id,
            'category_id' => $localCategory->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 250,
            'currency' => 'USD',
            'notes' => "\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}",
            'is_active' => true,
        ]);

        app(NikolaCarsDonorInventorySyncService::class)->syncProduct($product);

        $item = PartCatalogItem::query()
            ->where('source', 'nikolacars')
            ->where('source_url', 'nikolacars://donor-product/'.$product->id)
            ->firstOrFail();

        $this->assertSame('Body / Closures / Hood', data_get($item->raw_attributes, 'category_display'));
        $this->assertSame('Body', $item->main_category_name);
        $this->assertSame('Closures', $item->subcategory_name);
        $this->assertSame('Hood', $item->node_name);
        $this->assertSame('matched', data_get($item->raw_attributes, 'tesla_category_match.status'));
    }

    public function test_nomenclature_product_mirror_builds_ua_name_from_full_product_name(): void
    {
        $donorCar = DonorCar::query()->create([
            'vin' => '7SAYGDEE4NF447985',
            'brand' => 'Tesla',
            'model' => 'Model Y',
            'year' => 2022,
        ]);
        $product = Product::query()->create([
            'sku' => 'DON24-0580',
            'external_sku' => '1127503-01-D',
            'name' => 'Р”Р°С‚С‡РёРє РїР°СЂРєС‚СЂРѕРЅС–РєСѓ РїРµСЂРµРґРЅСЊРѕРіРѕ Р±Р°РјРїРµСЂР° Tesla MY 2020 - 2023 1127503-01-D',
            'slug' => 'don24-0580',
            'donor_car_id' => $donorCar->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 20,
            'currency' => 'USD',
            'notes' => 'Р вЂР ВµР В· Р С—Р С•Р Р†РЎР‚Р ВµР В¶Р Т‘Р ВµР Р…Р С‘Р в„–',
            'is_active' => true,
        ]);
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://donor-product/'.$product->id,
            'part_number' => '1127503-01-D',
            'name' => $product->name,
            'name_ru' => 'Р”Р°С‚С‡РёРє РїР°СЂРєС‚СЂРѕРЅРёРєР° РїРµСЂРµРґРЅРµРіРѕ Р±Р°РјРїРµСЂР° MY 2020 - 2023 1127503-01-D',
            'name_ua' => 'Р”Р°С‚С‡РёРє РїР°СЂРєС‚СЂРѕРЅС–РєР° - РїСЂСЏРјРёР№ РєРѕСЂРїСѓСЃ - Valeo',
            'raw_attributes' => [
                'code' => '580',
                'product_id' => $product->id,
                'nikolacars_has_distinct_ru_name' => true,
                'name_source_site_ua' => 'tcarservice.com',
                'name_source_url_ua' => 'https://tcarservice.com/example',
                'name_source_item_id_ua' => 215,
                'name_source_type_ua' => 'tesla_official_tcars_base_part_match',
            ],
        ]);
        $product->forceFill(['source_part_catalog_item_id' => $item->id])->save();

        app(NikolaCarsProductInventorySyncService::class)->syncProduct($product->refresh());

        $item->refresh();

        $this->assertSame('Р”Р°С‚С‡РёРє РїР°СЂРєС‚СЂРѕРЅРёРєР° РїРµСЂРµРґРЅРµРіРѕ Р±Р°РјРїРµСЂР°', $item->name_ru);
        $this->assertSame('Р”Р°С‚С‡РёРє РїР°СЂРєС‚СЂРѕРЅС–РєСѓ РїРµСЂРµРґРЅСЊРѕРіРѕ Р±Р°РјРїРµСЂР°', $item->name_ua);
        $this->assertNull(data_get($item->raw_attributes, 'name_source_url_ua'));
        $this->assertNull(data_get($item->raw_attributes, 'name_source_item_id_ua'));
    }

    public function test_nomenclature_product_mirror_clears_ru_name_when_file_has_same_ru_and_ua_name(): void
    {
        $product = Product::query()->create([
            'sku' => 'NC-181',
            'external_sku' => '1079749-00-D',
            'name' => 'Р‘Р»РѕРє РєРµСЂСѓРІР°РЅРЅСЏ РјСѓР»СЊС‚РёРјРµРґС–СЏ Tesla M3 2015 - 2020 1079749-00-D',
            'slug' => 'nc-181',
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 20,
            'currency' => 'USD',
            'notes' => 'Р‘РµР· РїРѕРІСЂРµР¶РґРµРЅРёР№',
            'is_active' => true,
        ]);
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://donor-product/'.$product->id,
            'part_number' => '1079749-00-D',
            'name' => $product->name,
            'name_ru' => 'Р‘Р»РѕРє РєРµСЂСѓРІР°РЅРЅСЏ РјСѓР»СЊС‚РёРјРµРґС–СЏ Tesla M3 2015 - 2020 1079749-00-D',
            'name_ua' => 'Р‘Р»РѕРє РєРµСЂСѓРІР°РЅРЅСЏ РјСѓР»СЊС‚РёРјРµРґС–СЏ',
            'raw_attributes' => [
                'code' => '181',
                'product_id' => $product->id,
                'nikolacars_has_distinct_ru_name' => false,
            ],
        ]);
        $product->forceFill(['source_part_catalog_item_id' => $item->id])->save();

        app(NikolaCarsProductInventorySyncService::class)->syncProduct($product->refresh());

        $item->refresh();

        $this->assertNull($item->name_ru);
        $this->assertSame('Р‘Р»РѕРє РєРµСЂСѓРІР°РЅРЅСЏ РјСѓР»СЊС‚РёРјРµРґС–СЏ', $item->name_ua);
    }

    public function test_nomenclature_product_mirror_keeps_ru_name_translated_from_ua(): void
    {
        $product = Product::query()->create([
            'sku' => 'NC-181',
            'external_sku' => '1079749-00-D',
            'name' => 'Р‘Р»РѕРє РєРµСЂСѓРІР°РЅРЅСЏ РјСѓР»СЊС‚РёРјРµРґС–СЏ Tesla M3 2015 - 2020 1079749-00-D',
            'slug' => 'nc-181-translated-ru',
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 20,
            'currency' => 'USD',
            'notes' => 'Р‘РµР· РїРѕРІСЂРµР¶РґРµРЅРёР№',
            'is_active' => true,
        ]);
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://donor-product/'.$product->id,
            'part_number' => '1079749-00-D',
            'name' => $product->name,
            'name_ru' => 'Р‘Р»РѕРє СѓРїСЂР°РІР»РµРЅРёСЏ РјСѓР»СЊС‚РёРјРµРґРёР°',
            'name_ua' => 'Р‘Р»РѕРє РєРµСЂСѓРІР°РЅРЅСЏ РјСѓР»СЊС‚РёРјРµРґС–СЏ',
            'raw_attributes' => [
                'code' => '181',
                'product_id' => $product->id,
                'nikolacars_has_distinct_ru_name' => false,
                'nikolacars_ru_translated_from_ua' => true,
            ],
        ]);
        $product->forceFill(['source_part_catalog_item_id' => $item->id])->save();

        app(NikolaCarsProductInventorySyncService::class)->syncProduct($product->refresh());

        $this->assertSame('Р‘Р»РѕРє СѓРїСЂР°РІР»РµРЅРёСЏ РјСѓР»СЊС‚РёРјРµРґРёР°', $item->refresh()->name_ru);
    }

    public function test_broken_donor_product_stays_in_nikolacars_catalog_history_but_not_active_inventory(): void
    {
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EB8JF091651',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2018,
        ]);
        $product = Product::query()->create([
            'sku' => 'DON28-0708',
            'external_sku' => '1120960-00-E',
            'name' => 'FRONT DRIVE UNIT ASSEMBLY- 3DU',
            'slug' => 'DON28-0708',
            'donor_car_id' => $donorCar->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'notes' => 'Р›РµРіРєРёРµ РїРѕРІСЂРµР¶РґРµРЅРёСЏ',
            'is_active' => true,
        ]);
        app(NikolaCarsDonorInventorySyncService::class)->syncProduct($product);

        $product->forceFill([
            'notes' => NikolaCarsProductInventorySyncService::BROKEN_DAMAGE_STATUS,
        ])->save();
        app(NikolaCarsDonorInventorySyncService::class)->syncProduct($product->refresh());

        $this->assertDatabaseHas('part_catalog_items', [
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://donor-product/'.$product->id,
            'quality' => NikolaCarsProductInventorySyncService::BROKEN_DAMAGE_STATUS,
        ]);

        $item = PartCatalogItem::query()
            ->where('source', 'nikolacars')
            ->where('source_url', 'nikolacars://donor-product/'.$product->id)
            ->firstOrFail();

        $this->assertSame(0.0, (float) data_get($item->raw_attributes, 'stock_quantity'));
        $this->assertFalse(
            app(NikolaCarsInventoryService::class)
                ->activeItemsQuery()
                ->whereKey($item->id)
                ->exists()
        );
    }

    public function test_sold_product_stays_in_nikolacars_catalog_history_but_not_active_inventory(): void
    {
        $product = Product::query()->create([
            'sku' => 'NC-SOLD-1',
            'external_sku' => '1081460-11-D',
            'name' => 'Sold warehouse NikolaCars part',
            'slug' => 'nc-sold-1',
            'storage_status' => Product::STORAGE_STATUS_SOLD,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 300,
            'currency' => 'USD',
            'is_active' => false,
        ]);

        app(NikolaCarsDonorInventorySyncService::class)->syncProduct($product);

        $item = PartCatalogItem::query()
            ->where('source', 'nikolacars')
            ->where('source_url', 'nikolacars://inventory-product/'.$product->id)
            ->firstOrFail();

        $this->assertSame($product->id, data_get($item->raw_attributes, 'product_id'));
        $this->assertSame(Product::STORAGE_STATUS_SOLD, data_get($item->raw_attributes, 'storage_status'));
        $this->assertSame(0.0, (float) data_get($item->raw_attributes, 'stock_quantity'));
        $this->assertFalse(
            app(NikolaCarsInventoryService::class)
                ->activeItemsQuery()
                ->whereKey($item->id)
                ->exists()
        );
    }

    public function test_active_inventory_hides_stale_donor_projection_when_product_status_is_unknown(): void
    {
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA0LF611659',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
        ]);
        $product = Product::query()->create([
            'sku' => 'DON31-0001',
            'external_sku' => '1084174-00-C',
            'name' => 'Stale checked donor mirror',
            'slug' => 'DON31-0001',
            'donor_car_id' => $donorCar->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'notes' => 'РќРµРёР·РІРµСЃС‚РЅРѕ',
            'is_auto_generated' => true,
            'is_active' => true,
        ]);
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://donor-product/'.$product->id,
            'part_number' => '1084174-00-C',
            'name' => 'Stale checked donor mirror',
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

        $this->assertFalse(
            app(NikolaCarsInventoryService::class)
                ->activeItemsQuery()
                ->whereKey($item->id)
                ->exists()
        );
    }

    public function test_sync_relinks_product_from_stale_nikolacars_catalog_item_to_current_projection(): void
    {
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA0LF611657',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
        ]);
        $staleItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://legacy-donor-product-582',
            'part_number' => 'OLD-582',
            'name' => 'Old NikolaCars donor battery',
            'raw_attributes' => [
                'product_id' => 582,
                'source_type' => 'donor',
                'donor_car_id' => $donorCar->id,
                'stock_quantity' => 1,
            ],
        ]);
        $product = Product::query()->create([
            'sku' => 'NC-582',
            'external_sku' => '5YJ3E1EA0LF611657',
            'name' => 'NikolaCars donor battery',
            'slug' => 'nikolacars-donor-battery',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $staleItem->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 3400,
            'currency' => 'USD',
            'notes' => "\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}",
            'is_active' => true,
        ]);

        $result = app(NikolaCarsDonorInventorySyncService::class)->syncProduct($product);

        $this->assertTrue($result['saved']);
        $item = PartCatalogItem::query()
            ->where('source', 'nikolacars')
            ->where('source_url', 'nikolacars://donor-product/'.$product->id)
            ->firstOrFail();

        $this->assertSame($item->id, $product->refresh()->source_part_catalog_item_id);
        $this->assertSame('582', data_get($item->raw_attributes, 'code'));
        $this->assertSame($staleItem->id, $item->id);
        $this->assertSame('nikolacars://donor-product/'.$product->id, $item->source_url);
    }

    public function test_sync_preserves_manually_locked_nikolacars_mirror_names(): void
    {
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EB8JF091651',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2018,
        ]);
        $officialItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=1081421-E0-C',
            'part_number' => '1081421-E0-C',
            'name' => 'ASSEMBLY - FRONT DOOR - LEFT HAND',
            'name_ru' => "\u{0414}\u{0432}\u{0435}\u{0440}\u{044C} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{044F}\u{044F} \u{043B}\u{0435}\u{0432}\u{0430}\u{044F} \u{043F}\u{043E}\u{0434} \u{0440}\u{0438}\u{0445}\u{0442}\u{043E}\u{0432}\u{043A}\u{0443}",
            'name_ua' => "\u{0414}\u{0432}\u{0435}\u{0440}\u{0456} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0456} LH",
        ]);
        $product = Product::query()->create([
            'sku' => 'DON28-0973',
            'external_sku' => '1081421-E0-C',
            'name' => 'PANEL - FRONT DOOR - LEFT HAND',
            'slug' => 'don28-0973',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $officialItem->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'tested',
            'unit' => 'pcs',
            'selling_price' => 450,
            'currency' => 'USD',
            'notes' => NikolaCarsProductInventorySyncService::CHECKED_DAMAGE_STATUSES[1],
            'is_active' => true,
        ]);
        $manualRuName = "\u{0420}\u{0443}\u{0447}\u{043D}\u{043E}\u{0435} \u{043D}\u{0430}\u{0437}\u{0432}\u{0430}\u{043D}\u{0438}\u{0435} \u{0434}\u{0432}\u{0435}\u{0440}\u{0438}";
        PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://donor-product/'.$product->id,
            'part_number' => '1081421-E0-C',
            'name' => 'PANEL - FRONT DOOR - LEFT HAND',
            'name_ru' => $manualRuName,
            'name_ua' => "\u{0420}\u{0443}\u{0447}\u{043D}\u{0430} \u{0443}\u{043A}\u{0440} \u{043D}\u{0430}\u{0437}\u{0432}\u{0430}",
            'raw_attributes' => [
                'manual_name_locks' => [
                    'ru' => '2026-06-12 15:30:35',
                    'ua' => '2026-06-12 15:31:45',
                ],
            ],
        ]);

        app(NikolaCarsProductInventorySyncService::class)->syncProduct($product->refresh());

        $mirror = PartCatalogItem::query()
            ->where('source_url', 'nikolacars://donor-product/'.$product->id)
            ->firstOrFail();

        $this->assertSame($manualRuName, $mirror->name_ru);
        $this->assertSame('2026-06-12 15:30:35', data_get($mirror->raw_attributes, 'manual_name_locks.ru'));
    }

    public function test_sync_inherits_existing_tesla_official_manual_names_for_unlocked_donor_mirror(): void
    {
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJYGDEE5MF081658',
            'brand' => 'Tesla',
            'model' => 'Model Y',
            'year' => 2021,
        ]);
        $lockedAtRu = now()->subMinutes(3);
        $lockedAtUa = now()->subMinutes(2);
        $officialItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=1501462-E0-A',
            'part_number' => '1501462-E0-A',
            'name' => 'FRONT DOOR - RIGHT HAND - SERVICE E-COATED',
            'name_ru' => "\u{0414}\u{0432}\u{0435}\u{0440}\u{044C} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{044F}\u{044F} \u{043F}\u{0440}\u{0430}\u{0432}\u{0430}\u{044F}",
            'name_ua' => "\u{0414}\u{0432}\u{0435}\u{0440}\u{0456} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0456} \u{043F}\u{0440}\u{0430}\u{0432}\u{0456}",
            'name_ru_manually_locked_at' => $lockedAtRu,
            'raw_attributes' => [
                'manual_name_locks' => [
                    'ru' => $lockedAtRu->toDateTimeString(),
                    'ua' => $lockedAtUa->toDateTimeString(),
                ],
            ],
        ]);
        $product = Product::query()->create([
            'sku' => 'DON29-0142',
            'external_sku' => '1501462-E0-A',
            'name' => 'FRONT DOOR - RIGHT HAND - SERVICE E-COATED',
            'slug' => 'don29-0142',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $officialItem->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 450,
            'currency' => 'USD',
            'notes' => NikolaCarsProductInventorySyncService::CHECKED_DAMAGE_STATUSES[0],
            'is_active' => true,
        ]);
        $mirror = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://donor-product/'.$product->id,
            'part_number' => '1501462-E0-A',
            'name' => 'FRONT DOOR - RIGHT HAND - SERVICE E-COATED',
            'name_ru' => "\u{0421}\u{0442}\u{0430}\u{0440}\u{043E}\u{0435} RU",
            'name_ua' => "\u{0421}\u{0442}\u{0430}\u{0440}\u{0435} UA",
            'raw_attributes' => [
                'source_catalog_item_id' => $officialItem->id,
                'source_catalog_source' => 'tesla_official',
            ],
        ]);
        $product->forceFill(['source_part_catalog_item_id' => $mirror->id])->save();

        app(NikolaCarsProductInventorySyncService::class)->syncProduct($product->refresh());
        $mirror->refresh();

        $this->assertSame($officialItem->name_ru, $mirror->name_ru);
        $this->assertSame($officialItem->name_ua, $mirror->name_ua);
        $this->assertNotNull($mirror->name_ru_manually_locked_at);
        $this->assertSame($lockedAtRu->toDateTimeString(), data_get($mirror->raw_attributes, 'manual_name_locks.ru'));
        $this->assertSame($lockedAtUa->toDateTimeString(), data_get($mirror->raw_attributes, 'manual_name_locks.ua'));
    }
}
