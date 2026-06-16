<?php

namespace Tests\Feature;

use App\Models\PartCatalogItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TeslaOfficialFindPartResultsDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_official_scheme_image_is_not_rendered_as_product_photo(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-tesla-official-scheme-photo@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $item = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=1002032-S0-C',
            'part_number' => '1002032-S0-C',
            'name' => 'MOUNT - FR SEAT RR M10 SVC',
            'name_en' => 'MOUNT - FR SEAT RR M10 SVC',
            'raw_attributes' => [
                'image_url' => 'tesla-official/resources-images/body-side-panels.svg',
                'image_urls' => ['tesla-official/resources-images/body-side-panels.svg'],
                'system_group_image_urls' => ['tesla-official/resources-images/body-side-panels.svg'],
            ],
        ]);

        $this->actingAs($user)
            ->get(route('admin.tesla-official-catalog.show', $item))
            ->assertOk()
            ->assertSee('part-catalog-photo-manager__empty', false)
            ->assertSee('part-catalog-photo-manager__item--scheme', false)
            ->assertSee('/storage/tesla-official/resources-images/body-side-panels.svg', false);
    }

    public function test_related_find_part_item_shows_source_search_results(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-tesla-official-related-find-part@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=1088607-00-G',
            'part_number' => '1088607-00-G',
            'name' => 'INSULATOR ACCESS - FASTCHARGE',
            'name_en' => 'INSULATOR ACCESS - FASTCHARGE',
            'raw_attributes' => [
                'tesla_part_search_results' => [
                    [
                        'part_number' => '1088607-00-G',
                        'description' => 'INSULATOR ACCESS - FASTCHARGE',
                        'model' => 'Model Y Feb 2025',
                        'category' => '16 - HV BATTERY SYSTEM',
                        'subcategory' => '1630 - HV Battery Electrical Components',
                        'group' => 'Ancillary Bay',
                    ],
                    [
                        'part_number' => '1088607-01-G',
                        'description' => 'INSULATOR ACCESS - FASTCHARGE',
                        'model' => 'Model 3 Jan 2024',
                        'category' => '16 - HV BATTERY SYSTEM',
                        'subcategory' => '1630 - HV Battery Electrical Components',
                        'group' => 'Ancillary Bay',
                    ],
                    [
                        'part_number' => '2053815-00-B',
                        'description' => 'INSULATOR ACCESS - FASTCHARGE',
                        'model' => 'Model Y Feb 2025',
                        'category' => '16 - HV BATTERY SYSTEM',
                        'subcategory' => '1630 - HV Battery Electrical Components',
                        'group' => 'Ancillary Bay',
                    ],
                ],
            ],
        ]);
        $linkedResultItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=1088607-01-G',
            'part_number' => '1088607-01-G',
            'name' => 'INSULATOR ACCESS - FASTCHARGE',
            'name_en' => 'INSULATOR ACCESS - FASTCHARGE',
        ]);

        $relatedItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=2053815-00-B',
            'part_number' => '2053815-00-B',
            'name' => 'INSULATOR ACCESS - FASTCHARGE',
            'name_en' => 'INSULATOR ACCESS - FASTCHARGE',
            'raw_attributes' => [
                'find_part_found_by_requested_part_numbers' => ['1088607-00-G'],
                'official_catalog_occurrences' => [
                    [
                        'model_label' => 'Model 3 Jan 2024',
                        'main_category_code' => '16',
                        'main_category_name' => 'HV BATTERY SYSTEM',
                        'subcategory_code' => '1630',
                        'subcategory_name' => 'HV Battery Electrical Components',
                        'node_name' => 'Ancillary Bay',
                    ],
                ],
            ],
        ]);

        $this->actingAs($user)
            ->get(route('admin.tesla-official-catalog.show', $relatedItem))
            ->assertOk()
            ->assertSee('Model Y Feb 2025')
            ->assertSee('Model 3 Jan 2024')
            ->assertSee('1088607-01-G')
            ->assertSee(route('admin.tesla-official-catalog.show', $linkedResultItem), false);
    }
}
