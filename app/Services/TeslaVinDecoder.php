<?php

namespace App\Services;

class TeslaVinDecoder
{
    private const YEAR_CODES = [
        'D' => 2013,
        'E' => 2014,
        'F' => 2015,
        'G' => 2016,
        'H' => 2017,
        'J' => 2018,
        'K' => 2019,
        'L' => 2020,
        'M' => 2021,
        'N' => 2022,
        'P' => 2023,
        'R' => 2024,
        'S' => 2025,
        'T' => 2026,
    ];

    public function decode(?string $vin): ?array
    {
        $vin = strtoupper(trim((string) $vin));

        if (! preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $vin)) {
            return null;
        }

        $year = self::YEAR_CODES[$vin[9]] ?? null;
        $model = $this->modelFromVin($vin, $year);

        if ($model === null) {
            return null;
        }

        return [
            'vin' => $vin,
            'brand' => 'Tesla',
            'model' => $model,
            'year' => $year,
        ];
    }

    private function modelFromVin(string $vin, ?int $year): ?string
    {
        return match (substr($vin, 0, 4)) {
            '5YJ3', '7SA3' => $year !== null && $year >= 2024
                ? 'Model 3 Highland 01.2024 - '
                : 'Model 3 06.2017 - 12.2023',
            '5YJY', '7SAY' => 'Model Y 01.2020 - 01.2025',
            '5YJS', '7SAS' => $year !== null && $year >= 2021
                ? 'Model S 01.2021 - '
                : 'Model S 05.2016 - 12.2020',
            '5YJX', '7SAX' => $year !== null && $year >= 2021
                ? 'Model X 01.2021 - '
                : 'Model X 05.2016 - 12.2020',
            default => null,
        };
    }
}
