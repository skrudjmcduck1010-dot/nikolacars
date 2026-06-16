<?php

namespace Tests\Feature;

use App\Models\DonorCar;
use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class DiagnoseNikolaCarsInventoryCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_reports_nikolacars_inventory_diagnostics(): void
    {
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJSA1E20GF129213',
            'brand' => 'Tesla',
            'model' => 'Model S',
            'year' => 2016,
        ]);

        PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla://1002066-00-A',
            'part_number' => '1002066-00-A',
            'name' => 'Official exact',
        ]);
        PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla://2000000-99-Z',
            'part_number' => '2000000-99-Z',
            'name' => 'Official fallback',
        ]);

        $exactDonorItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/1',
            'part_number' => '1002066-00-A',
            'name' => 'Exact donor item',
            'raw_attributes' => [
                'donor_vin' => '5YJSA1E20GF129213',
                'stock_quantity' => 1,
                'reserved_quantity' => 1,
                'category_display' => 'Body',
            ],
        ]);
        $fallbackStockItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/2',
            'part_number' => '2000000-00-A',
            'name' => 'Fallback stock item',
            'raw_attributes' => [
                'stock_quantity' => 2,
            ],
        ]);
        PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/3',
            'part_number' => 'NC-123',
            'name' => 'Invalid article item',
            'raw_attributes' => [],
        ]);
        $unmatchedDonorItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/4',
            'part_number' => '3000000-00-A',
            'name' => 'No official item',
            'raw_attributes' => [
                'donor_vin' => 'UNKNOWN DONOR',
            ],
        ]);
        $donorMismatchItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/5',
            'part_number' => '1002066-00-A',
            'name' => 'Known donor but stock product',
            'raw_attributes' => [
                'donor_vin' => '5YJSA1E20GF129213',
            ],
        ]);

        $this->createProduct($exactDonorItem, 'NC-1', Product::STORAGE_STATUS_ON_DONOR, $donorCar->id);
        $this->createProduct($fallbackStockItem, 'NC-2', Product::STORAGE_STATUS_IN_STOCK);
        $this->createProduct($unmatchedDonorItem, 'NC-4', Product::STORAGE_STATUS_IN_STOCK);
        $this->createProduct($donorMismatchItem, 'NC-5', Product::STORAGE_STATUS_IN_STOCK);

        Artisan::call('parts:diagnose-nikolacars-inventory', [
            '--json' => true,
            '--examples' => 10,
        ]);

        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(5, $report['stats']['catalog_items_total']);
        $this->assertSame(5, $report['stats']['default_admin_list_items']);
        $this->assertSame(2, $report['stats']['official_match_exact']);
        $this->assertSame(1, $report['stats']['official_match_seven_digit_prefix']);
        $this->assertSame(1, $report['stats']['official_match_none']);
        $this->assertSame(1, $report['stats']['official_match_invalid_article']);
        $this->assertSame(3, $report['stats']['donor_vin_present']);
        $this->assertSame(2, $report['stats']['donor_vin_known']);
        $this->assertSame(1, $report['stats']['donor_vin_unmatched']);
        $this->assertSame(2, $report['stats']['donor_vin_missing_purchase_or_warehouse_candidates']);
        $this->assertSame(4, $report['stats']['linked_products_present']);
        $this->assertSame(1, $report['stats']['linked_products_missing']);
        $this->assertSame(1, $report['stats']['linked_products_with_donor']);
        $this->assertSame(3, $report['stats']['linked_products_without_donor_purchase_or_warehouse']);
        $this->assertSame(1, $report['stats']['linked_product_donor_mismatch']);
        $this->assertSame(4, $report['stats']['category_missing']);
        $this->assertSame(4, $report['stats']['category_issue_total']);
        $this->assertSame(1, $report['stats']['category_issue_official_exact']);
        $this->assertSame(1, $report['stats']['category_issue_official_seven_digit_prefix']);
        $this->assertSame(1, $report['stats']['category_issue_official_none']);
        $this->assertSame(1, $report['stats']['category_issue_official_invalid_article']);

        $this->assertSame('NC-123', $report['examples']['official_match_invalid_article'][0]['part_number']);
        $this->assertSame('3000000-00-A', $report['examples']['official_match_none'][0]['part_number']);
        $this->assertSame('UNKNOWN DONOR', $report['examples']['donor_vin_unmatched'][0]['donor_vin']);

        Artisan::call('parts:diagnose-nikolacars-inventory', [
            '--json' => true,
            '--examples' => 10,
            '--focus' => 'category',
        ]);

        $categoryReport = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('category', $categoryReport['focus']);
        $this->assertArrayHasKey('category_issue_total', $categoryReport['stats']);
        $this->assertArrayNotHasKey('donor_vin_present', $categoryReport['stats']);
        $this->assertSame(4, $categoryReport['stats']['category_issue_total']);
        $this->assertSame('1002066-00-A', $categoryReport['examples']['category_issue_official_exact'][0]['part_number']);
        $this->assertSame('2000000-00-A', $categoryReport['examples']['category_issue_official_seven_digit_prefix'][0]['part_number']);
        $this->assertSame('3000000-00-A', $categoryReport['examples']['category_issue_official_none'][0]['part_number']);
        $this->assertSame('NC-123', $categoryReport['examples']['category_issue_official_invalid_article'][0]['part_number']);
    }

    public function test_command_reports_category_localization_diagnostics(): void
    {
        $matchingCategory = PartCatalogCategory::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla://category/body',
            'name' => 'Body',
            'name_en' => 'Body',
            'name_ru' => 'РљСѓР·РѕРІ',
            'name_ua' => 'РљСѓР·РѕРІ',
        ]);
        $mismatchedCategory = PartCatalogCategory::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla://category/closures',
            'name' => 'Closures',
            'name_en' => 'Closures',
            'name_ru' => 'Р”РІРµСЂРё',
            'name_ua' => 'Р”РІРµСЂС–',
        ]);
        PartCatalogCategory::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla://category/thermal',
            'name' => 'Legacy Thermal',
            'name_en' => 'Thermal',
        ]);

        PartCatalogCategory::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://tesla-category/'.$matchingCategory->id,
            'name' => 'Body',
            'name_en' => 'Body',
            'name_ru' => 'РљСѓР·РѕРІ',
            'name_ua' => 'РљСѓР·РѕРІ',
        ]);
        PartCatalogCategory::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://tesla-category/'.$mismatchedCategory->id,
            'name' => 'Closures',
            'name_en' => 'Wrong English',
            'name_ru' => 'Wrong Russian',
            'name_ua' => 'Р”РІРµСЂС–',
        ]);

        Artisan::call('parts:diagnose-nikolacars-inventory', [
            '--json' => true,
            '--examples' => 10,
            '--focus' => 'category-localization',
        ]);

        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('category-localization', $report['focus']);
        $this->assertSame(3, $report['stats']['tesla_categories_total']);
        $this->assertSame(3, $report['stats']['tesla_categories_with_name_en']);
        $this->assertSame(1, $report['stats']['tesla_categories_name_differs_from_name_en']);
        $this->assertSame(2, $report['stats']['tesla_categories_with_name_ru']);
        $this->assertSame(2, $report['stats']['tesla_categories_with_name_ua']);
        $this->assertSame(1, $report['stats']['tesla_categories_missing_name_ru']);
        $this->assertSame(1, $report['stats']['tesla_categories_missing_name_ua']);
        $this->assertSame(2, $report['stats']['nikolacars_mirrored_categories_total']);
        $this->assertSame(1, $report['stats']['nikolacars_mirrored_categories_missing']);
        $this->assertSame(2, $report['stats']['nikolacars_mirrored_categories_with_name_ru']);
        $this->assertSame(2, $report['stats']['nikolacars_mirrored_categories_with_name_ua']);
        $this->assertSame(1, $report['stats']['nikolacars_mirror_name_en_mismatch']);
        $this->assertSame(1, $report['stats']['nikolacars_mirror_name_ru_mismatch']);
        $this->assertSame(0, $report['stats']['nikolacars_mirror_name_ua_mismatch']);
        $this->assertArrayNotHasKey('catalog_items_total', $report['stats']);

        $this->assertSame('Legacy Thermal', $report['examples']['category_localization_missing_ru'][0]['name']);
        $this->assertSame('Legacy Thermal', $report['examples']['category_localization_missing_ua'][0]['name']);
        $this->assertSame('Legacy Thermal', $report['examples']['category_localization_name_differs_from_name_en'][0]['name']);
        $this->assertSame('Legacy Thermal', $report['examples']['category_localization_mirror_missing'][0]['name']);
        $this->assertSame('Closures', $report['examples']['category_localization_mirror_name_en_mismatch'][0]['name']);
        $this->assertSame('Closures', $report['examples']['category_localization_mirror_name_ru_mismatch'][0]['name']);
    }

    public function test_command_reports_sellability_diagnostics(): void
    {
        $sellableItem = $this->createNikolaCarsItem('nikolacars://sellability/1', '1000001-00-A', 25, [
            'stock_quantity' => 2,
            'image_urls' => ['storage/parts/1000001.jpg'],
        ]);
        $zeroStockItem = $this->createNikolaCarsItem('nikolacars://sellability/2', '1000002-00-A', 25, [
            'stock_quantity' => 0,
            'image_urls' => ['storage/parts/1000002.jpg'],
        ]);
        $fullyReservedItem = $this->createNikolaCarsItem('nikolacars://sellability/3', '1000003-00-A', 25, [
            'stock_quantity' => 1,
            'reserved_quantity' => 1,
            'image_urls' => ['storage/parts/1000003.jpg'],
        ]);
        $noImageItem = $this->createNikolaCarsItem('nikolacars://sellability/4', '1000004-00-A', 25, [
            'stock_quantity' => 1,
            'image_urls' => [],
        ]);
        $zeroPriceItem = $this->createNikolaCarsItem('nikolacars://sellability/5', '1000005-00-A', 0, [
            'stock_quantity' => 1,
            'image_urls' => ['storage/parts/1000005.jpg'],
        ]);
        $soldItem = $this->createNikolaCarsItem('nikolacars://sellability/6', '1000006-00-A', 25, [
            'storage_status' => Product::STORAGE_STATUS_SOLD,
            'stock_quantity' => 1,
            'image_urls' => ['storage/parts/1000006.jpg'],
        ]);

        $this->createProduct($sellableItem, 'NC-S1', Product::STORAGE_STATUS_IN_STOCK);
        $this->createProduct($zeroStockItem, 'NC-S2', Product::STORAGE_STATUS_IN_STOCK);
        $this->createProduct($fullyReservedItem, 'NC-S3', Product::STORAGE_STATUS_IN_STOCK);
        $this->createProduct($noImageItem, 'NC-S4', Product::STORAGE_STATUS_IN_STOCK);
        $this->createProduct($zeroPriceItem, 'NC-S5', Product::STORAGE_STATUS_IN_STOCK);
        $this->createProduct($soldItem, 'NC-S6', Product::STORAGE_STATUS_SOLD)->update(['is_active' => false]);

        Artisan::call('parts:diagnose-nikolacars-inventory', [
            '--json' => true,
            '--examples' => 10,
            '--focus' => 'sellability',
        ]);

        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('sellability', $report['focus']);
        $this->assertSame(5, $report['stats']['sellability_active_admin_items']);
        $this->assertSame(2, $report['stats']['sellability_customer_order_search_candidates']);
        $this->assertSame(3, $report['stats']['sellability_available_to_sell_items']);
        $this->assertSame(0, $report['stats']['sellability_customer_order_search_unavailable_risk_items']);
        $this->assertSame(4, $report['stats']['sellability_active_positive_stock_items']);
        $this->assertSame(1, $report['stats']['sellability_active_zero_or_missing_stock_items']);
        $this->assertSame(1, $report['stats']['sellability_active_reserved_items']);
        $this->assertSame(1, $report['stats']['sellability_active_fully_reserved_items']);
        $this->assertSame(0, $report['stats']['sellability_active_with_sold_or_inactive_product_risk_items']);
        $this->assertSame(2, $report['stats']['sellability_prom_exportable_items']);
        $this->assertSame(3, $report['stats']['sellability_prom_excluded_items']);
        $this->assertSame(1, $report['stats']['sellability_prom_excluded_zero_price_items']);
        $this->assertSame(1, $report['stats']['sellability_prom_excluded_no_image_items']);
        $this->assertArrayNotHasKey('catalog_items_total', $report['stats']);

        $this->assertSame('1000002-00-A', $report['examples']['sellability_active_zero_or_missing_stock'][0]['part_number']);
        $this->assertSame('1000003-00-A', $report['examples']['sellability_active_fully_reserved'][0]['part_number']);
        $this->assertArrayNotHasKey('sellability_customer_order_search_unavailable_risk', $report['examples']);
        $this->assertSame('1000005-00-A', $report['examples']['sellability_prom_excluded_zero_price'][0]['part_number']);
        $this->assertSame('1000004-00-A', $report['examples']['sellability_prom_excluded_no_image'][0]['part_number']);
    }

    protected function createNikolaCarsItem(string $sourceUrl, string $partNumber, ?float $priceAmount, array $rawAttributes): PartCatalogItem
    {
        return PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => $sourceUrl,
            'part_number' => $partNumber,
            'name' => 'NikolaCars '.$partNumber,
            'name_ua' => 'NikolaCars '.$partNumber,
            'price_amount' => $priceAmount,
            'currency' => 'USD',
            'raw_attributes' => $rawAttributes,
        ]);
    }

    protected function createProduct(PartCatalogItem $item, string $sku, string $storageStatus, ?int $donorCarId = null): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'external_sku' => $item->part_number,
            'name' => $item->name,
            'slug' => str($sku)->slug()->toString(),
            'donor_car_id' => $donorCarId,
            'source_part_catalog_item_id' => $item->id,
            'storage_status' => $storageStatus,
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'currency' => 'USD',
            'is_active' => true,
        ]);
    }
}
