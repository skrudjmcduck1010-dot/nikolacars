<?php

namespace Tests\Feature;

use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Services\NikolaCarsOfficialPartEnrichmentService;
use App\Services\NikolaCarsOfficialPartMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NikolaCarsOfficialPartEnrichmentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_enrichment_returns_tesla_official_data_for_exact_part_number_match(): void
    {
        $model = PartCatalogCategory::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-official://category/model-s',
            'depth' => 0,
            'name' => 'Model S',
            'model_label' => 'Model S',
        ]);
        $body = PartCatalogCategory::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-official://category/body',
            'parent_id' => $model->id,
            'depth' => 1,
            'name' => 'BODY',
            'name_ru' => 'Кузов',
            'model_label' => 'Model S',
        ]);
        $closures = PartCatalogCategory::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-official://category/closures',
            'parent_id' => $body->id,
            'depth' => 2,
            'name' => 'CLOSURES',
            'name_ru' => 'Двери',
            'model_label' => 'Model S',
        ]);
        PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/catalogs?part=1002066-00-A',
            'part_number' => '1002066-00-A',
            'name' => 'ASSEMBLY - FRONT DOOR',
            'name_en' => 'ASSEMBLY - FRONT DOOR',
            'scheme_number' => 12,
            'part_catalog_category_id' => $closures->id,
            'model_label' => 'Model S 2012-2016',
            'model_name' => 'Model S',
            'main_category_code' => '10',
            'main_category_name' => 'Body',
            'subcategory_code' => '20',
            'subcategory_name' => 'Closures',
            'node_name' => 'Front door',
            'compatibility_text' => 'Model S 2012-2016',
            'raw_attributes' => [
                'part_image_urls' => ['/storage/tesla-official/part-images/1002066.jpeg'],
                'system_group_image_urls' => ['https://epc.tesla.com/resources/images/body.png'],
                'image_urls' => ['/storage/tesla-official/resources-images/body-local.png'],
                'official_catalog_occurrences' => [[
                    'model_label' => 'Model S 2012-2016',
                    'main_category_code' => '10',
                    'main_category_name' => 'Body',
                    'subcategory_code' => '20',
                    'subcategory_name' => 'Closures',
                    'node_name' => 'Front door',
                    'category_id' => $closures->id,
                    'source_url' => 'https://parts.tesla.com/en-US/catalogs?part=1002066-00-A',
                ]],
            ],
        ]);
        $nikolaCarsItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/enrich-exact',
            'part_number' => '1002066-00-A',
            'name' => 'NikolaCars door',
        ]);

        $enrichment = app(NikolaCarsOfficialPartEnrichmentService::class)->enrich($nikolaCarsItem);

        $this->assertTrue($enrichment->matched());
        $this->assertSame(NikolaCarsOfficialPartMatch::TYPE_EXACT, $enrichment->matchType());
        $this->assertSame('1002066-00-A', $enrichment->requestedPartNumber);
        $this->assertSame('1002066-00-A', $enrichment->officialPartNumber);
        $this->assertSame('ASSEMBLY - FRONT DOOR', $enrichment->officialName);
        $this->assertSame(['Кузов', 'Двери'], $enrichment->categoryParts);
        $this->assertSame('Кузов / Двери', $enrichment->categoryPath);
        $this->assertSame(['Model S 2012-2016', 'Model S'], $enrichment->compatibilityModels);
        $this->assertSame(12, $enrichment->schemeNumber);
        $this->assertSame(['/storage/tesla-official/part-images/1002066.jpeg'], $enrichment->partImageUrls);
        $this->assertSame(['https://epc.tesla.com/resources/images/body.png'], $enrichment->schemeImageUrls);
        $this->assertSame([
            '/storage/tesla-official/resources-images/body-local.png',
            '/storage/tesla-official/part-images/1002066.jpeg',
            'https://epc.tesla.com/resources/images/body.png',
        ], $enrichment->imageUrls);
        $this->assertSame('10 Body', $enrichment->occurrences[0]['category']);
        $this->assertSame('20 Closures', $enrichment->occurrences[0]['subcategory']);
    }

    public function test_enrichment_uses_seven_digit_fallback_when_exact_match_is_missing(): void
    {
        PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/catalogs?part=1002066-01-B',
            'part_number' => '1002066-01-B',
            'name' => 'Fallback official part',
            'model_label' => 'Model X',
            'main_category_name' => 'Body',
            'subcategory_name' => 'Closures',
            'node_name' => 'Door',
        ]);
        $nikolaCarsItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/enrich-fallback',
            'part_number' => '1002066-00-A',
            'name' => 'NikolaCars door',
        ]);

        $enrichment = app(NikolaCarsOfficialPartEnrichmentService::class)->enrich($nikolaCarsItem);

        $this->assertTrue($enrichment->matched());
        $this->assertSame(NikolaCarsOfficialPartMatch::TYPE_SEVEN_DIGIT_PREFIX, $enrichment->matchType());
        $this->assertSame('1002066-01-B', $enrichment->officialPartNumber);
        $this->assertSame('Body / Closures / Door', $enrichment->categoryPath);
    }

    public function test_enrichment_prefers_canonical_official_item_over_vin_specific_exact_match(): void
    {
        $vinSpecific = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=1127503-11-D&vin=5YJYGDED4MF109750',
            'part_number' => '1127503-11-D',
            'name' => 'VIN specific parking sensor',
            'raw_attributes' => [
                'donor_vin' => '5YJYGDED4MF109750',
                'donor_car_id' => 6,
                'recommendation_type' => 'RECOMMENDED',
            ],
        ]);
        $canonical = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=1127503-11-D',
            'part_number' => '1127503-11-D',
            'name' => 'Canonical parking sensor',
        ]);

        $enrichment = app(NikolaCarsOfficialPartEnrichmentService::class)->enrich('1127503-11-D');

        $this->assertTrue($enrichment->matched());
        $this->assertSame($canonical->id, $enrichment->officialItem?->id);
        $this->assertNotSame($vinSpecific->id, $enrichment->officialItem?->id);
        $this->assertSame('Canonical parking sensor', $enrichment->officialName);
    }

    public function test_enrichment_returns_empty_official_data_for_invalid_part_number(): void
    {
        PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/catalogs?part=1002066-00-A',
            'part_number' => '1002066-00-A',
            'name' => 'Official part',
        ]);
        $nikolaCarsItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/enrich-invalid',
            'part_number' => 'NC-1002066',
            'name' => 'NikolaCars internal code',
        ]);

        $enrichment = app(NikolaCarsOfficialPartEnrichmentService::class)->enrich($nikolaCarsItem);

        $this->assertFalse($enrichment->matched());
        $this->assertSame(NikolaCarsOfficialPartMatch::TYPE_NONE, $enrichment->matchType());
        $this->assertNull($enrichment->officialPartNumber);
        $this->assertNull($enrichment->categoryPath);
        $this->assertSame([], $enrichment->imageUrls);
    }
}
