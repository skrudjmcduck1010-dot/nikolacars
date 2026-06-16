<?php

namespace Tests\Feature;

use App\Models\PartCatalogItem;
use App\Models\TranslationLanguageMarker;
use App\Services\PartCatalogSourceStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartCatalogSourceStatsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_rebuild_counts_competitor_images_and_missing_names(): void
    {
        PartCatalogItem::query()->create([
            'source' => 'driveparts',
            'source_url' => 'https://example.test/with-image',
            'name' => 'With image',
            'name_ru' => 'RU',
            'name_ua' => 'UA',
            'raw_attributes' => [
                'image_urls' => ['driveparts/part-images/item.jpg'],
            ],
        ]);
        PartCatalogItem::query()->create([
            'source' => 'driveparts',
            'source_url' => 'https://example.test/without-image',
            'name' => 'Without image',
            'name_ua' => 'UA',
        ]);

        $counts = app(PartCatalogSourceStatsService::class)->countsFor('driveparts');

        $this->assertSame(['total' => 2, 'with' => 1, 'without' => 1], $counts['image']);
        $this->assertSame(1, $counts['name']['missing_ru']);
        $this->assertSame(0, $counts['name']['missing_ua']);
    }

    public function test_model_events_keep_counts_in_sync(): void
    {
        $service = app(PartCatalogSourceStatsService::class);
        $service->rebuild('teslacompany');

        $item = PartCatalogItem::query()->create([
            'source' => 'teslacompany',
            'source_url' => 'https://example.test/item',
            'name' => 'Item',
        ]);

        $counts = $service->countsFor('teslacompany');
        $this->assertSame(['total' => 1, 'with' => 0, 'without' => 1], $counts['image']);
        $this->assertSame(1, $counts['name']['missing_ru']);
        $this->assertSame(1, $counts['name']['missing_ua']);

        $item->update([
            'name_ru' => 'RU',
            'name_ua' => 'UA',
            'raw_attributes' => [
                'image_urls' => ['teslacompany/item.jpg'],
            ],
        ]);

        $counts = $service->countsFor('teslacompany');
        $this->assertSame(['total' => 1, 'with' => 1, 'without' => 0], $counts['image']);
        $this->assertSame(0, $counts['name']['missing_ru']);
        $this->assertSame(0, $counts['name']['missing_ua']);

        $item->delete();

        $counts = $service->countsFor('teslacompany');
        $this->assertSame(['total' => 0, 'with' => 0, 'without' => 0], $counts['image']);
    }

    public function test_counts_active_language_marker_conflicts(): void
    {
        TranslationLanguageMarker::query()->create([
            'ua_marker' => 'рос',
            'ru_marker' => 'укр',
        ]);

        PartCatalogItem::query()->create([
            'source' => 'tsk',
            'source_url' => 'https://example.test/conflict',
            'name' => 'Conflict',
            'name_ru' => 'RU',
            'name_ua' => 'UA',
            'raw_attributes' => [
                'name_language_marker_conflict_ru' => [
                    'count' => 1,
                    'markers' => ['укр'],
                ],
            ],
        ]);
        PartCatalogItem::query()->create([
            'source' => 'tsk',
            'source_url' => 'https://example.test/inactive',
            'name' => 'Inactive',
            'name_ru' => 'RU',
            'name_ua' => 'UA',
            'raw_attributes' => [
                'name_language_marker_conflict_ru' => [
                    'count' => 1,
                    'markers' => ['inactive'],
                ],
            ],
        ]);

        $counts = app(PartCatalogSourceStatsService::class)->countsFor('tsk');

        $this->assertSame(1, $counts['name']['conflict']);
    }
}
