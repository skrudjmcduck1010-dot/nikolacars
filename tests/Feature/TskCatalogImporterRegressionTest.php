<?php

namespace Tests\Feature;

use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Services\TskCatalogImporter;
use App\Services\TskCatalogProductUrlDedupeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TskCatalogImporterRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_details_keep_order_only_product_without_promo_price(): void
    {
        Http::fake([
            'https://tsk.ua/1006529-00-a/' => Http::response(<<<'HTML'
                <!doctype html>
                <html>
                    <head><meta property="og:image" content="https://tsk.ua/bolt.jpg"></head>
                    <body>
                        <div class="one-tovar">
                            <h1 class="one-tovar__name">Bolt M6X18</h1>
                            <div class="one-tovar__specif">
                                Модель авто:
                                <b>Model S, Model SR, Model X</b>
                            </div>
                            <a class="btn btn-black invert">Під замовлення</a>
                        </div>
                        <section>
                            <h2>Акційні товари</h2>
                            <div class="tovar-anons__price"><span>300 USD</span></div>
                            <div class="tovar-anons__nonal">В наявності</div>
                        </section>
                    </body>
                </html>
                HTML),
        ]);

        $details = app(TskCatalogImporter::class)->productDetails('https://tsk.ua/1006529-00-a/');

        $this->assertArrayNotHasKey('price_amount', $details);
        $this->assertArrayNotHasKey('currency', $details);
        $this->assertSame('Під замовлення', $details['availability']);
        $this->assertSame('Model S, Model SR, Model X', $details['compatibility_text']);
    }

    public function test_leaf_import_updates_existing_product_url_card_for_epc_rows(): void
    {
        $category = PartCatalogCategory::query()->create([
            'source' => 'tsk',
            'source_url' => 'https://tsk.ua/katalog-zapchastey296/front-bumper-fascia-carrier-dual-motor-2352/',
            'depth' => 1,
            'name' => 'Front bumper fascia carrier',
            'model_label' => 'Model 3',
            'products_scanned_at' => null,
        ]);
        $existing = PartCatalogItem::query()->create([
            'source' => 'tsk',
            'source_url' => 'https://tsk.ua/1006529-00-a/',
            'part_number' => '1006529-00-A',
            'name' => 'Bolt M6X18',
            'availability' => 'Під замовлення',
            'raw_attributes' => [
                'product_url' => 'https://tsk.ua/1006529-00-a/',
            ],
        ]);

        Http::fake([
            'https://tsk.ua/katalog-zapchastey296/front-bumper-fascia-carrier-dual-motor-2352/' => Http::response(<<<'HTML'
                <!doctype html>
                <html>
                    <body>
                        <table>
                            <tr>
                                <td>1</td>
                                <td>BOLT HF M6X18 PC88 MAT.</td>
                                <td>1006529-00-A</td>
                                <td>2</td>
                                <td><a href="/1006529-00-a/">Bolt M6X18</a></td>
                            </tr>
                        </table>
                    </body>
                </html>
                HTML),
            'https://tsk.ua/1006529-00-a/' => Http::response(<<<'HTML'
                <!doctype html>
                <html>
                    <body>
                        <div class="one-tovar">
                            <a class="btn btn-black invert">Під замовлення</a>
                        </div>
                        <section>
                            <h2>Акційні товари</h2>
                            <div class="tovar-anons__price"><span>300 USD</span></div>
                            <div class="tovar-anons__nonal">В наявності</div>
                        </section>
                    </body>
                </html>
                HTML),
        ]);

        $stats = app(TskCatalogImporter::class)->importLeafProducts([
            'sleep_ms' => 0,
        ]);

        $this->assertSame(1, $stats['products_saved']);
        $this->assertSame(0, $stats['products_created']);
        $this->assertSame(1, $stats['products_updated']);
        $this->assertDatabaseCount('part_catalog_items', 1);
        $this->assertDatabaseHas('part_catalog_items', [
            'id' => $existing->id,
            'source' => 'tsk',
            'source_url' => 'https://tsk.ua/1006529-00-a/',
            'part_number' => '1006529-00-A',
            'price_amount' => null,
            'currency' => null,
            'availability' => 'Під замовлення',
            'part_catalog_category_id' => $category->id,
        ]);
        $this->assertDatabaseHas('part_catalog_item_occurrences', [
            'part_catalog_item_id' => $existing->id,
            'part_catalog_category_id' => $category->id,
            'source' => 'tsk',
            'page_url' => $category->source_url,
            'product_url' => 'https://tsk.ua/1006529-00-a/',
            'part_number' => '1006529-00-A',
            'scheme_number' => 1,
            'quantity' => '2',
        ]);
    }

    public function test_leaf_import_can_be_limited_to_category_branch(): void
    {
        $parent = PartCatalogCategory::query()->create([
            'source' => 'tsk',
            'source_url' => 'https://tsk.ua/katalog-zapchastey296/1001-bumper-and-fascia-28/',
            'depth' => 2,
            'name' => 'Bumper And Fascia',
            'model_label' => 'Model S',
        ]);
        $leaf = PartCatalogCategory::query()->create([
            'parent_id' => $parent->id,
            'source' => 'tsk',
            'source_url' => 'https://tsk.ua/katalog-zapchastey296/front-bumper-carrier-13680/',
            'depth' => 3,
            'name' => 'Front Bumper Carrier',
            'model_label' => 'Model S',
        ]);
        $otherLeaf = PartCatalogCategory::query()->create([
            'source' => 'tsk',
            'source_url' => 'https://tsk.ua/katalog-zapchastey296/closure-panels-3069/',
            'depth' => 3,
            'name' => 'Closure Panels',
            'model_label' => 'Model S',
        ]);

        Http::fake([
            $leaf->source_url => Http::response(<<<'HTML'
                <!doctype html>
                <html><body>
                    <table><tr>
                        <td>1</td>
                        <td>FRONT END CARRIER</td>
                        <td>1061950-98-E</td>
                        <td>1</td>
                        <td><a href="/1061950-98-e/">Front End Carrier</a></td>
                    </tr></table>
                </body></html>
                HTML),
            'https://tsk.ua/1061950-98-e/' => Http::response('<html><body><div class="one-tovar"><h1 class="one-tovar__name">Front End Carrier</h1></div></body></html>'),
            $otherLeaf->source_url => Http::response(<<<'HTML'
                <!doctype html>
                <html><body>
                    <table><tr>
                        <td>1</td>
                        <td>CLOSURE PANEL</td>
                        <td>1111111-00-A</td>
                        <td>1</td>
                        <td><a href="/1111111-00-a/">Closure Panel</a></td>
                    </tr></table>
                </body></html>
                HTML),
        ]);

        $stats = app(TskCatalogImporter::class)->importLeafProducts([
            'category_id' => $parent->id,
            'sleep_ms' => 0,
        ]);

        $this->assertSame(1, $stats['leaf_categories_seen']);
        $this->assertDatabaseHas('part_catalog_items', [
            'source' => 'tsk',
            'part_number' => '1061950-98-E',
            'part_catalog_category_id' => $leaf->id,
        ]);
        $this->assertDatabaseMissing('part_catalog_items', [
            'source' => 'tsk',
            'part_number' => '1111111-00-A',
            'part_catalog_category_id' => $otherLeaf->id,
        ]);
    }

    public function test_leaf_import_reuses_existing_product_when_tsk_url_has_language_prefix(): void
    {
        $category = PartCatalogCategory::query()->create([
            'source' => 'tsk',
            'source_url' => 'https://tsk.ua/katalog-zapchastey296/front-bumper-fascia-carrier-dual-motor-2352/',
            'depth' => 1,
            'name' => 'Front bumper fascia carrier',
            'model_label' => 'Model 3',
            'products_scanned_at' => null,
        ]);
        $existing = PartCatalogItem::query()->create([
            'source' => 'tsk',
            'source_url' => 'https://tsk.ua/1006529-00-a/',
            'part_number' => '1006529-00-A',
            'name' => 'Bolt M6X18',
            'raw_attributes' => [
                'product_url' => 'https://tsk.ua/1006529-00-a/',
            ],
        ]);

        Http::fake([
            'https://tsk.ua/katalog-zapchastey296/front-bumper-fascia-carrier-dual-motor-2352/' => Http::response(<<<'HTML'
                <!doctype html>
                <html>
                    <body>
                        <table>
                            <tr>
                                <td>1</td>
                                <td>BOLT HF M6X18 PC88 MAT.</td>
                                <td>1006529-00-A</td>
                                <td>2</td>
                                <td><a href="/ru/1006529-00-a/">Bolt M6X18</a></td>
                            </tr>
                        </table>
                    </body>
                </html>
                HTML),
            'https://tsk.ua/1006529-00-a/' => Http::response(<<<'HTML'
                <!doctype html>
                <html>
                    <body>
                        <div class="one-tovar">
                            <a class="btn btn-black invert">Під замовлення</a>
                        </div>
                    </body>
                </html>
                HTML),
        ]);

        $stats = app(TskCatalogImporter::class)->importLeafProducts([
            'sleep_ms' => 0,
        ]);

        $this->assertSame(1, $stats['products_saved']);
        $this->assertSame(0, $stats['products_created']);
        $this->assertSame(1, $stats['products_updated']);
        $this->assertDatabaseCount('part_catalog_items', 1);
        $this->assertDatabaseHas('part_catalog_item_occurrences', [
            'part_catalog_item_id' => $existing->id,
            'product_url' => 'https://tsk.ua/1006529-00-a/',
        ]);
    }

    public function test_tsk_product_url_dedupe_merges_language_and_epc_duplicates_into_occurrences(): void
    {
        $category = PartCatalogCategory::query()->create([
            'source' => 'tsk',
            'source_url' => 'https://tsk.ua/katalog-zapchastey296/front-bumper-fascia-carrier-dual-motor-2352/',
            'depth' => 1,
            'name' => 'Front bumper fascia carrier',
        ]);
        $canonical = PartCatalogItem::query()->create([
            'source' => 'tsk',
            'source_url' => 'https://tsk.ua/1006529-00-a/',
            'part_number' => '1006529-00-A',
            'name' => 'Bolt M6X18',
            'model_label' => 'Model S 02.2012-03.2016',
            'compatibility_text' => 'Model S 02.2012-03.2016',
            'raw_attributes' => [
                'product_url' => 'https://tsk.ua/1006529-00-a/',
            ],
        ]);
        PartCatalogItem::query()->create([
            'part_catalog_category_id' => $category->id,
            'source' => 'tsk',
            'source_url' => 'tsk-epc:duplicate',
            'part_number' => '1006529-00-A',
            'name' => 'BOLT HF M6X18 PC88 MAT.',
            'model_label' => 'Model S2 04.2016-01.2021',
            'compatibility_text' => 'Model S2 04.2016-01.2021',
            'scheme_number' => 1,
            'raw_attributes' => [
                'page_url' => $category->source_url,
                'product_url' => 'https://tsk.ua/ru/1006529-00-a/',
                'quantity' => '2',
            ],
        ]);

        $stats = app(TskCatalogProductUrlDedupeService::class)->run();

        $this->assertSame(1, $stats['items_merged']);
        $this->assertDatabaseCount('part_catalog_items', 1);
        $this->assertDatabaseHas('part_catalog_items', [
            'id' => $canonical->id,
            'source_url' => 'https://tsk.ua/1006529-00-a/',
            'compatibility_text' => 'Model S, Model SR',
        ]);
        $this->assertDatabaseHas('part_catalog_item_occurrences', [
            'part_catalog_item_id' => $canonical->id,
            'part_catalog_category_id' => $category->id,
            'product_url' => 'https://tsk.ua/1006529-00-a/',
            'part_number' => '1006529-00-A',
            'scheme_number' => 1,
            'quantity' => '2',
        ]);
    }

    public function test_tsk_product_url_dedupe_merges_epc_rows_without_explicit_product_url_by_part_number(): void
    {
        $closurePanels = PartCatalogCategory::query()->create([
            'source' => 'tsk',
            'source_url' => 'https://tsk.ua/katalog-zapchastey296/closure-panels-3069/',
            'depth' => 1,
            'name' => 'Closure Panels',
            'model_label' => 'Model X 09.2015-02.2021',
        ]);
        $frontDoor = PartCatalogCategory::query()->create([
            'source' => 'tsk',
            'source_url' => 'https://tsk.ua/katalog-zapchastey296/front-door-hinges-and-fittings-2047/',
            'depth' => 1,
            'name' => 'Front Door Hinges and Fittings',
            'model_label' => 'Model X 09.2015-02.2021',
        ]);
        $first = PartCatalogItem::query()->create([
            'part_catalog_category_id' => $closurePanels->id,
            'source' => 'tsk',
            'source_url' => 'tsk-epc:first',
            'part_number' => '1466720-00-A',
            'name' => 'MX Front Door Patch Replacement Kit',
            'model_label' => 'Model X 09.2015-02.2021',
            'raw_attributes' => [
                'page_url' => $closurePanels->source_url,
                'quantity' => '1',
            ],
        ]);
        PartCatalogItem::query()->create([
            'part_catalog_category_id' => $frontDoor->id,
            'source' => 'tsk',
            'source_url' => 'tsk-epc:second',
            'part_number' => '1466720-00-A',
            'name' => 'MX FRONT DOOR PATCH REPLACEMENT KIT',
            'model_label' => 'Model X 09.2015-02.2021',
            'raw_attributes' => [
                'page_url' => $frontDoor->source_url,
                'quantity' => '1',
            ],
        ]);

        $stats = app(TskCatalogProductUrlDedupeService::class)->run();

        $this->assertSame(1, $stats['items_merged']);
        $this->assertDatabaseCount('part_catalog_items', 1);
        $this->assertDatabaseHas('part_catalog_items', [
            'id' => $first->id,
            'source_url' => 'https://tsk.ua/1466720-00-a/',
            'part_number' => '1466720-00-A',
        ]);
        $this->assertDatabaseHas('part_catalog_item_occurrences', [
            'part_catalog_item_id' => $first->id,
            'part_catalog_category_id' => $closurePanels->id,
            'product_url' => 'https://tsk.ua/1466720-00-a/',
            'part_number' => '1466720-00-A',
            'quantity' => '1',
        ]);
        $this->assertDatabaseHas('part_catalog_item_occurrences', [
            'part_catalog_item_id' => $first->id,
            'part_catalog_category_id' => $frontDoor->id,
            'product_url' => 'https://tsk.ua/1466720-00-a/',
            'part_number' => '1466720-00-A',
            'quantity' => '1',
        ]);
    }

    public function test_competitor_part_number_dedupe_keeps_same_source_products_separate(): void
    {
        $firstCategory = PartCatalogCategory::query()->create([
            'source' => 'driveparts',
            'source_url' => 'https://drive-parts.test/model-x/body/',
            'depth' => 1,
            'name' => 'Body',
            'model_label' => 'Model X',
        ]);
        $secondCategory = PartCatalogCategory::query()->create([
            'source' => 'driveparts',
            'source_url' => 'https://drive-parts.test/model-x/door/',
            'depth' => 1,
            'name' => 'Door',
            'model_label' => 'Model X',
        ]);
        $first = PartCatalogItem::query()->create([
            'part_catalog_category_id' => $firstCategory->id,
            'source' => 'driveparts',
            'source_url' => 'https://drive-parts.test/first/',
            'part_number' => '1466720-00-A',
            'name' => 'Patch kit',
            'price_amount' => 100,
            'currency' => 'USD',
            'model_label' => 'Model X',
        ]);
        PartCatalogItem::query()->create([
            'part_catalog_category_id' => $secondCategory->id,
            'source' => 'driveparts',
            'source_url' => 'https://drive-parts.test/second/',
            'part_number' => '146672000A',
            'name' => 'Patch kit alternate row',
            'price_amount' => 120,
            'currency' => 'USD',
            'model_label' => 'Model X',
        ]);
        PartCatalogItem::query()->create([
            'source' => 'dkparts',
            'source_url' => 'https://dk-parts.test/same-number/',
            'part_number' => '1466720-00-A',
            'name' => 'Same number from another competitor',
        ]);

        $this->artisan('parts:dedupe-competitor-part-numbers', [
            '--source' => 'driveparts',
        ])->assertSuccessful();

        $this->assertDatabaseCount('part_catalog_items', 3);
        $this->assertDatabaseHas('part_catalog_items', [
            'id' => $first->id,
            'source' => 'driveparts',
            'source_url' => 'https://drive-parts.test/first/',
            'part_number' => '1466720-00-A',
        ]);
        $this->assertDatabaseHas('part_catalog_items', [
            'source' => 'driveparts',
            'source_url' => 'https://drive-parts.test/second/',
            'part_number' => '146672000A',
        ]);
        $this->assertDatabaseHas('part_catalog_items', [
            'source' => 'dkparts',
            'part_number' => '1466720-00-A',
        ]);
        $this->assertDatabaseCount('part_catalog_item_occurrences', 0);
    }
}
