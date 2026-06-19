<?php

namespace App\Console\Commands;

use App\Services\PartCatalogGoogleTranslateLocalizedNameBackfillService;
use Illuminate\Console\Command;

class GoogleTranslateMissingLocalizedNames extends Command
{
    protected $signature = 'parts:google-translate-missing-localized-names
        {--dry-run : Show changes without saving}
        {--all-sources : Include all catalog sources instead of only NikolaCars}
        {--only-id=0 : Translate only one part_catalog_items id}
        {--product-id=0 : Translate the catalog row linked to one products id}
        {--limit=0 : Maximum catalog items to process}
        {--show-progress : Print each translated item}';

    protected $description = 'Retired Google Translate backfill for missing RU/UA catalog names.';

    public function handle(PartCatalogGoogleTranslateLocalizedNameBackfillService $backfill): int
    {
        $stats = $backfill->backfill([
            'dry_run' => (bool) $this->option('dry-run'),
            'all_sources' => (bool) $this->option('all-sources'),
            'only_id' => (int) $this->option('only-id'),
            'product_id' => (int) $this->option('product-id'),
            'limit' => (int) $this->option('limit'),
            'progress' => (bool) $this->option('show-progress') ? fn (string $message) => $this->line($message) : null,
        ]);

        $this->info('Localized catalog names are frozen; Google Translate backfill is retired.');
        foreach ($stats as $name => $value) {
            $this->line(" - {$name}: {$value}");
        }

        if ((bool) $this->option('dry-run')) {
            $this->warn('Dry run only. Re-run without --dry-run to save translations.');
        }

        return self::SUCCESS;
    }
}
