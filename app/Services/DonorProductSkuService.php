<?php

namespace App\Services;

use App\Models\DonorCar;
use App\Models\Product;

class DonorProductSkuService
{
    private const AUTO_PREFIX = 'DON';

    private const MANUAL_PREFIX = 'RON';

    public function nextAutoCode(DonorCar $donorCar): string
    {
        return $this->format(self::AUTO_PREFIX, $donorCar, $this->nextNumber($donorCar, self::AUTO_PREFIX));
    }

    public function uniqueAutoCode(DonorCar $donorCar): string
    {
        return $this->uniqueCode($donorCar, self::AUTO_PREFIX);
    }

    public function nextManualCode(DonorCar $donorCar): string
    {
        return $this->format(self::MANUAL_PREFIX, $donorCar, $this->nextNumber($donorCar, self::MANUAL_PREFIX));
    }

    public function uniqueManualCode(DonorCar $donorCar): string
    {
        return $this->uniqueCode($donorCar, self::MANUAL_PREFIX);
    }

    private function uniqueCode(DonorCar $donorCar, string $prefix): string
    {
        $nextNumber = $this->nextNumber($donorCar, $prefix);

        do {
            $sku = $this->format($prefix, $donorCar, $nextNumber);
            $nextNumber++;
        } while (Product::query()
            ->where('sku', $sku)
            ->orWhere('barcode', $sku)
            ->orWhere('qr_code', $sku)
            ->exists());

        return $sku;
    }

    private function nextNumber(DonorCar $donorCar, string $prefix): int
    {
        $lastNumber = Product::query()
            ->where('donor_car_id', $donorCar->id)
            ->where('sku', 'like', $prefix.$donorCar->id.'-%')
            ->pluck('sku')
            ->map(fn (string $sku): int => $this->numberFromSku($sku, $prefix, $donorCar))
            ->max();

        return ((int) $lastNumber) + 1;
    }

    private function numberFromSku(string $sku, string $prefix, DonorCar $donorCar): int
    {
        if (preg_match('/^'.preg_quote($prefix.(string) $donorCar->id, '/').'-(\d{4})$/', $sku, $matches) !== 1) {
            return 0;
        }

        return (int) $matches[1];
    }

    private function format(string $prefix, DonorCar $donorCar, int $number): string
    {
        return $prefix.$donorCar->id.'-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }
}
