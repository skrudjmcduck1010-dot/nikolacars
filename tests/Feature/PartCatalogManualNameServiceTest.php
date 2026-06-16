<?php

namespace Tests\Feature;

use App\Models\PartCatalogItem;
use App\Services\PartCatalogManualNameService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartCatalogManualNameServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_name_lock_clears_language_marker_conflict_for_updated_locale(): void
    {
        $item = PartCatalogItem::query()->create([
            'source' => 'tsk',
            'source_url' => 'https://tsk.ua/example',
            'part_number' => '1234567-00-A',
            'name' => 'Source name',
            'name_ru' => null,
            'name_ua' => null,
            'raw_attributes' => [
                'name_language_marker_conflict_ru' => [
                    'locale' => 'ua',
                    'count' => 1,
                    'markers' => ['ua-marker'],
                ],
                'name_language_marker_conflict_ua' => [
                    'locale' => 'ru',
                    'count' => 1,
                    'markers' => ['ru-marker'],
                ],
            ],
        ]);

        app(PartCatalogManualNameService::class)->lockItem($item, [
            'name_ru' => 'Manual RU',
        ]);

        $item->refresh();

        $this->assertSame('Manual RU', $item->name_ru);
        $this->assertNotNull(data_get($item->raw_attributes, 'manual_name_locks.ru'));
        $this->assertNull(data_get($item->raw_attributes, 'name_language_marker_conflict_ru'));
        $this->assertSame(1, data_get($item->raw_attributes, 'name_language_marker_conflict_ua.count'));
    }

    public function test_manual_name_lock_propagates_only_to_internal_exact_part_number_matches(): void
    {
        $sourceItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=1104422-10-K',
            'part_number' => '1104422-10-K',
            'name' => 'HV BATTERY',
            'name_ru' => 'Old official RU',
        ]);

        $donorItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-common://donor-product/110442210K',
            'part_number' => '1104422-10-K',
            'name' => 'Donor HV battery',
            'name_ru' => 'Old donor RU',
        ]);

        $nikolaCarsItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://inventory/1',
            'part_number' => '1104422-10-K',
            'name' => 'NikolaCars HV battery',
            'name_ru' => 'Old NikolaCars RU',
        ]);

        $basePartItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=1104422-10',
            'part_number' => '1104422-10',
            'name' => 'Base HV battery',
            'name_ru' => null,
        ]);

        $rootPartItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=1104422',
            'part_number' => '1104422',
            'name' => 'Root HV battery',
            'name_ru' => null,
        ]);

        $commonCompetitorRow = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-common://competitor-item/tcarservice/1',
            'part_number' => '1104422-10-K',
            'name' => 'Common competitor row',
            'name_ru' => 'Common competitor RU',
        ]);

        $competitorItem = PartCatalogItem::query()->create([
            'source' => 'tcarservice',
            'source_url' => 'https://tcarservice.com/1104422-10-k',
            'part_number' => '1104422-10-K',
            'name' => 'Competitor HV battery',
            'name_ru' => 'Competitor RU',
        ]);

        $counts = app(PartCatalogManualNameService::class)->lockAndPropagate($sourceItem, [
            'name_ru' => 'Manual RU',
        ]);

        $this->assertSame(3, $counts['name_ru']);
        $this->assertSame(0, $counts['name_ua']);

        foreach ([$sourceItem, $donorItem, $nikolaCarsItem] as $item) {
            $item->refresh();

            $this->assertSame('Manual RU', $item->name_ru);
            $this->assertNotNull(data_get($item->raw_attributes, 'manual_name_locks.ru'));
        }

        $this->assertNull($basePartItem->refresh()->name_ru);
        $this->assertNull($rootPartItem->refresh()->name_ru);
        $this->assertSame('Common competitor RU', $commonCompetitorRow->refresh()->name_ru);
        $this->assertSame('Competitor RU', $competitorItem->refresh()->name_ru);
    }

    public function test_manual_name_lock_from_nikolacars_propagates_to_internal_exact_matches(): void
    {
        $nikolaCarsItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://inventory/2',
            'part_number' => '1498787-00-A',
            'name' => 'NikolaCars item',
            'name_ru' => 'Old NikolaCars RU',
        ]);

        $officialItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=1498787-00-A',
            'part_number' => '1498787-00-A',
            'name' => 'Official item',
            'name_ru' => 'Old official RU',
        ]);

        $donorItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-common://donor-product/149878700A',
            'part_number' => '1498787-00-A',
            'name' => 'Donor item',
            'name_ru' => 'Old donor RU',
        ]);

        $competitorItem = PartCatalogItem::query()->create([
            'source' => 'driveparts',
            'source_url' => 'https://drive-parts.com.ua/1498787-00-a/',
            'part_number' => '1498787-00-A',
            'name' => 'DriveParts item',
            'name_ru' => 'DriveParts RU',
        ]);

        app(PartCatalogManualNameService::class)->lockAndPropagate($nikolaCarsItem, [
            'name_ru' => 'NikolaCars Manual RU',
        ]);

        $this->assertSame('NikolaCars Manual RU', $nikolaCarsItem->refresh()->name_ru);
        $this->assertSame('NikolaCars Manual RU', $officialItem->refresh()->name_ru);
        $this->assertSame('NikolaCars Manual RU', $donorItem->refresh()->name_ru);
        $this->assertSame('DriveParts RU', $competitorItem->refresh()->name_ru);
        $this->assertNotNull(data_get($officialItem->raw_attributes, 'manual_name_locks.ru'));
        $this->assertNotNull(data_get($donorItem->raw_attributes, 'manual_name_locks.ru'));
    }
}
