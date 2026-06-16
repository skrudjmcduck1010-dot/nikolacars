<?php

namespace Tests\Feature;

use App\Models\PartCatalogCategory;
use App\Services\PartCatalogCategoryRouteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartCatalogCategoryRouteServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_path_uses_tcars_source_url_path(): void
    {
        $category = PartCatalogCategory::query()->create([
            'source' => 'tcarservice',
            'source_url' => 'https://tcarservice.com/zapchasty/model-3-326/body/front-bumper/',
            'name' => 'Front bumper',
        ]);

        $this->assertSame(
            'model-3-326/body/front-bumper',
            app(PartCatalogCategoryRouteService::class)->catalogPath($category)
        );
    }

    public function test_synthetic_catalog_path_reuses_matching_tcars_segments(): void
    {
        $tcarsModel = PartCatalogCategory::query()->create([
            'source' => 'tcarservice',
            'source_url' => 'https://tcarservice.com/zapchasty/model-3-326/',
            'name' => 'Model 3',
            'model_label' => 'Model 3',
            'depth' => 0,
        ]);
        PartCatalogCategory::query()->create([
            'source' => 'tcarservice',
            'parent_id' => $tcarsModel->id,
            'source_url' => 'https://tcarservice.com/zapchasty/model-3-326/body/front-bumper/',
            'name' => 'Front bumper',
            'code' => '10',
            'model_label' => 'Model 3',
            'depth' => 1,
        ]);
        $officialModel = PartCatalogCategory::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/model-3',
            'name' => 'Model 3 official',
            'model_label' => 'Model 3',
            'depth' => 0,
        ]);
        $officialBody = PartCatalogCategory::query()->create([
            'source' => 'tesla_official',
            'parent_id' => $officialModel->id,
            'source_url' => 'https://parts.tesla.com/model-3/body',
            'name' => 'Body official',
            'code' => '10',
            'model_label' => 'Model 3',
            'depth' => 1,
        ]);

        $this->assertSame(
            'model-3-326/front-bumper',
            app(PartCatalogCategoryRouteService::class)->catalogPath($officialBody)
        );
    }

    public function test_tesla_official_catalog_path_can_keep_legacy_slug_segments(): void
    {
        $tcarsModel = PartCatalogCategory::query()->create([
            'source' => 'tcarservice',
            'source_url' => 'https://tcarservice.com/zapchasty/model-y-327/',
            'name' => 'Model Y',
            'model_label' => 'Model Y',
            'depth' => 0,
        ]);
        PartCatalogCategory::query()->create([
            'source' => 'tcarservice',
            'parent_id' => $tcarsModel->id,
            'source_url' => 'https://tcarservice.com/zapchasty/model-y-327/body/',
            'name' => 'Body',
            'code' => '10',
            'model_label' => 'Model Y',
            'depth' => 1,
        ]);
        $officialModel = PartCatalogCategory::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/model-y',
            'name' => 'Model Y official',
            'model_label' => 'Model Y',
            'depth' => 0,
        ]);
        $officialBody = PartCatalogCategory::query()->create([
            'source' => 'tesla_official',
            'parent_id' => $officialModel->id,
            'source_url' => 'https://parts.tesla.com/model-y/body',
            'name' => 'Body official',
            'code' => '10',
            'model_label' => 'Model Y',
            'depth' => 1,
        ]);

        $this->assertSame(
            str($officialModel->name)->slug().'-'.$officialModel->id.'/'.str($officialBody->code.' '.$officialBody->name)->slug().'-'.$officialBody->id,
            app(PartCatalogCategoryRouteService::class)->catalogPath($officialBody, false)
        );
        $this->assertSame(
            0,
            app(PartCatalogCategoryRouteService::class)->categoryIdByCatalogPath('tesla_official', 'model-y-327/body', false)
        );
    }

    public function test_category_id_by_catalog_path_maps_tcars_path_to_source_category(): void
    {
        $tcarsModel = PartCatalogCategory::query()->create([
            'source' => 'tcarservice',
            'source_url' => 'https://tcarservice.com/zapchasty/model-3-326/',
            'name' => 'Model 3',
            'model_label' => 'Model 3',
            'depth' => 0,
        ]);
        PartCatalogCategory::query()->create([
            'source' => 'tcarservice',
            'parent_id' => $tcarsModel->id,
            'source_url' => 'https://tcarservice.com/zapchasty/model-3-326/body/front-bumper/',
            'name' => 'Front bumper',
            'code' => '10',
            'model_label' => 'Model 3',
            'depth' => 1,
        ]);
        $official = PartCatalogCategory::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/model-3/body',
            'name' => 'Body official',
            'code' => '10',
            'model_label' => 'Model 3',
            'depth' => 1,
        ]);

        $this->assertSame(
            $official->id,
            app(PartCatalogCategoryRouteService::class)->categoryIdByCatalogPath('tesla_official', 'model-3-326/front-bumper')
        );
    }

    public function test_category_url_falls_back_to_index_when_catalog_has_no_category_route(): void
    {
        $category = PartCatalogCategory::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/model-3',
            'name' => 'Model 3',
            'model_label' => 'Model 3',
            'depth' => 0,
        ]);

        $url = app(PartCatalogCategoryRouteService::class)->categoryUrl(
            $category,
            [
                'route_prefix' => 'admin.tesla-official-catalog',
                'has_category_route' => false,
            ],
            ['Model 3'],
            true,
        );

        $this->assertStringContainsString('/admin/tesla-official-catalog', $url);
        $this->assertStringContainsString('category_id='.$category->id, $url);
        $this->assertStringContainsString('models%5B0%5D=Model+3', $url);
        $this->assertStringContainsString('include_cybertruck=1', $url);
    }
}
