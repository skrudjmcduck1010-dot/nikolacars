<?php

namespace Tests\Feature;

use App\Models\PartCatalogItem;
use App\Services\PartCatalogFilterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartCatalogFilterServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_image_filter_counts_items_with_and_without_local_images(): void
    {
        PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://example.test/with-image',
            'name' => 'With image',
            'raw_attributes' => [
                'part_image_urls' => ['tesla-official/part-images/part.jpg'],
            ],
        ]);
        PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://example.test/without-image',
            'name' => 'Without image',
        ]);

        $service = app(PartCatalogFilterService::class);
        $query = PartCatalogItem::query()->where('source', 'tesla_official');

        $this->assertSame(['total' => 2, 'with' => 1, 'without' => 1], $service->catalogImageFilterCounts($query));

        $withImages = PartCatalogItem::query()->where('source', 'tesla_official');
        $service->applyCatalogImageFilter($withImages, 'with');
        $this->assertSame(['With image'], $withImages->pluck('name')->all());
    }

    public function test_tesla_check_filter_counts_statuses(): void
    {
        PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://example.test/exact',
            'name' => 'Exact',
            'raw_attributes' => [
                'tesla_part_search_checked_at' => '2026-05-01T10:00:00+00:00',
                'official_part_match_status' => 'exact',
            ],
        ]);
        PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://example.test/api-error',
            'name' => 'API error',
            'raw_attributes' => [
                'tesla_part_search_checked_at' => '2026-05-01T11:00:00+00:00',
                'official_part_match_status' => 'api_error',
            ],
        ]);
        PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://example.test/unchecked',
            'name' => 'Unchecked',
        ]);

        $counts = app(PartCatalogFilterService::class)->teslaCheckFilterCounts(
            PartCatalogItem::query()->where('source', 'tesla_official')
        );

        $this->assertSame(3, $counts['total']);
        $this->assertSame(1, $counts['checked']);
        $this->assertSame(1, $counts['unchecked']);
        $this->assertSame(1, $counts['exact']);
        $this->assertSame(1, $counts['api_error']);
    }

    public function test_competitor_name_filter_counts_missing_localized_names(): void
    {
        PartCatalogItem::query()->create([
            'source' => 'teslacompany',
            'source_url' => 'https://example.test/missing-ru',
            'name' => 'Missing RU',
            'name_ua' => 'UA',
        ]);
        PartCatalogItem::query()->create([
            'source' => 'teslacompany',
            'source_url' => 'https://example.test/missing-ua',
            'name' => 'Missing UA',
            'name_ru' => 'RU',
        ]);
        PartCatalogItem::query()->create([
            'source' => 'teslacompany',
            'source_url' => 'https://example.test/complete',
            'name' => 'Complete',
            'name_ru' => 'RU',
            'name_ua' => 'UA',
        ]);

        $counts = app(PartCatalogFilterService::class)->catalogNameFilterCounts(
            PartCatalogItem::query()->where('source', 'teslacompany')
        );

        $this->assertSame(1, $counts['missing_ru']);
        $this->assertSame(1, $counts['missing_ua']);
    }
}
