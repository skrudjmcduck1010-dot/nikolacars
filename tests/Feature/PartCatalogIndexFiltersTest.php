<?php

namespace Tests\Feature;

use App\Services\PartCatalogIndexFilters;
use Illuminate\Http\Request;
use Tests\TestCase;

class PartCatalogIndexFiltersTest extends TestCase
{
    public function test_from_request_normalizes_catalog_filters(): void
    {
        $request = Request::create('/admin/parts', 'GET', [
            'q' => '  bumper  ',
            'models' => ['Model 3', 'Unknown', 'Cybertruck'],
            'missing_names' => ['ru', 'bad'],
            'product_filters' => ['errors', 'bad'],
            'catalog_items_price_sort' => 'asc',
            'competitor_sort' => 'price',
            'competitor_direction' => 'desc',
            'catalog_image_filter' => 'with',
            'competitor_name_filter' => 'missing_ru',
            'tesla_check_filter' => 'exact',
            'tesla_visual_filter' => 'scheme',
            'sort' => 'price',
            'direction' => 'desc',
            'vins' => ['  VIN-1  ', '', 'VIN-2', 'VIN-1'],
            'name_source' => 'aleto',
        ]);

        $filters = PartCatalogIndexFilters::fromRequest(
            $request,
            ['Model 3', 'Cybertruck'],
        );

        $this->assertSame('bumper', $filters->query);
        $this->assertTrue($filters->modelFilterSubmitted);
        $this->assertSame(['Model 3', 'Cybertruck'], $filters->requestedModels);
        $this->assertSame(['Model 3'], $filters->selectedModels);
        $this->assertSame(['Model 3', 'Cybertruck'], $filters->filterModels);
        $this->assertSame(['Model 3'], $filters->urlModels);
        $this->assertSame('Model 3', $filters->model);
        $this->assertTrue($filters->includeCybertruck);
        $this->assertSame(['ru'], $filters->missingNames);
        $this->assertSame(['errors'], $filters->productFilters);
        $this->assertSame('asc', $filters->catalogItemsPriceSort);
        $this->assertSame('price', $filters->competitorSort);
        $this->assertSame('desc', $filters->competitorSortDirection);
        $this->assertSame('with', $filters->catalogImageFilter);
        $this->assertSame('missing_ru', $filters->competitorNameFilter);
        $this->assertSame('exact', $filters->teslaCheckFilter);
        $this->assertSame('scheme', $filters->teslaVisualFilter);
        $this->assertSame('price', $filters->nikolaCarsSort);
        $this->assertSame('desc', $filters->nikolaCarsSortDirection);
        $this->assertSame('VIN-1', $filters->nikolaCarsVin);
        $this->assertSame(['VIN-1', 'VIN-2'], $filters->nikolaCarsVins);
        $this->assertTrue($filters->hideNikolaCarsSold);
        $this->assertSame('aleto', $filters->nameSource);
        $this->assertFalse($filters->showCatalogItems);
        $this->assertTrue($filters->hasSourceCatalogItemRequest);
    }

    public function test_from_request_skips_default_models_without_submitted_filter(): void
    {
        $request = Request::create('/admin/parts', 'GET');

        $filters = PartCatalogIndexFilters::fromRequest(
            $request,
            ['Model S', 'Model 3'],
        );

        $this->assertFalse($filters->modelFilterSubmitted);
        $this->assertSame([], $filters->selectedModels);
        $this->assertSame([], $filters->filterModels);
        $this->assertSame([], $filters->urlModels);
        $this->assertSame('', $filters->model);
        $this->assertFalse($filters->showCatalogItems);
        $this->assertFalse($filters->hasSourceCatalogItemRequest);
        $this->assertTrue($filters->hideNikolaCarsSold);
    }

    public function test_from_request_keeps_legacy_single_vin_filter(): void
    {
        $request = Request::create('/admin/parts', 'GET', [
            'vin' => '  LEGACY-VIN  ',
        ]);

        $filters = PartCatalogIndexFilters::fromRequest(
            $request,
            ['Model S', 'Model 3'],
        );

        $this->assertSame('LEGACY-VIN', $filters->nikolaCarsVin);
        $this->assertSame(['LEGACY-VIN'], $filters->nikolaCarsVins);
    }

    public function test_from_request_allows_showing_nikolacars_sold_items(): void
    {
        $request = Request::create('/admin/parts', 'GET', [
            'hide_sold' => '0',
        ]);

        $filters = PartCatalogIndexFilters::fromRequest(
            $request,
            ['Model S', 'Model 3'],
        );

        $this->assertFalse($filters->hideNikolaCarsSold);
    }
}
