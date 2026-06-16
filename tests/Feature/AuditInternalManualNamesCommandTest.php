<?php

namespace Tests\Feature;

use App\Models\PartCatalogItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditInternalManualNamesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_manual_name_mismatch_without_changing_rows(): void
    {
        $lockedAt = now()->subMinute();
        $official = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=1081421-E0-C',
            'part_number' => '1081421-E0-C',
            'name' => 'Official door',
            'name_ru' => 'Manual RU',
            'name_ru_manually_locked_at' => $lockedAt,
        ]);
        $nikolaCars = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://inventory-product/70282',
            'part_number' => '1081421-E0-C',
            'name' => 'NikolaCars door',
            'name_ru' => 'Old RU',
        ]);
        $competitor = PartCatalogItem::query()->create([
            'source' => 'tcarservice',
            'source_url' => 'https://tcarservice.example/1081421-e0-c',
            'part_number' => '1081421-E0-C',
            'name' => 'Competitor door',
            'name_ru' => 'Competitor RU',
        ]);

        $this->artisan('parts:audit-internal-manual-names', [
            '--dry-run' => true,
            '--part-number' => ['1081421-E0-C'],
            '--locale' => 'ru',
        ])
            ->expectsOutputToContain('out_of_sync')
            ->expectsOutputToContain('would_repair')
            ->assertExitCode(0);

        $this->assertSame('Manual RU', $official->refresh()->name_ru);
        $this->assertSame('Old RU', $nikolaCars->refresh()->name_ru);
        $this->assertNull(data_get($nikolaCars->raw_attributes, 'manual_name_locks.ru'));
        $this->assertSame('Competitor RU', $competitor->refresh()->name_ru);
    }

    public function test_repair_propagates_newest_non_empty_manual_name_to_exact_internal_matches_only(): void
    {
        $olderLockedAt = now()->subMinutes(5);
        $newerLockedAt = now()->subMinute();
        $olderOfficial = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=1127503-11-D',
            'part_number' => '1127503-11-D',
            'name' => 'Older official',
            'name_ru' => 'Older manual RU',
            'name_ru_manually_locked_at' => $olderLockedAt,
        ]);
        $newerNikolaCars = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://donor-product/74342',
            'part_number' => '1127503-11-D',
            'name' => 'NikolaCars part',
            'name_ru' => 'Newest manual RU',
            'raw_attributes' => [
                'manual_name_locks' => [
                    'ru' => $newerLockedAt->toDateTimeString(),
                ],
            ],
        ]);
        $unlockedOfficial = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=1127503-11-D&ref=duplicate',
            'part_number' => '1127503-11-D',
            'name' => 'Unlocked official',
            'name_ru' => 'Autofilled RU',
        ]);
        $baseOfficial = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=1127503',
            'part_number' => '1127503',
            'name' => 'Base official',
            'name_ru' => 'Base RU',
        ]);
        $competitor = PartCatalogItem::query()->create([
            'source' => 'driveparts',
            'source_url' => 'https://drive-parts.example/1127503-11-d',
            'part_number' => '1127503-11-D',
            'name' => 'Competitor',
            'name_ru' => 'Competitor RU',
        ]);

        $this->artisan('parts:audit-internal-manual-names', [
            '--repair' => true,
            '--part-number' => ['1127503-11-D'],
            '--locale' => 'ru',
        ])
            ->expectsOutputToContain('repaired')
            ->assertExitCode(0);

        foreach ([$olderOfficial, $newerNikolaCars, $unlockedOfficial] as $item) {
            $item->refresh();

            $this->assertSame('Newest manual RU', $item->name_ru);
            $this->assertNotNull(data_get($item->raw_attributes, 'manual_name_locks.ru'));
        }

        $this->assertSame('Base RU', $baseOfficial->refresh()->name_ru);
        $this->assertSame('Competitor RU', $competitor->refresh()->name_ru);
    }

    public function test_repair_propagates_ua_manual_name_independently_from_ru(): void
    {
        $lockedAt = now()->subMinute();
        $nikolaCars = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://inventory-product/80001',
            'part_number' => '1498787-00-A',
            'name' => 'NikolaCars item',
            'name_ru' => 'Existing RU',
            'name_ua' => 'Manual UA',
            'raw_attributes' => [
                'manual_name_locks' => [
                    'ua' => $lockedAt->toDateTimeString(),
                ],
            ],
        ]);
        $official = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=1498787-00-A',
            'part_number' => '1498787-00-A',
            'name' => 'Official item',
            'name_ru' => 'Official RU',
            'name_ua' => 'Old UA',
        ]);

        $this->artisan('parts:audit-internal-manual-names', [
            '--repair' => true,
            '--part-number' => ['1498787-00-A'],
            '--locale' => 'ua',
        ])->assertExitCode(0);

        $this->assertSame('Existing RU', $nikolaCars->refresh()->name_ru);
        $this->assertSame('Official RU', $official->refresh()->name_ru);
        $this->assertSame('Manual UA', $official->name_ua);
        $this->assertNotNull(data_get($official->raw_attributes, 'manual_name_locks.ua'));
    }
}
