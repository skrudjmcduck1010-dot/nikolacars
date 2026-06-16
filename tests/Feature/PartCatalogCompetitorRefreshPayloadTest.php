<?php

namespace Tests\Feature;

use App\Models\CompetitorCatalogRun;
use App\Models\PartCatalogItem;
use App\Models\ProductPriceHistory;
use App\Services\PartCatalogCompetitorRefreshPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartCatalogCompetitorRefreshPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_make_returns_default_payload_when_source_has_no_runs(): void
    {
        $payload = app(PartCatalogCompetitorRefreshPayload::class)->make(
            'tcarservice',
            7,
            11,
            fn (PartCatalogItem $item) => $item->name,
            fn () => 'admin.part-catalog',
        );

        $this->assertNotNull($payload);
        $this->assertNull($payload['status']);
        $this->assertFalse($payload['is_running']);
        $this->assertSame(0, $payload['progress_percent']);
        $this->assertSame(7, $payload['items_count']);
        $this->assertSame(11, $payload['total_products_count']);
        $this->assertSame([], $payload['created_catalog_items']);
        $this->assertSame([], $payload['price_changes']);
    }

    public function test_make_includes_progress_created_items_and_price_changes(): void
    {
        $startedAt = now()->subMinutes(10);
        $run = CompetitorCatalogRun::query()->create([
            'source' => 'tcarservice',
            'status' => 'running',
            'progress_current' => 3,
            'progress_total' => 6,
            'message' => 'Refreshing',
            'stats' => ['created' => 1],
            'started_at' => $startedAt,
        ]);
        $createdItem = PartCatalogItem::query()->create([
            'source' => 'tcarservice',
            'source_url' => 'https://example.test/items/new',
            'part_number' => 'NEW-001',
            'name' => 'New part',
        ]);
        $createdItem->forceFill(['created_at' => $startedAt->copy()->addMinute()])->save();
        $oldItem = PartCatalogItem::query()->create([
            'source' => 'tcarservice',
            'source_url' => 'https://example.test/items/old',
            'part_number' => 'OLD-001',
            'name' => 'Old part',
        ]);
        $oldItem->forceFill(['created_at' => $startedAt->copy()->subDay()])->save();
        ProductPriceHistory::query()->create([
            'part_catalog_item_id' => $createdItem->id,
            'source' => 'tcarservice',
            'old_price' => 10,
            'new_price' => 15,
            'currency' => 'USD',
            'changed_at' => $startedAt->copy()->addMinutes(2),
        ]);
        ProductPriceHistory::query()->create([
            'part_catalog_item_id' => $oldItem->id,
            'source' => 'tcarservice',
            'old_price' => 20,
            'new_price' => 25,
            'currency' => 'USD',
            'changed_at' => $startedAt->copy()->subDay(),
        ]);

        $payload = app(PartCatalogCompetitorRefreshPayload::class)->make(
            'tcarservice',
            9,
            13,
            fn (PartCatalogItem $item) => 'Display '.$item->part_number,
            fn () => 'admin.part-catalog',
            $run,
        );

        $this->assertSame('running', $payload['status']);
        $this->assertTrue($payload['is_running']);
        $this->assertSame(3, $payload['progress_current']);
        $this->assertSame(6, $payload['progress_total']);
        $this->assertSame(50, $payload['progress_percent']);
        $this->assertSame(1, $payload['created']);
        $this->assertSame(1, $payload['catalog_products_created']);
        $this->assertSame(1, $payload['prices_changed']);
        $this->assertCount(1, $payload['created_catalog_items']);
        $this->assertSame('NEW-001', $payload['created_catalog_items'][0]['part_number']);
        $this->assertStringContainsString((string) $createdItem->id, $payload['created_catalog_items'][0]['url']);
        $this->assertCount(1, $payload['price_changes']);
        $this->assertSame('15.00', (string) $payload['price_changes'][0]['new_price']);
        $this->assertStringContainsString((string) $createdItem->id, $payload['price_changes'][0]['url']);
    }

    public function test_make_can_skip_catalog_counts_for_running_refresh(): void
    {
        $run = CompetitorCatalogRun::query()->create([
            'source' => 'tcarservice',
            'status' => 'running',
            'progress_current' => 1,
            'progress_total' => 4,
            'started_at' => now()->subMinute(),
        ]);

        $payload = app(PartCatalogCompetitorRefreshPayload::class)->make(
            'tcarservice',
            null,
            null,
            fn (PartCatalogItem $item) => $item->name,
            fn () => 'admin.part-catalog',
            $run,
        );

        $this->assertSame('running', $payload['status']);
        $this->assertArrayNotHasKey('items_count', $payload);
        $this->assertArrayNotHasKey('total_products_count', $payload);
    }

    public function test_make_returns_null_for_unsupported_source(): void
    {
        $payload = app(PartCatalogCompetitorRefreshPayload::class)->make(
            'unsupported',
            0,
            0,
            fn (PartCatalogItem $item) => $item->name,
            fn () => 'admin.part-catalog',
        );

        $this->assertNull($payload);
    }
}
