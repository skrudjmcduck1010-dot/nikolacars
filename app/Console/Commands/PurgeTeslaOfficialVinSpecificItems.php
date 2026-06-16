<?php

namespace App\Console\Commands;

use App\Services\TeslaOfficialVinSpecificCatalogCleanupService;
use Illuminate\Console\Command;

class PurgeTeslaOfficialVinSpecificItems extends Command
{
    protected $signature = 'parts:purge-tesla-official-vin-specific-items
        {--donor-car-id= : Limit cleanup to one donor car}
        {--dry-run : Show what would be relinked/deleted without changing data}';

    protected $description = 'Relink products away from VIN-specific Tesla official rows and delete those temporary rows.';

    public function handle(TeslaOfficialVinSpecificCatalogCleanupService $cleanup): int
    {
        $donorCarId = $this->option('donor-car-id') !== null && $this->option('donor-car-id') !== ''
            ? (int) $this->option('donor-car-id')
            : null;

        $stats = $cleanup->cleanupAll(
            dryRun: (bool) $this->option('dry-run'),
            donorCarId: $donorCarId,
        );

        $this->info(($stats['dry_run'] ? 'Scanned' : 'Purged').' VIN-specific Tesla official rows.');

        foreach ([
            'items_seen',
            'items_would_delete',
            'items_deleted',
            'items_skipped_referenced',
            'products_seen',
            'products_relinked',
        ] as $name) {
            $this->line(" - {$name}: {$stats[$name]}");
        }

        return self::SUCCESS;
    }
}
