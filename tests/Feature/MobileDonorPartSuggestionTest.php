<?php

namespace Tests\Feature;

use App\Models\DonorCar;
use App\Models\PartCatalogItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileDonorPartSuggestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_part_suggestions_match_donor_model_and_year_by_name_only(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJYGDEE1MF123456',
            'brand' => 'Tesla',
            'model' => 'Model Y',
            'year' => 2021,
            'status' => DonorCar::STATUS_DISMANTLING,
        ]);

        PartCatalogItem::query()->create([
            'source' => 'test',
            'source_url' => 'https://example.test/y-door-2021',
            'part_number' => 'Y-DOOR-2021',
            'name' => 'Door handle',
            'model_label' => 'Model Y 01.2020 - 01.2025',
            'model_name' => 'Model Y',
            'year_from' => 2020,
            'year_to' => 2025,
        ]);
        PartCatalogItem::query()->create([
            'source' => 'test',
            'source_url' => 'https://example.test/m3-door-2021',
            'part_number' => 'M3-DOOR-2021',
            'name' => 'Door handle',
            'model_label' => 'Model 3 06.2017 - 12.2023',
            'model_name' => 'Model 3',
            'year_from' => 2017,
            'year_to' => 2023,
        ]);
        PartCatalogItem::query()->create([
            'source' => 'test',
            'source_url' => 'https://example.test/y-door-2022',
            'part_number' => 'Y-DOOR-2022',
            'name' => 'Door handle',
            'model_label' => 'Model Y 02.2022 - 01.2025',
            'model_name' => 'Model Y',
            'year_from' => 2022,
            'year_to' => 2025,
        ]);
        PartCatalogItem::query()->create([
            'source' => 'test',
            'source_url' => 'https://example.test/y-category-only',
            'part_number' => 'Y-CATEGORY-ONLY',
            'name' => '',
            'model_label' => 'Model Y 01.2020 - 01.2025',
            'model_name' => 'Model Y',
            'year_from' => 2020,
            'year_to' => 2025,
            'subcategory_name' => 'Door handle',
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('admin.mobile.donor-cars.products.search', [$donorCar, 'q' => 'Door']));

        $response
            ->assertOk()
            ->assertJsonFragment(['external_sku' => 'Y-DOOR-2021'])
            ->assertJsonMissing(['external_sku' => 'M3-DOOR-2021'])
            ->assertJsonMissing(['external_sku' => 'Y-DOOR-2022'])
            ->assertJsonMissing(['external_sku' => 'Y-CATEGORY-ONLY']);
    }

    public function test_mobile_part_suggestions_can_search_by_part_number_but_return_real_name(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJYGDEE1MF123456',
            'brand' => 'Tesla',
            'model' => 'Model Y',
            'year' => 2021,
            'status' => DonorCar::STATUS_DISMANTLING,
        ]);

        PartCatalogItem::query()->create([
            'source' => 'test',
            'source_url' => 'https://example.test/y-mirror-2021',
            'part_number' => 'MY-MIRROR-2021',
            'name' => 'Mirror assembly',
            'model_label' => 'Model Y 01.2020 - 01.2025',
            'model_name' => 'Model Y',
            'year_from' => 2020,
            'year_to' => 2025,
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('admin.mobile.donor-cars.products.search', [$donorCar, 'q' => 'MIRROR-2021']));

        $response
            ->assertOk()
            ->assertJsonFragment([
                'name' => 'Mirror assembly',
                'external_sku' => 'MY-MIRROR-2021',
            ]);
    }
}
