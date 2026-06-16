<?php

namespace App\Console\Commands;

use App\Models\PartCatalogItem;
use App\Services\NikolaCarsInterimDonorResolver;
use Illuminate\Console\Command;

class EnsureNikolaCarsInterimDonors extends Command
{
    protected $signature = 'nikolacars:donors:ensure-interim {--dry-run : Show what would be created without saving changes}';

    protected $description = 'Create interim donor cars for NikolaCars catalog remainder groups.';

    public function handle(NikolaCarsInterimDonorResolver $donorResolver): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $stats = [
            'catalog_items_scanned' => 0,
            'donor_groups_found' => 0,
            'donors_created' => 0,
        ];
        $labels = [];

        PartCatalogItem::query()
            ->where('source', 'nikolacars')
            ->where('compatibility_text', 'like', '%залишки%')
            ->orderBy('id')
            ->chunkById(500, function ($items) use ($donorResolver, $dryRun, &$stats, &$labels): void {
                foreach ($items as $item) {
                    $stats['catalog_items_scanned']++;

                    $label = $donorResolver->donorLabel($item);
                    if ($label === null || isset($labels[$label])) {
                        continue;
                    }

                    $labels[$label] = true;
                    $stats['donor_groups_found']++;

                    if ($dryRun) {
                        continue;
                    }

                    $donor = $donorResolver->resolve($item);
                    if ($donor?->wasRecentlyCreated) {
                        $stats['donors_created']++;
                    }
                }
            });

        $this->info(json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
