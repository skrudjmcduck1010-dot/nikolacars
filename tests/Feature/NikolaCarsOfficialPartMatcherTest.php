<?php

namespace Tests\Feature;

use App\Models\PartCatalogItem;
use App\Services\NikolaCarsOfficialPartMatch;
use App\Services\NikolaCarsOfficialPartMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NikolaCarsOfficialPartMatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_match_prefers_exact_tesla_official_part_number_before_prefix_fallback(): void
    {
        $fallback = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-official://1002066-01-B',
            'part_number' => '1002066-01-B',
            'name' => 'Fallback official part',
        ]);
        $exact = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-official://1002066-00-A',
            'part_number' => '1002066-00-A',
            'name' => 'Exact official part',
        ]);

        $match = app(NikolaCarsOfficialPartMatcher::class)->match('1002066-00-A');

        $this->assertSame(NikolaCarsOfficialPartMatch::TYPE_EXACT, $match->matchType);
        $this->assertSame($exact->id, $match->officialItem?->id);
        $this->assertNotSame($fallback->id, $match->officialItem?->id);
        $this->assertSame('1002066', $match->partPrefix);
    }

    public function test_match_falls_back_to_seven_digit_prefix_when_exact_part_number_is_missing(): void
    {
        $official = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-official://1002066-01-B',
            'part_number' => '1002066-01-B',
            'name' => 'Fallback official part',
        ]);

        $match = app(NikolaCarsOfficialPartMatcher::class)->match('1002066-00-A');

        $this->assertSame(NikolaCarsOfficialPartMatch::TYPE_SEVEN_DIGIT_PREFIX, $match->matchType);
        $this->assertSame($official->id, $match->officialItem?->id);
        $this->assertSame('1002066-00-A', $match->normalizedPartNumber);
        $this->assertSame('1002066', $match->partPrefix);
    }

    public function test_seven_digit_fallback_prefers_manually_locked_official_localized_name(): void
    {
        $older = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-official://1002066-01-B',
            'part_number' => '1002066-01-B',
            'name' => 'Older official part',
            'name_ru' => 'Older RU name',
        ]);
        $manual = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-official://1002066-02-C',
            'part_number' => '1002066-02-C',
            'name' => 'Manual official part',
            'name_ru' => 'Manual RU name',
            'raw_attributes' => [
                'manual_name_locks' => [
                    'ru' => '2026-06-16 19:49:35',
                ],
            ],
        ]);

        $match = app(NikolaCarsOfficialPartMatcher::class)->match('1002066-00-A');

        $this->assertSame(NikolaCarsOfficialPartMatch::TYPE_SEVEN_DIGIT_PREFIX, $match->matchType);
        $this->assertSame($manual->id, $match->officialItem?->id);
        $this->assertNotSame($older->id, $match->officialItem?->id);
    }

    public function test_match_ignores_values_that_are_not_full_tesla_part_numbers(): void
    {
        PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-official://1002066-00-A',
            'part_number' => '1002066-00-A',
            'name' => 'Official part',
        ]);

        $match = app(NikolaCarsOfficialPartMatcher::class)->match('1002066');

        $this->assertFalse($match->matched());
        $this->assertSame(NikolaCarsOfficialPartMatch::TYPE_NONE, $match->matchType);
        $this->assertNull($match->officialItem);
        $this->assertNull($match->partPrefix);
    }
}
