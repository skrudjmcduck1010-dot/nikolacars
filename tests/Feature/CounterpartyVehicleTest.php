<?php

namespace Tests\Feature;

use App\Models\Counterparty;
use App\Models\CounterpartyVehicle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CounterpartyVehicleTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_add_another_vehicle_to_counterparty(): void
    {
        $user = $this->adminUser();
        $counterparty = Counterparty::query()->create([
            'type' => 'customer',
            'name' => 'Service Client',
            'phone' => '+380991112233',
            'car_model' => 'Model 3',
            'car_year' => 2021,
            'drive_type' => 'rear',
            'vin' => '5YJ3E1EA7NF000001',
            'license_plate' => 'AA1111AA',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->post(route('admin.counterparties.vehicles.store', $counterparty), [
            'car_model' => 'Model Y',
            'car_year' => 2024,
            'drive_type' => 'all',
            'vin' => '7SAYGDEE1PF000001',
            'license_plate' => 'BC2222BC',
        ]);

        $response->assertRedirect(route('admin.counterparties.show', $counterparty));

        $this->assertDatabaseHas('counterparty_vehicles', [
            'counterparty_id' => $counterparty->id,
            'car_model' => 'Model Y',
            'car_year' => 2024,
            'drive_type' => 'all',
            'vin' => '7SAYGDEE1PF000001',
            'license_plate' => 'BC2222BC',
        ]);

        $this->actingAs($user)
            ->get(route('admin.counterparties.show', $counterparty))
            ->assertOk()
            ->assertSee('Model 3')
            ->assertSee('5YJ3E1EA7NF000001')
            ->assertSee('Model Y')
            ->assertSee('7SAYGDEE1PF000001')
            ->assertSee('BC2222BC');
    }

    public function test_admin_can_delete_primary_vehicle_from_counterparty(): void
    {
        $user = $this->adminUser();
        $counterparty = Counterparty::query()->create([
            'type' => 'customer',
            'name' => 'Service Client',
            'phone' => '+380991112233',
            'car_model' => 'Model 3',
            'car_year' => 2021,
            'drive_type' => 'rear',
            'vin' => '5YJ3E1EA7NF000001',
            'license_plate' => 'AA1111AA',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->delete(route('admin.counterparties.vehicles.primary.destroy', $counterparty));

        $response->assertRedirect(route('admin.counterparties.show', $counterparty));

        $this->assertDatabaseHas('counterparties', [
            'id' => $counterparty->id,
            'car_model' => null,
            'car_year' => null,
            'drive_type' => null,
            'vin' => null,
            'license_plate' => null,
        ]);
    }

    public function test_admin_can_delete_another_vehicle_from_counterparty(): void
    {
        $user = $this->adminUser();
        $counterparty = Counterparty::query()->create([
            'type' => 'customer',
            'name' => 'Service Client',
            'phone' => '+380991112233',
            'car_model' => 'Model 3',
            'car_year' => 2021,
            'drive_type' => 'rear',
            'vin' => '5YJ3E1EA7NF000001',
            'license_plate' => 'AA1111AA',
            'is_active' => true,
        ]);
        $vehicle = CounterpartyVehicle::query()->create([
            'counterparty_id' => $counterparty->id,
            'car_model' => 'Model Y',
            'car_year' => 2024,
            'drive_type' => 'all',
            'vin' => '7SAYGDEE1PF000001',
            'license_plate' => 'BC2222BC',
        ]);

        $response = $this->actingAs($user)->delete(route('admin.counterparties.vehicles.destroy', [$counterparty, $vehicle]));

        $response->assertRedirect(route('admin.counterparties.show', $counterparty));

        $this->assertDatabaseMissing('counterparty_vehicles', [
            'id' => $vehicle->id,
        ]);
        $this->assertDatabaseHas('counterparties', [
            'id' => $counterparty->id,
            'vin' => '5YJ3E1EA7NF000001',
        ]);
    }

    private function adminUser(): User
    {
        return User::query()->create([
            'name' => 'Admin',
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
    }
}
