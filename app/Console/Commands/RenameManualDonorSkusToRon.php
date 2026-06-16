<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\NikolaCarsProductInventorySyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RenameManualDonorSkusToRon extends Command
{
    protected $signature = 'products:rename-manual-donor-skus-to-ron
        {--apply : Persist the planned SKU changes}
        {--json : Output the plan as JSON}';

    protected $description = 'Preview or rename existing manual donor DON* product SKUs to the RON* sequence.';

    public function handle(NikolaCarsProductInventorySyncService $inventorySyncService): int
    {
        $plans = $this->plans();

        if ($this->option('json')) {
            $this->line(json_encode([
                'apply' => (bool) $this->option('apply'),
                'count' => $plans->count(),
                'items' => $plans->values(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->info(($this->option('apply') ? 'Applying' : 'Dry run').': '.$plans->count().' product SKU change(s).');
            $this->table(
                ['product_id', 'donor_car_id', 'old_sku', 'new_sku', 'barcode', 'qr_code', 'external_sku', 'name'],
                $plans->map(fn (array $plan): array => [
                    $plan['product_id'],
                    $plan['donor_car_id'],
                    $plan['old_sku'],
                    $plan['new_sku'],
                    $plan['barcode_change'],
                    $plan['qr_code_change'],
                    $plan['external_sku'],
                    $plan['name'],
                ])->all()
            );
        }

        if (! $this->option('apply') || $plans->isEmpty()) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($plans, $inventorySyncService): void {
            foreach ($plans as $plan) {
                $product = Product::query()->lockForUpdate()->findOrFail($plan['product_id']);

                if ($product->sku !== $plan['old_sku']) {
                    throw new \RuntimeException("Product #{$product->id} SKU changed before apply.");
                }

                $payload = ['sku' => $plan['new_sku']];

                if ($plan['barcode_change'] === 'yes') {
                    $payload['barcode'] = $plan['new_sku'];
                }

                if ($plan['qr_code_change'] === 'yes') {
                    $payload['qr_code'] = $plan['new_sku'];
                }

                $product->forceFill($payload)->save();
                $inventorySyncService->syncProduct($product->refresh());
            }
        });

        $this->info('Applied '.$plans->count().' product SKU change(s).');

        return self::SUCCESS;
    }

    protected function plans(): Collection
    {
        $usedSkus = Product::query()
            ->whereNotNull('sku')
            ->pluck('id', 'sku')
            ->map(fn (int $id): int => $id);
        $reservedTargets = [];

        return $this->candidateProducts()
            ->map(function (Product $product) use ($usedSkus, &$reservedTargets): ?array {
                $parsed = $this->parseDonSku((string) $product->sku);

                if ($parsed === null || $parsed['donor_car_id'] !== (int) $product->donor_car_id) {
                    return null;
                }

                $target = $this->uniqueRonSku(
                    donorCarId: (int) $product->donor_car_id,
                    preferredNumber: $parsed['number'],
                    currentProductId: (int) $product->id,
                    usedSkus: $usedSkus,
                    reservedTargets: $reservedTargets
                );
                $reservedTargets[$target] = true;

                return [
                    'product_id' => (int) $product->id,
                    'donor_car_id' => (int) $product->donor_car_id,
                    'old_sku' => (string) $product->sku,
                    'new_sku' => $target,
                    'barcode_change' => $this->shouldMirrorSku($product->barcode, $product->sku) ? 'yes' : 'no',
                    'qr_code_change' => $this->shouldMirrorSku($product->qr_code, $product->sku) ? 'yes' : 'no',
                    'external_sku' => (string) $product->external_sku,
                    'name' => (string) $product->name,
                ];
            })
            ->filter()
            ->values();
    }

    protected function candidateProducts(): Collection
    {
        return Product::query()
            ->whereNotNull('donor_car_id')
            ->where('is_auto_generated', false)
            ->whereNull('generated_at')
            ->where('sku', 'like', 'DON%')
            ->orderBy('donor_car_id')
            ->orderBy('sku')
            ->orderBy('id')
            ->get([
                'id',
                'donor_car_id',
                'sku',
                'barcode',
                'qr_code',
                'external_sku',
                'name',
            ]);
    }

    protected function parseDonSku(string $sku): ?array
    {
        if (preg_match('/^DON(?P<donor_car_id>\d+)-(?P<number>\d{4})(?:-\d+)?$/', $sku, $matches) !== 1) {
            return null;
        }

        return [
            'donor_car_id' => (int) $matches['donor_car_id'],
            'number' => (int) $matches['number'],
        ];
    }

    protected function uniqueRonSku(
        int $donorCarId,
        int $preferredNumber,
        int $currentProductId,
        Collection $usedSkus,
        array $reservedTargets
    ): string {
        $number = $preferredNumber;

        do {
            $sku = 'RON'.$donorCarId.'-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
            $usedBy = $usedSkus->get($sku);
            $number++;
        } while (($usedBy !== null && (int) $usedBy !== $currentProductId) || isset($reservedTargets[$sku]));

        return $sku;
    }

    protected function shouldMirrorSku(mixed $value, mixed $oldSku): bool
    {
        $value = trim((string) $value);

        return $value === '' || $value === trim((string) $oldSku);
    }
}
