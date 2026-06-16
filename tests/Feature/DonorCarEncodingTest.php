<?php

namespace Tests\Feature;

use App\Models\DonorCar;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DonorCarEncodingTest extends TestCase
{
    use RefreshDatabase;

    public function test_donor_cars_index_repairs_mojibake_pseudo_vin_labels(): void
    {
        DonorCar::query()->create([
            'vin' => 'TESLA MX 2015 - 2021 Р·Р°Р»РёС€РєРё',
            'brand' => 'Tesla',
            'model' => 'Model X',
            'color' => 'Black',
            'mileage' => 1,
            'purchase_date' => '2026-05-01',
        ]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.donor-cars.index'))
            ->assertOk()
            ->assertSee('TESLA MX 2015 - 2021 залишки')
            ->assertDontSee('Р·Р°Р»РёС€РєРё');
    }

    public function test_donor_cars_index_shows_inline_paint_code_editor_and_suggestions(): void
    {
        DonorCar::query()->create([
            'vin' => '5YJPAINT000000001',
            'brand' => 'Tesla',
            'model' => 'Model Y',
            'color' => 'Black',
            'paint_code' => 'PBSB',
            'mileage' => 1,
            'purchase_date' => '2026-05-01',
        ]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.donor-cars.index'))
            ->assertOk()
            ->assertSee('&#1052;&#1072;&#1088;&#1082;&#1080;&#1088;&#1086;&#1074;&#1082;&#1072;', false)
            ->assertSee('&#1094;&#1074;&#1077;&#1090;&#1072;', false)
            ->assertSee('data-donor-paint-code-edit', false)
            ->assertSee('data-donor-paint-code-save', false)
            ->assertSee('list="donor-paint-code-suggestions"', false)
            ->assertSee('<option value="PBSB"></option>', false);
    }

    public function test_donor_car_paint_code_can_be_updated_inline(): void
    {
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJPAINT000000002',
            'brand' => 'Tesla',
            'model' => 'Model Y',
            'color' => 'White',
            'paint_code' => 'PPSW',
            'mileage' => 1,
            'purchase_date' => '2026-05-01',
        ]);

        $this->actingAs($this->adminUser())
            ->patchJson(route('admin.donor-cars.paint-code.update', $donorCar), [
                'paint_code' => 'PMNG',
            ])
            ->assertOk()
            ->assertJson([
                'paint_code' => 'PMNG',
            ]);

        $this->assertSame('PMNG', $donorCar->refresh()->paint_code);
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
