<?php

namespace Tests\Feature;

use App\Models\DonorCar;
use App\Models\PartCatalogItem;
use App\Models\PartSale;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DonorCarPartSuggestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_donor_car_part_suggestions_search_all_donor_product_statuses_and_sales(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-donor-part-suggestions@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJGLOBALPART0001',
            'brand' => 'Tesla',
            'model' => 'Model Y',
            'year' => 2021,
            'status' => DonorCar::STATUS_DISMANTLED,
        ]);
        $catalogItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla://tail-001',
            'part_number' => 'TAIL-001',
            'name' => 'Tail lamp assembly',
            'name_ru' => "\u{0424}\u{043E}\u{043D}\u{0430}\u{0440}\u{044C} \u{0437}\u{0430}\u{0434}\u{043D}\u{0438}\u{0439}",
        ]);
        $saleCatalogItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla://str-200',
            'part_number' => 'STR-200',
            'name' => 'Steering wheel',
            'name_ru' => "\u{0420}\u{0443}\u{043B}\u{044C}",
        ]);

        $soldProduct = Product::query()->create([
            'sku' => 'DON1-0001',
            'external_sku' => 'TAIL-001',
            'name' => 'Tail lamp assembly',
            'slug' => 'tail-lamp-assembly',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $catalogItem->id,
            'storage_status' => Product::STORAGE_STATUS_SOLD,
            'notes' => 'Разбит',
        ]);
        Product::query()->create([
            'sku' => 'STOCK-0001',
            'external_sku' => 'TAIL-STOCK',
            'name' => 'Tail lamp warehouse only',
            'slug' => 'tail-lamp-warehouse-only',
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
        ]);
        $uncheckedProduct = Product::query()->create([
            'sku' => 'DON1-0002',
            'external_sku' => 'UNCHECKED-001',
            'name' => 'Unchecked donor part',
            'slug' => 'unchecked-donor-part',
            'donor_car_id' => $donorCar->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
        ]);
        $sale = PartSale::query()->create([
            'part_catalog_item_id' => $saleCatalogItem->id,
            'product_id' => $soldProduct->id,
            'donor_car_id' => $donorCar->id,
            'source' => 'nikolacars',
            'part_number' => 'STR-200',
            'name' => 'Steering wheel',
            'quantity' => 1,
            'unit_price' => 120,
            'currency' => 'USD',
            'sold_at' => '2026-06-01 10:00:00',
            'source_row_hash' => 'donor-part-suggestion-sale',
        ]);

        $this->actingAs($user)
            ->getJson(route('admin.donor-cars.parts.search', ['q' => 'TAIL']))
            ->assertOk()
            ->assertJsonFragment([
                'type' => 'product',
                'name' => "\u{0424}\u{043E}\u{043D}\u{0430}\u{0440}\u{044C} \u{0437}\u{0430}\u{0434}\u{043D}\u{0438}\u{0439}",
                'part_number' => 'TAIL-001',
                'status' => "\u{041F}\u{0440}\u{043E}\u{0434}\u{0430}\u{043D}",
                'url' => route('admin.products.show', $soldProduct),
            ])
            ->assertJsonMissing([
                'name' => 'Tail lamp warehouse only',
            ]);

        $this->actingAs($user)
            ->getJson(route('admin.donor-cars.parts.search', ['q' => "\u{0424}\u{043E}\u{043D}\u{0430}\u{0440}\u{044C}"]))
            ->assertOk()
            ->assertJsonFragment([
                'type' => 'product',
                'name' => "\u{0424}\u{043E}\u{043D}\u{0430}\u{0440}\u{044C} \u{0437}\u{0430}\u{0434}\u{043D}\u{0438}\u{0439}",
                'part_number' => 'TAIL-001',
                'status' => "\u{041F}\u{0440}\u{043E}\u{0434}\u{0430}\u{043D}",
                'url' => route('admin.products.show', $soldProduct),
            ]);

        $this->actingAs($user)
            ->getJson(route('admin.donor-cars.parts.search', ['q' => 'STR-200']))
            ->assertOk()
            ->assertJsonFragment([
                'type' => 'product',
                'name' => "\u{0420}\u{0443}\u{043B}\u{044C}",
                'part_number' => 'STR-200',
                'status' => "\u{041F}\u{0440}\u{043E}\u{0434}\u{0430}\u{043D}",
                'url' => route('admin.products.show', $soldProduct),
            ]);

        $this->actingAs($user)
            ->getJson(route('admin.donor-cars.parts.search', ['q' => 'UNCHECKED-001']))
            ->assertOk()
            ->assertJsonFragment([
                'type' => 'product',
                'name' => 'Unchecked donor part',
                'part_number' => 'UNCHECKED-001',
                'status' => "\u{041D}\u{0435}\u{0438}\u{0437}\u{0432}\u{0435}\u{0441}\u{0442}\u{043D}\u{043E}",
                'url' => route('admin.products.show', $uncheckedProduct),
            ]);
        $this->actingAs($user)
            ->getJson(route('admin.mobile.parts.search', ['q' => "\u{0424}\u{043E}\u{043D}\u{0430}\u{0440}\u{044C}", 'context' => 'mobile']))
            ->assertOk()
            ->assertJsonFragment([
                'type' => 'product',
                'name' => "\u{0424}\u{043E}\u{043D}\u{0430}\u{0440}\u{044C} \u{0437}\u{0430}\u{0434}\u{043D}\u{0438}\u{0439}",
                'part_number' => 'TAIL-001',
                'status' => "\u{041F}\u{0440}\u{043E}\u{0434}\u{0430}\u{043D}",
                'url' => route('admin.mobile.donor-cars.products.edit', [$donorCar, $soldProduct]),
            ]);
    }

    public function test_donor_car_part_suggestions_include_donor_year_and_sto_arrival_date(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-donor-part-suggestions-dates@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJGLOBALPART0002',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
            'warehouse_arrival_date' => '2026-06-12',
            'status' => DonorCar::STATUS_AT_STO,
        ]);
        $product = Product::query()->create([
            'sku' => 'DON2-0001',
            'external_sku' => 'HANDLE-001',
            'name' => 'Door handle suggestion',
            'slug' => 'door-handle-suggestion',
            'donor_car_id' => $donorCar->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('admin.donor-cars.parts.search', ['q' => 'HANDLE']))
            ->assertOk();

        $payload = $response->json();

        $this->assertSame(1, count($payload));
        $this->assertSame(route('admin.products.show', $product), $payload[0]['url']);
        $this->assertSame(2020, $payload[0]['donor_year']);
        $this->assertSame('12.06.2026', $payload[0]['donor_warehouse_arrival_date']);
        $this->assertStringContainsString("\u{0413}\u{043E}\u{0434}: 2020", $payload[0]['meta']);
        $this->assertStringContainsString("\u{0421}\u{0422}\u{041E}: 12.06.2026", $payload[0]['meta']);
    }
}
