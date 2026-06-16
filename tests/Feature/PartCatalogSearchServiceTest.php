<?php

namespace Tests\Feature;

use App\Models\PartCatalogItem;
use App\Services\PartCatalogSearchService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartCatalogSearchServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_item_search_matches_compact_part_numbers_on_sqlite(): void
    {
        $matched = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://example.test/items/bumper',
            'part_number' => '1084171-00-E',
            'name' => 'Front Bumper Carrier',
        ]);
        PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://example.test/items/door',
            'part_number' => '1499999-00-A',
            'name' => 'Rear Door Trim',
        ]);

        $query = PartCatalogItem::query()->where('source', 'tesla_official');
        app(PartCatalogSearchService::class)->applyItemSearch($query, '108417100E', 'sqlite');

        $this->assertSame([$matched->id], $query->pluck('id')->all());
    }

    public function test_apply_item_search_matches_sqlite_raw_attribute_values(): void
    {
        $matched = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'https://example.test/items/vin',
            'part_number' => 'VIN-ITEM',
            'name' => 'Inventory item',
            'raw_attributes' => [
                'donor_vin' => '5YJ3E1EA7KF000001',
            ],
        ]);
        PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'https://example.test/items/other',
            'part_number' => 'OTHER',
            'name' => 'Other item',
            'raw_attributes' => [
                'donor_vin' => '5YJSA1E26HF000002',
            ],
        ]);

        $query = PartCatalogItem::query()->where('source', 'nikolacars');
        app(PartCatalogSearchService::class)->applyItemSearch($query, 'KF000001', 'sqlite');

        $this->assertSame([$matched->id], $query->pluck('id')->all());
    }

    public function test_suggestion_items_are_filtered_by_source_and_deduplicated(): void
    {
        $matched = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://example.test/items/official',
            'part_number' => '1084171-00-E',
            'name' => 'Front Bumper Carrier',
        ]);
        PartCatalogItem::query()->create([
            'source' => 'tcarservice',
            'source_url' => 'https://example.test/items/competitor',
            'part_number' => '1084171-00-E',
            'name' => 'Competitor Bumper',
        ]);

        $items = app(PartCatalogSearchService::class)->suggestionItems(
            'tesla_official',
            '108417100E',
            fn (Builder $builder, string $source): Builder => $builder->where('source', $source),
            'sqlite',
        );

        $this->assertCount(1, $items);
        $this->assertSame($matched->id, $items->first()->id);
    }
}
