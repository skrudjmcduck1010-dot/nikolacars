<?php

namespace Tests\Feature;

use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Services\PartCatalogCsvExportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartCatalogCsvExportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_stream_exports_filtered_catalog_items_with_expected_columns(): void
    {
        $category = PartCatalogCategory::query()->create([
            'source' => 'tcarservice',
            'source_url' => 'https://example.test/categories/body',
            'name' => 'Body',
            'name_ru' => 'Кузов',
        ]);
        PartCatalogItem::query()->create([
            'part_catalog_category_id' => $category->id,
            'source' => 'tcarservice',
            'source_url' => 'https://example.test/items/door',
            'part_number' => '1491234-00-A',
            'name' => 'Door handle',
            'name_ru' => 'Ручка двери',
            'name_ua' => 'Ручка дверей',
            'model_label' => 'Model 3',
            'subcategory_name' => 'Doors',
            'node_name' => 'Front door',
            'price_amount' => 42.50,
            'currency' => 'USD',
            'availability' => 'In stock',
            'condition' => 'Used',
            'quality' => 'A',
        ]);
        PartCatalogItem::query()->create([
            'source' => 'teslacompany',
            'source_url' => 'https://example.test/items/other',
            'part_number' => 'OTHER',
            'name' => 'Other source',
        ]);

        ob_start();
        app(PartCatalogCsvExportService::class)->stream(
            'tcarservice',
            fn (Builder $builder, string $source) => $builder->where('source', $source),
            fn (PartCatalogItem $item) => $item->name,
            fn (PartCatalogCategory $category) => $category->name_ru ?: $category->name,
            fn (PartCatalogItem $item) => $item->condition ?: ''
        );
        $csv = (string) ob_get_clean();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('tcarservice;1491234-00-A;"Door handle"', $csv);
        $this->assertStringContainsString('Кузов', $csv);
        $this->assertStringContainsString('42.50;USD', $csv);
        $this->assertStringNotContainsString('OTHER', $csv);
    }
}
