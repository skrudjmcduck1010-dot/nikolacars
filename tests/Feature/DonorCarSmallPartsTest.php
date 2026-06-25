<?php

namespace Tests\Feature;

use App\Models\DonorCar;
use App\Models\PartCatalogItem;
use App\Models\Product;
use App\Models\User;
use App\Services\DonorProductGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DonorCarSmallPartsTest extends TestCase
{
    use RefreshDatabase;

    public function test_donor_product_can_be_moved_to_small_parts_page(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF100001',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2019,
        ]);

        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/test-small-part',
            'part_number' => '1100000-00-A',
            'name' => 'Small bracket',
            'name_ua' => 'Малий кронштейн',
            'raw_attributes' => [],
        ]);

        $product = Product::query()->create([
            'sku' => 'DON'.$donorCar->id.'-0001',
            'external_sku' => '1100000-00-A',
            'name' => 'Small bracket',
            'slug' => 'small-bracket',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $catalogItem->id,
            'part_origin' => Product::PART_ORIGIN_ORIGINAL,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 15,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('admin.donor-cars.products.small-part.store', [$donorCar, $product]))
            ->assertRedirect(route('admin.donor-cars.show', $donorCar));

        $rawAttributes = $catalogItem->refresh()->raw_attributes->getArrayCopy();

        $this->assertTrue($rawAttributes['donor_vin_small_part']);
        $this->assertSame('manual', $rawAttributes['donor_vin_small_part_reason']);

        $this->actingAs($user)
            ->get(route('admin.donor-cars.small-parts.index', $donorCar))
            ->assertOk()
            ->assertSee('Мелочевка '.$donorCar->vin)
            ->assertSee('1100000-00-A')
            ->assertSee('Малий кронштейн');
    }

    public function test_small_parts_are_synchronized_by_part_number_across_donors(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-sync@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $firstDonor = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF100002',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2019,
        ]);
        $secondDonor = DonorCar::query()->create([
            'vin' => '5YJSA1E26HF100003',
            'brand' => 'Tesla',
            'model' => 'Model S',
            'year' => 2017,
        ]);

        $firstCatalogItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/test-small-part-first',
            'part_number' => '1100000-00-A',
            'name' => 'Small bracket first',
            'raw_attributes' => [],
        ]);
        $secondCatalogItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/test-small-part-second',
            'part_number' => '1100000-00-A',
            'name' => 'Small bracket second',
            'raw_attributes' => [],
        ]);

        $firstProduct = Product::query()->create([
            'sku' => 'DON'.$firstDonor->id.'-0001',
            'external_sku' => '1100000-00-A',
            'name' => 'Small bracket first',
            'slug' => 'small-bracket-first',
            'donor_car_id' => $firstDonor->id,
            'source_part_catalog_item_id' => $firstCatalogItem->id,
            'part_origin' => Product::PART_ORIGIN_ORIGINAL,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 15,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $secondProduct = Product::query()->create([
            'sku' => 'DON'.$secondDonor->id.'-0001',
            'external_sku' => '1100000-00-A',
            'name' => 'Small bracket second',
            'slug' => 'small-bracket-second',
            'donor_car_id' => $secondDonor->id,
            'source_part_catalog_item_id' => $secondCatalogItem->id,
            'part_origin' => Product::PART_ORIGIN_ORIGINAL,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 15,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('admin.donor-cars.products.small-part.store', [$firstDonor, $firstProduct]))
            ->assertRedirect(route('admin.donor-cars.show', $firstDonor));

        $this->assertTrue((bool) data_get($firstCatalogItem->refresh()->raw_attributes, 'donor_vin_small_part'));
        $this->assertTrue((bool) data_get($secondCatalogItem->refresh()->raw_attributes, 'donor_vin_small_part'));

        $this->actingAs($user)
            ->get(route('admin.donor-cars.small-parts.index', $secondDonor))
            ->assertOk()
            ->assertSee('Small bracket second');

        $this->actingAs($user)
            ->delete(route('admin.donor-cars.products.small-part.destroy', [$secondDonor, $secondProduct]))
            ->assertRedirect(route('admin.donor-cars.small-parts.index', $secondDonor));

        $this->assertFalse((bool) data_get($firstCatalogItem->refresh()->raw_attributes, 'donor_vin_small_part'));
        $this->assertFalse((bool) data_get($secondCatalogItem->refresh()->raw_attributes, 'donor_vin_small_part'));

        $this->actingAs($user)
            ->get(route('admin.donor-cars.small-parts.index', $firstDonor))
            ->assertOk()
            ->assertDontSee('Small bracket first');
    }

    public function test_small_part_mark_returns_json_for_async_donor_page_action(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-json@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF100004',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2019,
        ]);

        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/test-small-part-json',
            'part_number' => '1100001-00-A',
            'name' => 'Small json bracket',
            'raw_attributes' => [],
        ]);

        $product = Product::query()->create([
            'sku' => 'DON'.$donorCar->id.'-0001',
            'external_sku' => '1100001-00-A',
            'name' => 'Small json bracket',
            'slug' => 'small-json-bracket',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $catalogItem->id,
            'part_origin' => Product::PART_ORIGIN_ORIGINAL,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 5,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->postJson(route('admin.donor-cars.products.small-part.store', [$donorCar, $product]))
            ->assertOk()
            ->assertJson([
                'part_number' => '1100001-00-A',
                'affected_product_ids' => [$product->id],
            ])
            ->assertJsonPath('message', 'Запчасть перенесена в Мелочевку.');
    }

    public function test_generation_refresh_keeps_small_parts_limited_to_existing_part_numbers(): void
    {
        PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/existing-small-part',
            'part_number' => '1100000-00-A',
            'name' => 'Existing small bracket',
            'raw_attributes' => [
                'donor_vin_small_part' => true,
                'donor_vin_small_part_part_number' => '1100000-00-A',
                'donor_vin_small_part_reason' => 'manual',
            ],
        ]);

        $samePartNumberItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/new-same-small-part',
            'part_number' => '1100000-00-A',
            'name' => 'Same small bracket',
            'raw_attributes' => [
                'donor_vin' => '5YJ3E1EA7KF100002',
            ],
        ]);
        $hardwareItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/new-hardware-part',
            'part_number' => '1200000-00-B',
            'name' => 'Bolt',
            'raw_attributes' => [
                'donor_vin' => '5YJ3E1EA7KF100002',
                'part_type' => 'Hardware (including fasteners)',
            ],
        ]);

        $updated = app(DonorProductGenerationService::class)
            ->refreshSmallVinCatalogFlags(collect([$samePartNumberItem, $hardwareItem]));

        $this->assertSame(1, $updated);
        $this->assertTrue((bool) data_get($samePartNumberItem->refresh()->raw_attributes, 'donor_vin_small_part'));
        $this->assertSame(
            'part_number: 1100000-00-A',
            data_get($samePartNumberItem->raw_attributes, 'donor_vin_small_part_reason')
        );
        $this->assertFalse((bool) data_get($hardwareItem->refresh()->raw_attributes, 'donor_vin_small_part'));
        $this->assertNull(data_get($hardwareItem->raw_attributes, 'donor_vin_small_part_reason'));
    }
}
