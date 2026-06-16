<?php

namespace Tests\Feature;

use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Services\DrivePartsCatalogImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DrivePartsCatalogImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_products_save_maps_card_compatibility_url_to_local_category(): void
    {
        $category = PartCatalogCategory::query()->create([
            'source' => 'driveparts',
            'source_url' => 'https://drive-parts.com.ua/model-s1-2012-2016/10-body/1001-bumper-and-fascia/front-bumper-fascia/',
            'depth' => 3,
            'name' => 'Облицовка переднего бампера',
            'name_en' => 'Front Bumper Fascia',
            'model_label' => 'Model S 02.2012-03.2016',
            'model_name' => 'Model S',
        ]);

        $importer = app(DrivePartsCatalogImporter::class);
        $method = new \ReflectionMethod($importer, 'saveAllProduct');
        $method->setAccessible(true);

        $item = $method->invoke($importer, [
            'source_url' => 'https://drive-parts.com.ua/1003124-00-f-product/',
            'part_number' => '1003124-00-F',
            'name' => 'Fog lamp bracket',
            'name_ru' => 'Кронштейн фары противотуманной левой',
            'name_ua' => 'Кронштейн фари протитуманної лівої',
            'price_amount' => 15,
            'currency' => 'USD',
            'model_label' => 'Model S 02.2012-03.2016',
            'model_name' => 'Model S',
            'main_category_code' => '10',
            'main_category_name' => 'Body',
            'subcategory_code' => '1001',
            'subcategory_name' => 'Bumper and Fascia',
            'node_name' => 'Front Bumper Fascia',
            'compatibility_paths' => [[
                'model' => 'Model S (2012-2016)',
                'path' => 'Model S1 2012-2016/10 - Body/1001 - Bumper and Fascia/Front Bumper Fascia',
                'url' => 'https://drive-parts.com.ua/model-s1-2012-2016/10-body/1001-bumper-and-fascia/front-bumper-fascia/',
            ]],
        ]);

        $this->assertSame($category->id, $item->part_catalog_category_id);
        $this->assertDatabaseHas('part_catalog_item_occurrences', [
            'part_catalog_item_id' => $item->id,
            'part_catalog_category_id' => $category->id,
            'source' => 'driveparts',
            'product_url' => 'https://drive-parts.com.ua/1003124-00-f-product/',
        ]);
    }

    public function test_all_products_save_creates_occurrences_for_every_compatibility_category(): void
    {
        $firstCategory = PartCatalogCategory::query()->create([
            'source' => 'driveparts',
            'source_url' => 'https://drive-parts.com.ua/model-3-1-2017-2023/15-interior-trim/1518-pillar-and-sill-trim/a-b-c-post-interior-trim/',
            'depth' => 3,
            'name' => 'A-B-C Post Interior Trim',
            'name_en' => 'A-B-C Post Interior Trim',
            'model_label' => 'Model 3 06.2017 - 12.2023',
            'model_name' => 'Model 3',
        ]);
        $secondCategory = PartCatalogCategory::query()->create([
            'source' => 'driveparts',
            'source_url' => 'https://drive-parts.com.ua/model-y2-2025/15-interior-trim/1511-trunk-trim/trunk-interior/',
            'depth' => 3,
            'name' => 'Trunk Interior',
            'name_en' => 'Trunk Interior',
            'model_label' => 'Model Y Juniper 02.2025 -',
            'model_name' => 'Model Y',
        ]);

        $importer = app(DrivePartsCatalogImporter::class);
        $method = new \ReflectionMethod($importer, 'saveAllProduct');
        $method->setAccessible(true);

        $item = $method->invoke($importer, [
            'source_url' => 'https://drive-parts.com.ua/1098408-00-b-clip/',
            'part_number' => '1098408-00-B',
            'name' => 'Interior trim clip',
            'price_amount' => 3,
            'currency' => 'USD',
            'model_label' => 'Model 3 06.2017 - 12.2023',
            'model_name' => 'Model 3',
            'main_category_code' => '15',
            'main_category_name' => 'Interior Trim',
            'subcategory_code' => '1518',
            'subcategory_name' => 'Pillar and Sill Trim',
            'node_name' => 'A-B-C Post Interior Trim',
            'compatibility_paths' => [
                [
                    'model' => 'Model 3 (2017-2023)',
                    'path' => 'Model 3-1 2017-2023/15 - Interior Trim/1518 - Pillar and Sill Trim/A-B-C Post Interior Trim',
                    'url' => 'https://drive-parts.com.ua/model-3-1-2017-2023/15-interior-trim/1518-pillar-and-sill-trim/a-b-c-post-interior-trim/',
                ],
                [
                    'model' => 'Model Y (2025-)',
                    'path' => 'Model Y2 2025-/15 - Interior Trim/1511 - Trunk Trim/Trunk Interior',
                    'url' => 'https://drive-parts.com.ua/model-y2-2025/15-interior-trim/1511-trunk-trim/trunk-interior/',
                ],
            ],
        ]);

        $this->assertSame($firstCategory->id, $item->part_catalog_category_id);
        $this->assertDatabaseHas('part_catalog_item_occurrences', [
            'part_catalog_item_id' => $item->id,
            'part_catalog_category_id' => $firstCategory->id,
            'source' => 'driveparts',
        ]);
        $this->assertDatabaseHas('part_catalog_item_occurrences', [
            'part_catalog_item_id' => $item->id,
            'part_catalog_category_id' => $secondCategory->id,
            'source' => 'driveparts',
        ]);
    }

    public function test_category_import_saves_direct_occurrences_from_paginated_category_pages(): void
    {
        $category = PartCatalogCategory::query()->create([
            'source' => 'driveparts',
            'source_url' => 'https://drive-parts.com.ua/model-s1-2012-2016/10-body/1001-bumper-and-fascia/front-bumper-fascia/',
            'depth' => 3,
            'name' => 'Front Bumper Fascia',
            'name_en' => 'Front Bumper Fascia',
            'model_label' => 'Model S 02.2012-03.2016',
            'model_name' => 'Model S',
        ]);

        Http::fake([
            'https://drive-parts.com.ua/model-s1-2012-2016/10-body/1001-bumper-and-fascia/front-bumper-fascia/' => Http::response($this->categoryListingHtml(
                '101',
                '/ru/1003124-00-f-product/',
                '1003124-00-F First listing',
                '<a class="next" href="/model-s1-2012-2016/10-body/1001-bumper-and-fascia/front-bumper-fascia/page-2/">next</a>'
            ), 200),
            'https://drive-parts.com.ua/model-s1-2012-2016/10-body/1001-bumper-and-fascia/front-bumper-fascia/page-2/' => Http::response($this->categoryListingHtml(
                '202',
                '/ru/1003126-00-c-product/',
                '1003126-00-C Second listing'
            ), 200),
            'https://drive-parts.com.ua/ru/1003124-00-f-product/' => Http::response('<h1>RU first name 1003124-00-F</h1>', 200),
            'https://drive-parts.com.ua/1003124-00-f-product/' => Http::response('<h1>UA first name 1003124-00-F</h1>', 200),
            'https://drive-parts.com.ua/ru/1003126-00-c-product/' => Http::response('<h1>RU second name 1003126-00-C</h1>', 200),
            'https://drive-parts.com.ua/1003126-00-c-product/' => Http::response('<h1>UA second name 1003126-00-C</h1>', 200),
        ]);

        $stats = app(DrivePartsCatalogImporter::class)->importProducts([
            'category' => (string) $category->id,
            'sleep_ms' => 0,
        ]);

        $this->assertSame(2, $stats['source_pages_fetched']);
        $this->assertSame(2, $stats['pages_scanned']);
        $this->assertSame(1, $stats['categories_scanned']);
        $this->assertSame(2, $stats['products_found']);
        $this->assertSame(2, $stats['products_saved']);
        $this->assertSame(2, $stats['product_category_occurrences_saved']);

        $items = PartCatalogItem::query()->where('source', 'driveparts')->pluck('id', 'part_number');

        $this->assertDatabaseHas('part_catalog_item_occurrences', [
            'part_catalog_item_id' => $items['1003124-00-F'],
            'part_catalog_category_id' => $category->id,
            'source' => 'driveparts',
            'page_url' => $category->source_url,
            'product_url' => 'https://drive-parts.com.ua/1003124-00-f-product/',
        ]);
        $this->assertDatabaseHas('part_catalog_item_occurrences', [
            'part_catalog_item_id' => $items['1003126-00-C'],
            'part_catalog_category_id' => $category->id,
            'source' => 'driveparts',
            'page_url' => $category->source_url,
            'product_url' => 'https://drive-parts.com.ua/1003126-00-c-product/',
        ]);
    }

    public function test_refresh_product_translations_can_update_only_missing_russian_names(): void
    {
        PartCatalogItem::query()->create([
            'source' => 'driveparts',
            'source_url' => 'https://drive-parts.com.ua/36750-olyva-transmisiina-sp-matic-4036-5l/',
            'part_number' => '36750',
            'name' => 'UA oil',
            'name_ua' => 'UA oil',
        ]);
        PartCatalogItem::query()->create([
            'source' => 'driveparts',
            'source_url' => 'https://drive-parts.com.ua/already-translated/',
            'part_number' => 'EXISTING',
            'name' => 'Existing',
            'name_ru' => 'Already translated',
            'name_ua' => 'Already translated UA',
        ]);

        Http::fake([
            'https://drive-parts.com.ua/ru/36750-olyva-transmisiina-sp-matic-4036-5l/' => Http::response('<h1>36750 '.$this->u('\u0420\u0423 \u043c\u0430\u0441\u043b\u043e').'</h1>', 200),
            'https://drive-parts.com.ua/36750-olyva-transmisiina-sp-matic-4036-5l/' => Http::response('uk page should not be fetched', 500),
            'https://drive-parts.com.ua/ru/already-translated/' => Http::response('existing ru should not be fetched', 500),
        ]);

        $stats = app(DrivePartsCatalogImporter::class)->refreshProductTranslations([
            'missing_ru_only' => true,
            'sleep_ms' => 0,
        ]);

        $this->assertSame(1, $stats['products_seen']);
        $this->assertSame(1, $stats['product_ru_pages_fetched']);
        $this->assertSame(0, $stats['product_ua_pages_fetched']);
        $this->assertSame(1, $stats['name_ru_updated']);
        $this->assertDatabaseHas('part_catalog_items', [
            'source_url' => 'https://drive-parts.com.ua/36750-olyva-transmisiina-sp-matic-4036-5l/',
            'name_ru' => $this->u('\u0420\u0423 \u043c\u0430\u0441\u043b\u043e'),
            'name_ua' => 'UA oil',
        ]);
    }

    public function test_category_import_keeps_shared_product_occurrences_in_every_category(): void
    {
        $firstCategory = PartCatalogCategory::query()->create([
            'source' => 'driveparts',
            'source_url' => 'https://drive-parts.com.ua/model-s1-2012-2016/10-body/1001-bumper-and-fascia/front-bumper-fascia/',
            'depth' => 3,
            'name' => 'Front Bumper Fascia',
            'model_label' => 'Model S 02.2012-03.2016',
            'model_name' => 'Model S',
        ]);
        $secondCategory = PartCatalogCategory::query()->create([
            'source' => 'driveparts',
            'source_url' => 'https://drive-parts.com.ua/model-s1-2012-2016/10-body/1001-bumper-and-fascia/front-bumper-fascia-carrier-and-attachments/',
            'depth' => 3,
            'name' => 'Front Bumper Fascia Carrier and Attachments',
            'model_label' => 'Model S 02.2012-03.2016',
            'model_name' => 'Model S',
        ]);

        Http::fake([
            $firstCategory->source_url => Http::response($this->categoryListingHtml(
                '101',
                '/ru/shared-product/',
                '1004537-00-A Shared listing'
            ), 200),
            $secondCategory->source_url => Http::response($this->categoryListingHtml(
                '202',
                '/ru/shared-product/',
                '1004537-00-A Shared listing'
            ), 200),
        ]);

        $stats = app(DrivePartsCatalogImporter::class)->importProducts([
            'rescan' => true,
            'skip_localized' => true,
            'sleep_ms' => 0,
        ]);

        $item = PartCatalogItem::query()
            ->where('source_url', 'https://drive-parts.com.ua/shared-product/')
            ->firstOrFail();

        $this->assertSame(2, $stats['products_found']);
        $this->assertSame(2, $stats['products_saved']);
        $this->assertSame(1, PartCatalogItem::query()->where('source', 'driveparts')->count());
        $this->assertDatabaseHas('part_catalog_item_occurrences', [
            'part_catalog_item_id' => $item->id,
            'part_catalog_category_id' => $firstCategory->id,
            'source' => 'driveparts',
        ]);
        $this->assertDatabaseHas('part_catalog_item_occurrences', [
            'part_catalog_item_id' => $item->id,
            'part_catalog_category_id' => $secondCategory->id,
            'source' => 'driveparts',
        ]);
    }

    public function test_all_products_import_fetches_product_card_only_for_new_items(): void
    {
        Storage::fake('public');

        PartCatalogItem::query()->create([
            'source' => 'driveparts',
            'source_url' => 'https://drive-parts.com.ua/existing-product/',
            'part_number' => '1111111-00-A',
            'name' => 'Old existing name',
            'price_amount' => 10,
            'currency' => 'USD',
            'availability' => 'Old availability',
            'condition' => 'Old condition',
            'raw_attributes' => [
                'driveparts_sku' => '1111111-00-A',
            ],
        ]);

        Http::fake([
            'https://drive-parts.com.ua/ru/vsi-tovary/' => Http::response($this->listingHtml(), 200),
            'https://drive-parts.com.ua/ru/catalog/load-additional-data/101' => Http::response($this->listingDetailsJson('В наличии', 'SKU-NEW', 'Б/У'), 200),
            'https://drive-parts.com.ua/ru/catalog/load-additional-data/202' => Http::response($this->listingDetailsJson('В наличии', '1111111-00-A', 'Б/У'), 200),
            'https://drive-parts.com.ua/ru/new-product/' => Http::response('<h1>RU new name SKU-NEW</h1>', 200),
            'https://drive-parts.com.ua/new-product/' => Http::response('<h1>UA new name SKU-NEW</h1><div>Артикул</div><div>SKU-NEW</div>', 200),
            'https://drive-parts.com.ua/ru/existing-product/' => Http::response('existing card should not be fetched', 500),
            'https://drive-parts.com.ua/existing-product/' => Http::response('existing card should not be fetched', 500),
            'https://drive-parts.com.ua/content/images/1/450x600l80mc100/new.jpg' => Http::response('image', 200, ['Content-Type' => 'image/jpeg']),
            'https://drive-parts.com.ua/content/images/1/450x600l80mc100/existing.jpg' => Http::response('image', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $stats = app(DrivePartsCatalogImporter::class)->importProducts([
            'all_products' => true,
            'sleep_ms' => 0,
        ]);

        $this->assertSame(2, $stats['products_found']);
        $this->assertSame(2, $stats['products_saved']);
        $this->assertSame(1, $stats['products_created']);
        $this->assertSame(1, $stats['products_updated']);
        $this->assertSame(1, $stats['product_ru_pages_fetched']);
        $this->assertSame(1, $stats['product_listing_extra_pages_fetched']);
        $this->assertSame(1, $stats['product_listing_extra_pages_skipped']);
        $this->assertSame(1, $stats['product_compatibility_pages_fetched']);
        $this->assertSame(1, $stats['product_detail_pages_skipped']);
        $this->assertSame(2, $stats['product_images_downloaded']);

        $this->assertDatabaseHas('part_catalog_items', [
            'source' => 'driveparts',
            'source_url' => 'https://drive-parts.com.ua/new-product/',
            'part_number' => 'SKU-NEW',
            'name_ru' => 'RU new name',
        ]);

        $this->assertDatabaseHas('part_catalog_items', [
            'source' => 'driveparts',
            'source_url' => 'https://drive-parts.com.ua/existing-product/',
            'part_number' => '1111111-00-A',
            'name' => 'Existing listing name',
            'availability' => 'Нет в наличии',
            'condition' => 'Old condition',
        ]);

        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://drive-parts.com.ua/ru/catalog/load-additional-data/202');

        $existing = PartCatalogItem::query()
            ->where('source_url', 'https://drive-parts.com.ua/existing-product/')
            ->firstOrFail();
        $this->assertSame('#767676', data_get($existing->raw_attributes, 'price_color'));
        $this->assertStringStartsWith('driveparts/part-images/111111100A/', (string) data_get($existing->raw_attributes, 'image_url'));
    }

    public function test_product_details_ignore_driveparts_placeholder_image(): void
    {
        $importer = app(DrivePartsCatalogImporter::class);
        $pageMethod = new \ReflectionMethod($importer, 'page');
        $pageMethod->setAccessible(true);
        $detailsMethod = new \ReflectionMethod($importer, 'productCardDetails');
        $detailsMethod->setAccessible(true);

        $html = <<<'HTML'
            <h1>1006275-00-A Brake pedal pin</h1>
            <img src="/content/images/23/310x268l85nn0/44052052692034.png">
        HTML;

        $details = $detailsMethod->invoke(
            $importer,
            $pageMethod->invoke($importer, $html),
            'https://drive-parts.com.ua/1006275-00-a-product/',
            $html
        );

        $this->assertArrayNotHasKey('image_url', $details);
        $this->assertArrayNotHasKey('image_urls', $details);
    }

    public function test_purge_placeholder_images_removes_duplicates_when_real_image_exists(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('driveparts/part-images/100627500A/44052052692034-abc123.webp', 'placeholder');
        Storage::disk('public')->put('driveparts/part-images/100627500A/real.webp', 'real');

        $item = PartCatalogItem::query()->create([
            'source' => 'driveparts',
            'source_url' => 'https://drive-parts.com.ua/1006275-00-a-product/',
            'part_number' => '1006275-00-A',
            'name' => 'Brake pedal pin',
            'raw_attributes' => [
                'image_url' => 'driveparts/part-images/100627500A/44052052692034-abc123.webp',
                'image_urls' => [
                    'driveparts/part-images/100627500A/44052052692034-abc123.webp',
                    'driveparts/part-images/100627500A/real.webp',
                ],
                'remote_image_url' => 'https://drive-parts.com.ua/content/images/23/310x268l85nn0/44052052692034.png',
                'remote_image_urls' => [
                    'https://drive-parts.com.ua/content/images/23/310x268l85nn0/44052052692034.png',
                    'https://drive-parts.com.ua/content/images/23/450x600l80mc100/real.jpg',
                ],
            ],
        ]);

        $stats = app(DrivePartsCatalogImporter::class)->purgePlaceholderImages();

        $item->refresh();

        $this->assertSame(1, $stats['items_seen']);
        $this->assertSame(1, $stats['items_updated']);
        $this->assertSame(4, $stats['image_references_removed']);
        $this->assertSame(1, $stats['files_deleted']);
        $this->assertFalse(Storage::disk('public')->exists('driveparts/part-images/100627500A/44052052692034-abc123.webp'));
        $this->assertSame('driveparts/part-images/100627500A/real.webp', data_get($item->raw_attributes, 'image_url'));
        $this->assertSame('https://drive-parts.com.ua/content/images/23/450x600l80mc100/real.jpg', data_get($item->raw_attributes, 'remote_image_url'));
    }

    public function test_purge_placeholder_images_replaces_only_placeholders_with_shared_placeholder(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('driveparts/part-images/100627500A/35938788866351-c446aac198.png', 'placeholder');

        $item = PartCatalogItem::query()->create([
            'source' => 'driveparts',
            'source_url' => 'https://drive-parts.com.ua/1006275-00-a-product/',
            'part_number' => '1006275-00-A',
            'name' => 'Brake pedal pin',
            'raw_attributes' => [
                'image_url' => 'driveparts/part-images/100627500A/35938788866351-c446aac198.png',
                'image_urls' => [
                    'driveparts/part-images/100627500A/35938788866351-c446aac198.png',
                ],
            ],
        ]);

        $stats = app(DrivePartsCatalogImporter::class)->purgePlaceholderImages();

        $item->refresh();

        $this->assertSame(1, $stats['items_seen']);
        $this->assertSame(1, $stats['items_updated']);
        $this->assertSame(2, $stats['image_references_removed']);
        $this->assertSame(1, $stats['files_deleted']);
        $this->assertSame(DrivePartsCatalogImporter::PLACEHOLDER_IMAGE_PATH, data_get($item->raw_attributes, 'image_url'));
        $this->assertSame([DrivePartsCatalogImporter::PLACEHOLDER_IMAGE_PATH], data_get($item->raw_attributes, 'image_urls'));
    }

    public function test_purge_placeholder_images_replaces_missing_driveparts_local_image_with_shared_placeholder(): void
    {
        Storage::fake('public');

        $item = PartCatalogItem::query()->create([
            'source' => 'driveparts',
            'source_url' => 'https://drive-parts.com.ua/missing-photo-product/',
            'part_number' => '1074907-00-F',
            'name' => 'Missing photo product',
            'raw_attributes' => [
                'image_url' => 'driveparts/part-images/107490700F/missing-local-image.png',
            ],
        ]);

        $stats = app(DrivePartsCatalogImporter::class)->purgePlaceholderImages();

        $item->refresh();

        $this->assertSame(1, $stats['items_seen']);
        $this->assertSame(1, $stats['items_updated']);
        $this->assertSame(1, $stats['image_references_removed']);
        $this->assertSame(DrivePartsCatalogImporter::PLACEHOLDER_IMAGE_PATH, data_get($item->raw_attributes, 'image_url'));
        $this->assertSame([DrivePartsCatalogImporter::PLACEHOLDER_IMAGE_PATH], data_get($item->raw_attributes, 'image_urls'));
    }

    public function test_purge_placeholder_images_collapses_shared_placeholder_with_old_placeholder_copy(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put(
            'driveparts/part-images/6654323T1A/76951182311577-1098b50f12.png',
            hash('sha256', '') === '' ? '' : 'placeholder'
        );

        $item = PartCatalogItem::query()->create([
            'source' => 'driveparts',
            'source_url' => 'https://drive-parts.com.ua/6654323-t1-a-sydinnia-vodiiske-tesla-model-y/',
            'part_number' => '6654323-T1-A',
            'name' => 'Seat',
            'raw_attributes' => [
                'image_url' => 'driveparts/part-images/6654323T1A/76951182311577-1098b50f12.png',
                'image_urls' => [
                    DrivePartsCatalogImporter::PLACEHOLDER_IMAGE_PATH,
                    'driveparts/part-images/6654323T1A/76951182311577-1098b50f12.png',
                ],
                'remote_image_urls' => [
                    'https://drive-parts.com.ua/content/images/14/310x268l85nn0/76951182311577.png',
                ],
            ],
        ]);

        $stats = app(DrivePartsCatalogImporter::class)->purgePlaceholderImages();

        $item->refresh();

        $this->assertSame(1, $stats['items_seen']);
        $this->assertSame(1, $stats['items_updated']);
        $this->assertSame(DrivePartsCatalogImporter::PLACEHOLDER_IMAGE_PATH, data_get($item->raw_attributes, 'image_url'));
        $this->assertSame([DrivePartsCatalogImporter::PLACEHOLDER_IMAGE_PATH], data_get($item->raw_attributes, 'image_urls'));
        $this->assertNull(data_get($item->raw_attributes, 'remote_image_urls'));
    }

    protected function listingHtml(): string
    {
        return <<<'HTML'
            <div class="j-product-container" data-id="101">
                <div class="catalogCard-title"><a href="/ru/new-product/" title="1234567-00-A New listing name">1234567-00-A New listing name</a></div>
                <img class="catalogCard-img" src="/content/images/1/small/new.jpg">
                <div class="catalogCard-price" style="color: #000;">$125</div>
            </div>
            <div class="j-product-container" data-id="202">
                <div class="catalogCard-title"><a href="/ru/existing-product/" title="1111111-00-A Existing listing name">1111111-00-A Existing listing name</a></div>
                <img class="catalogCard-img" src="/content/images/1/small/existing.jpg">
                <div class="catalogCard-price" style="color: #767676;">$25</div>
            </div>
        HTML;
    }

    protected function u(string $value): string
    {
        return json_decode('"'.$value.'"', true, 512, JSON_THROW_ON_ERROR);
    }

    protected function categoryListingHtml(string $id, string $href, string $title, string $pagination = ''): string
    {
        return <<<HTML
            <div class="j-product-container" data-id="{$id}">
                <div class="catalogCard-title"><a href="{$href}" title="{$title}">{$title}</a></div>
                <img class="catalogCard-img" src="/content/images/1/small/{$id}.jpg">
                <div class="catalogCard-price">\$15</div>
            </div>
            {$pagination}
        HTML;
    }

    protected function listingDetailsJson(string $availability, string $sku, string $condition): string
    {
        return json_encode([
            'response' => [
                'html' => <<<HTML
                    <div class="modification">
                        <div class="modification__title">Наличие</div>
                        <button class="modification__button modification__button--active">{$availability}</button>
                    </div>
                    <div class="modification">
                        <div class="modification__title">Артикул</div>
                        <button class="modification__button modification__button--active">{$sku}</button>
                    </div>
                    <div class="modification">
                        <div class="modification__title">Состояние</div>
                        <button class="modification__button modification__button--active">{$condition}</button>
                    </div>
                HTML,
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
