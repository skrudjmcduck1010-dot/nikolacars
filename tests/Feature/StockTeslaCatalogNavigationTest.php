<?php

namespace Tests\Feature;

use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Models\User;
use App\Services\StockTeslaCatalogImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

class StockTeslaCatalogNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_tesla_catalog_shows_imported_stock_model_labels(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $modelLabel = "\u{041C}\u{041E}\u{0414}\u{0415}\u{041B}\u{042C} 3";

        $model = PartCatalogCategory::query()->create([
            'source' => 'stock-tesla',
            'source_url' => 'https://stock-tesla.com/category/10/',
            'depth' => 0,
            'name' => $modelLabel,
            'model_label' => $modelLabel,
            'model_name' => $modelLabel,
        ]);

        $strayRootCategory = PartCatalogCategory::query()->create([
            'source' => 'stock-tesla',
            'source_url' => 'https://stock-tesla.com/category/21-infotainment/',
            'depth' => 0,
            'name' => '21 - INFOTAINMENT',
            'model_label' => $modelLabel,
            'model_name' => $modelLabel,
        ]);

        $this->actingAs($user)
            ->get(route('admin.stock-tesla-catalog.index'))
            ->assertOk()
            ->assertSee($modelLabel)
            ->assertDontSee("\u{041C}\u{043E}\u{0434}\u{0435}\u{043B}\u{0438} Tesla \u{043D}\u{0435} \u{043D}\u{0430}\u{0439}\u{0434}\u{0435}\u{043D}\u{044B}.");
    }

    public function test_stock_tesla_import_matches_existing_products_by_source_url_only(): void
    {
        PartCatalogItem::query()->create([
            'source' => 'stock-tesla',
            'source_url' => 'https://stock-tesla.com/product/1002633-00-a/',
            'part_number' => '1002633-00-A',
            'name' => 'Existing listing',
        ]);

        $method = new ReflectionMethod(StockTeslaCatalogImporter::class, 'existingProductItem');
        $method->setAccessible(true);

        $this->assertNull($method->invoke(
            app(StockTeslaCatalogImporter::class),
            'https://stock-tesla.com/product/1002633-00-a-r/',
            '1002633-00-A',
        ));
    }

    public function test_stock_tesla_import_reports_created_and_updated_products_separately(): void
    {
        PartCatalogItem::query()->create([
            'source' => 'stock-tesla',
            'source_url' => 'https://stock-tesla.com/product/1002633-00-a/',
            'part_number' => '1002633-00-A',
            'name' => 'Existing listing',
            'raw_attributes' => [],
        ]);

        $importer = new class(app(Factory::class)) extends StockTeslaCatalogImporter
        {
            protected function categoryUrls(string $baseUrl): array
            {
                return [];
            }

            protected function siteCatalogCategories(array $categories, array $categoryUrls, int $maxCategories, string $categoryUrl = '', array $modelCategoryUrls = []): array
            {
                return [['name' => 'Model 3', 'url' => 'https://stock-tesla.com/ru/category/3-1/']];
            }

            protected function categoryPageUrls(string $categoryUrl, string $baseUrl, int $maxCategoryPages, array &$stats): iterable
            {
                yield $categoryUrl => '<html></html>';
            }

            protected function productSummariesFromCategoryHtml(string $html, string $baseUrl): array
            {
                return [
                    [
                        'source_url' => 'https://stock-tesla.com/product/1002633-00-a/',
                        'price_amount' => 10,
                        'currency' => 'USD',
                        'available' => true,
                    ],
                    [
                        'source_url' => 'https://stock-tesla.com/product/2000000-00-a/',
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
                return [
                    'source_url' => $sourceUrl,
                    'part_number' => '2000000-00-A',
                    'name_ua' => 'New Stock Tesla part',
                    'price_amount' => 0,
                    'currency' => 'UAH',
                    'pictures' => [],
                    'category_urls' => [],
                    'raw_attributes' => [],
                ];
            }

            protected function ensureBreadcrumbCategory(array $offer, array &$savedCategoriesBySiteUrl): ?PartCatalogCategory
            {
                return null;
            }

            protected function fallbackCategory(array $offer, array $savedCategories): ?PartCatalogCategory
            {
                return null;
            }

            protected function productPayload(?PartCatalogCategory $category, ?array $categoryPayload, array $offer, ?array $russian): array
            {
                return [
                    'source' => $this->source,
                    'part_number' => $offer['part_number'],
                    'name' => $offer['name_ua'],
                    'name_ua' => $offer['name_ua'],
                    'name_ru' => null,
                    'raw_attributes' => ['url_uk' => $offer['source_url']],
                ];
            }
        };

        $stats = $importer->import([
            'with_russian' => false,
            'download_images' => false,
            'sleep_ms' => 0,
        ]);

        $this->assertSame(2, $stats['products_saved']);
        $this->assertSame(1, $stats['products_created']);
        $this->assertSame(1, $stats['products_updated']);
    }

    public function test_stock_tesla_canonicalizes_russian_product_urls_to_base_product_urls(): void
    {
        $method = new ReflectionMethod(StockTeslaCatalogImporter::class, 'canonicalProductUrl');
        $method->setAccessible(true);

        $this->assertSame(
            'https://stock-tesla.com/product/1044354-00-a/',
            $method->invoke(app(StockTeslaCatalogImporter::class), 'https://stock-tesla.com/ru/product/1044354-00-a/'),
        );
    }

    public function test_stock_tesla_model_category_urls_can_drive_listing_scan(): void
    {
        $method = new ReflectionMethod(StockTeslaCatalogImporter::class, 'siteCatalogCategories');
        $method->setAccessible(true);

        $categories = $method->invoke(
            app(StockTeslaCatalogImporter::class),
            [],
            [],
            0,
            '',
            [
                'https://stock-tesla.com/ru/category/3-1/',
                'https://stock-tesla.com/ru/category/s-2016-1/',
                'https://stock-tesla.com/ru/category/s-2016/',
                'https://stock-tesla.com/ru/category/x/',
                'https://stock-tesla.com/ru/category/y/',
            ],
        );

        $this->assertSame([
            'https://stock-tesla.com/ru/category/3-1/',
            'https://stock-tesla.com/ru/category/s-2016-1/',
            'https://stock-tesla.com/ru/category/s-2016/',
            'https://stock-tesla.com/ru/category/x/',
            'https://stock-tesla.com/ru/category/y/',
        ], collect($categories)->pluck('url')->all());
        $this->assertSame('MODEL S до 2016 року', $categories[1]['name']);
        $this->assertSame('MODEL S після 2016 року', $categories[2]['name']);
    }

    public function test_stock_tesla_import_adds_russian_name_when_new_product_is_found(): void
    {
        $productUrl = 'https://stock-tesla.com/product/1118599-00-c/';
        $ruName = 'Крепление верхней пластины аккумулятора Tesla model 3 1118599-00-C';
        $cleanRuName = 'Крепление верхней пластины аккумулятора';

        Http::fake([
            'https://stock-tesla.test/categories/' => Http::response(''),
            'https://stock-tesla.test/ru/categories/' => Http::response(''),
            'https://stock-tesla.test/en/categories/' => Http::response(''),
            'https://stock-tesla.test/ru/category/3-1/' => Http::response(<<<'HTML'
                <!doctype html>
                <html>
                    <body>
                        <a href="/product/1118599-00-c/">1118599-00-C</a>
                    </body>
                </html>
                HTML),
            $productUrl => Http::response(<<<'HTML'
                <!doctype html>
                <html>
                    <head>
                        <script type="application/ld+json">
                            {
                                "@context": "https://schema.org",
                                "@type": "Product",
                                "name": "Кріплення верхньої пластини акумулятора Tesla model 3 1118599-00-C",
                                "sku": "1118599-00-C",
                                "mpn": "1118599-00-C",
                                "offers": {
                                    "@type": "Offer",
                                    "price": "0",
                                    "priceCurrency": "UAH",
                                    "availability": "https://schema.org/InStock"
                                }
                            }
                        </script>
                    </head>
                    <body></body>
                </html>
                HTML),
            'https://stock-tesla.test/ru/product/1118599-00-c/' => Http::response(<<<HTML
                <!doctype html>
                <html>
                    <head>
                        <script type="application/ld+json">
                            {
                                "@context": "https://schema.org",
                                "@type": "Product",
                                "name": "{$ruName}",
                                "sku": "1118599-00-C",
                                "mpn": "1118599-00-C"
                            }
                        </script>
                    </head>
                    <body></body>
                </html>
                HTML),
        ]);

        $stats = app(StockTeslaCatalogImporter::class)->import([
            'base_url' => 'https://stock-tesla.test',
            'create_categories' => false,
            'model_category_urls' => ['https://stock-tesla.test/ru/category/3-1/'],
            'sleep_ms' => 0,
            'download_images' => false,
        ]);

        $this->assertSame(1, $stats['products_saved']);
        $this->assertSame(1, $stats['russian_pages_fetched']);
        $this->assertDatabaseHas('part_catalog_items', [
            'source' => 'stock-tesla',
            'source_url' => $productUrl,
            'part_number' => '1118599-00-C',
            'name_ru' => $cleanRuName,
        ]);
    }

    public function test_stock_tesla_listing_category_keeps_shared_products_in_every_site_category(): void
    {
        $listingCategory = PartCatalogCategory::query()->create([
            'source' => 'stock-tesla',
            'source_url' => 'https://stock-tesla.com/category/s-2016/',
            'depth' => 0,
            'name' => 'MODEL S after 2016',
            'model_label' => 'MODEL S after 2016',
            'model_name' => 'MODEL S after 2016',
        ]);

        $productUrl = 'https://stock-tesla.com/product/shared-00-a/';

        Http::fake([
            'https://stock-tesla.test/categories/' => Http::response(''),
            'https://stock-tesla.test/ru/categories/' => Http::response(''),
            'https://stock-tesla.test/en/categories/' => Http::response(''),
            'https://stock-tesla.test/ru/category/s-2016/' => Http::response(<<<'HTML'
                <!doctype html>
                <html>
                    <body>
                        <a href="/product/shared-00-a/">Shared product</a>
                    </body>
                </html>
                HTML),
            $productUrl => Http::response(<<<'HTML'
                <!doctype html>
                <html>
                    <head>
                        <script type="application/ld+json">
                            {
                                "@context": "https://schema.org",
                                "@type": "Product",
                                "name": "Shared Model S REST and Model X part SHARED-00-A",
                                "sku": "SHARED-00-A",
                                "mpn": "SHARED-00-A",
                                "offers": {
                                    "@type": "Offer",
                                    "price": "1200",
                                    "priceCurrency": "UAH",
                                    "availability": "https://schema.org/InStock"
                                }
                            }
                        </script>
                        <script type="application/ld+json">
                            {
                                "@context": "https://schema.org",
                                "@type": "BreadcrumbList",
                                "itemListElement": [
                                    {
                                        "@type": "ListItem",
                                        "position": 1,
                                        "name": "Model X",
                                        "item": "https://stock-tesla.com/category/x/"
                                    },
                                    {
                                        "@type": "ListItem",
                                        "position": 2,
                                        "name": "Model X dashboard",
                                        "item": "https://stock-tesla.com/category/x-dashboard/"
                                    }
                                ]
                            }
                        </script>
                    </head>
                    <body></body>
                </html>
                HTML),
        ]);

        $stats = app(StockTeslaCatalogImporter::class)->import([
            'base_url' => 'https://stock-tesla.test',
            'create_categories' => false,
            'model_category_urls' => ['https://stock-tesla.test/ru/category/s-2016/'],
            'sleep_ms' => 0,
            'with_russian' => false,
            'download_images' => false,
        ]);

        $this->assertSame(1, $stats['products_saved']);
        $this->assertSame(1, $stats['product_category_occurrences_saved']);

        $item = PartCatalogItem::query()
            ->where('source', 'stock-tesla')
            ->where('source_url', $productUrl)
            ->firstOrFail();

        $this->assertNotSame($listingCategory->id, $item->part_catalog_category_id);
        $this->assertDatabaseHas('part_catalog_item_occurrences', [
            'part_catalog_item_id' => $item->id,
            'part_catalog_category_id' => $listingCategory->id,
            'source' => 'stock-tesla',
            'product_url' => $productUrl,
            'part_number' => 'SHARED-00-A',
        ]);

        PartCatalogItem::query()->create([
            'part_catalog_category_id' => $listingCategory->id,
            'source' => 'stock-tesla',
            'source_url' => 'https://stock-tesla.com/product/stale-model-3-only/',
            'part_number' => 'STALE-00-A',
            'name' => 'Stale local category item',
        ]);

        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.stock-tesla-catalog.index', ['category_id' => $listingCategory->id]))
            ->assertOk()
            ->assertSee('Shared Model S REST and Model X part')
            ->assertSee('SHARED-00-A')
            ->assertSee('Stale local category item');
    }
}
