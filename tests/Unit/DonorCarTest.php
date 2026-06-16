<?php

namespace Tests\Unit;

use App\Models\DonorCar;
use PHPUnit\Framework\TestCase;

class DonorCarTest extends TestCase
{
    public function test_status_labels_are_not_blank(): void
    {
        foreach (DonorCar::STATUSES as $status => $label) {
            $this->assertNotSame('', trim($label), "Status {$status} has an empty label.");
        }
    }

    public function test_at_sto_is_not_a_selectable_status(): void
    {
        $this->assertArrayNotHasKey(DonorCar::STATUS_AT_STO, DonorCar::STATUSES);
        $this->assertArrayHasKey(DonorCar::STATUS_AT_STO, DonorCar::LEGACY_STATUSES);
    }

    public function test_highland_has_specific_battery_type_labels(): void
    {
        $this->assertSame([
            DonorCar::BATTERY_TYPE_STANDARD_RANGE => 'Highland RWD / Standard Range',
            DonorCar::BATTERY_TYPE_LONG_RANGE => 'Highland Long Range / AWD / Dual Motor',
            DonorCar::BATTERY_TYPE_PERFORMANCE => 'Highland Performance',
        ], DonorCar::batteryTypeOptionsForModel('Model 3 Highland 01.2024 - '));
    }

    public function test_tesla_models_have_specific_battery_type_labels(): void
    {
        $this->assertSame([
            DonorCar::BATTERY_TYPE_STANDARD_RANGE => 'Model 3 RWD / Standard Range',
            DonorCar::BATTERY_TYPE_LONG_RANGE => 'Model 3 Long Range / AWD / Dual Motor',
            DonorCar::BATTERY_TYPE_PERFORMANCE => 'Model 3 Performance',
        ], DonorCar::batteryTypeOptionsForModel('Model 3 06.2017 - 12.2023'));

        $this->assertSame([
            DonorCar::BATTERY_TYPE_STANDARD_RANGE => 'Model Y RWD / Standard Range',
            DonorCar::BATTERY_TYPE_LONG_RANGE => 'Model Y Long Range / AWD / Dual Motor',
            DonorCar::BATTERY_TYPE_PERFORMANCE => 'Model Y Performance',
        ], DonorCar::batteryTypeOptionsForModel('Model Y 01.2020 - 01.2025'));

        $this->assertSame([
            DonorCar::BATTERY_TYPE_STANDARD_RANGE => 'Model S Base / Standard / 60-75',
            DonorCar::BATTERY_TYPE_LONG_RANGE => 'Model S Long Range / 85-100',
            DonorCar::BATTERY_TYPE_PERFORMANCE => 'Model S Performance / Ludicrous / Plaid',
        ], DonorCar::batteryTypeOptionsForModel('Model S 01.2021 - '));

        $this->assertSame([
            DonorCar::BATTERY_TYPE_STANDARD_RANGE => 'Model X Base / Standard / 60-75',
            DonorCar::BATTERY_TYPE_LONG_RANGE => 'Model X Long Range / 90-100',
            DonorCar::BATTERY_TYPE_PERFORMANCE => 'Model X Performance / Ludicrous / Plaid',
        ], DonorCar::batteryTypeOptionsForModel('Model X 01.2021 - '));
    }

    public function test_display_vin_repairs_mojibake_pseudo_vin_labels(): void
    {
        $donorCar = new DonorCar([
            'vin' => 'TESLA Рњ3 2018 - 2023 Р·Р°Р»РёС€РєРё',
        ]);

        $this->assertSame('TESLA М3 2018 - 2023 залишки', $donorCar->display_vin);
    }

    public function test_color_repairs_short_mojibake_value(): void
    {
        $donorCar = new DonorCar([
            'color' => "\u{0420}\u{0490}\u{0420}\u{2014}",
        ]);

        $this->assertSame("\u{0425}\u{0417}", $donorCar->color);
    }

    public function test_paint_code_repairs_mojibake_value(): void
    {
        $donorCar = new DonorCar([
            'paint_code' => "\u{0420}\u{0490}\u{0420}\u{2014}",
        ]);

        $this->assertSame("\u{0425}\u{0417}", $donorCar->paint_code);
    }
}
