<?php

namespace Tests\Feature;

use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Models\PartCatalogItemOccurrence;
use App\Services\TeslaOfficialCatalogImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TeslaOfficialCatalogImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_official_tesla_parts_with_prices_and_language_fields(): void
    {
        Http::fake([
            'https://epcapi.tesla.com/api/catalogs/cat-1/categories' => Http::response([
                'catalog' => [
                    'externalReference' => 'cat-1',
                    'title' => 'Model S Feb 2012 - Mar 2016',
                    'catalogModelTitle' => 'Model S',
                    'startDate' => '2012-02-01T00:00:00',
                    'endDate' => '2016-03-31T00:00:00',
                ],
                'categories' => [[
                    'externalReference' => 'cat-body',
                    'title' => '10 - BODY',
                    'subCategories' => [[
                        'externalReference' => 'cat-bumper',
                        'title' => '1001 - Bumper and Fascia',
                        'systemGroups' => [[
                            'externalReference' => 'sg-front',
                            'title' => 'Front Bumper Carrier',
                            'images' => '[{"Mimetype":"image/png","ImageURL":"resources/images/front.png"}]',
                        ]],
                    ]],
                    'image' => 'https://epc.tesla.com/resources/images/body.jpg',
                ]],
            ]),
            'https://epcapi.tesla.com/api/catalogs/cat-1/systemgroups/sg-front' => Http::response([
                'parts' => [[
                    'annotation' => '1',
                    'displayOrder' => 4,
                    'partNumber' => '1061950-98-E',
                    'title' => 'FRONT END CARRIER',
                    'quantity' => '1',
                    'notes' => 'DUAL MOTOR',
                    'price' => 900.25,
                    'currencyCode' => 'USD',
                    'partRestrictionMessage' => 'Sold by Tesla; None',
                ]],
            ]),
        ]);

        $stats = app(TeslaOfficialCatalogImporter::class)->import([
            'catalog_external_reference' => 'cat-1',
            'sleep_ms' => 0,
        ]);

        $this->assertSame(1, $stats['items_saved']);

        $item = PartCatalogItem::query()->firstOrFail();

        $this->assertSame('tesla_official', $item->source);
        $this->assertSame('1061950-98-E', $item->part_number);
        $this->assertSame('1. FRONT END CARRIER', $item->name);
        $this->assertSame('1. FRONT END CARRIER', $item->name_en);
        $this->assertNull($item->name_ru);
        $this->assertNull($item->name_ua);
        $this->assertSame('900.25', $item->price_amount);
        $this->assertSame('USD', $item->currency);
        $this->assertSame('Sold by Tesla; None', $item->availability);
        $this->assertSame('DUAL MOTOR', $item->notes_en);
        $this->assertArrayNotHasKey('price', (array) $item->raw_attributes);

        $this->assertDatabaseHas('part_catalog_categories', [
            'source' => 'tesla_official',
            'depth' => 0,
            'name' => 'Model S 02.2012-03.2016',
            'name_en' => 'Model S 02.2012-03.2016',
            'preview_image_url' => 'https://epc.tesla.com/resources/images/body.jpg',
        ]);

        $this->assertDatabaseHas('part_catalog_categories', [
            'source' => 'tesla_official',
            'depth' => 3,
            'name' => 'Front Bumper Carrier',
            'preview_image_url' => 'https://epc.tesla.com/resources/images/front.png',
        ]);

        $this->assertSame(4, PartCatalogCategory::query()->where('source', 'tesla_official')->count());
        $this->assertSame(1, PartCatalogItemOccurrence::query()->where('source', 'tesla_official')->count());
        $this->assertDatabaseHas('part_catalog_item_occurrences', [
            'part_catalog_item_id' => $item->id,
            'part_catalog_category_id' => $item->part_catalog_category_id,
            'source' => 'tesla_official',
            'part_number' => '1061950-98-E',
            'scheme_number' => 1,
        ]);
        $occurrence = PartCatalogItemOccurrence::query()->firstOrFail();
        $this->assertSame('1', data_get($occurrence->raw_attributes, 'annotation'));
        $this->assertSame(4, data_get($occurrence->raw_attributes, 'display_order'));
    }

    public function test_import_keeps_non_numeric_official_scheme_annotation(): void
    {
        Http::fake([
            'https://epcapi.tesla.com/api/catalogs/cat-1/categories' => Http::response([
                'catalog' => [
                    'externalReference' => 'cat-1',
                    'title' => 'Model 3 Jun 2017 - Dec 2023',
                    'catalogModelTitle' => 'Model 3',
                    'startDate' => '2017-06-01T00:00:00',
                    'endDate' => '2023-12-31T00:00:00',
                ],
                'categories' => [[
                    'externalReference' => 'cat-body',
                    'title' => '10 - BODY',
                    'subCategories' => [[
                        'externalReference' => 'cat-panels',
                        'title' => '1010 - Body Panels',
                        'systemGroups' => [[
                            'externalReference' => 'sg-front-inner',
                            'title' => 'Front Inner Panels',
                        ]],
                    ]],
                ]],
            ]),
            'https://epcapi.tesla.com/api/catalogs/cat-1/systemgroups/sg-front-inner' => Http::response([
                'images' => [[
                    'Mimetype' => 'image/png',
                    'ImageURL' => 'resources/images/model-3/front-inner-panels.png',
                ]],
                'parts' => [[
                    'annotation' => '*',
                    'displayOrder' => 69,
                    'partNumber' => '1978118-S0-A',
                    'title' => 'ASSEMBLY- FRONT RAIL COMPLETE - LEFT HAND',
                    'quantity' => '1',
                    'price' => 2200,
                    'currencyCode' => 'USD',
                ]],
            ]),
        ]);

        app(TeslaOfficialCatalogImporter::class)->import([
            'catalog_external_reference' => 'cat-1',
            'sleep_ms' => 0,
        ]);

        $item = PartCatalogItem::query()->where('part_number', '1978118-S0-A')->firstOrFail();

        $this->assertNull($item->scheme_number);
        $this->assertSame('*', data_get($item->raw_attributes, 'annotation'));
        $this->assertSame(69, data_get($item->raw_attributes, 'display_order'));
        $this->assertSame(['https://epc.tesla.com/resources/images/model-3/front-inner-panels.png'], data_get($item->raw_attributes, 'system_group_image_urls'));
        $this->assertSame('ASSEMBLY- FRONT RAIL COMPLETE - LEFT HAND', $item->name);

        $occurrence = PartCatalogItemOccurrence::query()->where('part_catalog_item_id', $item->id)->firstOrFail();
        $this->assertNull($occurrence->scheme_number);
        $this->assertSame('*', data_get($occurrence->raw_attributes, 'annotation'));
        $this->assertSame(69, data_get($occurrence->raw_attributes, 'display_order'));
        $this->assertSame(['https://epc.tesla.com/resources/images/model-3/front-inner-panels.png'], data_get($occurrence->raw_attributes, 'system_group_image_urls'));
    }

    public function test_vin_import_keeps_donor_binding_only_for_recommended_parts(): void
    {
        app(TeslaOfficialCatalogImporter::class)->importBrowserSnapshot([
            'catalogs' => [[
                'catalog' => [
                    'externalReference' => 'cat-1',
                    'title' => 'Model Y Jan 2020 - Jan 2025',
                    'catalogModelTitle' => 'Model Y',
                    'startDate' => '2020-01-01T00:00:00',
                    'endDate' => '2025-01-31T00:00:00',
                ],
                'tree' => [
                    'catalog' => [
                        'externalReference' => 'cat-1',
                        'title' => 'Model Y Jan 2020 - Jan 2025',
                        'catalogModelTitle' => 'Model Y',
                        'startDate' => '2020-01-01T00:00:00',
                        'endDate' => '2025-01-31T00:00:00',
                    ],
                ],
                'categories' => [[
                    'externalReference' => 'cat-electrical',
                    'title' => '17 - ELECTRICAL',
                    'subCategories' => [[
                        'externalReference' => 'cat-parking',
                        'title' => '1727 - Parking Sensors',
                        'systemGroups' => [[
                            'externalReference' => 'sg-parking',
                            'title' => 'Parking Sensors',
                        ]],
                    ]],
                ]],
                'system_group_details' => [[
                    'system_group_external_reference' => 'sg-parking',
                    'details' => [
                        'parts' => [
                            [
                                'annotation' => '1',
                                'partNumber' => '1127503-11-D',
                                'title' => 'ULTRASONIC SENSOR',
                                'quantity' => 8,
                                'recommendationType' => 'RECOMMENDED',
                                'recommendedPartNumber' => '1127503-11-D',
                            ],
                            [
                                'annotation' => '2',
                                'partNumber' => '1598486-00-G',
                                'title' => 'CATALOG ONLY PART',
                                'quantity' => 1,
                            ],
                        ],
                    ],
                ]],
            ]],
        ], [
            'raw_attributes_extra' => [
                'donor_vin' => '5YJYGDED4MF109750',
                'donor_car_id' => 6,
                'vin_catalog_imported_at' => '2026-06-15T13:07:59+03:00',
            ],
        ]);

        $recommended = PartCatalogItem::query()->where('part_number', '1127503-11-D')->firstOrFail();
        $catalogOnly = PartCatalogItem::query()->where('part_number', '1598486-00-G')->firstOrFail();

        $this->assertSame('5YJYGDED4MF109750', data_get($recommended->raw_attributes, 'donor_vin'));
        $this->assertSame(6, data_get($recommended->raw_attributes, 'donor_car_id'));
        $this->assertSame('RECOMMENDED', data_get($recommended->raw_attributes, 'recommendation_type'));
        $this->assertSame(8, data_get($recommended->raw_attributes, 'quantity'));

        $this->assertNull(data_get($catalogOnly->raw_attributes, 'donor_vin'));
        $this->assertNull(data_get($catalogOnly->raw_attributes, 'donor_car_id'));
        $this->assertNull(data_get($catalogOnly->raw_attributes, 'vin_catalog_imported_at'));
        $this->assertSame(1, data_get($catalogOnly->raw_attributes, 'quantity'));

        $catalogOnlyOccurrence = collect($catalogOnly->raw_attributes['official_catalog_occurrences'] ?? [])->first();
        $this->assertNull(data_get($catalogOnlyOccurrence, 'donor_vin'));
        $this->assertNull(data_get($catalogOnlyOccurrence, 'donor_car_id'));
    }

    public function test_find_part_refresh_does_not_drop_existing_official_scheme_data(): void
    {
        $category = PartCatalogCategory::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-official://catalog/model-3/category/10-body/subcategory/1010-body-panels/system-group/front-inner-panels',
            'depth' => 3,
            'name' => 'Front Inner Panels',
            'model_label' => 'Model 3 06.2017 - 12.2023',
            'preview_image_url' => '/storage/tesla-official/resources-images/front-inner-panels.png',
        ]);

        $item = PartCatalogItem::query()->create([
            'part_catalog_category_id' => $category->id,
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=1978118-S0-A',
            'part_number' => '1978118-S0-A',
            'name' => '7. ASSEMBLY- FRONT RAIL COMPLETE - LEFT HAND',
            'name_en' => '7. ASSEMBLY- FRONT RAIL COMPLETE - LEFT HAND',
            'scheme_number' => 7,
            'raw_attributes' => [
                'annotation' => '7',
                'system_group_image_urls' => ['/storage/tesla-official/resources-images/front-inner-panels.png'],
                'image_urls' => ['/storage/tesla-official/resources-images/front-inner-panels.png'],
            ],
        ]);

        $method = new \ReflectionMethod(TeslaOfficialCatalogImporter::class, 'savePartSearchMatch');
        $method->setAccessible(true);
        $method->invoke(app(TeslaOfficialCatalogImporter::class), [
            'partNumber' => '1978118-S0-A',
            'description' => 'ASSEMBLY- FRONT RAIL COMPLETE - LEFT HAND',
            'catalogName' => 'Model 3 Jun 2017 - Dec 2023',
            'categoryTitle' => '10 - BODY',
            'subcategoryTitle' => '1010 - Body Panels',
            'systemGroupTitle' => 'Front Inner Panels',
            'catalogExternalReference' => 'find-part-catalog',
            'categoryExternalReference' => 'cat-body',
            'subcategoryExternalReference' => 'sub-body-panels',
            'systemGroupExternalReference' => 'find-part-system-group',
        ], ['US'], []);

        $item->refresh();

        $this->assertSame($category->id, $item->part_catalog_category_id);
        $this->assertSame(7, $item->scheme_number);
        $this->assertSame('7', data_get($item->raw_attributes, 'annotation'));
        $this->assertSame('7. ASSEMBLY- FRONT RAIL COMPLETE - LEFT HAND', $item->name);
        $this->assertContains('/storage/tesla-official/resources-images/front-inner-panels.png', (array) data_get($item->raw_attributes, 'system_group_image_urls', []));
        $this->assertDatabaseHas('part_catalog_item_occurrences', [
            'part_catalog_item_id' => $item->id,
            'source' => 'tesla_official',
            'scheme_number' => 7,
        ]);
    }
}
