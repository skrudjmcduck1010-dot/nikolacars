<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\PartCatalogController;
use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Models\PartCatalogItemOccurrence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DrivePartsCatalogCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_shows_items_linked_by_raw_card_compatibility_url(): void
    {
        $model = PartCatalogCategory::query()->create([
            'source' => 'driveparts',
            'source_url' => 'https://drive-parts.com.ua/model-s1-2012-2016/',
            'depth' => 0,
            'name' => 'Model S 02.2012-03.2016',
            'model_label' => 'Model S 02.2012-03.2016',
            'model_name' => 'Model S',
        ]);
        $main = PartCatalogCategory::query()->create([
            'source' => 'driveparts',
            'source_url' => 'https://drive-parts.com.ua/model-s1-2012-2016/10-body/',
            'parent_id' => $model->id,
            'depth' => 1,
            'code' => '10',
            'name' => 'Body',
            'model_label' => 'Model S 02.2012-03.2016',
            'model_name' => 'Model S',
        ]);
        $subcategory = PartCatalogCategory::query()->create([
            'source' => 'driveparts',
            'source_url' => 'https://drive-parts.com.ua/model-s1-2012-2016/10-body/1001-bumper-and-fascia/',
            'parent_id' => $main->id,
            'depth' => 2,
            'code' => '1001',
            'name' => 'Bumper and Fascia',
            'model_label' => 'Model S 02.2012-03.2016',
            'model_name' => 'Model S',
        ]);
        $category = PartCatalogCategory::query()->create([
            'source' => 'driveparts',
            'source_url' => 'https://drive-parts.com.ua/model-s1-2012-2016/10-body/1001-bumper-and-fascia/front-bumper-fascia/',
            'parent_id' => $subcategory->id,
            'depth' => 3,
            'name' => 'Front Bumper Fascia',
            'name_en' => 'Front Bumper Fascia',
            'model_label' => 'Model S 02.2012-03.2016',
            'model_name' => 'Model S',
        ]);

        $item = PartCatalogItem::query()->create([
            'source' => 'driveparts',
            'source_url' => 'https://drive-parts.com.ua/1003124-00-f-product/',
            'part_number' => '1003124-00-F',
            'name' => 'Fog lamp bracket Tesla Model S',
            'model_label' => 'Model S1 2012-2016',
            'model_name' => 'Model S1',
            'node_name' => 'Front Bumper Fascia',
            'raw_attributes' => [
                'category_source_url' => 'https://drive-parts.com.ua/ru/model-s1-2012-2016/10-body/1001-bumper-and-fascia/front-bumper-fascia/',
                'compatibility_paths' => [[
                    'model' => 'Model S (2012-2016)',
                    'path' => 'Model S1 2012-2016/10 - Body/1001 - Bumper and Fascia/Front Bumper Fascia',
                    'url' => 'https://drive-parts.com.ua/model-s1-2012-2016/10-body/1001-bumper-and-fascia/front-bumper-fascia/',
                ]],
            ],
        ]);

        $controller = app(PartCatalogController::class);
        $branchIdsMethod = new \ReflectionMethod($controller, 'categoryBranchIds');
        $branchIdsMethod->setAccessible(true);
        $filterMethod = new \ReflectionMethod($controller, 'whereInSelectedCatalogBranch');
        $filterMethod->setAccessible(true);

        $query = PartCatalogItem::query()->where('source', 'driveparts');
        $filterMethod->invoke($controller, $query, $category, $branchIdsMethod->invoke($controller, $category));

        $this->assertSame([$item->id], $query->pluck('id')->all());
    }

    public function test_category_shows_items_linked_by_driveparts_occurrences(): void
    {
        $category = PartCatalogCategory::query()->create([
            'source' => 'driveparts',
            'source_url' => 'https://drive-parts.com.ua/model-y2-2025/15-interior-trim/1511-trunk-trim/trunk-interior/',
            'depth' => 3,
            'name' => 'Trunk Interior',
            'model_label' => 'Model Y Juniper 02.2025 -',
            'model_name' => 'Model Y',
        ]);
        $item = PartCatalogItem::query()->create([
            'source' => 'driveparts',
            'source_url' => 'https://drive-parts.com.ua/1098408-00-b-clip/',
            'part_number' => '1098408-00-B',
            'name' => 'Interior trim clip',
            'part_catalog_category_id' => null,
        ]);
        PartCatalogItemOccurrence::query()->create([
            'part_catalog_item_id' => $item->id,
            'part_catalog_category_id' => $category->id,
            'source' => 'driveparts',
            'occurrence_key' => sha1('driveparts|'.$item->id.'|'.$category->id),
            'product_url' => $item->source_url,
            'part_number' => $item->part_number,
            'name' => $item->name,
        ]);

        $controller = app(PartCatalogController::class);
        $branchIdsMethod = new \ReflectionMethod($controller, 'categoryBranchIds');
        $branchIdsMethod->setAccessible(true);
        $filterMethod = new \ReflectionMethod($controller, 'whereInSelectedCatalogBranch');
        $filterMethod->setAccessible(true);

        $query = PartCatalogItem::query()->where('source', 'driveparts');
        $filterMethod->invoke($controller, $query, $category, $branchIdsMethod->invoke($controller, $category));

        $this->assertSame([$item->id], $query->pluck('id')->all());
    }
}
