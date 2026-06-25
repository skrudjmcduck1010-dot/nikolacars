<?php

namespace Tests\Feature;

use App\Models\DonorCar;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductStockStatusDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_notes_repairs_question_only_unknown_damage_status(): void
    {
        $product = Product::query()->create([
            'sku' => 'DMG-QUESTION-001',
            'name' => 'Question damage part',
            'slug' => 'question-damage-part',
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'notes' => str_repeat('?', strlen('Неизвестно')),
            'is_active' => true,
        ]);

        $this->assertSame('Неизвестно', $product->refresh()->getRawOriginal('notes'));
        $this->assertSame('Неизвестно', $product->notes);
    }

    public function test_product_without_stock_is_shown_as_sold(): void
    {
        $product = Product::query()->create([
            'sku' => 'OUT-001',
            'name' => 'Out of stock part',
            'slug' => 'out-of-stock-part',
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.products.index'))
            ->assertOk()
            ->assertSee($product->sku)
            ->assertSee('<span class="tag tag-danger">'.Product::STORAGE_STATUSES[Product::STORAGE_STATUS_SOLD].'</span>', false)
            ->assertDontSee('<span class="tag ">'.Product::STORAGE_STATUSES[Product::STORAGE_STATUS_IN_STOCK].'</span>', false);
    }

    public function test_product_with_positive_stock_is_shown_as_in_stock(): void
    {
        $warehouse = Warehouse::query()->create([
            'name' => 'Main stock',
            'type' => Warehouse::TYPE_MAIN,
            'floor_count' => 1,
            'is_active' => true,
        ]);
        $location = Location::query()->create([
            'warehouse_id' => $warehouse->id,
            'floor' => 'floor_1',
            'full_code' => 'MAIN-A1',
            'cell' => 'A1',
            'is_active' => true,
        ]);
        $product = Product::query()->create([
            'sku' => 'IN-001',
            'name' => 'In stock part',
            'slug' => 'in-stock-part',
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $product->stockItems()->create([
            'warehouse_id' => $warehouse->id,
            'location_id' => $location->id,
            'quantity' => 1,
            'reserved_quantity' => 0,
            'testing_status' => 'not_tested',
        ]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.products.index'))
            ->assertOk()
            ->assertSee($product->sku)
            ->assertSee('<span class="tag">На складе</span>', false);
    }

    public function test_product_show_displays_pcs_unit_without_mojibake(): void
    {
        $warehouse = Warehouse::query()->create([
            'name' => 'Main stock',
            'type' => Warehouse::TYPE_MAIN,
            'floor_count' => 1,
            'is_active' => true,
        ]);
        $location = Location::query()->create([
            'warehouse_id' => $warehouse->id,
            'floor' => 'floor_1',
            'full_code' => 'MAIN-A1',
            'cell' => 'A1',
            'is_active' => true,
        ]);
        $product = Product::query()->create([
            'sku' => 'UNIT-001',
            'name' => 'Unit display part',
            'slug' => 'unit-display-part',
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $product->stockItems()->create([
            'warehouse_id' => $warehouse->id,
            'location_id' => $location->id,
            'quantity' => 1,
            'reserved_quantity' => 0,
            'testing_status' => 'not_tested',
        ]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.products.show', $product))
            ->assertOk()
            ->assertSee("\u{041E}\u{0441}\u{0442}\u{0430}\u{0442}\u{043E}\u{043A}: 1 \u{0448}\u{0442}")
            ->assertDontSee("\u{0421}\u{20AC}\u{0421}\u{201A}");
    }

    public function test_product_show_displays_unknown_damage_when_note_is_empty(): void
    {
        $product = Product::query()->create([
            'sku' => 'DMG-001',
            'name' => 'Unknown damage part',
            'slug' => 'unknown-damage-part',
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'notes' => null,
            'is_active' => true,
        ]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.products.show', $product))
            ->assertOk()
            ->assertSee($this->u('\\u0421\\u0442\\u0430\\u0442\\u0443\\u0441:'))
            ->assertSee($this->u('\\u041d\\u0435\\u0438\\u0437\\u0432\\u0435\\u0441\\u0442\\u043d\\u043e'))
            ->assertDontSee($this->u('\\u0411\\u0435\\u0437 \\u043f\\u043e\\u0432\\u0440\\u0435\\u0436\\u0434\\u0435\\u043d\\u0438\\u0439'));
    }

    public function test_product_show_appends_first_donor_photo_for_on_donor_product(): void
    {
        $donor = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF010004',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2019,
            'photos' => ['donor-cars/first-donor-photo.jpg', 'donor-cars/second-donor-photo.jpg'],
        ]);
        $product = Product::query()->create([
            'sku' => 'DONOR-PHOTO-001',
            'name' => 'On donor part',
            'slug' => 'on-donor-part',
            'donor_car_id' => $donor->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'main_image' => 'product-photos/main.jpg',
            'images_json' => ['product-photos/main.jpg', 'product-photos/detail.jpg'],
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->adminUser())
            ->get(route('admin.products.show', $product));

        $response
            ->assertOk()
            ->assertSee(Storage::url('donor-cars/first-donor-photo.jpg'), false)
            ->assertDontSee(Storage::url('donor-cars/second-donor-photo.jpg'), false);

        $html = $response->getContent();

        $this->assertLessThan(
            strpos($html, Storage::url('product-photos/detail.jpg')),
            strpos($html, Storage::url('donor-cars/first-donor-photo.jpg')),
        );
    }

    public function test_product_show_same_part_on_other_donors_hides_broken_parts_and_shows_condition(): void
    {
        $baseDonor = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF010001',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2019,
        ]);
        $goodDonor = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF010002',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2019,
            'color' => "\u{0411}\u{0435}\u{043B}\u{044B}\u{0439}",
            'photos' => ['donor-cars/good-preview.jpg'],
        ]);
        $brokenDonor = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF010003',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2019,
        ]);

        $baseProduct = Product::query()->create([
            'sku' => 'SAME-BASE-001',
            'external_sku' => '1084174-00-C',
            'name' => 'Front bumper',
            'slug' => 'same-base-front-bumper',
            'donor_car_id' => $baseDonor->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'notes' => 'Без повреждений',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        Product::query()->create([
            'sku' => 'SAME-GOOD-001',
            'external_sku' => '1084174-00-C',
            'name' => 'Front bumper',
            'slug' => 'same-good-front-bumper',
            'donor_car_id' => $goodDonor->id,
            'color' => "\u{0420}\u{0490}\u{0420}\u{2014}",
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'notes' => 'Без повреждений',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        Product::query()->create([
            'sku' => 'SAME-BROKEN-001',
            'external_sku' => '1084174-00-C',
            'name' => 'Front bumper',
            'slug' => 'same-broken-front-bumper',
            'donor_car_id' => $brokenDonor->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'notes' => 'Разбит',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.products.show', $baseProduct))
            ->assertOk()
            ->assertSee('SAME-GOOD-001')
            ->assertSee(Storage::url('donor-cars/good-preview.jpg'), false)
            ->assertSee('background-color: #f8fafc', false)
            ->assertSee('Без повреждений')
            ->assertDontSee('SAME-BROKEN-001')
            ->assertDontSee('Разбит');
    }

    protected function adminUser(): User
    {
        return User::query()->create([
            'name' => 'Admin',
            'email' => uniqid('admin-', true).'@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    protected function u(string $value): string
    {
        return json_decode('"'.$value.'"', true, 512, JSON_THROW_ON_ERROR);
    }
}
