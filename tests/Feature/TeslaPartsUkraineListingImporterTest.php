<?php

namespace Tests\Feature;

use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Services\TeslaPartsUkraineCatalogImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TeslaPartsUkraineListingImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_listing_importer_fetches_full_product_data_only_for_new_products(): void
    {
        Http::fake([
            'https://teslapartsukraine.com.ua/model-s-lyutyj-2012-r-*' => Http::response($this->listingPage()),
            'https://teslapartsukraine.com.ua/tesla-model-s?product_id=8043' => Http::response($this->productPage()),
        ]);

        $stats = app(TeslaPartsUkraineCatalogImporter::class)->refreshModelListings([
            'model_urls' => ['https://teslapartsukraine.com.ua/model-s-lyutyj-2012-r-%E2%80%93-berezen-2016-r-model-s-feb-2012-mar-2016?limit=10000'],
            'sleep_ms' => 0,
        ]);

        $this->assertSame(1, $stats['listing_products_seen']);
        $this->assertSame(1, $stats['product_pages_fetched']);
        $this->assertSame(1, PartCatalogItem::query()->where('source', 'teslapartsukraine')->count());

        $generation = PartCatalogCategory::query()
            ->where('source', 'teslapartsukraine')
            ->where('name', 'Model S Feb 2012')
            ->firstOrFail();

        $item = PartCatalogItem::query()->where('source', 'teslapartsukraine')->firstOrFail();

        $this->assertSame($generation->id, $item->part_catalog_category_id);
        $this->assertSame('Model S Feb 2012', $item->model_label);
        $this->assertSame('Model S', $item->model_name);
        $this->assertSame('8043', $item->raw_attributes['listing_product_id']);
        $this->assertSame('Product page description.', $item->notes_ua);
        $this->assertSame([
            'https://teslapartsukraine.com.ua/1c_image/detail-1.jpg',
            'https://teslapartsukraine.com.ua/1c_image/detail-2.jpg',
        ], $item->raw_attributes['image_urls']);
        $this->assertSame(
            'https://teslapartsukraine.com.ua/model-s-lyutyj-2012-r-%E2%80%93-berezen-2016-r-model-s-feb-2012-mar-2016',
            $item->raw_attributes['listing_source_url']
        );
    }

    public function test_listing_importer_updates_only_price_for_existing_products(): void
    {
        $item = PartCatalogItem::query()->create([
            'source' => 'teslapartsukraine',
            'source_url' => 'https://teslapartsukraine.com.ua/tesla-model-x?product_id=8043',
            'part_number' => '6007372-00-A',
            'name' => 'Existing name',
            'price_amount' => 40,
            'currency' => 'USD',
            'availability' => 'old availability',
            'model_label' => 'Old model',
            'raw_attributes' => [
                'product_url' => 'https://teslapartsukraine.com.ua/tesla-model-x?product_id=8043',
                'image_urls' => ['https://teslapartsukraine.com.ua/1c_image/old.jpg'],
            ],
        ]);

        Http::fake([
            'https://teslapartsukraine.com.ua/model-x-berezen-2021-r-model-x-mar-2021?limit=10000' => Http::response($this->palladiumPageWithSuspensionLink()),
        ]);

        $stats = app(TeslaPartsUkraineCatalogImporter::class)->refreshModelListings([
            'model_urls' => ['https://teslapartsukraine.com.ua/model-x-berezen-2021-r-model-x-mar-2021?limit=10000'],
            'sleep_ms' => 0,
        ]);

        $item->refresh();

        $this->assertSame(1, $stats['listing_products_matched']);
        $this->assertSame(1, $stats['prices_changed']);
        $this->assertSame(0, $stats['product_pages_fetched']);
        $this->assertSame('65.00', (string) $item->price_amount);
        $this->assertSame('Existing name', $item->name);
        $this->assertSame('old availability', $item->availability);
        $this->assertSame('Old model', $item->model_label);
        $this->assertSame(['https://teslapartsukraine.com.ua/1c_image/old.jpg'], $item->raw_attributes['image_urls']);

        Http::assertNotSent(fn ($request): bool => (string) $request->url() === 'https://teslapartsukraine.com.ua/tesla-model-x?product_id=8043');
    }

    private function listingPageWithGenerationLink(): string
    {
        return <<<'HTML'
            <html>
                <body>
                    <a href="https://teslapartsukraine.com.ua/model-s-lyutyj-2012-r-%E2%80%93-berezen-2016-r-model-s-feb-2012-mar-2016">
                        Model S Feb 2012 - Mar 2016
                    </a>
                    <div class="product-thumb">
                        <div class="name">
                            <a href="https://teslapartsukraine.com.ua/tesla-model-s?product_id=8043&amp;limit=10000">Радіатор охолодження основний Tesla Model S аналог</a>
                        </div>
                        <span>Артикул:</span> <span>6007372-00-A</span>
                        <span class="price-normal">65.00 $</span>
                        <input type="hidden" name="product_id" value="8043">
                        <img src="https://teslapartsukraine.com.ua/1c_image/radiator.jpg">
                    </div>
                </body>
            </html>
            HTML;
    }

    private function productPage(): string
    {
        return <<<'HTML'
            <html>
                <head>
                    <meta property="og:image" content="https://teslapartsukraine.com.ua/1c_image/detail-1.jpg">
                </head>
                <body>
                    <h1>Р Р°РґС–Р°С‚РѕСЂ РѕС…РѕР»РѕРґР¶РµРЅРЅСЏ РѕСЃРЅРѕРІРЅРёР№ Tesla Model S Р°РЅР°Р»РѕРі</h1>
                    <div>РќР°СЏРІРЅС–СЃС‚СЊ: РЅР° СЃРєР»Р°РґС– РђСЂС‚РёРєСѓР»: 6007372-00-A</div>
                    <div>65.00 $</div>
                    <div class="tab-pane" id="tab-description">Product page description.</div>
                    <a href="https://teslapartsukraine.com.ua/1c_image/detail-2.jpg">photo</a>
                    <img src="https://teslapartsukraine.com.ua/image/cache/placeholder-250x250.png">
                </body>
            </html>
            HTML;
    }

    private function listingPage(): string
    {
        return <<<'HTML'
            <html>
                <body>
                    <div class="product-thumb">
                        <div class="name">
                            <a href="https://teslapartsukraine.com.ua/tesla-model-s?product_id=8043&amp;limit=10000">Радіатор охолодження основний Tesla Model S аналог</a>
                        </div>
                        <span>Артикул:</span> <span>6007372-00-A</span>
                        <span class="price-normal">65.00 $</span>
                        <input type="hidden" name="product_id" value="8043">
                        <img src="https://teslapartsukraine.com.ua/1c_image/radiator.jpg">
                    </div>
                </body>
            </html>
            HTML;
    }

    private function palladiumPageWithSuspensionLink(): string
    {
        return <<<'HTML'
            <html>
                <body>
                    <div id="content">
                        <div class="refine-categories">
                            <a href="https://teslapartsukraine.com.ua/31-pidviska-31-suspension-4">31 - SUSPENSION</a>
                        </div>
                    </div>
                    <div class="product-thumb">
                        <div class="name">
                            <a href="https://teslapartsukraine.com.ua/tesla-model-x?product_id=8043&amp;limit=10000">Радіатор охолодження основний Tesla Model X PLAID аналог</a>
                        </div>
                        <span>Артикул:</span> <span>6007372-00-A</span>
                        <span class="price-normal">65.00 $</span>
                        <input type="hidden" name="product_id" value="8043">
                        <img src="https://teslapartsukraine.com.ua/1c_image/radiator.jpg">
                    </div>
                </body>
            </html>
            HTML;
    }

    private function suspensionPageWithFrontSuspensionLink(): string
    {
        return <<<'HTML'
            <html>
                <body>
                    <div id="content">
                        <div class="refine-categories">
                            <a href="https://teslapartsukraine.com.ua/3101-perednya-pidviska-vklyuchayuchy-stupyci-3101-front-suspension-including-hubs-4">3101 - Front Suspension including Hubs</a>
                        </div>
                    </div>
                    <div class="product-thumb">
                        <div class="name">
                            <a href="https://teslapartsukraine.com.ua/tesla-model-x?product_id=8043&amp;limit=10000">Радіатор охолодження основний Tesla Model X PLAID аналог</a>
                        </div>
                        <span>Артикул:</span> <span>6007372-00-A</span>
                        <span class="price-normal">65.00 $</span>
                        <input type="hidden" name="product_id" value="8043">
                        <img src="https://teslapartsukraine.com.ua/1c_image/radiator.jpg">
                    </div>
                </body>
            </html>
            HTML;
    }
}
