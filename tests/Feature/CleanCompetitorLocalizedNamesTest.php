<?php

namespace Tests\Feature;

use App\Models\PartCatalogItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CleanCompetitorLocalizedNamesTest extends TestCase
{
    use RefreshDatabase;

    public function test_clears_competitor_name_that_points_to_another_source_item(): void
    {
        $drivePartsItem = PartCatalogItem::query()->create([
            'source' => 'driveparts',
            'source_url' => 'https://drive-parts.example/1738125-00-f',
            'part_number' => '1738125-00-F',
            'name' => 'DriveParts name',
            'name_ua' => $this->u('\u041f\u0435\u0440\u0435\u0434\u043d\u044f \u043a\u0430\u043c\u0435\u0440\u0430 Tesla Model 3R, SP, XP'),
        ]);

        $erazborkaItem = PartCatalogItem::query()->create([
            'source' => 'erazborka',
            'source_url' => 'https://erazborka.com.ua/catalog/part',
            'part_number' => '1738125-00-F',
            'name' => 'Erazborka name',
            'name_ua' => $drivePartsItem->name_ua,
            'raw_attributes' => [
                'source_url_ua' => 'https://erazborka.com.ua/ua/catalog/part',
                'name_source_url_ua' => $drivePartsItem->source_url,
                'name_source_site_ua' => 'drive-parts.example',
                'name_source_item_id_ua' => $drivePartsItem->id,
            ],
        ]);

        $this->artisan('catalog:clean-competitor-localized-names', [
            '--source' => 'erazborka',
            '--apply' => true,
        ])->assertSuccessful();

        $erazborkaItem->refresh();

        $this->assertNull($erazborkaItem->name_ua);
        $this->assertArrayNotHasKey('name_source_url_ua', $erazborkaItem->raw_attributes->getArrayCopy());
        $this->assertArrayNotHasKey('name_source_item_id_ua', $erazborkaItem->raw_attributes->getArrayCopy());
    }

    public function test_keeps_competitor_name_sourced_from_same_item(): void
    {
        $item = PartCatalogItem::query()->create([
            'source' => 'erazborka',
            'source_url' => 'https://erazborka.com.ua/catalog/part',
            'part_number' => '1738125-00-F',
            'name' => 'Erazborka name',
            'name_ua' => $this->u('\u041a\u043e\u0440\u043f\u0443\u0441 \u0434\u0437\u0435\u0440\u043a\u0430\u043b\u043e \u0437\u0430\u0434\u043d\u044c\u043e\u0433\u043e \u0432\u0438\u0434\u0443'),
            'raw_attributes' => [
                'source_url_ua' => 'https://erazborka.com.ua/ua/catalog/part',
            ],
        ]);

        $item->forceFill([
            'raw_attributes' => [
                'source_url_ua' => 'https://erazborka.com.ua/ua/catalog/part',
                'name_source_url_ua' => 'https://erazborka.com.ua/ua/catalog/part',
                'name_source_site_ua' => 'erazborka.com.ua',
                'name_source_item_id_ua' => $item->id,
            ],
        ])->save();

        $this->artisan('catalog:clean-competitor-localized-names', [
            '--source' => 'erazborka',
            '--apply' => true,
        ])->assertSuccessful();

        $item->refresh();

        $this->assertSame($this->u('\u041a\u043e\u0440\u043f\u0443\u0441 \u0434\u0437\u0435\u0440\u043a\u0430\u043b\u043e \u0437\u0430\u0434\u043d\u044c\u043e\u0433\u043e \u0432\u0438\u0434\u0443'), $item->name_ua);
        $this->assertSame($item->id, $item->raw_attributes['name_source_item_id_ua']);
    }

    protected function u(string $value): string
    {
        return json_decode('"'.$value.'"', false, 512, JSON_THROW_ON_ERROR);
    }
}
