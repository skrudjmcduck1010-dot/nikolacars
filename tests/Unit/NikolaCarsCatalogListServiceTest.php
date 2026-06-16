<?php

namespace Tests\Unit;

use App\Models\PartCatalogItem;
use App\Models\Product;
use App\Services\NikolaCarsCatalogListService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class NikolaCarsCatalogListServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_added_today_count_counts_manual_creates_and_newly_checked_donor_parts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00', config('app.timezone')));

        try {
            $manualToday = $this->productCreatedAt('MANUAL-TODAY', '2026-06-16 00:05:00');
            $manualYesterday = $this->productCreatedAt('MANUAL-YESTERDAY', '2026-06-15 23:59:59');
            $ordinaryToday = $this->productCreatedAt('ORDINARY-TODAY', '2026-06-16 10:00:00');
            $checkedToday = $this->productCreatedAt('CHECKED-TODAY', '2026-06-01 10:00:00');
            $checkedYesterday = $this->productCreatedAt('CHECKED-YESTERDAY', '2026-06-01 10:00:00');
            $manualCheckedToday = $this->productCreatedAt('MANUAL-CHECKED-TODAY', '2026-06-16 08:00:00');

            $count = app(NikolaCarsCatalogListService::class)->addedTodayCount(collect([
                $this->itemForProduct($manualToday, ['manual_create_source_type' => 'purchase']),
                $this->itemForProduct($manualYesterday, ['manual_create_source_type' => 'purchase']),
                $this->itemForProduct($ordinaryToday),
                $this->itemForProduct($checkedToday, [
                    'source_type' => 'donor',
                    'donor_damage_status' => "\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}",
                    'donor_damage_checked_at' => '2026-06-16T12:00:00+03:00',
                ]),
                $this->itemForProduct($checkedYesterday, [
                    'source_type' => 'donor',
                    'donor_damage_status' => "\u{041B}\u{0435}\u{0433}\u{043A}\u{0438}\u{0435} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{044F}",
                    'donor_damage_checked_at' => '2026-06-15T12:00:00+03:00',
                ]),
                $this->itemForProduct($manualCheckedToday, [
                    'manual_create_source_type' => 'donor',
                    'source_type' => 'donor',
                    'donor_damage_status' => "\u{0421}\u{0438}\u{043B}\u{044C}\u{043D}\u{044B}\u{0435} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{044F}",
                    'donor_damage_checked_at' => '2026-06-16T12:00:00+03:00',
                ]),
                $this->unlinkedItemCreatedAt('2026-06-16 12:00:00'),
            ]));

            $this->assertSame(3, $count);
        } finally {
            Carbon::setTestNow();
        }
    }

    private function productCreatedAt(string $sku, string $timestamp): Product
    {
        $product = Product::query()->create([
            'sku' => $sku,
            'name' => $sku,
            'slug' => strtolower($sku),
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $product->forceFill([
            'created_at' => Carbon::parse($timestamp, config('app.timezone')),
            'updated_at' => Carbon::parse($timestamp, config('app.timezone')),
        ])->saveQuietly();

        return $product->refresh();
    }

    private function itemForProduct(Product $product, array $rawAttributes = []): PartCatalogItem
    {
        $item = new PartCatalogItem;
        $item->raw_attributes = ['product_id' => $product->id] + $rawAttributes;
        $item->quality = $rawAttributes['donor_damage_status'] ?? null;

        return $item;
    }

    private function unlinkedItemCreatedAt(string $timestamp): PartCatalogItem
    {
        $item = new PartCatalogItem;
        $item->created_at = Carbon::parse($timestamp, config('app.timezone'));

        return $item;
    }
}
