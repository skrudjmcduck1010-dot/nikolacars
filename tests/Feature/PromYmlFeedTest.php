<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\DonorCar;
use App\Models\ExchangeRate;
use App\Models\Location;
use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Models\Product;
use App\Models\StockItem;
use App\Models\Warehouse;
use App\Support\PublicStorageUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PromYmlFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_prom_feed_exports_active_donor_products_in_uah(): void
    {
        config([
            'app.url' => 'https://example.test',
            'filesystems.disks.public.url' => 'https://example.test/storage',
            'filesystems.public_fallback_url' => null,
            'prom.feed_token' => null,
            'prom.shop_name' => 'Tesla Parts',
            'prom.company_name' => 'Tesla Parts LLC',
        ]);

        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_date' => Carbon::today()->toDateString(),
            'rate' => 40,
            'source' => 'nbu',
            'fetched_at' => now(),
        ]);

        $category = Category::query()->create([
            'name' => "\u{041A}\u{0443}\u{0437}\u{043E}\u{0432}",
            'slug' => 'body',
        ]);
        $brand = Brand::query()->create([
            'name' => 'Tesla',
            'slug' => 'tesla',
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000001',
            'status' => DonorCar::STATUS_DISMANTLING,
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
        ]);
        $warehouse = Warehouse::query()->create([
            'name' => 'Main',
            'type' => 'main',
            'is_active' => true,
        ]);
        $location = Location::query()->create([
            'warehouse_id' => $warehouse->id,
            'full_code' => 'A-1',
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'sku' => 'D1-0001',
            'external_sku' => '1084168-00-E',
            'name' => "\u{0411}\u{0430}\u{043C}\u{043F}\u{0435}\u{0440} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0438}\u{0439}",
            'slug' => 'front-bumper',
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'donor_car_id' => $donorCar->id,
            'description' => "\u{041E}\u{0440}\u{0438}\u{0433}\u{0438}\u{043D}\u{0430}\u{043B}\u{044C}\u{043D}\u{0430}\u{044F} \u{0437}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{044C}.",
            'compatibility' => 'Tesla Model 3',
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'main_image' => 'product-photos/front.jpg',
            'images_json' => ['product-photos/front.jpg', 'product-photos/detail.jpg'],
            'is_active' => true,
        ]);

        StockItem::query()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'location_id' => $location->id,
            'quantity' => 2,
            'reserved_quantity' => 1,
            'testing_status' => 'not_tested',
        ]);

        Product::query()->create([
            'sku' => 'PRD-000002',
            'name' => "\u{041D}\u{0435} \u{0434}\u{043E}\u{043D}\u{043E}\u{0440}\u{0441}\u{043A}\u{0438}\u{0439} \u{0442}\u{043E}\u{0432}\u{0430}\u{0440}",
            'slug' => 'not-donor',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        Product::query()->create([
            'sku' => 'D1-0002',
            'name' => "\u{0414}\u{043E}\u{043D}\u{043E}\u{0440}\u{0441}\u{043A}\u{0438}\u{0439} \u{0442}\u{043E}\u{0432}\u{0430}\u{0440} \u{0431}\u{0435}\u{0437} \u{0444}\u{043E}\u{0442}\u{043E}",
            'slug' => 'donor-without-photo',
            'donor_car_id' => $donorCar->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $response = $this->get(route('prom.donor-products.feed'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

        $xml = $response->getContent();

        $this->assertStringContainsString('<name>Tesla Parts</name>', $xml);
        $this->assertStringContainsString('<category id="'.$category->id.'">'."\u{041A}\u{0443}\u{0437}\u{043E}\u{0432}".'</category>', $xml);
        $this->assertStringContainsString('<offer id="D1-0001" available="true" in_stock="true" selling_type="r">', $xml);
        $this->assertStringContainsString('<price>4000.00</price>', $xml);
        $this->assertStringContainsString('<quantity_in_stock>1</quantity_in_stock>', $xml);
        $this->assertStringContainsString('<vendorCode>1084168-00-E</vendorCode>', $xml);
        $this->assertStringContainsString('<picture>https://example.test'.Storage::url('product-photos/front.jpg').'</picture>', $xml);
        $this->assertStringNotContainsString("\u{041D}\u{0435} \u{0434}\u{043E}\u{043D}\u{043E}\u{0440}\u{0441}\u{043A}\u{0438}\u{0439} \u{0442}\u{043E}\u{0432}\u{0430}\u{0440}", $xml);
        $this->assertStringNotContainsString("\u{0414}\u{043E}\u{043D}\u{043E}\u{0440}\u{0441}\u{043A}\u{0438}\u{0439} \u{0442}\u{043E}\u{0432}\u{0430}\u{0440} \u{0431}\u{0435}\u{0437} \u{0444}\u{043E}\u{0442}\u{043E}", $xml);
    }

    public function test_prom_feed_can_be_protected_by_token(): void
    {
        config(['prom.feed_token' => 'secret']);

        $this->get(route('prom.donor-products.feed'))->assertForbidden();
        $this->get(route('prom.donor-products.feed', ['token' => 'secret']))->assertOk();
    }

    public function test_nikolacars_prom_feed_removes_part_number_from_name_and_description(): void
    {
        config([
            'app.url' => 'https://example.test',
            'filesystems.disks.public.url' => 'https://example.test/storage',
            'filesystems.public_fallback_url' => null,
            'prom.feed_token' => null,
            'prom.shop_name' => 'Tesla Parts',
            'prom.company_name' => 'Tesla Parts LLC',
        ]);

        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_date' => Carbon::today()->toDateString(),
            'rate' => 40,
            'source' => 'nbu',
            'fetched_at' => now(),
        ]);

        PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/393',
            'part_number' => '1002295-00-D',
            'name' => "\u{041F}\u{0430}\u{043D}\u{0435}\u{043B}\u{044C} \u{0431}\u{0430}\u{0440}\u{0434}\u{0430}\u{0447}\u{043A}\u{0430} Tesla MS 2012 - 2015 1002295-00-D",
            'name_ua' => "\u{041F}\u{0430}\u{043D}\u{0435}\u{043B}\u{044C} \u{0431}\u{0430}\u{0440}\u{0434}\u{0430}\u{0447}\u{043A}\u{0430} Tesla MS 2012 - 2015 1002295-00-D",
            'notes_ua' => "\u{041E}\u{043F}\u{0438}\u{0441} \u{0434}\u{043B}\u{044F} Prom 1002295-00-D",
            'price_amount' => 100,
            'currency' => 'USD',
            'main_category_name' => "\u{0421}\u{0430}\u{043B}\u{043E}\u{043D}",
            'raw_attributes' => [
                'code' => '393',
                'stock_quantity' => 1,
                'image_urls' => ['/nikolacars/prod/393.jpg'],
            ],
        ]);

        $xml = $this->get(route('prom.nikolacars-products.feed'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->getContent();

        $this->assertStringContainsString('<name>'."\u{041F}\u{0430}\u{043D}\u{0435}\u{043B}\u{044C} \u{0431}\u{0430}\u{0440}\u{0434}\u{0430}\u{0447}\u{043A}\u{0430} MS 2012 - 2015 \u{0430}\u{0440}\u{0442}. 1002295-00-D".'</name>', $xml);
        $this->assertStringContainsString('<description>'."\u{041E}\u{043F}\u{0438}\u{0441} \u{0434}\u{043B}\u{044F} Prom".'</description>', $xml);
        $this->assertStringNotContainsString('<name>'."\u{041F}\u{0430}\u{043D}\u{0435}\u{043B}\u{044C} \u{0431}\u{0430}\u{0440}\u{0434}\u{0430}\u{0447}\u{043A}\u{0430} Tesla MS 2012 - 2015 \u{0430}\u{0440}\u{0442}. 1002295-00-D".'</name>', $xml);
        $this->assertStringNotContainsString("\u{041F}\u{0430}\u{043D}\u{0435}\u{043B}\u{044C} \u{0431}\u{0430}\u{0440}\u{0434}\u{0430}\u{0447}\u{043A}\u{0430} Tesla MS 2012 - 2015 1002295-00-D \u{0430}\u{0440}\u{0442}.", $xml);
        $this->assertStringNotContainsString("\u{041E}\u{043F}\u{0438}\u{0441} \u{0434}\u{043B}\u{044F} Prom 1002295-00-D", $xml);
    }

    public function test_nikolacars_prom_feed_prefers_linked_product_article_price_stock_and_images(): void
    {
        config([
            'app.url' => 'https://example.test',
            'filesystems.disks.public.url' => 'https://example.test/storage',
            'filesystems.public_fallback_url' => null,
            'prom.feed_token' => null,
            'prom.shop_name' => 'Tesla Parts',
            'prom.company_name' => 'Tesla Parts LLC',
        ]);

        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_date' => Carbon::today()->toDateString(),
            'rate' => 40,
            'source' => 'nbu',
            'fetched_at' => now(),
        ]);

        $warehouse = Warehouse::query()->create([
            'name' => 'Main',
            'type' => 'main',
            'is_active' => true,
        ]);
        $location = Location::query()->create([
            'warehouse_id' => $warehouse->id,
            'full_code' => 'A-1',
            'is_active' => true,
        ]);
        $product = Product::query()->create([
            'sku' => 'NC-PROM-PRICE',
            'external_sku' => '1081421-E0-C',
            'name' => 'Product source name',
            'slug' => 'product-source-name',
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'selling_price' => 125,
            'currency' => 'USD',
            'main_image' => 'product-photos/current.jpg',
            'images_json' => ['product-photos/extra.jpg'],
            'is_active' => true,
        ]);
        StockItem::query()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'location_id' => $location->id,
            'quantity' => 1,
            'reserved_quantity' => 0,
            'testing_status' => 'not_tested',
        ]);
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://inventory-product/'.$product->id,
            'part_number' => '1081439-S0-A',
            'name' => 'Old projection name',
            'name_ua' => "\u{041B}\u{043E}\u{043A}\u{0430}\u{043B}\u{0456}\u{0437}\u{043E}\u{0432}\u{0430}\u{043D}\u{0430} \u{043D}\u{0430}\u{0437}\u{0432}\u{0430}",
            'price_amount' => 10,
            'currency' => 'USD',
            'main_category_name' => 'Body',
            'raw_attributes' => [
                'product_id' => $product->id,
                'code' => 'NC-PROM-PRICE',
                'stock_quantity' => 2,
                'image_urls' => ['https://example.test/old-projection.jpg'],
            ],
        ]);
        $product->forceFill(['source_part_catalog_item_id' => $item->id])->save();

        $xml = $this->get(route('prom.nikolacars-products.feed'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<price>5000</price>', $xml);
        $this->assertStringContainsString('<quantity_in_stock>1</quantity_in_stock>', $xml);
        $this->assertStringContainsString('<vendorCode>1081421-E0-C</vendorCode>', $xml);
        $this->assertStringContainsString('<picture>'.PublicStorageUrl::url('product-photos/current.jpg').'</picture>', $xml);
        $this->assertStringNotContainsString('<price>400</price>', $xml);
        $this->assertStringNotContainsString('<vendorCode>1081439-S0-A</vendorCode>', $xml);
    }

    public function test_nikolacars_prom_feed_uses_linked_category_display_instead_of_raw_snapshot(): void
    {
        config([
            'app.url' => 'https://example.test',
            'filesystems.disks.public.url' => 'https://example.test/storage',
            'filesystems.public_fallback_url' => null,
            'prom.feed_token' => null,
            'prom.shop_name' => 'Tesla Parts',
            'prom.company_name' => 'Tesla Parts LLC',
        ]);

        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_date' => Carbon::today()->toDateString(),
            'rate' => 40,
            'source' => 'nbu',
            'fetched_at' => now(),
        ]);

        $category = PartCatalogCategory::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://tesla-category/test-temperature',
            'name' => 'THERMAL MANAGEMENT',
            'name_ru' => 'Управление температурным режимом',
            'name_ua' => 'Управління температурним режимом',
        ]);

        PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/category-snapshot',
            'part_catalog_category_id' => $category->id,
            'part_number' => '1100000-00-A',
            'name' => 'Pipe',
            'name_ua' => 'Трубка',
            'price_amount' => 100,
            'currency' => 'USD',
            'raw_attributes' => [
                'code' => 'CATEGORY-SNAPSHOT',
                'stock_quantity' => 1,
                'category_display' => 'УПРАВЛІННЯ ТЕМПЕРАТУРНИМ РЕЖИМОМ',
                'category_path' => 'УПРАВЛІННЯ ТЕМПЕРАТУРНИМ РЕЖИМОМ',
                'image_urls' => ['/nikolacars/prod/category-snapshot.jpg'],
            ],
        ]);

        $xml = $this->get(route('prom.nikolacars-products.feed'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Управление температурным режимом', $xml);
        $this->assertStringNotContainsString('УПРАВЛІННЯ ТЕМПЕРАТУРНИМ РЕЖИМОМ', $xml);
    }
}
