<?php

namespace Tests\Feature;

use App\Models\PartCatalogItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeslaOfficialLocalizedNameSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_official_item_gets_names_when_created_after_competitor_item(): void
    {
        $sourceItem = PartCatalogItem::query()->create([
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

        $this->assertSame("\u{041A}\u{0440}\u{044B}\u{0448}\u{043A}\u{0430} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{044F}\u{044F}", $officialItem->name_ru);
        $this->assertSame("\u{041A}\u{0440}\u{0438}\u{0448}\u{043A}\u{0430} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{044F}", $officialItem->name_ua);
        $this->assertSame('tesla_official_tcars_base_part_match', $officialItem->raw_attributes['name_source_type_ru']);
        $this->assertSame($sourceItem->id, $officialItem->raw_attributes['name_source_item_id_ua']);
    }

    public function test_competitor_item_creation_fills_existing_official_item(): void
    {
        $officialItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/catalogs/1002033-S0-B',
            'part_number' => '1002033-S0-B',
            'name' => 'English official name',
        ]);

        PartCatalogItem::query()->create([
            'source' => 'teslapartsukraine',
            'source_url' => 'https://teslapartsukraine.com.ua/product/part',
            'part_number' => '1002033',
            'name' => "\u{041A}\u{0440}\u{0438}\u{0448}\u{043A}\u{0430} \u{0437}\u{0430}\u{0434}\u{043D}\u{044F}",
            'name_ru' => "\u{041A}\u{0440}\u{044B}\u{0448}\u{043A}\u{0430} \u{0437}\u{0430}\u{0434}\u{043D}\u{044F}\u{044F}",
            'name_ua' => "\u{041A}\u{0440}\u{0438}\u{0448}\u{043A}\u{0430} \u{0437}\u{0430}\u{0434}\u{043D}\u{044F}",
        ]);

        $officialItem->refresh();

        $this->assertSame("\u{041A}\u{0440}\u{044B}\u{0448}\u{043A}\u{0430} \u{0437}\u{0430}\u{0434}\u{043D}\u{044F}\u{044F}", $officialItem->name_ru);
        $this->assertSame("\u{041A}\u{0440}\u{0438}\u{0448}\u{043A}\u{0430} \u{0437}\u{0430}\u{0434}\u{043D}\u{044F}", $officialItem->name_ua);
        $this->assertSame('tesla_official_teslapartsukraine_base_part_match', $officialItem->raw_attributes['name_source_type_ru']);
    }

    public function test_competitor_name_change_updates_auto_sourced_official_name(): void
    {
        $officialItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/catalogs/1002034-S0-B',
            'part_number' => '1002034-S0-B',
            'name' => 'English official name',
        ]);

        $sourceItem = PartCatalogItem::query()->create([
            'source' => 'erazborka',
            'source_url' => 'https://erazborka.com.ua/catalog/part',
            'part_number' => '1002034',
            'name' => "\u{0421}\u{0442}\u{0430}\u{0440}\u{0430} \u{043D}\u{0430}\u{0437}\u{0432}\u{0430}",
            'name_ru' => "\u{0421}\u{0442}\u{0430}\u{0440}\u{043E}\u{0435} \u{043D}\u{0430}\u{0437}\u{0432}\u{0430}\u{043D}\u{0438}\u{0435}",
            'name_ua' => "\u{0421}\u{0442}\u{0430}\u{0440}\u{0430} \u{043D}\u{0430}\u{0437}\u{0432}\u{0430}",
        ]);

        $sourceItem->forceFill([
            'name_ru' => "\u{041D}\u{043E}\u{0432}\u{043E}\u{0435} \u{043D}\u{0430}\u{0437}\u{0432}\u{0430}\u{043D}\u{0438}\u{0435}",
        ])->save();

        $officialItem->refresh();

        $this->assertSame("\u{041D}\u{043E}\u{0432}\u{043E}\u{0435} \u{043D}\u{0430}\u{0437}\u{0432}\u{0430}\u{043D}\u{0438}\u{0435}", $officialItem->name_ru);
        $this->assertSame("\u{0421}\u{0442}\u{0430}\u{0440}\u{0430} \u{043D}\u{0430}\u{0437}\u{0432}\u{0430}", $officialItem->name_ua);
    }

    public function test_manual_locked_official_name_is_not_changed_by_competitor_update(): void
    {
        $officialItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/catalogs/1002035-S0-B',
            'part_number' => '1002035-S0-B',
            'name' => 'English official name',
        ]);

        $sourceItem = PartCatalogItem::query()->create([
            'source' => 'dkparts',
            'source_url' => 'https://dk-parts.com.ua/part',
            'part_number' => '1002035',
            'name' => "\u{041F}\u{0435}\u{0440}\u{0432}\u{043E}\u{0435} \u{0438}\u{043C}\u{044F}",
            'name_ru' => "\u{041F}\u{0435}\u{0440}\u{0432}\u{043E}\u{0435} \u{0438}\u{043C}\u{044F}",
        ]);

        $officialItem->refresh();
        $officialItem->forceFill([
            'name_ru' => "\u{0420}\u{0443}\u{0447}\u{043D}\u{043E}\u{0435} \u{0438}\u{043C}\u{044F}",
            'name_ru_manually_locked_at' => now(),
        ])->save();

        $sourceItem->forceFill([
            'name_ru' => "\u{0418}\u{043C}\u{044F} \u{0438}\u{0437} \u{043A}\u{0430}\u{0442}\u{0430}\u{043B}\u{043E}\u{0433}\u{0430}",
        ])->save();

        $officialItem->refresh();

        $this->assertSame("\u{0420}\u{0443}\u{0447}\u{043D}\u{043E}\u{0435} \u{0438}\u{043C}\u{044F}", $officialItem->name_ru);
    }

    public function test_higher_priority_source_replaces_auto_sourced_lower_priority_name(): void
    {
        $officialItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/catalogs/1002036-S0-B',
            'part_number' => '1002036-S0-B',
            'name' => 'English official name',
        ]);

        PartCatalogItem::query()->create([
            'source' => 'stock-tesla',
            'source_url' => 'https://stock-tesla.com/part',
            'part_number' => '1002036',
            'name' => "\u{0418}\u{043C}\u{044F} Stock Tesla",
            'name_ru' => "\u{0418}\u{043C}\u{044F} Stock Tesla",
        ]);

        PartCatalogItem::query()->create([
            'source' => 'tcarservice',
            'source_url' => 'https://tcarservice.com/part',
            'part_number' => '1002036',
            'name' => "\u{0418}\u{043C}\u{044F} TCARS",
            'name_ru' => "\u{0418}\u{043C}\u{044F} TCARS",
        ]);

        $officialItem->refresh();

        $this->assertSame("\u{0418}\u{043C}\u{044F} TCARS", $officialItem->name_ru);
        $this->assertSame('tesla_official_tcars_base_part_match', $officialItem->raw_attributes['name_source_type_ru']);
    }

    public function test_common_name_without_explicit_localized_name_is_ignored(): void
    {
        $officialItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/catalogs/1002037-S0-B',
            'part_number' => '1002037-S0-B',
            'name' => 'English official name',
        ]);

        PartCatalogItem::query()->create([
            'source' => 'tsk',
            'source_url' => 'https://tsk.ua/part',
            'part_number' => '1002037',
            'name' => "\u{041A}\u{0440}\u{0438}\u{0448}\u{043A}\u{0430} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{044F}",
        ]);

        $officialItem->refresh();

        $this->assertNull($officialItem->name_ru);
        $this->assertNull($officialItem->name_ua);
    }
}
