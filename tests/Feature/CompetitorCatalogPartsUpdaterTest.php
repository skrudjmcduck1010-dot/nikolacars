<?php

namespace Tests\Feature;

use App\Models\CompetitorCatalogRun;
use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\User;
use App\Services\CompetitorCatalogImageLocalizer;
use App\Services\CompetitorCatalogPartsUpdater;
use App\Services\ErazborkaCatalogImporter;
use App\Services\TeslaCompanyCatalogImporter;
use App\Services\TskCatalogImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CompetitorCatalogPartsUpdaterTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_updates_competitor_catalog_without_creating_products(): void
    {
        $updater = new class extends CompetitorCatalogPartsUpdater
        {
            protected function importSource(string $source, CompetitorCatalogRun $run): array
            {
                PartCatalogItem::query()->create([
                    'source' => $source,
                    'source_url' => 'https://teslacompany.test/goods/part-1/',
                    'part_number' => '1495508-00-A',
                    'name' => 'Tesla test part 1495508-00-A',
                    'price_amount' => 100,
                    'currency' => 'USD',
                ]);

                return [
                    'products_created' => 1,
                    'products_updated' => 0,
                ];
            }
        };

        $run = CompetitorCatalogRun::query()->create([
            'source' => 'teslacompany',
            'status' => 'pending',
        ]);

        $stats = $updater->run($run);

        $this->assertSame(1, PartCatalogItem::query()->where('source', 'teslacompany')->count());
        $this->assertSame(0, Product::query()->count());
        $this->assertSame(1, $stats['catalog_products_created']);
        $this->assertSame(0, $stats['products_created']);
        $this->assertSame(0, $stats['products_updated']);
    }

    public function test_tsk_refresh_passes_selected_category_to_leaf_importer(): void
    {
        $parent = PartCatalogCategory::query()->create([
            'source' => 'tsk',
            'name' => '1001 Bumper and Fascia',
            'source_url' => 'https://tsk.ua/katalog-zapchastey296/1001-bumper-and-fascia/',
            'depth' => 2,
        ]);

        PartCatalogCategory::query()->create([
            'source' => 'tsk',
            'parent_id' => $parent->id,
            'name' => 'Front Bumper Fascia',
            'source_url' => 'https://tsk.ua/katalog-zapchastey296/front-bumper-fascia/',
            'depth' => 3,
        ]);

        PartCatalogCategory::query()->create([
            'source' => 'tsk',
            'name' => 'Outside Branch',
            'source_url' => 'https://tsk.ua/katalog-zapchastey296/outside-branch/',
            'depth' => 3,
        ]);

        $fakeImporter = new class
        {
            public array $options = [];

            public function importLeafProducts(array $options = []): array
            {
                $this->options = $options;

                return [
                    'products_created' => 0,
                    'products_updated' => 0,
                ];
            }
        };

        $this->app->instance(TskCatalogImporter::class, $fakeImporter);
        $this->app->instance(CompetitorCatalogImageLocalizer::class, new class
        {
            public function localizeSource(string $source, array $options = []): array
            {
                return ['images_localized' => 0];
            }
        });

        $run = CompetitorCatalogRun::query()->create([
            'source' => 'tsk',
            'status' => 'pending',
            'stats' => ['category_id' => $parent->id],
        ]);

        app(CompetitorCatalogPartsUpdater::class)->run($run);

        $this->assertSame($parent->id, $fakeImporter->options['category_id']);
        $this->assertSame($parent->id, $run->refresh()->stats['category_id']);
    }

    public function test_tsk_admin_refresh_button_runs_all_leaf_categories_from_any_page(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-tsk-category-refresh@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $category = PartCatalogCategory::query()->create([
            'source' => 'tsk',
            'name' => 'Front Bumper Fascia',
            'source_url' => 'https://tsk.ua/katalog-zapchastey296/front-bumper-fascia-2353/',
            'depth' => 3,
        ]);

        $this->actingAs($user)
            ->get(route('admin.tsk-catalog.index', ['category_id' => $category->id]))
            ->assertOk()
            ->assertSee('competitor-refresh/tsk', false)
            ->assertDontSee('competitor-refresh/tsk?category_id='.$category->id, false);
    }

    public function test_teslacompany_importer_counts_only_new_or_changed_catalog_rows(): void
    {
        $importer = app(TeslaCompanyCatalogImporter::class);
        $path = $this->teslaCompanyCsv('100');

        $first = $importer->import($path);
        $second = $importer->import($path);

        $this->assertSame(1, $first['products_created']);
        $this->assertSame(0, $first['products_updated']);
        $this->assertSame(0, $second['products_created']);
        $this->assertSame(0, $second['products_updated']);

        $changedPath = $this->teslaCompanyCsv('125');
        $third = $importer->import($changedPath);

        $this->assertSame(0, $third['products_created']);
        $this->assertSame(1, $third['products_updated']);
        $this->assertSame(1, PartCatalogItem::query()->where('source', 'teslacompany')->count());
        $this->assertSame(1, ProductPriceHistory::query()->where('source', 'teslacompany')->count());
        $this->assertDatabaseHas('product_price_histories', [
            'part_catalog_item_id' => PartCatalogItem::query()->where('source', 'teslacompany')->value('id'),
            'old_price' => 100,
            'new_price' => 125,
            'currency' => 'USD',
        ]);
    }

    public function test_teslacompany_importer_walks_model_subcategories_and_ignores_category_urls_as_products(): void
    {
        PartCatalogItem::query()->create([
            'source' => 'teslacompany',
            'source_url' => 'https://teslacompany.com.ua/category/tesla-model-y-552772/2/',
            'name' => 'Tesla Model Y',
        ]);

        $modelUrl = 'https://teslacompany.com.ua/category/tesla-model-3-552761/';
        $categoryUrl = 'https://teslacompany.com.ua/category/kuzov-tesla-model-3/';
        $productUrl = 'https://teslacompany.com.ua/goods/test-product/';

        $importer = new class([$modelUrl => <<<HTML
                <html><head></head><body>
                    <div class="a-vizit-category-list">
                        <div class="avc-item"><a href="{$categoryUrl}">Кузов Tesla Model 3 <span>(1)</span></a></div>
                    </div>
                    <div class="a-vizit-goods-list">
                        <div class="avg-item">
                            <div class="title"><a href="https://teslacompany.com.ua/category/tesla-model-y-552772/2/"><span itemprop="name">Tesla Model Y</span></a></div>
                        </div>
                    </div>
                </body></html>
                HTML, $categoryUrl => <<<HTML
                <html><head></head><body>
                    <div class="a-vizit-goods-list">
                        <div class="avg-item">
                            <div class="img"><a href="{$productUrl}"><img itemprop="image" src="/image-cache/test.jpg"></a></div>
                            <div class="title"><a href="{$productUrl}"><span itemprop="name">Крыло переднее Tesla Model 3</span></a></div>
                            <div class="article"><span>Код:</span> <b>1493452-E0-B</b></div>
                            <div class="price-cont">$100</div>
                            <div class="to-cart"><button data-goods-id="21031849">Купить</button></div>
                        </div>
                    </div>
                </body></html>
                HTML, $productUrl => <<<'HTML'
                <html><head></head><body>
                    <div class="bread-crumbs">
                        <a>Главная</a><a>Каталог</a><a>Tesla Model 3</a><a>Кузов Tesla Model 3</a><b>Крыло переднее Tesla Model 3</b>
                    </div>
                    <div class="goods-view-content">
                        <button data-goods-id="21031849"></button>
                        <div class="goods-title">
                            <div class="article"><b>1493452-E0-B</b></div>
                            <h1 itemprop="name">Крыло переднее Tesla Model 3</h1>
                        </div>
                    </div>
                </body></html>
                HTML, ]) extends TeslaCompanyCatalogImporter
        {
            public function __construct(private array $htmlByUrl) {}

            protected function fetchHtml(string $url): ?string
            {
                return $this->htmlByUrl[$url] ?? null;
            }
        };

        $stats = $importer->refreshModelListings([
            'max_pages' => 2,
            'sleep_ms' => 0,
        ]);

        $this->assertSame(1, $stats['category_products_deleted']);
        $this->assertSame(2, $stats['pages_scanned']);
        $this->assertSame(1, $stats['listing_products_seen']);
        $this->assertSame(1, $stats['products_created']);
        $this->assertDatabaseMissing('part_catalog_items', [
            'source' => 'teslacompany',
            'source_url' => 'https://teslacompany.com.ua/category/tesla-model-y-552772/2/',
        ]);
        $this->assertDatabaseHas('part_catalog_items', [
            'source' => 'teslacompany',
            'source_url' => $productUrl,
            'part_number' => '1493452-E0-B',
            'name' => 'Крыло переднее Tesla Model 3',
        ]);
    }

    public function test_teslacompany_importer_updates_only_price_for_existing_listing_product(): void
    {
        $productUrl = 'https://teslacompany.com.ua/goods/existing-product/';
        PartCatalogItem::query()->create([
            'source' => 'teslacompany',
            'source_url' => $productUrl,
            'part_number' => '1041575-00-B',
            'name' => 'Existing product',
            'price_amount' => 100,
            'currency' => 'USD',
            'raw_attributes' => [
                'category_path_items' => ['Tesla Model X Plaid', 'Suspension', 'Lever'],
            ],
        ]);

        $modelUrl = 'https://teslacompany.com.ua/category/tesla-model-3-552761/';
        $importer = new class([$modelUrl => <<<HTML
                <html><head></head><body>
                    <div class="a-vizit-goods-list">
                        <div class="avg-item">
                            <div class="title"><a href="{$productUrl}"><span itemprop="name">Existing product</span></a></div>
                            <div class="price-cont">6090,00 грн./шт $140,00</div>
                        </div>
                    </div>
                </body></html>
                HTML, ]) extends TeslaCompanyCatalogImporter
        {
            public function __construct(private array $htmlByUrl) {}

            protected function fetchHtml(string $url): ?string
            {
                if (str_contains($url, '/goods/')) {
                    throw new \RuntimeException('Existing products must not fetch detail pages.');
                }

                return $this->htmlByUrl[$url] ?? null;
            }
        };

        $stats = $importer->refreshModelListings([
            'max_pages' => 1,
            'sleep_ms' => 0,
        ]);

        $this->assertSame(0, $stats['detail_pages_fetched']);
        $this->assertSame(1, $stats['products_updated']);
        $this->assertDatabaseHas('part_catalog_items', [
            'source' => 'teslacompany',
            'source_url' => $productUrl,
            'price_amount' => 140,
            'currency' => 'USD',
        ]);
        $this->assertDatabaseHas('product_price_histories', [
            'part_catalog_item_id' => PartCatalogItem::query()->where('source_url', $productUrl)->value('id'),
            'old_price' => 100,
            'new_price' => 140,
            'currency' => 'USD',
        ]);
    }

    public function test_teslacompany_importer_creates_model_categories_before_products_are_seen(): void
    {
        $importer = new class extends TeslaCompanyCatalogImporter
        {
            protected function fetchHtml(string $url): ?string
            {
                return '<html><head></head><body></body></html>';
            }
        };

        $stats = $importer->refreshModelListings([
            'max_pages' => 1,
            'sleep_ms' => 0,
        ]);

        $this->assertSame(1, $stats['pages_scanned']);
        $this->assertSame(0, PartCatalogItem::query()->where('source', 'teslacompany')->count());
        $this->assertDatabaseHas('part_catalog_categories', [
            'source' => 'teslacompany',
            'parent_id' => null,
            'name' => 'Tesla Model 3',
            'source_url' => 'https://teslacompany.com.ua/category/tesla-model-3-552761/',
        ]);
    }

    public function test_teslacompany_importer_emits_created_progress_for_realtime_refresh_counter(): void
    {
        $modelUrl = 'https://teslacompany.com.ua/category/tesla-model-3-552761/';
        $productUrl = 'https://teslacompany.com.ua/goods/test-product/';
        $messages = [];

        $importer = new class([$modelUrl => <<<HTML
                <html><head></head><body>
                    <div class="a-vizit-goods-list">
                        <div class="avg-item">
                            <div class="title"><a href="{$productUrl}"><span itemprop="name">Test product</span></a></div>
                            <div class="price-cont">$100</div>
                        </div>
                    </div>
                </body></html>
                HTML, $productUrl => <<<'HTML'
                <html><head></head><body>
                    <div class="bread-crumbs">
                        <a href="https://teslacompany.com.ua/">Главная</a><a href="https://teslacompany.com.ua/goods/">Каталог</a><a>Tesla Model 3</a><a>Кузов</a><b>Test product</b>
                    </div>
                    <div class="goods-view-content">
                        <div class="goods-title">
                            <div class="article"><b>1493452-E0-B</b></div>
                            <h1 itemprop="name">Test product</h1>
                        </div>
                    </div>
                </body></html>
                HTML, ]) extends TeslaCompanyCatalogImporter
        {
            public function __construct(private array $htmlByUrl) {}

            protected function fetchHtml(string $url): ?string
            {
                return $this->htmlByUrl[$url] ?? null;
            }
        };

        $importer->refreshModelListings([
            'max_pages' => 1,
            'sleep_ms' => 0,
            'verbose' => true,
            'progress' => function (string $message) use (&$messages): void {
                $messages[] = $message;
            },
        ]);

        $this->assertContains('created: Test product', $messages);
        $this->assertContains('TeslaCompany download: fetching: 1: https://teslacompany.com.ua/category/tesla-model-3-552761/', $messages);
        $this->assertContains('TeslaCompany download: 1: https://teslacompany.com.ua/category/tesla-model-3-552761/ - 1 items', $messages);
    }

    public function test_teslacompany_importer_filters_cap_placeholder_images(): void
    {
        $modelUrl = 'https://teslacompany.com.ua/category/tesla-model-3-552761/';
        $productUrl = 'https://teslacompany.com.ua/goods/cap-placeholder-product/';

        $importer = new class([$modelUrl => <<<HTML
                <html><head></head><body>
                    <div class="a-vizit-goods-list">
                        <div class="avg-item">
                            <div class="img"><a href="{$productUrl}"><img itemprop="image" src="/image-cache/?w=300&h=280&a=3&f=static-files%2Fimg%2Fgoods%2Fcap.jpg"></a></div>
                            <div class="title"><a href="{$productUrl}"><span itemprop="name">Placeholder product</span></a></div>
                            <div class="price-cont">$100</div>
                        </div>
                    </div>
                </body></html>
                HTML, $productUrl => <<<'HTML'
                <html><head></head><body>
                    <div class="bread-crumbs">
                        <a href="https://teslacompany.com.ua/">Главная</a><a href="https://teslacompany.com.ua/goods/">Каталог</a><a>Tesla Model 3</a><b>Placeholder product</b>
                    </div>
                    <div class="goods-view-content">
                        <div class="goods-title">
                            <div class="article"><b>2287.3005</b></div>
                            <h1 itemprop="name">Placeholder product</h1>
                        </div>
                        <div class="images">
                            <a href="https://podkapot.com.ua/static-files/img/goods/cap.jpg">cap</a>
                        </div>
                    </div>
                </body></html>
                HTML, ]) extends TeslaCompanyCatalogImporter
        {
            public function __construct(private array $htmlByUrl) {}

            protected function fetchHtml(string $url): ?string
            {
                return $this->htmlByUrl[$url] ?? null;
            }
        };

        $importer->refreshModelListings([
            'max_pages' => 1,
            'sleep_ms' => 0,
        ]);

        $item = PartCatalogItem::query()->where('source_url', $productUrl)->firstOrFail();
        $raw = $item->raw_attributes;

        $this->assertArrayNotHasKey('image_url', $raw);
        $this->assertArrayNotHasKey('image_urls', $raw);
    }

    public function test_teslacompany_image_localizer_keeps_distinct_image_cache_files_and_dedupes_sizes(): void
    {
        $item = PartCatalogItem::query()->create([
            'source' => 'teslacompany',
            'source_url' => 'https://teslacompany.test/goods/product/',
            'part_number' => '1095145-00-G',
            'name' => 'Product',
            'raw_attributes' => [
                'image_urls' => [
                    'https://teslacompany.com.ua/image-cache/?fi=28466&wt=0&f=static-files%2Fimg%2Fgoods%2Fd7%2F0b%2Fphoto-1.jpg',
                    'https://teslacompany.com.ua/image-cache/?fi=28466&wt=0&f=static-files%2Fimg%2Fgoods%2F6e%2F39%2Fphoto-2.jpg',
                    'https://teslacompany.com.ua/image-cache/?w=300&h=280&a=3&fi=28466&wt=0&f=static-files%2Fimg%2Fgoods%2Fd7%2F0b%2Fphoto-1.jpg',
                ],
            ],
        ]);

        $localizer = app(CompetitorCatalogImageLocalizer::class);
        $method = new \ReflectionMethod($localizer, 'remoteImageIdentityKey');
        $method->setAccessible(true);
        $raw = $item->raw_attributes;
        $keys = collect($raw['image_urls'])
            ->map(fn (string $url): string => $method->invoke($localizer, $url))
            ->unique()
            ->values()
            ->all();

        $this->assertCount(2, $keys);
    }

    public function test_tesla_official_image_localizer_deduplicates_identical_resource_images(): void
    {
        Storage::fake('public');
        Http::fake([
            'https://epc.tesla.com/resources/images/Model3/Classic/US/Supermanifold%20M3Y%20Fremont%20TI-4565_a.png' => Http::response('same-image-bytes', 200, ['Content-Type' => 'image/png']),
            'https://epc.tesla.com/resources/images/Model3/Classic/US/Supermanifold%20M3Y%20Fremont%20TI-4565_b.png' => Http::response('same-image-bytes', 200, ['Content-Type' => 'image/png']),
        ]);

        $item = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=1492611-S0-A',
            'part_number' => '1492611-S0-A',
            'name' => 'SERVICE KIT - COOLANT PUMP',
            'raw_attributes' => [
                'system_group_image_urls' => [
                    'https://epc.tesla.com/resources/images/Model3/Classic/US/Supermanifold%20M3Y%20Fremont%20TI-4565_a.png',
                    'https://epc.tesla.com/resources/images/Model3/Classic/US/Supermanifold%20M3Y%20Fremont%20TI-4565_b.png',
                ],
            ],
        ]);

        $stats = [];
        app(CompetitorCatalogImageLocalizer::class)->localizeItemImages($item, $stats);
        $raw = $item->refresh()->raw_attributes;

        $this->assertCount(1, $raw['system_group_image_urls']);
        $this->assertCount(1, $raw['image_urls']);
        $this->assertCount(1, Storage::disk('public')->files('tesla-official/resources-images'));
    }

    public function test_tesla_official_image_localizer_cleans_existing_local_duplicate_resource_images(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('tesla-official/resources-images/scheme-a.png', 'same-image-bytes');
        Storage::disk('public')->put('tesla-official/resources-images/scheme-b.png', 'same-image-bytes');

        $item = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=1492611-S0-A',
            'part_number' => '1492611-S0-A',
            'name' => 'SERVICE KIT - COOLANT PUMP',
            'raw_attributes' => [
                'image_urls' => [
                    'tesla-official/resources-images/scheme-a.png',
                    'tesla-official/resources-images/scheme-b.png',
                ],
                'system_group_image_urls' => [
                    'tesla-official/resources-images/scheme-a.png',
                    'tesla-official/resources-images/scheme-b.png',
                ],
            ],
        ]);

        $stats = [];
        app(CompetitorCatalogImageLocalizer::class)->localizeItemImages($item, $stats);
        $raw = $item->refresh()->raw_attributes;

        $this->assertCount(1, $raw['image_urls']);
        $this->assertCount(1, $raw['system_group_image_urls']);
    }

    public function test_localizer_removes_remote_image_fields_after_local_images_exist(): void
    {
        $item = PartCatalogItem::query()->create([
            'source' => 'teslacompany',
            'source_url' => 'https://teslacompany.test/goods/product/',
            'part_number' => '1095145-00-G',
            'name' => 'Product',
            'raw_attributes' => [
                'image_urls' => ['competitor-catalog/teslacompany/1095145-00-g/local.jpg'],
                'image_url' => 'competitor-catalog/teslacompany/1095145-00-g/local.jpg',
                'remote_image_urls' => ['https://teslacompany.com.ua/image-cache/?fi=28466&wt=0&f=static-files%2Fimg%2Fgoods%2Fd7%2F0b%2Fphoto-1.jpg'],
                'remote_image_url' => 'https://teslacompany.com.ua/image-cache/?fi=28466&wt=0&f=static-files%2Fimg%2Fgoods%2Fd7%2F0b%2Fphoto-1.jpg',
            ],
        ]);

        $stats = [];
        app(CompetitorCatalogImageLocalizer::class)->localizeItemImages($item, $stats);
        $raw = $item->refresh()->raw_attributes;

        $this->assertContains('competitor-catalog/teslacompany/1095145-00-g/local.jpg', $raw['image_urls']);
        $this->assertFalse(collect($raw['image_urls'])->contains(fn (string $url): bool => str_starts_with($url, 'http')));
        $this->assertArrayNotHasKey('remote_image_urls', $raw);
        $this->assertArrayNotHasKey('remote_image_url', $raw);
    }

    public function test_competitor_refresh_cannot_start_more_than_once_per_24_hours(): void
    {
        $this->travelTo('2026-05-14 12:00:00');

        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-competitor-refresh-cooldown@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        CompetitorCatalogRun::query()->create([
            'source' => 'teslacompany',
            'status' => 'done',
            'started_at' => now()->subHours(23),
            'finished_at' => now()->subHours(23),
        ]);

        $this->actingAs($user)
            ->postJson(route('admin.part-catalog.source-competitor-refresh.start', ['source' => 'teslacompany']))
            ->assertStatus(429)
            ->assertJson([
                'status' => 'done',
                'next_available_at' => now()->addHour()->toIso8601String(),
            ]);

        $this->assertSame(1, CompetitorCatalogRun::query()->where('source', 'teslacompany')->count());

        $this->travelTo('2026-05-14 13:01:00');

        $this->assertFalse(CompetitorCatalogRun::latestRefreshForCooldown('teslacompany')?->isInRefreshCooldown());
    }

    public function test_failed_competitor_refresh_does_not_start_cooldown(): void
    {
        $this->travelTo('2026-05-14 12:00:00');

        CompetitorCatalogRun::query()->create([
            'source' => 'teslapartsukraine',
            'status' => 'failed',
            'started_at' => now()->subMinute(),
            'finished_at' => now()->subMinute(),
            'error' => 'Undefined array key "scheme_number"',
        ]);

        $this->assertNull(CompetitorCatalogRun::latestRefreshForCooldown('teslapartsukraine'));
    }

    public function test_competitor_refresh_status_counts_created_items_and_price_changes_in_realtime(): void
    {
        $startedAt = now()->subMinutes(5);
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-competitor-refresh-status@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $run = CompetitorCatalogRun::query()->create([
            'source' => 'teslacompany',
            'status' => 'running',
            'progress_current' => 1,
            'progress_total' => 10,
            'stats' => [
                'catalog_products_created' => 0,
                'prices_changed' => 0,
            ],
            'started_at' => $startedAt,
        ]);

        $item = PartCatalogItem::query()->create([
            'source' => 'teslacompany',
            'source_url' => 'https://teslacompany.test/goods/realtime-new/',
            'part_number' => 'REALTIME-001',
            'name' => 'Realtime new part',
            'price_amount' => 100,
            'currency' => 'USD',
        ]);
        $item->forceFill(['created_at' => $startedAt->copy()->addMinute()])->save();

        ProductPriceHistory::query()->create([
            'part_catalog_item_id' => $item->id,
            'source' => 'teslacompany',
            'old_price' => 100,
            'new_price' => 125,
            'currency' => 'USD',
            'changed_at' => $startedAt->copy()->addMinutes(2),
        ]);

        $this->actingAs($user)
            ->getJson(route('admin.part-catalog.source-competitor-refresh.status', ['source' => 'teslacompany']))
            ->assertOk()
            ->assertJson([
                'source' => 'teslacompany',
                'status' => 'running',
                'catalog_products_created' => 1,
                'prices_changed' => 1,
            ]);

        $this->assertTrue($run->refresh()->isRunning());
    }

    public function test_competitor_refresh_progress_tracks_current_model(): void
    {
        PartCatalogCategory::query()->create([
            'source' => 'tcarservice',
            'name' => 'Body',
            'source_url' => 'https://tcarservice.com/zapchasty/model-s-321/body',
            'model_label' => 'Model S',
            'model_name' => 'Model S',
        ]);

        $run = CompetitorCatalogRun::query()->create([
            'source' => 'tcarservice',
            'status' => 'pending',
        ]);

        $updater = new class extends CompetitorCatalogPartsUpdater
        {
            public function exposeProgress(CompetitorCatalogRun $run, string $message, string $source): void
            {
                $this->handleImporterProgress($run, $message, $source);
            }
        };

        $updater->exposeProgress($run, 'Leaf category #1: https://tcarservice.com/zapchasty/model-s-321/body', 'tcarservice');

        $this->assertSame('Model S', $run->refresh()->stats['progress_current_model']);

        $updater->exposeProgress($run, 'Model: Model 3 Highland', 'driveparts');

        $this->assertSame('Model 3 Highland', $run->refresh()->stats['progress_current_model']);
    }

    public function test_competitor_refresh_category_progress_does_not_count_category_id_as_pages(): void
    {
        $run = CompetitorCatalogRun::query()->create([
            'source' => 'erazborka',
            'status' => 'pending',
        ]);

        $updater = new class extends CompetitorCatalogPartsUpdater
        {
            public function exposeProgress(CompetitorCatalogRun $run, string $message, string $source): void
            {
                $this->handleImporterProgress($run, $message, $source);
            }
        };

        $updater->exposeProgress($run, 'Category #19528: Body', 'erazborka');

        $stats = $run->refresh()->stats;

        $this->assertSame(19528, $stats['progress_categories_scanned']);
        $this->assertArrayNotHasKey('progress_pages_scanned', $stats);
        $this->assertArrayNotHasKey('progress_pages_opened', $stats);

        $updater->exposeProgress($run, '  Page 1: https://erazborka.com.ua/tesla/body/', 'erazborka');

        $stats = $run->refresh()->stats;

        $this->assertSame(1, $stats['progress_pages_opened']);
        $this->assertSame(1, $stats['progress_pages_scanned']);

        $updater->exposeProgress($run, 'Opened pages 7', 'erazborka');

        $this->assertSame(7, $run->refresh()->stats['progress_pages_opened']);
    }

    public function test_erazborka_product_import_skips_model_root_listing_pages(): void
    {
        PartCatalogCategory::query()->create([
            'source' => 'erazborka',
            'name' => 'Model 3 root',
            'source_url' => 'https://erazborka.com.ua/catalog/zapchasti-tesla-model-3/',
            'depth' => 1,
        ]);

        PartCatalogCategory::query()->create([
            'source' => 'erazborka',
            'name' => 'Accessories Model 3',
            'source_url' => 'https://erazborka.com.ua/catalog/aksessuary-tesla-model-3/',
            'depth' => 1,
        ]);

        PartCatalogCategory::query()->create([
            'source' => 'erazborka',
            'name' => 'Rear trunk Model 3',
            'source_url' => 'https://erazborka.com.ua/catalog/bagazhnik-zadniy-tesla-model-3/',
            'depth' => 2,
        ]);

        PartCatalogCategory::query()->create([
            'source' => 'erazborka',
            'name' => 'Accessories Model 3 UA',
            'source_url' => 'https://erazborka.com.ua/ua/catalog/aksessuary-tesla-model-3/',
            'depth' => 1,
        ]);

        $importer = new class(app(Factory::class)) extends ErazborkaCatalogImporter
        {
            public array $fetchedUrls = [];

            protected function fetch(string $url): ?string
            {
                $this->fetchedUrls[] = $url;

                return '<html><body></body></html>';
            }
        };

        $stats = $importer->importProducts([
            'dry_run' => true,
            'rescan' => true,
            'max_pages_per_category' => 1,
            'sleep_ms' => 0,
        ]);

        $this->assertSame([
            'https://erazborka.com.ua/catalog/aksessuary-tesla-model-3/',
            'https://erazborka.com.ua/catalog/bagazhnik-zadniy-tesla-model-3/',
        ], $importer->fetchedUrls);
        $this->assertSame(3, $stats['categories_scanned']);
        $this->assertSame(2, $stats['category_pages_scanned']);
        $this->assertSame(2, $stats['source_pages_fetched']);
    }

    public function test_competitor_refresh_tracks_opened_pages_for_generic_importer_messages(): void
    {
        $run = CompetitorCatalogRun::query()->create([
            'source' => 'tcarservice',
            'status' => 'pending',
        ]);

        $updater = new class extends CompetitorCatalogPartsUpdater
        {
            public function exposeProgress(CompetitorCatalogRun $run, string $message, string $source): void
            {
                $this->handleImporterProgress($run, $message, $source);
            }
        };

        $updater->exposeProgress($run, '  fetched page: https://tcarservice.com/zapchasty/model-s/body', 'tcarservice');
        $updater->exposeProgress($run, '  fetched', 'tcarservice');
        $updater->exposeProgress($run, '  Page: https://stock-tesla.com/category/model-3/', 'stock-tesla');
        $updater->exposeProgress($run, 'category: https://teslaparts.com.ua/model-3/', 'teslapartsukraine');

        $this->assertSame(4, $run->refresh()->stats['progress_pages_opened']);
    }

    protected function teslaCompanyCsv(string $price): string
    {
        $path = tempnam(sys_get_temp_dir(), 'teslacompany-test-');
        $handle = fopen($path, 'wb');

        fputcsv($handle, [
            'source',
            'page',
            'page_url',
            'goods_id',
            'part_number',
            'name_ru',
            'price_text',
            'button_text',
            'url',
            'image_url',
            'category',
            'detail_goods_id',
            'detail_part_number',
            'detail_name_ru',
            'availability',
            'condition',
            'make_model',
            'detail_price',
            'publication_date',
            'manufacturer',
            'description',
            'detail_button_text',
            'detail_image_urls',
            'detail_info_json',
            'characteristics_json',
        ]);

        fputcsv($handle, [
            'teslacompany',
            '1',
            'https://teslacompany.test/goods/',
            '21099836',
            '1495508-00-A',
            'Tesla test part 1495508-00-A',
            '$'.$price,
            'Buy',
            'https://teslacompany.test/goods/part-1/',
            '',
            'Glass',
            '21099836',
            '1495508-00-A',
            'Tesla test part 1495508-00-A',
            'В наличии',
            'Новое',
            'Tesla Model Y 2020-2025',
            '$'.$price,
            '07.04.2026',
            'Tesla',
            '',
            'Buy',
            '[]',
            '{}',
            '{}',
        ]);

        fclose($handle);

        return $path;
    }
}
