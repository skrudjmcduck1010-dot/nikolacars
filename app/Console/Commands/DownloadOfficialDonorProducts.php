<?php

namespace App\Console\Commands;

use App\Models\DonorCar;
use App\Services\DonorProductGenerationService;
use App\Services\OfficialCatalogDownloadStatus;
use App\Services\TeslaCatalogDonorProductSync;
use App\Services\TeslaOfficialVinSpecificCatalogCleanupService;
use Illuminate\Console\Command;

class DownloadOfficialDonorProducts extends Command
{
    protected $signature = 'donor-cars:download-official {donorCarId} {--token=}';

    protected $description = 'Download official Tesla catalog products for a donor car in the background.';

    public function handle(
        DonorProductGenerationService $generator,
        OfficialCatalogDownloadStatus $statuses,
        TeslaCatalogDonorProductSync $catalogSync,
        TeslaOfficialVinSpecificCatalogCleanupService $vinSpecificCleanup,
    ): int {
        $donorCar = DonorCar::query()->findOrFail((int) $this->argument('donorCarId'));

        try {
            $statuses->markRunning($donorCar, 'Подбираю детали из официального каталога Tesla.');

            $preview = $generator->preview($donorCar, []);
            $catalogItemIds = collect($preview['items'])
                ->where('status', 'creatable')
                ->filter(fn (array $item): bool => ($item['source'] ?? null) === 'tesla_official'
                    && str_starts_with((string) ($item['source_url'] ?? ''), 'https://parts.tesla.com/'))
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all();

            if ($catalogItemIds === []) {
                $hasOfficialGeneratedProducts = $donorCar->products()
                    ->where('is_auto_generated', true)
                    ->whereHas(
                        'sourcePartCatalogItem',
                        fn ($query) => $query->where('source', 'tesla_official')
                    )
                    ->exists();

                if ($hasOfficialGeneratedProducts) {
                    $catalogSync->syncDonor($donorCar);
                    $cleanupStats = $vinSpecificCleanup->cleanupDonor($donorCar);

                    $statuses->complete($donorCar, [
                        'created' => 0,
                        'created_whole' => 0,
                        'created_damaged' => 0,
                        'updated_existing' => 0,
                        'skipped_existing' => 0,
                        'vin_specific_items_deleted' => $cleanupStats['items_deleted'],
                        'vin_specific_products_relinked' => $cleanupStats['products_relinked'],
                    ]);

                    return self::SUCCESS;
                }

                $statuses->fail($donorCar, 'Для этого донора не найдены подходящие запчасти в официальном каталоге Tesla.');

                return self::SUCCESS;
            }

            $statuses->markRunning($donorCar, 'Создаю запчасти на доноре из официального каталога Tesla.');
            $stats = $generator->generate($donorCar, [], $catalogItemIds);
            $catalogSync->syncDonor($donorCar);
            $cleanupStats = $vinSpecificCleanup->cleanupDonor($donorCar);
            $stats['vin_specific_items_deleted'] = (int) ($stats['vin_specific_items_deleted'] ?? 0) + (int) $cleanupStats['items_deleted'];
            $stats['vin_specific_products_relinked'] = (int) ($stats['vin_specific_products_relinked'] ?? 0) + (int) $cleanupStats['products_relinked'];
            $statuses->complete($donorCar, $stats);

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            report($exception);
            $statuses->fail($donorCar, 'Не удалось выкачать официальный каталог: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
