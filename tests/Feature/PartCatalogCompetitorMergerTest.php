<?php

namespace Tests\Feature;

use Tests\TestCase;

class PartCatalogCompetitorMergerTest extends TestCase
{
    public function test_legacy_competitor_part_number_dedupe_command_is_disabled(): void
    {
        $this->artisan('parts:dedupe-competitor-part-numbers', [
            '--source' => 'dkparts',
        ])
            ->expectsOutput('Disabled: competitor catalog items are no longer merged by part number. Different product cards with the same part number stay as separate records.')
            ->assertSuccessful();
    }

    public function test_legacy_tesla_common_part_search_import_command_is_disabled(): void
    {
        $this->artisan('parts:import-tesla-official-search-exact', [
            '--part-number' => ['1005536-00-J'],
        ])
            ->expectsOutput('Disabled: common Tesla catalog and Tesla.com enrichment are frozen until the new rules are defined.')
            ->assertSuccessful();
    }
}
