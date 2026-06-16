<?php

namespace Tests\Feature;

use App\Models\DonorCar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DonorCarAutomaticStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_transit_donor_moves_to_sto_when_arrival_details_are_complete(): void
    {
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJAUTO0000000001',
            'brand' => 'Tesla',
            'model' => 'Model Y',
            'year' => 2026,
            'status' => DonorCar::STATUS_IN_TRANSIT,
            'purchase_date' => '2026-05-01',
            'warehouse_arrival_date' => '2026-05-05',
            'estimated_cost_usd' => 10000,
            'usa_delivery_price_usd' => 1200,
            'klaipeda_ukraine_delivery_price_usd' => 900,
            'customs_clearance_price_usd' => 2500,
        ]);

        $this->assertSame(DonorCar::STATUS_AT_STO, $donorCar->refresh()->status);
    }

    public function test_transit_donor_stays_in_transit_until_all_arrival_details_are_complete(): void
    {
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJAUTO0000000002',
            'brand' => 'Tesla',
            'model' => 'Model Y',
            'year' => 2026,
            'status' => DonorCar::STATUS_IN_TRANSIT,
            'purchase_date' => '2026-05-01',
            'warehouse_arrival_date' => '2026-05-05',
            'estimated_cost_usd' => 10000,
            'usa_delivery_price_usd' => 1200,
            'klaipeda_ukraine_delivery_price_usd' => 900,
        ]);

        $this->assertSame(DonorCar::STATUS_IN_TRANSIT, $donorCar->refresh()->status);
    }

    public function test_automatic_sto_status_does_not_override_later_statuses(): void
    {
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJAUTO0000000003',
            'brand' => 'Tesla',
            'model' => 'Model Y',
            'year' => 2026,
            'status' => DonorCar::STATUS_DISMANTLING,
            'purchase_date' => '2026-05-01',
            'warehouse_arrival_date' => '2026-05-05',
            'estimated_cost_usd' => 10000,
            'usa_delivery_price_usd' => 1200,
            'klaipeda_ukraine_delivery_price_usd' => 900,
            'customs_clearance_price_usd' => 2500,
        ]);

        $this->assertSame(DonorCar::STATUS_DISMANTLING, $donorCar->refresh()->status);
    }
}
