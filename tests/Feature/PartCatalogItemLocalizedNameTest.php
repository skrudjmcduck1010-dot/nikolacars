<?php

namespace Tests\Feature;

use App\Models\PartCatalogItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartCatalogItemLocalizedNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_clears_ukrainian_name_when_it_matches_latin_source_name(): void
    {
        $item = PartCatalogItem::query()->create([
            'source' => 'driveparts',
            'source_url' => 'https://drive-parts.example/part',
            'part_number' => '1048647-00-B',
            'name' => 'NET PAD - 40X8X3.5MM',
            'name_ua' => 'NET PAD - 40X8X3.5MM',
        ]);

        $this->assertNull($item->refresh()->name_ua);
    }

    public function test_clears_ukrainian_name_when_it_matches_latin_english_name(): void
    {
        $item = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.example/part',
            'part_number' => '1104428-00-W',
            'name' => 'ASY,HVBAT,E1P,RWD,1PH',
            'name_en' => 'ASY,HVBAT,E1P,RWD,1PH',
            'name_ua' => 'ASY,HVBAT,E1P,RWD,1PH',
        ]);

        $this->assertNull($item->refresh()->name_ua);
    }

    public function test_keeps_ukrainian_name_with_latin_technical_terms(): void
    {
        $nameUa = $this->u('\u0410\u043d\u0442\u0435\u043d\u0430 GPS');

        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://inventory-product/1',
            'part_number' => '1004794-00-B',
            'name' => 'GPS antenna',
            'name_ua' => $nameUa,
        ]);

        $this->assertSame($nameUa, $item->refresh()->name_ua);
    }

    protected function u(string $value): string
    {
        return json_decode('"'.$value.'"', false, 512, JSON_THROW_ON_ERROR);
    }
}
