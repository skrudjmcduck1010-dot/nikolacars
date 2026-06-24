<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class WarehouseManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_on_donor_warehouse_does_not_offer_manual_location_creation(): void
    {
        $user = $this->adminUser();
        $mainWarehouse = Warehouse::query()->create([
            'name' => 'Main',
            'type' => Warehouse::TYPE_MAIN,
            'floor_count' => 1,
            'is_active' => true,
        ]);
        $donorWarehouse = Warehouse::query()->create([
            'name' => Warehouse::DONOR_WAREHOUSE_NAME,
            'type' => Warehouse::TYPE_DONOR,
            'floor_count' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.warehouses.index'))
            ->assertOk()
            ->assertSee("location-dialog-{$mainWarehouse->id}", false)
            ->assertDontSee("location-dialog-{$donorWarehouse->id}", false);
    }

    public function test_warehouse_index_offers_floor_specific_location_creation_and_short_location_names(): void
    {
        $warehouse = Warehouse::query()->create([
            'name' => 'Two floor warehouse',
            'type' => Warehouse::TYPE_MAIN,
            'floor_count' => 2,
            'is_active' => true,
        ]);
        $firstFloorLocation = Location::query()->create([
            'warehouse_id' => $warehouse->id,
            'floor' => 'floor_1',
            'full_code' => 'WH2-F1-A10',
            'cell' => 'A10',
            'is_active' => true,
        ]);
        Location::query()->create([
            'warehouse_id' => $warehouse->id,
            'floor' => 'floor_2',
            'full_code' => 'WH2-F2-B20',
            'is_active' => true,
        ]);
        $product = Product::query()->create([
            'sku' => 'WH-CELL-QTY-001',
            'name' => 'Cell quantity part',
            'slug' => 'cell-quantity-part',
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $product->stockItems()->create([
            'warehouse_id' => $warehouse->id,
            'location_id' => $firstFloorLocation->id,
            'quantity' => 3,
            'reserved_quantity' => 0,
            'testing_status' => 'not_tested',
        ]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.warehouses.index'))
            ->assertOk()
            ->assertSee('data-location-floor="floor_1"', false)
            ->assertSee('data-location-floor="floor_2"', false)
            ->assertSee('<span>A10</span>', false)
            ->assertSee('<small>3 запчастей</small>', false)
            ->assertSee('<span>B20</span>', false)
            ->assertSee('<small>0 запчастей</small>', false)
            ->assertDontSee('WH2-F1-A10')
            ->assertDontSee('WH2-F2-B20');
    }

    public function test_location_cannot_be_manually_created_for_on_donor_warehouse(): void
    {
        $donorWarehouse = Warehouse::query()->create([
            'name' => Warehouse::DONOR_WAREHOUSE_NAME,
            'type' => Warehouse::TYPE_DONOR,
            'floor_count' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($this->adminUser())
            ->from(route('admin.locations.create'))
            ->post(route('admin.locations.store'), [
                'warehouse_id' => $donorWarehouse->id,
                'full_code' => 'MANUAL-DONOR-CELL',
                'floor' => 'floor_1',
                'zone' => 'A',
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.locations.create'))
            ->assertSessionHasErrors('warehouse_id');

        $this->assertDatabaseMissing('locations', [
            'warehouse_id' => $donorWarehouse->id,
            'full_code' => 'MANUAL-DONOR-CELL',
        ]);
    }

    public function test_warehouse_with_stock_cannot_be_deleted(): void
    {
        $warehouse = Warehouse::query()->create([
            'name' => 'Stocked',
            'type' => Warehouse::TYPE_MAIN,
            'floor_count' => 1,
            'is_active' => true,
        ]);
        $location = Location::query()->create([
            'warehouse_id' => $warehouse->id,
            'floor' => 'floor_1',
            'full_code' => 'STOCKED-A1',
            'cell' => 'A1',
            'is_active' => true,
        ]);
        $product = Product::query()->create([
            'sku' => 'WH-STOCK-001',
            'name' => 'Warehouse stock part',
            'slug' => 'warehouse-stock-part',
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
            ->get(route('admin.warehouses.index'))
            ->assertOk()
            ->assertDontSee('action="'.route('admin.warehouses.destroy', $warehouse).'"', false);

        $this->actingAs($this->adminUser())
            ->delete(route('admin.warehouses.destroy', $warehouse))
            ->assertRedirect(route('admin.warehouses.index'));

        $this->assertDatabaseHas('warehouses', [
            'id' => $warehouse->id,
        ]);
    }

    public function test_warehouse_index_shows_stock_quantities(): void
    {
        $warehouse = Warehouse::query()->create([
            'name' => 'Quantity warehouse',
            'type' => Warehouse::TYPE_MAIN,
            'floor_count' => 1,
            'is_active' => true,
        ]);
        $location = Location::query()->create([
            'warehouse_id' => $warehouse->id,
            'floor' => 'floor_1',
            'full_code' => 'QTY-A1',
            'cell' => 'A1',
            'is_active' => true,
        ]);
        $product = Product::query()->create([
            'sku' => 'WH-QTY-001',
            'name' => 'Quantity part',
            'slug' => 'quantity-part',
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
            'quantity' => 7,
            'reserved_quantity' => 2,
            'available_quantity' => 5,
            'testing_status' => 'not_tested',
        ]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.warehouses.index'))
            ->assertOk()
            ->assertSee('Товары')
            ->assertSee('Quantity warehouse')
            ->assertSee('<strong>7</strong>', false)
            ->assertSee('Позиций 1 · доступно 5 · резерв 2');
    }

    public function test_warehouse_index_does_not_show_action_column(): void
    {
        $warehouse = Warehouse::query()->create([
            'name' => 'No edit warehouse',
            'type' => Warehouse::TYPE_MAIN,
            'floor_count' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.warehouses.index'))
            ->assertOk()
            ->assertDontSee('href="'.route('admin.warehouses.edit', $warehouse).'"', false)
            ->assertDontSee('action="'.route('admin.warehouses.destroy', $warehouse).'"', false)
            ->assertDontSee('Есть товары, удалить нельзя');
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
}
