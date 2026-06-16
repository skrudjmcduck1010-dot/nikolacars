<?php

use App\Models\CashTransaction;
use App\Models\DonorCar;
use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Models\ValeraCashTransaction;
use App\Services\CompetitorCatalogImageLocalizer;
use App\Services\DkPartsCatalogImporter;
use App\Services\DonorProductGenerationService;
use App\Services\DrivePartsCatalogImporter;
use App\Services\ErazborkaCatalogImporter;
use App\Services\ExchangeRateService;
use App\Services\NikolaCarsCatalogImporter;
use App\Services\NikolaCarsTeslaCategoryResolver;
use App\Services\NikolaCarsTeslaCategoryTreeSyncService;
use App\Services\PartCatalogCategoryRouteService;
use App\Services\PartCatalogSourceStatsService;
use App\Services\PartCatalogZoneClassifier;
use App\Services\PublicStorageReferenceAuditService;
use App\Services\StoCashbookImporter;
use App\Services\StockTeslaCatalogImporter;
use App\Services\TcarserviceCatalogImporter;
use App\Services\TeslaCompanyCatalogImporter;
use App\Services\TeslaHelpCatalogImporter;
use App\Services\TeslaOfficialCatalogImporter;
use App\Services\TeslaOfficialCatalogOccurrenceBackfiller;
use App\Services\TeslaOfficialFindPartResultApplier;
use App\Services\TeslaOfficialVinSpecificCatalogCleanupService;
use App\Services\TeslaPartsUkraineCatalogImporter;
use App\Services\TeslaWestPartsCatalogImporter;
use App\Services\TopRazborkaCatalogImporter;
use App\Services\TskCatalogImporter;
use App\Services\TskCatalogProductUrlDedupeService;
use App\Services\ValeraCashbookImporter;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Process;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('storage:purge-unreferenced-public-files {--delete} {--prefix=*} {--sample=30} {--include-dotfiles}', function (PublicStorageReferenceAuditService $audit): int {
    if ((bool) $this->option('delete') && app()->environment('production')) {
        $this->error('Deleting public storage files is forbidden in production. Run without --delete for audit only.');

        return Command::FAILURE;
    }

    $stats = $audit->audit([
        'delete' => (bool) $this->option('delete'),
        'prefixes' => (array) $this->option('prefix'),
        'sample' => (int) $this->option('sample'),
        'include_dotfiles' => (bool) $this->option('include-dotfiles'),
    ]);

    $this->info(((bool) $this->option('delete') ? 'Purged locally' : 'Scanned').' unreferenced public storage files.');
    foreach ([
        'referenced_paths',
        'public_files_seen',
        'referenced_files_seen',
        'case_mismatched_referenced_files_seen',
        'unreferenced_files_seen',
        'unreferenced_megabytes_seen',
        'files_deleted',
        'megabytes_deleted',
        'directories_deleted',
        'missing_referenced_files',
    ] as $name) {
        $this->line(" - {$name}: ".$stats[$name]);
    }

    if ($stats['unreferenced_by_prefix'] !== []) {
        $this->line('Unreferenced by prefix:');
        foreach (array_slice($stats['unreferenced_by_prefix'], 0, 20, true) as $prefix => $row) {
            $this->line(" - {$prefix}: {$row['files']} files, {$row['megabytes']} MB");
        }
    }

    if ($stats['sample_unreferenced'] !== []) {
        $this->line('Sample unreferenced files:');
        foreach ($stats['sample_unreferenced'] as $path) {
            $this->line(" - {$path}");
        }
    }

    return Command::SUCCESS;
})->purpose('Find public storage files that are not referenced by catalog, product, or donor records');

Artisan::command('exchange-rates:fetch {--date=} {--currency=USD}', function (): int {
    $currency = strtoupper((string) $this->option('currency'));

    if ($currency !== 'USD') {
        $this->error('Only USD is supported for now.');

        return Command::FAILURE;
    }

    $exchangeRate = app(ExchangeRateService::class)->fetchAndStoreUsdRate($this->option('date') ?: null);

    if ($exchangeRate === null) {
        $this->error('Could not fetch USD exchange rate from NBU.');

        return Command::FAILURE;
    }

    $this->info(sprintf(
        'Stored USD rate for %s: %s',
        $exchangeRate->rate_date->toDateString(),
        number_format((float) $exchangeRate->rate, 6, '.', ''),
    ));

    return Command::SUCCESS;
})->purpose('Fetch and store the NBU USD exchange rate');

Schedule::command('exchange-rates:fetch --currency=USD')
    ->dailyAt('08:00')
    ->timezone('Europe/Kyiv')
    ->withoutOverlapping();

Artisan::command('sto:import-cashbook {path} {--fresh}', function (string $path): int {
    if (! is_file($path)) {
        $this->error("File not found: {$path}");

        return Command::FAILURE;
    }

    $rows = app(StoCashbookImporter::class)->rows($path);
    $fresh = (bool) $this->option('fresh');

    DB::transaction(function () use ($rows, $fresh): void {
        if ($fresh) {
            CashTransaction::query()->whereIn('source', ['csv', 'xlsx'])->delete();
        }

        foreach ($rows as $row) {
            CashTransaction::query()->create($row);
        }
    });

    $bySheet = collect($rows)->groupBy('source_sheet')->map->count()->filter();

    $this->info('Imported cashbook rows: '.count($rows));

    foreach ($bySheet as $sheet => $count) {
        $this->line(" - {$sheet}: {$count}");
    }

    return Command::SUCCESS;
})->purpose('Import STO cashbook rows from exported Google Sheets CSV/XLSX');

Artisan::command('valera:import-cashbook {path} {--fresh}', function (string $path): int {
    if (! is_file($path)) {
        $this->error("File not found: {$path}");

        return Command::FAILURE;
    }

    $rows = app(ValeraCashbookImporter::class)->rows($path);
    $fresh = (bool) $this->option('fresh');

    DB::transaction(function () use ($rows, $fresh): void {
        if ($fresh) {
            ValeraCashTransaction::query()->delete();
        }

        foreach ($rows as $row) {
            ValeraCashTransaction::query()->create($row);
        }
    });

    $bySheet = collect($rows)->groupBy('source_sheet')->map->count()->filter();

    $this->info('Imported Valera cashbook rows: '.count($rows));

    foreach ($bySheet as $sheet => $count) {
        $this->line(" - {$sheet}: {$count}");
    }

    return Command::SUCCESS;
})->purpose('Import Valera cashbook rows from XLSX');

Artisan::command('parts:import-tcarservice {--dry-run} {--categories-only} {--max-category-pages=0} {--max-products=0} {--sleep-ms=250} {--start-url=/zapchasty} {--no-skip-existing-categories} {--show-progress}', function (): int {
    $stats = app(TcarserviceCatalogImporter::class)->import([
        'dry_run' => (bool) $this->option('dry-run'),
        'categories_only' => (bool) $this->option('categories-only'),
        'max_category_pages' => (int) $this->option('max-category-pages'),
        'max_products' => (int) $this->option('max-products'),
        'sleep_ms' => (int) $this->option('sleep-ms'),
        'start_url' => (string) $this->option('start-url'),
        'skip_existing_categories' => ! (bool) $this->option('no-skip-existing-categories'),
        'verbose' => (bool) $this->option('show-progress'),
        'progress' => fn (string $message) => $this->line($message),
    ]);

    $this->info(($this->option('dry-run') ? 'Scanned' : 'Imported').' TCARS catalog.');

    foreach ($stats as $name => $value) {
        $this->line(" - {$name}: {$value}");
    }

    return Command::SUCCESS;
})->purpose('Import Tesla parts catalog from tcarservice.com into local suggestions');

Artisan::command('parts:import-tcarservice-categories {--dry-run} {--max-category-pages=0} {--sleep-ms=100} {--start-url=/zapchasty} {--resume} {--no-skip-existing-categories} {--show-progress}', function (): int {
    $stats = app(TcarserviceCatalogImporter::class)->import([
        'dry_run' => (bool) $this->option('dry-run'),
        'categories_only' => true,
        'max_category_pages' => (int) $this->option('max-category-pages'),
        'max_products' => 0,
        'sleep_ms' => (int) $this->option('sleep-ms'),
        'start_url' => (string) $this->option('start-url'),
        'skip_existing_categories' => ! (bool) $this->option('no-skip-existing-categories'),
        'verbose' => (bool) $this->option('show-progress'),
        'progress' => fn (string $message) => $this->line($message),
    ]);

    $leafCategories = PartCatalogCategory::query()->doesntHave('children')->count();

    $this->info(($this->option('dry-run') ? 'Scanned' : 'Imported').' TCARS categories.');

    foreach ($stats as $name => $value) {
        $this->line(" - {$name}: {$value}");
    }

    $this->line(" - leaf_categories: {$leafCategories}");

    return Command::SUCCESS;
})->purpose('Import only the TCARS category tree without parsing product cards');

Artisan::command('parts:import-tcarservice-category-previews {--dry-run} {--source-url=/zapchasty/model-s-321} {--all-models} {--exclude-source-url=*} {--show-progress}', function (): int {
    $stats = app(TcarserviceCatalogImporter::class)->importCategoryPreviews([
        'dry_run' => (bool) $this->option('dry-run'),
        'source_url' => (string) $this->option('source-url'),
        'all_models' => (bool) $this->option('all-models'),
        'exclude_source_urls' => (array) $this->option('exclude-source-url'),
        'verbose' => (bool) $this->option('show-progress'),
        'progress' => fn (string $message) => $this->line($message),
    ]);

    $this->info(($this->option('dry-run') ? 'Scanned' : 'Imported').' TCARS category previews.');

    foreach ($stats as $name => $value) {
        $this->line(" - {$name}: {$value}");
    }

    return Command::SUCCESS;
})->purpose('Import preview images for saved TCARS catalog categories');

Artisan::command('parts:import-tcarservice-products {--dry-run} {--max-categories=0} {--max-products=0} {--max-pages-per-category=20} {--sleep-ms=250} {--category-url=} {--rescan} {--show-progress}', function (): int {
    $stats = app(TcarserviceCatalogImporter::class)->importLeafProducts([
        'dry_run' => (bool) $this->option('dry-run'),
        'max_categories' => (int) $this->option('max-categories'),
        'max_products' => (int) $this->option('max-products'),
        'max_pages_per_category' => (int) $this->option('max-pages-per-category'),
        'sleep_ms' => (int) $this->option('sleep-ms'),
        'category_url' => (string) $this->option('category-url'),
        'rescan_products' => (bool) $this->option('rescan'),
        'verbose' => (bool) $this->option('show-progress'),
        'progress' => fn (string $message) => $this->line($message),
    ]);

    $this->info(($this->option('dry-run') ? 'Scanned' : 'Imported').' TCARS products from leaf categories.');

    foreach ($stats as $name => $value) {
        $this->line(" - {$name}: {$value}");
    }

    return Command::SUCCESS;
})->purpose('Import product cards only from saved TCARS leaf categories');

Artisan::command('parts:refresh-tcarservice-russian-names {--dry-run} {--limit=0} {--sleep-ms=100} {--refresh-urls} {--show-progress}', function (): int {
    $stats = app(TcarserviceCatalogImporter::class)->refreshRussianNames([
        'dry_run' => (bool) $this->option('dry-run'),
        'limit' => (int) $this->option('limit'),
        'sleep_ms' => (int) $this->option('sleep-ms'),
        'refresh_urls' => (bool) $this->option('refresh-urls'),
        'progress' => (bool) $this->option('show-progress') ? fn (string $message) => $this->line($message) : null,
    ]);

    $this->info(($this->option('dry-run') ? 'Scanned' : 'Refreshed').' TCARS Russian names.');

    foreach ($stats as $name => $value) {
        $this->line(" - {$name}: {$value}");
    }

    return Command::SUCCESS;
})->purpose('Fetch Russian product names for existing TCARS catalog items');

Artisan::command('parts:import-teslapartsukraine {--dry-run} {--sleep-ms=150} {--show-progress}', function (): int {
    $stats = app(TeslaPartsUkraineCatalogImporter::class)->refreshModelListings([
        'dry_run' => (bool) $this->option('dry-run'),
        'sleep_ms' => (int) $this->option('sleep-ms'),
        'verbose' => (bool) $this->option('show-progress'),
        'progress' => fn (string $message) => $this->line($message),
    ]);

    $this->info(($this->option('dry-run') ? 'Scanned' : 'Imported').' Tesla Parts Ukraine store products.');

    foreach ($stats as $name => $value) {
        $this->line(" - {$name}: {$value}");
    }

    return Command::SUCCESS;
})->purpose('Import TeslaPartsUkraine store products from model listing pages');

Artisan::command('parts:refresh-teslapartsukraine-images {--dry-run} {--limit=0} {--sleep-ms=150} {--missing-only=1} {--show-progress}', function (): int {
    $stats = app(TeslaPartsUkraineCatalogImporter::class)->refreshProductImages([
        'dry_run' => (bool) $this->option('dry-run'),
        'limit' => (int) $this->option('limit'),
        'sleep_ms' => (int) $this->option('sleep-ms'),
        'missing_only' => (bool) $this->option('missing-only'),
        'verbose' => (bool) $this->option('show-progress'),
        'progress' => fn (string $message) => $this->line($message),
    ]);

    $this->info(($this->option('dry-run') ? 'Scanned' : 'Refreshed').' TeslaPartsUkraine product images.');

    foreach ($stats as $name => $value) {
        $this->line(" - {$name}: {$value}");
    }

    return Command::SUCCESS;
})->purpose('Fetch full product image galleries for TeslaPartsUkraine catalog items');

Artisan::command('parts:import-tsk {--dry-run} {--max-categories=0} {--max-products=0} {--sleep-ms=150} {--start-url=/katalog-zapchastey296/} {--rescan} {--show-progress}', function (): int {
    $stats = app(TskCatalogImporter::class)->import([
        'dry_run' => (bool) $this->option('dry-run'),
        'max_categories' => (int) $this->option('max-categories'),
        'max_products' => (int) $this->option('max-products'),
        'sleep_ms' => (int) $this->option('sleep-ms'),
        'start_url' => (string) $this->option('start-url'),
        'rescan' => (bool) $this->option('rescan'),
        'verbose' => (bool) $this->option('show-progress'),
        'progress' => fn (string $message) => $this->line($message),
    ]);

    $this->info(($this->option('dry-run') ? 'Scanned' : 'Imported').' TSK catalog.');

    foreach ($stats as $name => $value) {
        $this->line(" - {$name}: {$value}");
    }

    return Command::SUCCESS;
})->purpose('Import Tesla parts catalog from tsk.ua into local suggestions');

Artisan::command('parts:import-tsk-leaf-products {category? : Optional TSK category id, admin URL, or catalog path} {--dry-run} {--max-categories=0} {--max-products=0} {--sleep-ms=150} {--rescan} {--show-progress}', function (?string $category = null): int {
    $category = trim((string) $category);
    $categoryId = 0;

    if ($category !== '') {
        if (ctype_digit($category)) {
            $categoryId = (int) $category;
        } else {
            $path = trim((string) parse_url($category, PHP_URL_PATH), '/');
            $path = $path !== '' ? $path : trim($category, '/');
            $path = preg_replace('#^admin/tsk-catalog/#', '', $path) ?? $path;
            $path = preg_replace('#^tsk-catalog/#', '', $path) ?? $path;
            $categoryId = app(PartCatalogCategoryRouteService::class)->categoryIdByCatalogPath('tsk', $path);
        }

        if ($categoryId <= 0 || ! PartCatalogCategory::query()->where('source', 'tsk')->whereKey($categoryId)->exists()) {
            $this->error('TSK category was not found for: '.$category);

            return Command::FAILURE;
        }
    }

    $stats = app(TskCatalogImporter::class)->importLeafProducts([
        'dry_run' => (bool) $this->option('dry-run'),
        'max_categories' => (int) $this->option('max-categories'),
        'max_products' => (int) $this->option('max-products'),
        'sleep_ms' => (int) $this->option('sleep-ms'),
        'rescan' => (bool) $this->option('rescan'),
        'category_id' => $categoryId,
        'verbose' => (bool) $this->option('show-progress'),
        'progress' => fn (string $message) => $this->line($message),
    ]);

    $this->info(($this->option('dry-run') ? 'Scanned' : 'Imported').' TSK leaf catalog products.');

    foreach ($stats as $name => $value) {
        $this->line(" - {$name}: {$value}");
    }

    return Command::SUCCESS;
})->purpose('Import products directly from saved TSK leaf categories');

Artisan::command('parts:import-stock-tesla {--dry-run} {--categories-only} {--rebuild-categories} {--rescan-products} {--category-url=} {--max-categories=0} {--max-category-pages=0} {--max-products=0} {--sleep-ms=1000} {--without-russian} {--without-images} {--show-progress}', function (): int {
    $stats = app(StockTeslaCatalogImporter::class)->import([
        'dry_run' => (bool) $this->option('dry-run'),
        'categories_only' => (bool) $this->option('categories-only'),
        'rebuild_categories' => (bool) $this->option('rebuild-categories'),
        'rescan_products' => (bool) $this->option('rescan-products'),
        'category_url' => (string) $this->option('category-url'),
        'max_categories' => (int) $this->option('max-categories'),
        'max_category_pages' => (int) $this->option('max-category-pages'),
        'max_products' => (int) $this->option('max-products'),
        'sleep_ms' => (int) $this->option('sleep-ms'),
        'with_russian' => ! (bool) $this->option('without-russian'),
        'download_images' => ! (bool) $this->option('without-images'),
        'verbose' => (bool) $this->option('show-progress'),
        'progress' => fn (string $message) => $this->line($message),
    ]);

    $this->info(($this->option('dry-run') ? 'Scanned' : 'Imported').' Stock Tesla catalog.');

    foreach ($stats as $name => $value) {
        $this->line(" - {$name}: {$value}");
    }

    return Command::SUCCESS;
})->purpose('Import Tesla parts catalog from stock-tesla.com into local suggestions');

Artisan::command('parts:backfill-stock-tesla-categories {--max-products=0} {--sleep-ms=0} {--show-progress}', function (): int {
    $stats = app(StockTeslaCatalogImporter::class)->backfillMissingCategoriesFromProductPages([
        'max_products' => (int) $this->option('max-products'),
        'sleep_ms' => (int) $this->option('sleep-ms'),
        'verbose' => (bool) $this->option('show-progress'),
        'progress' => fn (string $message) => $this->line($message),
    ]);

    $this->info('Backfilled Stock Tesla categories from product pages.');

    foreach ($stats as $name => $value) {
        $this->line(" - {$name}: {$value}");
    }

    return Command::SUCCESS;
})->purpose('Backfill Stock Tesla item categories from product page breadcrumbs');

Artisan::command('parts:backfill-stock-tesla-russian-names {--dry-run} {--max-products=0} {--sleep-ms=250} {--show-progress}', function (): int {
    $stats = app(StockTeslaCatalogImporter::class)->backfillMissingRussianNames([
        'dry_run' => (bool) $this->option('dry-run'),
        'max_products' => (int) $this->option('max-products'),
        'sleep_ms' => (int) $this->option('sleep-ms'),
        'verbose' => (bool) $this->option('show-progress'),
        'progress' => fn (string $message) => $this->line($message),
    ]);

    $this->info(($this->option('dry-run') ? 'Scanned' : 'Backfilled').' Stock Tesla Russian names.');

    foreach ($stats as $name => $value) {
        $this->line(" - {$name}: {$value}");
    }

    return Command::SUCCESS;
})->purpose('Backfill missing Stock Tesla Russian item names from Russian product pages');

Artisan::command('parts:import-teslahelp {--dry-run} {--max-categories=0} {--max-products=0} {--sleep-ms=250} {--start-url=/} {--rescan} {--fresh} {--without-teslashop} {--show-progress}', function (): int {
    $stats = app(TeslaHelpCatalogImporter::class)->import([
        'dry_run' => (bool) $this->option('dry-run'),
        'max_categories' => (int) $this->option('max-categories'),
        'max_products' => (int) $this->option('max-products'),
        'sleep_ms' => (int) $this->option('sleep-ms'),
        'start_url' => (string) $this->option('start-url'),
        'rescan' => (bool) $this->option('rescan'),
        'fresh' => (bool) $this->option('fresh'),
        'with_teslashop' => ! (bool) $this->option('without-teslashop'),
        'verbose' => (bool) $this->option('show-progress'),
        'progress' => fn (string $message) => $this->line($message),
    ]);

    $this->info(($this->option('dry-run') ? 'Scanned' : 'Imported').' TeslaHelp catalog.');

    foreach ($stats as $name => $value) {
        $this->line(" - {$name}: {$value}");
    }

    return Command::SUCCESS;
})->purpose('Import TeslaHelp EPC and enrich parts with TeslaShop Russian names');

Artisan::command('parts:import-driveparts {--dry-run} {--recreate} {--start-url=/ru/kataloh/} {--show-progress}', function (): int {
    $stats = app(DrivePartsCatalogImporter::class)->import([
        'dry_run' => (bool) $this->option('dry-run'),
        'recreate' => (bool) $this->option('recreate'),
        'start_url' => (string) $this->option('start-url'),
        'verbose' => (bool) $this->option('show-progress'),
        'progress' => fn (string $message) => $this->line($message),
    ]);

    $this->info(($this->option('dry-run') ? 'Scanned' : 'Imported').' DriveParts catalog.');

    foreach ($stats as $name => $value) {
        $this->line(" - {$name}: {$value}");
    }

    return Command::SUCCESS;
})->purpose('Import Tesla parts category catalog from drive-parts.com.ua');

Artisan::command('parts:import-driveparts-products {category? : Optional DriveParts category id, source URL, or admin catalog path} {--dry-run} {--all-products} {--max-categories=0} {--max-pages=0} {--max-products=0} {--sleep-ms=100} {--rescan} {--skip-localized} {--show-progress}', function (): int {
    $stats = app(DrivePartsCatalogImporter::class)->importProducts([
        'category' => (string) ($this->argument('category') ?? ''),
        'dry_run' => (bool) $this->option('dry-run'),
        'all_products' => (bool) $this->option('all-products'),
        'max_categories' => (int) $this->option('max-categories'),
        'max_pages' => (int) $this->option('max-pages'),
        'max_products' => (int) $this->option('max-products'),
        'sleep_ms' => (int) $this->option('sleep-ms'),
        'rescan' => (bool) $this->option('rescan'),
        'skip_localized' => (bool) $this->option('skip-localized'),
        'verbose' => (bool) $this->option('show-progress'),
        'progress' => fn (string $message) => $this->line($message),
    ]);

    $this->info(($this->option('dry-run') ? 'Scanned' : 'Imported').' DriveParts products.');

    foreach ($stats as $name => $value) {
        $this->line(" - {$name}: {$value}");
    }

    return Command::SUCCESS;
})->purpose('Import product cards from saved DriveParts leaf categories');

Artisan::command('parts:refresh-driveparts-translations {--dry-run} {--limit=0} {--sleep-ms=100} {--missing-ru-only} {--show-progress}', function (): int {
    $stats = app(DrivePartsCatalogImporter::class)->refreshProductTranslations([
        'dry_run' => (bool) $this->option('dry-run'),
        'limit' => (int) $this->option('limit'),
        'sleep_ms' => (int) $this->option('sleep-ms'),
        'missing_ru_only' => (bool) $this->option('missing-ru-only'),
        'verbose' => (bool) $this->option('show-progress'),
        'progress' => fn (string $message) => $this->line($message),
    ]);

    $this->info(($this->option('dry-run') ? 'Scanned' : 'Updated').' DriveParts product translations.');

    if (! $this->option('dry-run')) {
        app(PartCatalogSourceStatsService::class)->rebuild('driveparts');
        $this->line(' - source_stats_rebuilt: 1');
    }

    foreach ($stats as $name => $value) {
        $this->line(" - {$name}: {$value}");
    }

    return Command::SUCCESS;
})->purpose('Refresh DriveParts product RU/UA names from localized product pages');

Artisan::command('parts:refresh-driveparts-images {--dry-run} {--limit=0} {--sleep-ms=100} {--missing-only=1} {--with-cards} {--show-progress}', function (): int {
    $stats = app(DrivePartsCatalogImporter::class)->refreshProductImages([
        'dry_run' => (bool) $this->option('dry-run'),
        'limit' => (int) $this->option('limit'),
        'sleep_ms' => (int) $this->option('sleep-ms'),
        'missing_only' => (bool) $this->option('missing-only'),
        'with_cards' => (bool) $this->option('with-cards'),
        'verbose' => (bool) $this->option('show-progress'),
        'progress' => fn (string $message) => $this->line($message),
    ]);

    $this->info(($this->option('dry-run') ? 'Scanned' : 'Downloaded').' DriveParts product images.');

    foreach ($stats as $name => $value) {
        $this->line(" - {$name}: {$value}");
    }

    return Command::SUCCESS;
})->purpose('Download DriveParts product images from stored remote URLs, optionally fetching cards when URLs are missing');

Artisan::command('parts:purge-driveparts-placeholder-images {--dry-run} {--delete-files=1}', function (): int {
    $stats = app(DrivePartsCatalogImporter::class)->purgePlaceholderImages([
        'dry_run' => (bool) $this->option('dry-run'),
        'delete_files' => (bool) ((int) $this->option('delete-files')),
    ]);

    $this->info(($this->option('dry-run') ? 'Scanned' : 'Purged').' DriveParts placeholder images.');

    foreach ($stats as $name => $value) {
        $this->line(" - {$name}: {$value}");
    }

    return Command::SUCCESS;
})->purpose('Normalize known DriveParts placeholder image references to the shared placeholder image');

Artisan::command('parts:import-dkparts {--dry-run} {--start-url=/ru} {--show-progress}', function (): int {
    $stats = app(DkPartsCatalogImporter::class)->import([
        'dry_run' => (bool) $this->option('dry-run'),
        'start_url' => (string) $this->option('start-url'),
        'verbose' => (bool) $this->option('show-progress'),
        'progress' => fn (string $message) => $this->line($message),
    ]);

    $this->info(($this->option('dry-run') ? 'Scanned' : 'Imported').' DK-Parts catalog.');

    foreach ($stats as $name => $value) {
        $this->line(" - {$name}: {$value}");
    }

    return Command::SUCCESS;
})->purpose('Import Tesla parts category catalog from dk-parts.com.ua');

Artisan::command('parts:import-dkparts-products {--dry-run} {--max-categories=0} {--max-products=0} {--max-pages-per-category=20} {--sleep-ms=100} {--rescan} {--show-progress}', function (): int {
    $stats = app(DkPartsCatalogImporter::class)->importProducts([
        'dry_run' => (bool) $this->option('dry-run'),
        'max_categories' => (int) $this->option('max-categories'),
        'max_products' => (int) $this->option('max-products'),
        'max_pages_per_category' => (int) $this->option('max-pages-per-category'),
        'sleep_ms' => (int) $this->option('sleep-ms'),
        'rescan' => (bool) $this->option('rescan'),
        'verbose' => (bool) $this->option('show-progress'),
        'progress' => fn (string $message) => $this->line($message),
    ]);

    $this->info(($this->option('dry-run') ? 'Scanned' : 'Imported').' DK-Parts products from leaf categories.');

    foreach ($stats as $name => $value) {
        $this->line(" - {$name}: {$value}");
    }

    return Command::SUCCESS;
})->purpose('Import product cards from saved DK-Parts leaf categories');

Artisan::command('parts:refresh-dkparts-localized-names {--dry-run} {--limit=0} {--sleep-ms=100} {--show-progress}', function (): int {
    $stats = app(DkPartsCatalogImporter::class)->refreshLocalizedNames([
        'dry_run' => (bool) $this->option('dry-run'),
        'limit' => (int) $this->option('limit'),
        'sleep_ms' => (int) $this->option('sleep-ms'),
        'progress' => (bool) $this->option('show-progress') ? fn (string $message) => $this->line($message) : null,
    ]);

    $this->info(($this->option('dry-run') ? 'Scanned' : 'Refreshed').' DK-Parts localized names.');

    foreach ($stats as $name => $value) {
        $this->line(" - {$name}: {$value}");
    }

    return Command::SUCCESS;
})->purpose('Fetch missing RU/UA product names and localized URLs for existing DK-Parts catalog items');

Artisan::command('parts:import-erazborka {--dry-run} {--start-url=/catalog/} {--show-progress}', function (): int {
    $stats = app(ErazborkaCatalogImporter::class)->import([
        'dry_run' => (bool) $this->option('dry-run'),
        'start_url' => (string) $this->option('start-url'),
        'verbose' => (bool) $this->option('show-progress'),
        'progress' => fn (string $message) => $this->line($message),
    ]);

    $this->info(($this->option('dry-run') ? 'Scanned' : 'Imported').' Erazborka catalog.');

    foreach ($stats as $name => $value) {
        $this->line(" - {$name}: {$value}");
    }

    return Command::SUCCESS;
})->purpose('Import Tesla category catalog from erazborka.com.ua');

Artisan::command('parts:import-erazborka-products {--dry-run} {--model-roots} {--max-categories=0} {--max-products=0} {--max-pages-per-category=50} {--sleep-ms=100} {--rescan} {--show-progress}', function (): int {
    $importer = app(ErazborkaCatalogImporter::class);
    $options = [
        'dry_run' => (bool) $this->option('dry-run'),
        'max_categories' => (int) $this->option('max-categories'),
        'max_products' => (int) $this->option('max-products'),
        'max_pages_per_category' => (int) $this->option('max-pages-per-category'),
        'sleep_ms' => (int) $this->option('sleep-ms'),
        'rescan' => (bool) $this->option('rescan'),
        'verbose' => (bool) $this->option('show-progress'),
        'progress' => fn (string $message) => $this->line($message),
    ];

    $stats = (bool) $this->option('model-roots')
        ? $importer->importModelRootProducts($options)
        : $importer->importProducts($options);

    $scope = (bool) $this->option('model-roots') ? 'model root pages' : 'category pages';
    $this->info(($this->option('dry-run') ? 'Scanned' : 'Imported')." Erazborka products from {$scope}.");

    foreach ($stats as $name => $value) {
        $this->line(" - {$name}: {$value}");
    }

    return Command::SUCCESS;
})->purpose('Import Tesla product names and prices from erazborka.com.ua');

Artisan::command('parts:refresh-erazborka-localized-names {--dry-run} {--limit=0} {--sleep-ms=100} {--show-progress}', function (): int {
    $stats = app(ErazborkaCatalogImporter::class)->refreshLocalizedNames([
        'dry_run' => (bool) $this->option('dry-run'),
        'limit' => (int) $this->option('limit'),
        'sleep_ms' => (int) $this->option('sleep-ms'),
        'progress' => (bool) $this->option('show-progress') ? fn (string $message) => $this->line($message) : null,
    ]);

    $this->info(($this->option('dry-run') ? 'Scanned' : 'Refreshed').' Erazborka localized names.');

    foreach ($stats as $name => $value) {
        $this->line(" - {$name}: {$value}");
    }

    return Command::SUCCESS;
})->purpose('Fetch missing RU/UA product names and localized URLs for existing Erazborka catalog items');

Artisan::command('parts:import-erazborka-saved-leaf-products {--dry-run} {--category-id=*} {--max-categories=0} {--max-products=0} {--max-pages-per-category=50} {--sleep-ms=100} {--rescan} {--show-progress}', function (): int {
    $stats = app(ErazborkaCatalogImporter::class)->importSavedLeafProducts([
        'dry_run' => (bool) $this->option('dry-run'),
        'category_ids' => (array) $this->option('category-id'),
        'max_categories' => (int) $this->option('max-categories'),
        'max_products' => (int) $this->option('max-products'),
        'max_pages_per_category' => (int) $this->option('max-pages-per-category'),
        'sleep_ms' => (int) $this->option('sleep-ms'),
        'rescan' => (bool) $this->option('rescan'),
        'verbose' => (bool) $this->option('show-progress'),
        'progress' => fn (string $message) => $this->line($message),
    ]);

    $this->info(($this->option('dry-run') ? 'Scanned' : 'Imported').' Erazborka products from saved leaf categories.');

    foreach ($stats as $name => $value) {
        $this->line(" - {$name}: {$value}");
    }

    return Command::SUCCESS;
})->purpose('Resume Erazborka product import from saved leaf categories');

Artisan::command('parts:refresh-erazborka-images {--dry-run} {--limit=0} {--sleep-ms=100} {--missing-only=1} {--only-suspicious} {--only-mismatched-image-counts} {--start-id=0} {--show-progress}', function (): int {
    $stats = app(ErazborkaCatalogImporter::class)->refreshProductImages([
        'dry_run' => (bool) $this->option('dry-run'),
        'limit' => (int) $this->option('limit'),
        'sleep_ms' => (int) $this->option('sleep-ms'),
        'missing_only' => (bool) ((int) $this->option('missing-only')),
        'only_suspicious' => (bool) $this->option('only-suspicious'),
        'only_mismatched_image_counts' => (bool) $this->option('only-mismatched-image-counts'),
        'start_id' => (int) $this->option('start-id'),
        'progress' => (bool) $this->option('show-progress') ? fn (string $message) => $this->line($message) : null,
    ]);

    $this->info(($this->option('dry-run') ? 'Scanned' : 'Refreshed').' Erazborka product images.');

    foreach ($stats as $name => $value) {
        $this->line(" - {$name}: {$value}");
    }

    return Command::SUCCESS;
})->purpose('Fetch Erazborka product cards and download local product images');

Artisan::command('parts:purge-erazborka-non-product-images {--dry-run} {--delete-files=1}', function (): int {
    $stats = app(ErazborkaCatalogImporter::class)->purgeNonProductImages([
        'dry_run' => (bool) $this->option('dry-run'),
        'delete_files' => (bool) ((int) $this->option('delete-files')),
    ]);

    $this->info(($this->option('dry-run') ? 'Scanned' : 'Purged').' Erazborka non-product images.');

    foreach ($stats as $name => $value) {
        $this->line(" - {$name}: {$value}");
    }

    return Command::SUCCESS;
})->purpose('Remove known Erazborka logo/banner/noimage references from product image fields');

Artisan::command('parts:import-toprazborka {--dry-run} {--start-url=/} {--show-progress}', function (): int {
    $stats = app(TopRazborkaCatalogImporter::class)->import([
        'dry_run' => (bool) $this->option('dry-run'),
        'start_url' => (string) $this->option('start-url'),
        'verbose' => (bool) $this->option('show-progress'),
        'progress' => fn (string $message) => $this->line($message),
    ]);

    $this->info(($this->option('dry-run') ? 'Scanned' : 'Imported').' TopRazborka catalog.');

    foreach ($stats as $name => $value) {
        $this->line(" - {$name}: {$value}");
    }

    return Command::SUCCESS;
})->purpose('Import Tesla category catalog from toprazborka.com.ua');

Artisan::command('parts:import-toprazborka-products {--dry-run} {--max-categories=0} {--max-products=0} {--max-pages-per-category=50} {--sleep-ms=100} {--rescan} {--show-progress}', function (): int {
    $stats = app(TopRazborkaCatalogImporter::class)->importProducts([
        'dry_run' => (bool) $this->option('dry-run'),
        'max_categories' => (int) $this->option('max-categories'),
        'max_products' => (int) $this->option('max-products'),
        'max_pages_per_category' => (int) $this->option('max-pages-per-category'),
        'sleep_ms' => (int) $this->option('sleep-ms'),
        'rescan' => (bool) $this->option('rescan'),
        'verbose' => (bool) $this->option('show-progress'),
        'progress' => fn (string $message) => $this->line($message),
    ]);

    $this->info(($this->option('dry-run') ? 'Scanned' : 'Imported').' TopRazborka products from category pages.');

    foreach ($stats as $name => $value) {
        $this->line(" - {$name}: {$value}");
    }

    return Command::SUCCESS;
})->purpose('Import Tesla product names and prices from toprazborka.com.ua');

Artisan::command('parts:import-toprazborka-root-products {--dry-run} {--model=} {--max-pages-per-category=80} {--sleep-ms=100} {--show-progress}', function (): int {
    $stats = app(TopRazborkaCatalogImporter::class)->importModelRootProducts([
        'dry_run' => (bool) $this->option('dry-run'),
        'model' => (string) $this->option('model'),
        'max_pages_per_category' => (int) $this->option('max-pages-per-category'),
        'sleep_ms' => (int) $this->option('sleep-ms'),
        'verbose' => (bool) $this->option('show-progress'),
        'progress' => fn (string $message) => $this->line($message),
    ]);

    $this->info(($this->option('dry-run') ? 'Scanned' : 'Imported').' TopRazborka root product listings.');

    foreach ($stats as $name => $value) {
        $this->line(" - {$name}: {$value}");
    }

    return Command::SUCCESS;
})->purpose('Import missing products from TopRazborka model root listings');

Artisan::command('parts:import-teslawestparts {--dry-run} {--max-pages=0} {--max-products=0} {--sleep-ms=100} {--fresh} {--skip-images} {--show-progress}', function (): int {
    $stats = app(TeslaWestPartsCatalogImporter::class)->import([
        'dry_run' => (bool) $this->option('dry-run'),
        'max_pages' => (int) $this->option('max-pages'),
        'max_products' => (int) $this->option('max-products'),
        'sleep_ms' => (int) $this->option('sleep-ms'),
        'fresh' => (bool) $this->option('fresh'),
        'verbose' => (bool) $this->option('show-progress'),
        'progress' => fn (string $message) => $this->line($message),
    ]);

    if (! $this->option('dry-run') && ! $this->option('skip-images')) {
        $stats = array_merge($stats, app(CompetitorCatalogImageLocalizer::class)->localizeSource('teslawestparts', [
            'progress' => fn (string $message) => $this->line($message),
        ]));
    }

    $this->info(($this->option('dry-run') ? 'Scanned' : 'Imported').' Tesla West Parts catalog.');

    foreach ($stats as $name => $value) {
        $this->line(" - {$name}: {$value}");
    }

    return Command::SUCCESS;
})->purpose('Import Tesla West Parts products with prices and characteristics from WooCommerce Store API');

Artisan::command('parts:import-teslacompany {path?} {--dry-run} {--fresh} {--show-progress}', function (?string $path = null): int {
    if ($path === null || trim($path) === '') {
        $stats = app(TeslaCompanyCatalogImporter::class)->refreshModelListings([
            'dry_run' => (bool) $this->option('dry-run'),
            'fresh' => (bool) $this->option('fresh'),
            'verbose' => (bool) $this->option('show-progress'),
            'progress' => fn (string $message) => $this->line($message),
        ]);

        if (isset($stats['error'])) {
            $this->error($stats['error']);

            return Command::FAILURE;
        }

        if (! $this->option('dry-run')) {
            $stats = array_merge($stats, app(CompetitorCatalogImageLocalizer::class)->localizeSource('teslacompany', [
                'progress' => fn (string $message) => $this->line($message),
            ]));
        }

        $this->info(($this->option('dry-run') ? 'Scanned' : 'Imported').' TeslaCompany catalog directly from model pages.');

        foreach ($stats as $name => $value) {
            $this->line(" - {$name}: {$value}");
        }

        return Command::SUCCESS;
    }

    $stats = app(TeslaCompanyCatalogImporter::class)->import($path, [
        'dry_run' => (bool) $this->option('dry-run'),
        'fresh' => (bool) $this->option('fresh'),
        'verbose' => (bool) $this->option('show-progress'),
        'progress' => fn (string $message) => $this->line($message),
    ]);

    if (isset($stats['error'])) {
        $this->error($stats['error']);

        return Command::FAILURE;
    }

    if (! $this->option('dry-run')) {
        $stats = array_merge($stats, app(CompetitorCatalogImageLocalizer::class)->localizeSource('teslacompany', [
            'progress' => fn (string $message) => $this->line($message),
        ]));
    }

    $this->info(($this->option('dry-run') ? 'Scanned' : 'Imported').' TeslaCompany catalog.');

    foreach ($stats as $name => $value) {
        $this->line(" - {$name}: {$value}");
    }

    return Command::SUCCESS;
})->purpose('Import TeslaCompany catalog from downloaded CSV with product characteristics');

Artisan::command('parts:import-nikolacars {path=NC/nomenklatura.csv} {--dry-run} {--fresh} {--show-progress}', function (string $path): int {
    $stats = app(NikolaCarsCatalogImporter::class)->import($path, [
        'dry_run' => (bool) $this->option('dry-run'),
        'fresh' => (bool) $this->option('fresh'),
        'verbose' => (bool) $this->option('show-progress'),
        'progress' => fn (string $message) => $this->line($message),
    ]);

    if (isset($stats['error'])) {
        $this->error($stats['error']);

        return Command::FAILURE;
    }

    $this->info(($this->option('dry-run') ? 'Scanned' : 'Imported').' NikolaCars catalog.');

    foreach ($stats as $name => $value) {
        $this->line(" - {$name}: {$value}");
    }

    return Command::SUCCESS;
})->purpose('Import NikolaCars products from local NC export into the intermediate catalog');

Artisan::command('parts:resolve-nikolacars-tesla-categories {--all : Re-resolve every NikolaCars catalog item instead of only undetermined categories} {--no-product-sync : Do not update linked products after catalog category changes}', function (): int {
    app(NikolaCarsTeslaCategoryTreeSyncService::class)->syncAll();

    $stats = app(NikolaCarsTeslaCategoryResolver::class)->resolveAll([
        'missing_only' => ! (bool) $this->option('all'),
        'sync_products' => ! (bool) $this->option('no-product-sync'),
    ]);

    $this->info('Resolved NikolaCars categories from Tesla official catalog.');

    foreach ($stats as $name => $value) {
        $this->line(" - {$name}: {$value}");
    }

    return Command::SUCCESS;
})->purpose('Resolve undetermined NikolaCars categories from Tesla official catalog by seven-digit part prefix');

Artisan::command('parts:sync-nikolacars-tesla-category-tree {--resolve-items : Reattach NikolaCars items to the mirrored Tesla.com category tree} {--missing-only : With --resolve-items, update only undetermined NikolaCars categories}', function (): int {
    $stats = app(NikolaCarsTeslaCategoryTreeSyncService::class)->syncAll();

    $this->info('Synced Tesla.com category tree into NikolaCars catalog categories.');

    foreach ($stats as $name => $value) {
        $this->line(" - {$name}: {$value}");
    }

    if ((bool) $this->option('resolve-items')) {
        $itemStats = app(NikolaCarsTeslaCategoryResolver::class)->resolveAll([
            'missing_only' => (bool) $this->option('missing-only'),
            'sync_products' => true,
        ]);

        $this->info('Resolved NikolaCars items onto the mirrored Tesla.com category tree.');

        foreach ($itemStats as $name => $value) {
            $this->line(" - {$name}: {$value}");
        }
    }

    return Command::SUCCESS;
})->purpose('Copy Tesla.com category tree into NikolaCars categories and sync localized category names');

Artisan::command('parts:import-tesla-official {catalogExternalReference?} {--all-catalogs} {--dry-run} {--max-catalogs=0} {--max-system-groups=0} {--max-parts=0} {--sleep-ms=100} {--show-progress}', function (?string $catalogExternalReference = null): int {
    $this->warn('Disabled: Tesla.com/common catalog import is frozen until the new rules are defined.');

    return Command::SUCCESS;
})->purpose('Import official Tesla EPC parts and prices into the common catalog');

Artisan::command('parts:backfill-tesla-official-occurrences {--limit=0} {--canonicalize-items} {--canonicalize-legacy-categories} {--all} {--show-progress}', function (): int {
    $stats = app(TeslaOfficialCatalogOccurrenceBackfiller::class)->backfill([
        'limit' => (int) $this->option('limit'),
        'canonicalize_items' => (bool) $this->option('canonicalize-items'),
        'canonicalize_legacy_categories' => (bool) $this->option('canonicalize-legacy-categories'),
        'missing_only' => ! (bool) $this->option('all'),
        'progress' => (bool) $this->option('show-progress') ? fn (string $message) => $this->line($message) : null,
    ]);

    foreach ($stats as $name => $value) {
        $this->line("{$name}: {$value}");
    }

    return Command::SUCCESS;
})->purpose('Backfill Tesla official catalog item occurrences from saved raw occurrence JSON');

Artisan::command('parts:import-tesla-official-images {catalogExternalReference?} {--all-catalogs} {--show-progress}', function (?string $catalogExternalReference = null): int {
    $this->warn('Disabled: Tesla.com/common catalog images are frozen until the new rules are defined.');

    return Command::SUCCESS;
})->purpose('Import official Tesla EPC preview images for saved catalog categories');

Artisan::command('parts:import-tesla-official-search-exact {--dry-run} {--limit=0} {--part-number=*} {--countries=US,CA,MX,DE,NO,GB} {--sleep-ms=200} {--show-progress}', function (): int {
    $this->warn('Disabled: common Tesla catalog and Tesla.com enrichment are frozen until the new rules are defined.');

    return Command::SUCCESS;
})->purpose('Find competitor part numbers through official Tesla partSearch and add exact matches to the common catalog');

Artisan::command('parts:find-catalog {query}', function (string $query): int {
    $items = PartCatalogItem::query()
        ->where('part_number', 'like', '%'.$query.'%')
        ->orWhere('name', 'like', '%'.$query.'%')
        ->orWhere('source_url', 'like', '%'.$query.'%')
        ->limit(20)
        ->get();

    if ($items->isEmpty()) {
        $this->warn('Nothing found.');

        return Command::SUCCESS;
    }

    foreach ($items as $item) {
        $this->line("#{$item->id} {$item->part_number} | {$item->name}");
        $this->line("  {$item->source_url}");
    }

    return Command::SUCCESS;
})->purpose('Find a local TCARS catalog item by part number, name, or source URL');

Artisan::command('donor-products:backfill-tcars-names {--dry-run} {--donor-car-id=0} {--limit=0} {--overwrite} {--show-progress}', function (): int {
    $stats = app(DonorProductTcarsNameBackfiller::class)->run([
        'dry_run' => (bool) $this->option('dry-run'),
        'donor_car_id' => (int) $this->option('donor-car-id'),
        'limit' => (int) $this->option('limit'),
        'overwrite' => (bool) $this->option('overwrite'),
        'progress' => (bool) $this->option('show-progress') ? fn (string $message) => $this->line($message) : null,
    ]);

    $this->info(($this->option('dry-run') ? 'Scanned' : 'Updated').' donor product RU/UA names from TCARS article matches.');

    foreach ($stats as $name => $value) {
        $this->line(" - {$name}: {$value}");
    }

    return Command::SUCCESS;
})->purpose('Fill donor product catalog RU/UA names from TCARS by article number');

Artisan::command('parts:dedupe-tsk-product-urls {--dry-run} {--limit=0} {--part-number=} {--show-progress}', function (): int {
    $stats = app(TskCatalogProductUrlDedupeService::class)->run([
        'dry_run' => (bool) $this->option('dry-run'),
        'limit' => (int) $this->option('limit'),
        'part_number' => (string) $this->option('part-number'),
        'progress' => (bool) $this->option('show-progress') ? fn (string $message) => $this->line($message) : null,
    ]);

    $this->info(($this->option('dry-run') ? 'Scanned' : 'Merged').' TSK catalog items by canonical product URL.');

    foreach ($stats as $name => $value) {
        $this->line(" - {$name}: {$value}");
    }

    return Command::SUCCESS;
})->purpose('Merge duplicate TSK catalog items that point to the same product URL and keep EPC occurrences separately');

Artisan::command('parts:dedupe-competitor-part-numbers {--dry-run} {--source=} {--part-number=} {--limit=0} {--show-progress}', function (): int {
    $this->warn('Disabled: competitor catalog items are no longer merged by part number. Different product cards with the same part number stay as separate records.');

    return Command::SUCCESS;
})->purpose('Disabled legacy command: competitor products are kept separate by source URL, not merged by part number');

Artisan::command('parts:tesla-official-login {--node=node}', function (): int {
    $script = base_path('scripts/tesla_official_browser_search.mjs');

    if (! is_file($script)) {
        $this->error("Missing script: {$script}");

        return Command::FAILURE;
    }

    $this->info('Opening Chrome with a dedicated Tesla official profile.');
    $this->line('Log in manually if Tesla asks, open any catalog once, then press Enter in this terminal.');

    $process = new Process([(string) $this->option('node'), $script, 'login'], base_path());
    $process->setTimeout(null);
    $process->setTty(false);
    $process->run(function (string $type, string $buffer): void {
        $this->output->write($buffer);
    });

    return $process->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
})->purpose('Open Chrome once to save a Tesla official authenticated browser session');

Artisan::command('parts:tesla-official-browser-search {--part-number=*} {--source=*} {--limit=50} {--method=find-part-page} {--countries=US,CA,MX,DE,NO,GB} {--headed} {--node=node}', function (): int {
    $script = base_path('scripts/tesla_official_browser_search.mjs');

    if (! is_file($script)) {
        $this->error("Missing script: {$script}");

        return Command::FAILURE;
    }

    $partNumbers = collect((array) $this->option('part-number'))
        ->map(fn (mixed $partNumber): string => trim((string) $partNumber))
        ->filter()
        ->unique()
        ->values();

    if ($partNumbers->isEmpty()) {
        $sources = collect((array) $this->option('source'))
            ->map(fn (mixed $source): string => trim((string) $source))
            ->filter()
            ->values()
            ->all();

        $defaultSources = [
            'tcarservice',
            'teslapartsukraine',
            'tsk',
            'stock-tesla',
            'teslahelp',
            'driveparts',
            'dkparts',
            'erazborka',
            'toprazborka',
            'teslawestparts',
            'teslacompany',
            'nikolacars',
        ];

        $limit = max(1, (int) $this->option('limit'));
        $partNumbers = PartCatalogItem::query()
            ->whereIn('source', $sources === [] ? $defaultSources : $sources)
            ->whereNotNull('part_number')
            ->orderByDesc('source_updated_at')
            ->orderByDesc('id')
            ->limit($limit * 5)
            ->pluck('part_number')
            ->map(fn (?string $partNumber): string => strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) $partNumber) ?: ''))
            ->filter(fn (string $partNumber): bool => preg_match('/^[0-9]{7}[A-Z0-9]{2}[A-Z0-9]$/', $partNumber) === 1)
            ->map(fn (string $partNumber): string => preg_replace('/^([0-9]{7})([A-Z0-9]{2})([A-Z0-9])$/', '$1-$2-$3', $partNumber) ?: $partNumber)
            ->unique()
            ->take($limit)
            ->values();
    }

    if ($partNumbers->isEmpty()) {
        $this->warn('No part numbers to check.');

        return Command::SUCCESS;
    }

    $inputPath = storage_path('app/tesla-official-browser-search-input.json');
    if (! is_dir(dirname($inputPath))) {
        mkdir(dirname($inputPath), 0775, true);
    }
    file_put_contents($inputPath, json_encode(['part_numbers' => $partNumbers->all()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $command = [
        (string) $this->option('node'),
        $script,
        'search',
        '--input='.$inputPath,
        '--method='.(string) $this->option('method'),
        '--countries='.(string) $this->option('countries'),
    ];

    if ((bool) $this->option('headed')) {
        $command[] = '--headed';
    }

    $process = new Process($command, base_path());
    $process->setTimeout(null);
    $process->run();

    if (! $process->isSuccessful()) {
        $this->error($process->getErrorOutput() ?: $process->getOutput());

        return Command::FAILURE;
    }

    $this->line($process->getOutput());

    return Command::SUCCESS;
})->purpose('Search Tesla official parts through the saved authenticated browser profile');

Artisan::command('parts:rebuild-catalog-source-stats {source? : Optional competitor source}', function (PartCatalogSourceStatsService $stats): int {
    $source = trim((string) ($this->argument('source') ?? ''));
    $rebuilt = $stats->rebuild($source !== '' ? $source : null);

    foreach (is_array($rebuilt) ? $rebuilt : [$rebuilt->source => $rebuilt] as $sourceName => $stat) {
        $this->line(sprintf(
            '%s: total=%d, with_photo=%d, without_photo=%d, conflict=%d, missing_ru=%d, missing_ua=%d',
            $sourceName,
            (int) $stat->total_count,
            (int) $stat->with_image_count,
            (int) $stat->without_image_count,
            (int) $stat->name_conflict_count,
            (int) $stat->missing_ru_count,
            (int) $stat->missing_ua_count
        ));
    }

    return Command::SUCCESS;
})->purpose('Rebuild cached competitor catalog filter counters');

Artisan::command('parts:import-tesla-official-browser {--dry-run} {--max-catalogs=1} {--max-system-groups=3} {--max-parts=20} {--country=US} {--sleep-ms=100} {--headed} {--show-progress} {--node=node}', function (): int {
    $this->warn('Disabled: Tesla.com/common catalog import is frozen until the new rules are defined.');

    return Command::SUCCESS;
})->purpose('Import official Tesla catalog data through the saved Chrome browser profile');

Artisan::command('donor-cars:download-official-vin-cdp {donorCarId} {--vin=} {--dry-run} {--max-system-groups=0} {--max-parts=0} {--sleep-ms=1200} {--cdp=http://127.0.0.1:9222} {--node=node} {--show-progress}', function (TeslaOfficialCatalogImporter $importer, DonorProductGenerationService $generator, TeslaOfficialVinSpecificCatalogCleanupService $vinSpecificCleanup): int {
    $script = base_path('scripts/tesla_official_browser_search.mjs');

    if (! is_file($script)) {
        $this->error("Missing script: {$script}");

        return Command::FAILURE;
    }

    $donorCar = DonorCar::query()->findOrFail((int) $this->argument('donorCarId'));
    $vin = strtoupper(trim((string) ($this->option('vin') ?: $donorCar->vin)));

    if ($vin === '') {
        $this->error('Donor VIN is empty.');

        return Command::FAILURE;
    }

    $command = [
        (string) $this->option('node'),
        $script,
        'cdp-vin-catalog-snapshot',
        '--vin='.$vin,
        '--cdp='.(string) $this->option('cdp'),
        '--max-system-groups='.(int) $this->option('max-system-groups'),
        '--max-parts='.(int) $this->option('max-parts'),
        '--sleep-ms='.(int) $this->option('sleep-ms'),
    ];

    $process = new Process($command, base_path());
    $process->setTimeout(null);
    $process->run(function (string $type, string $buffer): void {
        if ($type === Process::ERR) {
            $this->output->write($buffer);
        }
    });

    if (! $process->isSuccessful()) {
        $this->error($process->getErrorOutput() ?: $process->getOutput());

        return Command::FAILURE;
    }

    $snapshot = json_decode($process->getOutput(), true);

    if (! is_array($snapshot)) {
        $this->error('VIN catalog snapshot returned invalid JSON.');

        return Command::FAILURE;
    }

    $importStats = $importer->importBrowserSnapshot($snapshot, [
        'dry_run' => (bool) $this->option('dry-run'),
        'max_parts' => (int) $this->option('max-parts'),
        'verbose' => (bool) $this->option('show-progress'),
        'progress' => fn (string $message) => $this->line($message),
        'raw_attributes_extra' => [
            'donor_vin' => $vin,
            'donor_car_id' => $donorCar->id,
            'vin_catalog_imported_at' => now()->toIso8601String(),
        ],
    ]);

    $catalogItemIds = collect();
    $generationStats = [
        'created' => 0,
        'created_whole' => 0,
        'created_damaged' => 0,
        'updated_existing' => 0,
        'skipped_existing' => 0,
    ];

    if (! (bool) $this->option('dry-run')) {
        $catalogItemIds = PartCatalogItem::query()
            ->where('source', 'tesla_official')
            ->where('source_url', 'like', 'https://parts.tesla.com/%')
            ->where('raw_attributes', 'like', '%'.$vin.'%')
            ->where('raw_attributes->recommendation_type', 'RECOMMENDED')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($catalogItemIds->isNotEmpty()) {
            $generationStats = $generator->generate($donorCar, [], $catalogItemIds->all());
        }

        $cleanupStats = $vinSpecificCleanup->cleanupDonor($donorCar);
        $generationStats['vin_specific_items_deleted'] = (int) ($generationStats['vin_specific_items_deleted'] ?? 0) + (int) $cleanupStats['items_deleted'];
        $generationStats['vin_specific_products_relinked'] = (int) ($generationStats['vin_specific_products_relinked'] ?? 0) + (int) $cleanupStats['products_relinked'];
    }

    $this->info(($this->option('dry-run') ? 'Scanned' : 'Downloaded').' official Tesla VIN catalog for donor #'.$donorCar->id.' '.$vin.'.');

    foreach ($importStats as $name => $value) {
        $this->line(" - import_{$name}: {$value}");
    }

    $this->line(' - imported_catalog_items_for_vin: '.$catalogItemIds->count());
    foreach ($generationStats as $name => $value) {
        if (is_array($value)) {
            $value = implode(', ', $value);
        }
        $this->line(" - products_{$name}: {$value}");
    }

    return Command::SUCCESS;
})->purpose('Download Tesla official VIN-specific catalog through the logged-in Chrome session and create donor products');

Artisan::command('parts:download-tesla-official-part-images {--limit=0} {--part-number=*}', function (TeslaOfficialCatalogImporter $importer): int {
    $this->warn('Disabled: Tesla.com/common catalog images are frozen until the new rules are defined.');

    return Command::SUCCESS;
})->purpose('Download public Tesla part images locally and attach them to saved official catalog items');

Artisan::command('parts:sync-tesla-official-part-images {--limit=0}', function (TeslaOfficialCatalogImporter $importer): int {
    $this->warn('Disabled: Tesla.com/common catalog images are frozen until the new rules are defined.');

    return Command::SUCCESS;
})->purpose('Copy already downloaded Tesla part images to all saved official catalog rows with the same part number');

Artisan::command('donor-cars:cleanup-vin-nonrecommended-products {--dry-run}', function (): int {
    $items = PartCatalogItem::query()
        ->where('source', 'tesla_official')
        ->where('source_url', 'like', 'https://parts.tesla.com/%')
        ->whereNotNull('raw_attributes')
        ->where('raw_attributes', 'like', '%"donor_vin"%')
        ->with('products')
        ->get();

    $raw = fn (PartCatalogItem $item): array => $item->raw_attributes instanceof ArrayObject
        ? $item->raw_attributes->getArrayCopy()
        : (array) $item->raw_attributes;
    $compact = fn (?string $value): string => strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) $value) ?: '');

    $recommendedKeys = $items
        ->filter(function (PartCatalogItem $item) use ($raw, $compact): bool {
            $attributes = $raw($item);
            if (! data_get($attributes, 'donor_vin')) {
                return false;
            }

            $recommended = $compact(data_get($attributes, 'recommended_part_number'));

            return strtoupper((string) data_get($attributes, 'recommendation_type', '')) === 'RECOMMENDED'
                || ($recommended !== '' && $recommended === $compact($item->part_number));
        })
        ->mapWithKeys(function (PartCatalogItem $item) use ($raw): array {
            $attributes = $raw($item);

            return [implode('|', [
                data_get($attributes, 'donor_vin'),
                data_get($attributes, 'system_group_external_reference'),
                data_get($attributes, 'annotation'),
            ]) => true];
        });

    $deleted = 0;
    $candidates = $items->filter(function (PartCatalogItem $item) use ($raw, $compact, $recommendedKeys): bool {
        $attributes = $raw($item);
        if (! data_get($attributes, 'donor_vin')) {
            return false;
        }

        $recommended = $compact(data_get($attributes, 'recommended_part_number'));
        $isRecommended = strtoupper((string) data_get($attributes, 'recommendation_type', '')) === 'RECOMMENDED'
            || ($recommended !== '' && $recommended === $compact($item->part_number));

        if ($isRecommended) {
            return false;
        }

        $key = implode('|', [
            data_get($attributes, 'donor_vin'),
            data_get($attributes, 'system_group_external_reference'),
            data_get($attributes, 'annotation'),
        ]);

        return $recommendedKeys->has($key);
    });

    foreach ($candidates as $item) {
        foreach ($item->products as $product) {
            if (! $product->is_auto_generated) {
                continue;
            }

            $this->line(($this->option('dry-run') ? 'Would delete' : 'Deleting')." product #{$product->id} donor #{$product->donor_car_id} {$product->sku} {$product->external_sku}");
            if (! $this->option('dry-run')) {
                $product->delete();
            }
            $deleted++;
        }
    }

    $this->info(($this->option('dry-run') ? 'Found' : 'Deleted')." {$deleted} non-recommended VIN-generated products.");

    return Command::SUCCESS;
})->purpose('Remove VIN-generated donor products that Tesla does not mark as RECOMMENDED when a recommended row exists');

Artisan::command('parts:enrich-tesla-official-cdp-find-part {--dry-run} {--limit=20} {--item-id=*} {--part-number=*} {--retry-checked} {--browser=cdp} {--profile-dir=} {--delay-ms=7000} {--page-wait-ms=8000} {--cdp=http://127.0.0.1:9222} {--headed} {--node=node}', function (TeslaOfficialCatalogImporter $importer): int {
    $script = base_path('scripts/tesla_official_browser_search.mjs');

    if (! is_file($script)) {
        $this->error("Missing script: {$script}");

        return Command::FAILURE;
    }

    $partNumbers = collect((array) $this->option('part-number'))
        ->map(fn (mixed $partNumber): string => strtoupper(trim((string) $partNumber)))
        ->filter()
        ->unique()
        ->values();

    if ($partNumbers->isEmpty()) {
        $itemIds = collect((array) $this->option('item-id'))
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        $partNumbers = PartCatalogItem::query()
            ->where('source', 'tesla_official')
            ->where('source_url', 'like', 'https://parts.tesla.com/%')
            ->whereNotNull('part_number')
            ->when($itemIds->isNotEmpty(), fn ($query) => $query->whereIn('id', $itemIds->all()))
            ->when(! (bool) $this->option('retry-checked') && $itemIds->isEmpty(), fn ($query) => $query->where(function ($query): void {
                $query
                    ->whereNull('raw_attributes')
                    ->orWhere('raw_attributes', 'not like', '%"tesla_part_search_checked_at"%');
            }))
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->pluck('part_number')
            ->map(fn (?string $partNumber): string => strtoupper(trim((string) $partNumber)))
            ->filter()
            ->unique()
            ->values();
    }

    if ($partNumbers->isEmpty()) {
        $this->warn('No Tesla official part numbers to check.');

        return Command::SUCCESS;
    }

    $inputPath = tempnam(storage_path('app'), 'tesla-official-cdp-find-part-input-');
    if (! is_dir(dirname($inputPath))) {
        mkdir(dirname($inputPath), 0775, true);
    }
    file_put_contents($inputPath, json_encode(['part_numbers' => $partNumbers->all()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $browserMode = strtolower(trim((string) $this->option('browser')));
    $scriptMode = match ($browserMode) {
        'firefox' => 'firefox-find-part-search',
        'chrome' => 'chrome-find-part-search',
        'edge' => 'edge-find-part-search',
        'opera' => 'opera-find-part-search',
        default => 'cdp-find-part-search',
    };
    $command = [
        (string) $this->option('node'),
        $script,
        $scriptMode,
        '--input='.$inputPath,
        '--delay-ms='.(int) $this->option('delay-ms'),
        '--page-wait-ms='.(int) $this->option('page-wait-ms'),
    ];
    if ($scriptMode === 'cdp-find-part-search') {
        $command[] = '--cdp='.(string) $this->option('cdp');
    }
    if ((string) $this->option('profile-dir') !== '') {
        $command[] = '--profile-dir='.(string) $this->option('profile-dir');
    }
    if ((bool) $this->option('headed')) {
        $command[] = '--headed';
    }

    $process = new Process($command, base_path());
    $process->setTimeout(null);
    $process->run(function (string $type, string $buffer): void {
        if ($type === Process::ERR) {
            $this->output->write($buffer);
        }
    });
    @unlink($inputPath);

    if (! $process->isSuccessful()) {
        $this->error($process->getErrorOutput() ?: $process->getOutput());

        return Command::FAILURE;
    }

    $results = json_decode($process->getOutput(), true);

    if (! is_array($results)) {
        $this->error('Tesla find-part browser search returned invalid JSON.');

        return Command::FAILURE;
    }

    $stats = [
        'part_numbers_requested' => $partNumbers->count(),
        'results_seen' => 0,
        'source_items_marked' => 0,
        'source_items_skipped' => 0,
        'related_rows_seen' => 0,
        'related_rows_skipped' => 0,
        'related_items_saved' => 0,
    ];
    $applier = app(TeslaOfficialFindPartResultApplier::class);

    foreach ($results as $result) {
        if (! is_array($result)) {
            continue;
        }

        $stats['results_seen']++;
        $applier->apply($result, (bool) $this->option('dry-run'))
            ? $stats['source_items_marked']++
            : $stats['source_items_skipped']++;

        $importStats = $importer->importFindPartBrowserResult($result, [
            'dry_run' => (bool) $this->option('dry-run'),
            'download_images' => true,
        ]);
        $stats['related_rows_seen'] += (int) ($importStats['rows_seen'] ?? 0);
        $stats['related_rows_skipped'] += (int) ($importStats['rows_skipped'] ?? 0);
        $stats['related_items_saved'] += (int) ($importStats['items_saved'] ?? 0);
    }

    $this->info(($this->option('dry-run') ? 'Scanned' : 'Enriched').' Tesla official items through logged-in find-part.');
    foreach ($stats as $name => $value) {
        $this->line(" - {$name}: {$value}");
    }

    return Command::SUCCESS;
})->purpose('Slowly enrich official Tesla catalog items through a logged-in Chrome find-part page');

Artisan::command('parts:classify-zones {--dry-run} {--show-progress}', function (): int {
    $stats = app(PartCatalogZoneClassifier::class)->refreshAll(
        dryRun: (bool) $this->option('dry-run'),
        progress: (bool) $this->option('show-progress') ? fn (string $message) => $this->line($message) : null,
    );

    $this->info(($this->option('dry-run') ? 'Scanned' : 'Classified').' catalog item zones.');

    foreach ($stats as $name => $value) {
        $this->line(" - {$name}: {$value}");
    }

    return Command::SUCCESS;
})->purpose('Classify local catalog items by vehicle zones for donor product generation');

Artisan::command('parts:purge-non-tesla-catalog', function (): int {
    $models = ['Cybertruck', 'RIVIAN', 'LUCID AIR', 'Lucid Air'];
    $pathMarkers = ['/zapchasty/cybertruck-', '/zapchasty/rivian-', '/zapchasty/lucid-air-'];

    $itemsDeleted = PartCatalogItem::query()
        ->where(function ($query) use ($models, $pathMarkers): void {
            foreach ($models as $model) {
                $query->orWhere('model_name', 'like', $model.'%')
                    ->orWhere('model_label', 'like', $model.'%');
            }

            foreach ($pathMarkers as $marker) {
                $query->orWhere('source_url', 'like', '%'.$marker.'%');
            }
        })
        ->delete();

    $categoriesDeleted = 0;

    do {
        $deleted = PartCatalogCategory::query()
            ->whereDoesntHave('children')
            ->where(function ($query) use ($models, $pathMarkers): void {
                foreach ($models as $model) {
                    $query->orWhere('model_name', 'like', $model.'%')
                        ->orWhere('model_label', 'like', $model.'%');
                }

                foreach ($pathMarkers as $marker) {
                    $query->orWhere('source_url', 'like', '%'.$marker.'%');
                }
            })
            ->delete();

        $categoriesDeleted += $deleted;
    } while ($deleted > 0);

    $this->info('Removed non-Tesla catalog branches.');
    $this->line(" - categories_deleted: {$categoriesDeleted}");
    $this->line(" - items_deleted: {$itemsDeleted}");

    return Command::SUCCESS;
})->purpose('Remove Cybertruck, Rivian, and Lucid catalog rows from the local parts catalog');

Artisan::command('parts:purge-teslapartsukraine-catalog {--dry-run}', function (): int {
    $catalogItemsQuery = PartCatalogItem::query()
        ->where('source', 'teslapartsukraine')
        ->where('source_url', 'like', '%route=tesla/catalog/product%');

    $nonStoreItemsQuery = PartCatalogItem::query()
        ->where('source', 'teslapartsukraine')
        ->where(function ($query): void {
            $query
                ->whereNull('source_url')
                ->orWhere('source_url', 'not like', '%route=tesla/catalog/product%');
        })
        ->where(function ($query): void {
            $query
                ->whereNull('raw_attributes->product_url')
                ->whereNull('raw_attributes->listing_product_url');
        });

    $categoriesQuery = PartCatalogCategory::query()
        ->where('source', 'teslapartsukraine');

    $catalogItemsCount = (clone $catalogItemsQuery)->count();
    $nonStoreItemsCount = (clone $nonStoreItemsQuery)->count();
    $categoriesCount = (clone $categoriesQuery)->count();
    $storeItemsKept = PartCatalogItem::query()
        ->where('source', 'teslapartsukraine')
        ->where(function ($query): void {
            $query
                ->whereNotNull('raw_attributes->product_url')
                ->orWhereNotNull('raw_attributes->listing_product_url');
        })
        ->where(function ($query): void {
            $query
                ->whereNull('source_url')
                ->orWhere('source_url', 'not like', '%route=tesla/catalog/product%');
        })
        ->count();

    if ($this->option('dry-run')) {
        $this->info('TeslaPartsUkraine catalog purge preview.');
    } else {
        DB::transaction(function () use ($catalogItemsQuery, $nonStoreItemsQuery, $categoriesQuery): void {
            (clone $catalogItemsQuery)->delete();
            (clone $nonStoreItemsQuery)->delete();
            (clone $categoriesQuery)->delete();
        });

        foreach ([
            'part-catalog:items-count:teslapartsukraine',
            'part-catalog:categories-count:teslapartsukraine',
            'part-catalog:unique-parts-count:teslapartsukraine',
            'part-catalog:items-count:v2:teslapartsukraine',
            'part-catalog:categories-count:v2:teslapartsukraine',
            'part-catalog:unique-parts-count:v2:teslapartsukraine',
            'part-catalog:model-options:teslapartsukraine',
            'part-catalog:sidebar-unique-counts:v2',
            'part-catalog:sidebar-unique-counts:v3',
        ] as $key) {
            Cache::forget($key);
        }

        $this->info('TeslaPartsUkraine catalog purged.');
    }

    $this->line(" - catalog_items_deleted: {$catalogItemsCount}");
    $this->line(" - non_store_items_deleted: {$nonStoreItemsCount}");
    $this->line(" - categories_deleted: {$categoriesCount}");
    $this->line(" - store_items_kept: {$storeItemsKept}");

    return Command::SUCCESS;
})->purpose('Delete old TeslaPartsUkraine schematic catalog rows and keep only store products');

Artisan::command('parts:reset-teslapartsukraine-products {--dry-run}', function (): int {
    $itemsCount = PartCatalogItem::query()->where('source', 'teslapartsukraine')->count();
    $categoriesCount = PartCatalogCategory::query()->where('source', 'teslapartsukraine')->count();

    if (! $this->option('dry-run')) {
        DB::transaction(function (): void {
            PartCatalogItem::query()->where('source', 'teslapartsukraine')->delete();
            PartCatalogCategory::query()->where('source', 'teslapartsukraine')->delete();
        });

        foreach ([
            'part-catalog:items-count:teslapartsukraine',
            'part-catalog:categories-count:teslapartsukraine',
            'part-catalog:unique-parts-count:teslapartsukraine',
            'part-catalog:items-count:v2:teslapartsukraine',
            'part-catalog:categories-count:v2:teslapartsukraine',
            'part-catalog:unique-parts-count:v2:teslapartsukraine',
            'part-catalog:model-options:teslapartsukraine',
            'part-catalog:sidebar-unique-counts:v2',
            'part-catalog:sidebar-unique-counts:v3',
        ] as $key) {
            Cache::forget($key);
        }
    }

    $this->info($this->option('dry-run') ? 'TeslaPartsUkraine reset preview.' : 'TeslaPartsUkraine products reset.');
    $this->line(" - items_deleted: {$itemsCount}");
    $this->line(" - categories_deleted: {$categoriesCount}");

    return Command::SUCCESS;
})->purpose('Delete all TeslaPartsUkraine rows before a clean product-only import');
