<?php

namespace App\Models\Concerns;

trait HasUppercaseVinAttributes
{
    public function getVinAttribute(?string $value): ?string
    {
        return $this->uppercaseVin($value);
    }

    public function setVinAttribute(?string $value): void
    {
        $this->attributes['vin'] = $this->uppercaseVin($value);
    }

    public function getVehicleVinAttribute(?string $value): ?string
    {
        return $this->uppercaseVin($value);
    }

    public function setVehicleVinAttribute(?string $value): void
    {
        $this->attributes['vehicle_vin'] = $this->uppercaseVin($value);
    }

    private function uppercaseVin(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return strtoupper($value);
    }
}
