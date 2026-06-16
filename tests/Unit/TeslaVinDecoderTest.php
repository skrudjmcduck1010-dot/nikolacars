<?php

namespace Tests\Unit;

use App\Services\TeslaVinDecoder;
use PHPUnit\Framework\TestCase;

class TeslaVinDecoderTest extends TestCase
{
    public function test_it_decodes_tesla_model_and_year_from_vin(): void
    {
        $decoded = (new TeslaVinDecoder)->decode('5YJSA1E41MF424298');

        $this->assertSame([
            'vin' => '5YJSA1E41MF424298',
            'brand' => 'Tesla',
            'model' => 'Model S 01.2021 - ',
            'year' => 2021,
        ], $decoded);
    }

    public function test_it_decodes_2024_model_3_as_highland(): void
    {
        $decoded = (new TeslaVinDecoder)->decode('5YJ3E1EA7RF722862');

        $this->assertSame([
            'vin' => '5YJ3E1EA7RF722862',
            'brand' => 'Tesla',
            'model' => 'Model 3 Highland 01.2024 - ',
            'year' => 2024,
        ], $decoded);
    }

    public function test_it_ignores_invalid_or_unknown_vins(): void
    {
        $decoder = new TeslaVinDecoder;

        $this->assertNull($decoder->decode('not-a-vin'));
        $this->assertNull($decoder->decode('1HGCM82633A004352'));
    }
}
