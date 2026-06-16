<?php

namespace Tests\Feature;

use App\Models\PartCatalogItem;
use App\Services\PartCatalogItemOrderingService;
use Tests\TestCase;

class PartCatalogItemOrderingServiceTest extends TestCase
{
    public function test_competitor_price_sort_places_null_prices_last_and_normalizes_direction(): void
    {
        $query = PartCatalogItem::query();

        app(PartCatalogItemOrderingService::class)->orderCompetitorCatalogItems($query, 'price', 'invalid');

        $this->assertStringContainsString('price_amount is null', $query->toSql());
        $this->assertStringContainsString('"price_amount" asc', $query->toSql());
        $this->assertStringContainsString('"id" asc', $query->toSql());
    }

    public function test_competitor_default_sort_uses_recent_items_first(): void
    {
        $query = PartCatalogItem::query();

        app(PartCatalogItemOrderingService::class)->orderCompetitorCatalogItems($query, 'unknown', 'asc');

        $this->assertStringContainsString('"created_at" desc', $query->toSql());
        $this->assertStringContainsString('"id" desc', $query->toSql());
    }

    public function test_nikola_cars_stock_sort_uses_available_quantity_expression(): void
    {
        $query = PartCatalogItem::query();

        app(PartCatalogItemOrderingService::class)->orderNikolaCarsItems($query, 'stock', 'desc');

        $this->assertStringContainsString("json_extract(raw_attributes, '$.stock_quantity')", $query->toSql());
        $this->assertStringContainsString("json_extract(raw_attributes, '$.reserved_quantity')", $query->toSql());
        $this->assertStringContainsString('case when', $query->toSql());
        $this->assertStringContainsString('desc', $query->toSql());
        $this->assertStringContainsString('"name_ua" asc', $query->toSql());
    }
}
