<?php

namespace Tests\Feature;

use App\Models\PartCatalogItem;
use App\Services\TeslaOfficialLocalizedNameSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeslaOfficialLocalizedNameSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_official_item_names_stay_static_when_created_after_competitor_item(): void
    {
        PartCatalogItem::query()->create([
            'source' => 'tcarservice',
            'source_url' => 'https://tcarservice.com/part',
            'part_number' => '1002032',
            'name' => "\u{041A}\u{0440}\u{0438}\u{0448}\u{043A}\u{0430} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{044F}",
            'name_ru' => "\u{041A}\u{0440}\u{044B}\u{0448}\u{043A}\u{0430} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{044F}\u{044F}",
            'name_ua' => "\u{041A}\u{0440}\u{0438}\u{0448}\u{043A}\u{0430} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{044F}",
        ]);

        $officialItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/catalogs/1002032-S0-B',
            'part_number' => '1002032-S0-B',
            'name' => 'English official name',
        ]);

        $officialItem->refresh();

        $this->assertNull($officialItem->name_ru);
        $this->assertNull($officialItem->name_ua);
        $this->assertArrayNotHasKey('name_source_type_ru', $officialItem->raw_attributes ?? []);
    }

    public function test_explicit_sync_service_call_does_not_change_official_names(): void
    {
        $officialItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/catalogs/1002034-S0-B',
            'part_number' => '1002034-S0-B',
            'name' => 'English official name',
            'name_ru' => 'Static RU',
        ]);

        $competitorItem = PartCatalogItem::query()->create([
            'source' => 'erazborka',
            'source_url' => 'https://erazborka.com.ua/catalog/part',
            'part_number' => '1002034',
            'name' => "\u{041D}\u{043E}\u{0432}\u{0430} \u{043D}\u{0430}\u{0437}\u{0432}\u{0430}",
            'name_ru' => "\u{041D}\u{043E}\u{0432}\u{043E}\u{0435} \u{043D}\u{0430}\u{0437}\u{0432}\u{0430}\u{043D}\u{0438}\u{0435}",
            'name_ua' => "\u{041D}\u{043E}\u{0432}\u{0430} \u{043D}\u{0430}\u{0437}\u{0432}\u{0430}",
        ]);

        $stats = app(TeslaOfficialLocalizedNameSyncService::class)->syncForCompetitorItem($competitorItem);

        $officialItem->refresh();

        $this->assertSame('Static RU', $officialItem->name_ru);
        $this->assertNull($officialItem->name_ua);
        $this->assertSame(0, $stats['official_items_updated']);
    }
}
