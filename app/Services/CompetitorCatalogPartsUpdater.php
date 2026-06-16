<?php

namespace App\Services;

use App\Models\CompetitorCatalogRun;
use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CompetitorCatalogPartsUpdater
{
    public const SOURCES = [
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
    ];

    public function run(CompetitorCatalogRun $run): array
    {
        $source = $this->normalizeSource($run->source);
        $stats = [
            'catalog_products_saved' => 0,
            'catalog_products_created' => 0,
            'catalog_products_updated' => 0,
            'products_seen' => 0,
            'products_created' => 0,
            'products_updated' => 0,
            'products_unchanged' => 0,
            'prices_changed' => 0,
            'products_marked_unavailable' => 0,
        ];

        $this->markRunning($run, 0, max($this->sourceCategoryTotal($source, $run), 1), $source === 'teslapartsukraine'
            ? 'Парсю товары '.$this->sourceLabel($source).'.'
            : 'Парсю каталог '.$this->sourceLabel($source).'.');
        $importStats = $this->importSource($source, $run);
        if (isset($importStats['error'])) {
            throw new InvalidArgumentException((string) $importStats['error']);
        }

        $this->markRunning($run, 0, max($this->sourceCategoryTotal($source, $run), 1), 'Скачиваю фото конкурента в локальное хранилище.');
        $importStats = array_merge($importStats, app(CompetitorCatalogImageLocalizer::class)->localizeSource($source, [
            'progress' => fn (string $message) => $this->handleImporterProgress($run, $message, $source),
        ]));

        $progressStats = $run->stats ? (array) $run->stats : [];
        $stats = array_merge($stats, $importStats, $progressStats);
        $stats['progress_pages_opened'] = max(
            (int) ($stats['progress_pages_opened'] ?? 0),
            (int) ($importStats['pages_scanned'] ?? 0),
            (int) ($importStats['listing_pages_fetched'] ?? 0),
            (int) ($importStats['category_pages_scanned'] ?? 0),
            (int) ($importStats['site_category_pages_scanned'] ?? 0),
            (int) ($importStats['model_pages_fetched'] ?? 0),
            (int) ($importStats['category_pages_fetched'] ?? 0)
        );
        $stats['progress_items_found'] = max(
            (int) ($stats['progress_items_found'] ?? 0),
            (int) ($importStats['products_found'] ?? 0),
            (int) ($importStats['listing_products_seen'] ?? 0),
            (int) ($importStats['site_products_found'] ?? 0),
            (int) ($importStats['product_pages_seen'] ?? 0),
            (int) ($importStats['products_seen'] ?? 0),
            (int) ($importStats['items_seen'] ?? 0)
        );
        $stats['catalog_products_saved'] = (int) (
            $importStats['products_saved']
            ?? $importStats['items_saved']
            ?? collect($importStats)
                ->only(['products_created', 'products_updated', 'items_created', 'items_updated'])
                ->sum()
        );
        $stats['catalog_products_created'] = (int) collect($importStats)
            ->only(['products_created', 'items_created'])
            ->sum();

        if ($run->started_at !== null) {
            $stats['catalog_products_created'] = max($stats['catalog_products_created'], PartCatalogItem::query()
                ->where('source', $source)
                ->where('created_at', '>=', $run->started_at)
                ->count());
        }

        $stats['catalog_products_updated'] = max(0, $stats['catalog_products_saved'] - $stats['catalog_products_created']);

        if ($run->started_at !== null) {
            $stats['catalog_products_updated'] = max($stats['catalog_products_updated'], PartCatalogItem::query()
                ->where('source', $source)
                ->where('updated_at', '>=', $run->started_at)
                ->count() - $stats['catalog_products_created']);
        }

        $stats['products_created'] = 0;
        $stats['products_updated'] = 0;
        $stats['catalog_part_number_duplicate_groups'] = 0;
        $stats['catalog_part_number_items_merged'] = 0;
        $stats['catalog_part_number_occurrences_saved'] = 0;

        $this->markRunning($run, 0, max($stats['catalog_products_saved'], 1), 'Каталог конкурента обновлён.');

        $run->forceFill([
            'status' => 'done',
            'progress_current' => max($stats['catalog_products_saved'], 1),
            'progress_total' => max($stats['catalog_products_saved'], 1),
            'message' => 'Готово: каталог конкурента обновлён; товары не создавались.',
            'stats' => $stats,
            'finished_at' => now(),
        ])->save();

        $run->forceFill([
            'message' => "Готово: каталог конкурента обновлён; новых позиций {$stats['catalog_products_created']}, обновлено {$stats['catalog_products_updated']}.",
        ])->save();

        $this->clearCatalogCache($source);

        return $stats;
    }

    public function isSupported(string $source): bool
    {
        return in_array($source, self::SOURCES, true);
    }

    public function normalizeSource(string $source): string
    {
        $source = trim($source);

        if (! $this->isSupported($source)) {
            throw new InvalidArgumentException("Unsupported competitor source [{$source}].");
        }

        return $source;
    }

    public function sourceLabel(string $source): string
    {
        return [
            'tcarservice' => 'TCARS',
            'teslapartsukraine' => 'TeslaPartsUkraine',
            'tsk' => 'TSK',
            'stock-tesla' => 'Stock Tesla',
            'teslahelp' => 'TeslaHelp',
            'driveparts' => 'DriveParts',
            'dkparts' => 'DK-Parts',
            'erazborka' => 'Erazborka',
            'toprazborka' => 'TopRazborka',
            'teslawestparts' => 'Tesla West Parts',
            'teslacompany' => 'TeslaCompany',
        ][$source] ?? $source;
    }

    protected function importSource(string $source, CompetitorCatalogRun $run): array
    {
        $progress = fn (string $message) => $this->handleImporterProgress($run, $message, $source);

        return match ($source) {
            'tcarservice' => app(TcarserviceCatalogImporter::class)->importLeafProducts([
                'max_categories' => 0,
                'max_products' => 0,
                'max_pages_per_category' => 50,
                'sleep_ms' => 150,
                'rescan_products' => true,
                'discover_child_categories' => false,
                'verbose' => true,
                'progress' => $progress,
            ]),
            'teslapartsukraine' => $this->importTeslaPartsUkraine($progress),
            'tsk' => app(TskCatalogImporter::class)->importLeafProducts([
                'category_id' => $this->refreshCategoryId($run),
                'sleep_ms' => 150,
                'rescan' => true,
                'verbose' => true,
                'progress' => $progress,
            ]),
            'stock-tesla' => app(StockTeslaCatalogImporter::class)->import([
                'create_categories' => false,
                'rescan_products' => false,
                'model_category_urls' => $this->stockTeslaModelCategoryUrls(),
                'sleep_ms' => 500,
                'with_russian' => true,
                'verbose' => true,
                'progress' => $progress,
            ]),
            'teslahelp' => app(TeslaHelpCatalogImporter::class)->import([
                'sleep_ms' => 250,
                'rescan' => true,
                'with_teslashop' => true,
                'verbose' => true,
                'progress' => $progress,
            ]),
            'driveparts' => app(DrivePartsCatalogImporter::class)->importProducts([
                'all_products' => true,
                'sleep_ms' => 100,
                'rescan' => true,
                'verbose' => true,
                'progress' => $progress,
            ]),
            'dkparts' => app(DkPartsCatalogImporter::class)->importProducts([
                'max_pages_per_category' => 50,
                'sleep_ms' => 100,
                'rescan' => true,
                'verbose' => true,
                'progress' => $progress,
            ]),
            'erazborka' => app(ErazborkaCatalogImporter::class)->importProducts([
                'max_pages_per_category' => 50,
                'sleep_ms' => 100,
                'rescan' => true,
                'download_images' => false,
                'verbose' => true,
                'progress' => $progress,
            ]),
            'toprazborka' => app(TopRazborkaCatalogImporter::class)->importProducts([
                'max_pages_per_category' => 50,
                'sleep_ms' => 100,
                'rescan' => true,
                'verbose' => true,
                'progress' => $progress,
            ]),
            'teslawestparts' => app(TeslaWestPartsCatalogImporter::class)->import([
                'sleep_ms' => 100,
                'verbose' => true,
                'progress' => $progress,
            ]),
            'teslacompany' => $this->importTeslaCompany($progress),
        };
    }

    protected function importTeslaCompany(callable $progress): array
    {
        return app(TeslaCompanyCatalogImporter::class)->refreshModelListings([
            'sleep_ms' => 150,
            'verbose' => true,
            'progress' => $progress,
        ]);
    }

    protected function importTeslaPartsUkraine(callable $progress): array
    {
        return app(TeslaPartsUkraineCatalogImporter::class)->refreshModelListings([
            'sleep_ms' => 150,
            'verbose' => true,
            'progress' => $progress,
        ]);
    }

    protected function stockTeslaModelCategoryUrls(): array
    {
        return [
            'https://stock-tesla.com/ru/category/3-1/',
            'https://stock-tesla.com/ru/category/s-2016-1/',
            'https://stock-tesla.com/ru/category/s-2016/',
            'https://stock-tesla.com/ru/category/x/',
            'https://stock-tesla.com/ru/category/y/',
        ];
    }

    protected function sourceCategoryTotal(string $source, ?CompetitorCatalogRun $run = null): int
    {
        if ($source === 'teslapartsukraine') {
            return PartCatalogItem::query()
                ->where('source', $source)
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
        }

        $query = PartCatalogCategory::query()->where('source', $source);

        if ($source === 'tsk' && $run !== null && $this->refreshCategoryId($run) > 0) {
            return (clone $query)
                ->whereIn('id', $this->categoryBranchIds($this->refreshCategoryId($run), $source))
                ->doesntHave('children')
                ->count();
        }

        return in_array($source, ['tcarservice', 'tsk'], true)
            ? (clone $query)->doesntHave('children')->count()
            : max((clone $query)->count(), PartCatalogItem::query()->where('source', $source)->count());
    }

    protected function refreshCategoryId(CompetitorCatalogRun $run): int
    {
        $stats = $run->stats instanceof \ArrayObject
            ? $run->stats->getArrayCopy()
            : (array) $run->stats;

        return max(0, (int) ($stats['category_id'] ?? 0));
    }

    protected function categoryBranchIds(int $categoryId, string $source): array
    {
        if ($categoryId <= 0) {
            return [];
        }

        $ids = [$categoryId];
        $pending = [$categoryId];

        while ($pending !== []) {
            $children = PartCatalogCategory::query()
                ->where('source', $source)
                ->whereIn('parent_id', $pending)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $children = array_values(array_diff($children, $ids));

            if ($children === []) {
                break;
            }

            $ids = array_values(array_unique([...$ids, ...$children]));
            $pending = $children;
        }

        return $ids;
    }

    protected function markRunning(CompetitorCatalogRun $run, int $current, int $total, string $message): void
    {
        $run->forceFill([
            'status' => 'running',
            'progress_current' => $current,
            'progress_total' => $total,
            'message' => Str::limit($message, 255, ''),
            'started_at' => $run->started_at ?: now(),
        ])->save();
    }

    protected function updateMessage(CompetitorCatalogRun $run, string $message): void
    {
        $run->forceFill(['message' => Str::limit($message, 255, '')])->save();
    }

    protected function handleImporterProgress(CompetitorCatalogRun $run, string $message, string $source): void
    {
        $sourceLabel = $this->sourceLabel($source);
        $stats = $run->stats ? (array) $run->stats : [];

        if (preg_match('/^Model(?:\s+root)?:\s*(.+)$/i', $message, $matches) === 1) {
            $stats['progress_current_model'] = $this->cleanProgressModel((string) $matches[1]);
            $run->forceFill(['stats' => $stats])->save();
            $this->markRunning($run, max((int) $run->progress_current, 1), max((int) $run->progress_total, 1), "{$sourceLabel}: сканируется модель {$stats['progress_current_model']}.");

            return;
        }

        if ($source === 'teslacompany' && preg_match('/^TeslaCompany download:\s*(\d+):\s+.+\s+-\s+(\d+)\s+items$/i', $message, $matches) === 1) {
            $page = (int) $matches[1];
            $itemsOnPage = (int) $matches[2];
            $stats = $run->stats ? (array) $run->stats : [];
            $stats['teslacompany_pages_scanned'] = max((int) ($stats['teslacompany_pages_scanned'] ?? 0), $page);
            $stats['progress_pages_opened'] = max((int) ($stats['progress_pages_opened'] ?? 0), $page);
            $stats['teslacompany_items_found'] = (int) ($stats['teslacompany_items_found'] ?? 0) + $itemsOnPage;
            $stats['progress_pages_scanned'] = max((int) ($stats['progress_pages_scanned'] ?? 0), $page);
            $stats['progress_items_found'] = (int) ($stats['progress_items_found'] ?? 0) + $itemsOnPage;

            $this->markRunning(
                $run,
                $page,
                max((int) $run->progress_total, $page + 1),
                "TeslaCompany: просмотрено страниц {$stats['teslacompany_pages_scanned']}, найдено товаров {$stats['teslacompany_items_found']}."
            );
            $run->forceFill(['stats' => $stats])->save();

            return;
        }

        if ($source === 'teslacompany' && preg_match('/^TeslaCompany download:\s*fetching:\s*(\d+):\s+(.+)$/i', $message, $matches) === 1) {
            $page = (int) $matches[1];
            $stats['progress_pages_opened'] = max((int) ($stats['progress_pages_opened'] ?? 0), $page);
            $stats['progress_pages_scanned'] = max((int) ($stats['progress_pages_scanned'] ?? 0), $page);
            $stats['progress_page_url'] = (string) $matches[2];
            $stats['progress_current_model'] = $this->progressModelFromTarget($source, (string) $matches[2])
                ?: ($stats['progress_current_model'] ?? null);

            $this->markRunning($run, $page, max((int) $run->progress_total, $page + 1), "{$sourceLabel}: запрашиваю страницу {$page}.");
            $run->forceFill(['stats' => $stats])->save();

            return;
        }

        if ($source === 'teslacompany' && preg_match('/^TeslaCompany download:\s*details:\s*(\d+)\/(\d+)$/i', $message, $matches) === 1) {
            $current = (int) $matches[1];
            $total = max(1, (int) $matches[2]);
            $stats = $run->stats ? (array) $run->stats : [];
            $stats['teslacompany_detail_current'] = $current;
            $stats['teslacompany_detail_total'] = $total;
            $stats['progress_detail_current'] = $current;
            $stats['progress_detail_total'] = $total;

            $this->markRunning($run, $current, $total, "TeslaCompany: обработано карточек {$current} из {$total}.");
            $run->forceFill(['stats' => $stats])->save();

            return;
        }

        if ($source === 'teslacompany' && preg_match('/^TeslaCompany download:\s*Saved\s+(\d+)\s+rows$/i', $message, $matches) === 1) {
            $total = max(1, (int) $matches[1]);
            $stats['progress_items_found'] = max((int) ($stats['progress_items_found'] ?? 0), $total);
            $stats['progress_detail_current'] = max((int) ($stats['progress_detail_current'] ?? 0), $total);
            $stats['progress_detail_total'] = max((int) ($stats['progress_detail_total'] ?? 0), $total);
            $run->forceFill(['stats' => $stats])->save();
            $this->markRunning($run, $total, $total, "TeslaCompany: скачано {$total} товаров, импортирую в базу.");

            return;
        }

        if ($source === 'teslacompany' && preg_match('/^TeslaCompany\s+(\d+):/i', $message, $matches) === 1) {
            $current = (int) $matches[1];
            $stats['progress_imported_rows'] = max((int) ($stats['progress_imported_rows'] ?? 0), $current);
            $run->forceFill(['stats' => $stats])->save();
            $this->markRunning($run, $current, max((int) $run->progress_total, $current), "TeslaCompany: импортировано строк {$current}.");

            return;
        }

        if (preg_match('/^(?:Model|Category|Leaf category|TeslaHelp category)\s*#?(\d+)[: ]/i', $message, $matches) === 1) {
            $current = (int) $matches[1];
            $stats['progress_categories_scanned'] = max((int) ($stats['progress_categories_scanned'] ?? 0), $current);
            $stats['progress_current_model'] = $this->progressModelFromMessage($source, $message)
                ?: ($stats['progress_current_model'] ?? null);
            $this->markRunning($run, $current, max((int) $run->progress_total, $current), "{$sourceLabel}: просмотрено категорий {$stats['progress_categories_scanned']}.");
            $run->forceFill(['stats' => $stats])->save();

            return;
        }

        if (preg_match('/^All products page\s*#?(\d+):\s*(.+)$/i', $message, $matches) === 1) {
            $current = (int) $matches[1];
            $stats['progress_pages_opened'] = max((int) ($stats['progress_pages_opened'] ?? 0), $current);
            $stats['progress_pages_scanned'] = max((int) ($stats['progress_pages_scanned'] ?? 0), $current);
            $stats['progress_page_url'] = (string) $matches[2];
            $stats['progress_current_model'] = $this->progressModelFromTarget($source, (string) $matches[2])
                ?: ($stats['progress_current_model'] ?? null);
            $this->markRunning($run, $current, max((int) $run->progress_total, $current + 1), "{$sourceLabel}: просмотрено страниц {$stats['progress_pages_scanned']}.");
            $run->forceFill(['stats' => $stats])->save();

            return;
        }

        if (preg_match('/^Opened pages\s+(\d+)$/i', $message, $matches) === 1) {
            $current = (int) $matches[1];
            $stats['progress_pages_opened'] = max((int) ($stats['progress_pages_opened'] ?? 0), $current);
            $run->forceFill(['stats' => $stats])->save();

            return;
        }

        if (preg_match('/^Opened listing pages\s+(\d+)$/i', $message, $matches) === 1) {
            $current = (int) $matches[1];
            $stats['progress_pages_opened'] = max((int) ($stats['progress_pages_opened'] ?? 0), $current);
            $stats['progress_pages_scanned'] = max((int) ($stats['progress_pages_scanned'] ?? 0), $current);
            $this->markRunning($run, $current, max((int) $run->progress_total, $current), "{$sourceLabel}: просмотрено страниц {$stats['progress_pages_opened']}.");
            $run->forceFill(['stats' => $stats])->save();

            return;
        }

        if (preg_match('/^\s*(?:fetched$|fetched page:\s+.+|Page:\s+.+|category:\s+.+)$/i', $message) === 1) {
            $current = (int) ($stats['progress_pages_opened'] ?? 0) + 1;
            $stats['progress_pages_opened'] = $current;
            $this->markRunning($run, $current, max((int) $run->progress_total, $current), "{$sourceLabel}: просмотрено страниц {$current}.");
            $run->forceFill(['stats' => $stats])->save();

            return;
        }

        if (preg_match('/^\s*Page\s+(\d+):/i', $message, $matches) === 1) {
            $current = (int) $matches[1];
            $stats['progress_pages_opened'] = max((int) ($stats['progress_pages_opened'] ?? 0), $current);
            $stats['progress_pages_scanned'] = max((int) ($stats['progress_pages_scanned'] ?? 0), $current);
            $this->markRunning($run, $current, max((int) $run->progress_total, $current), "{$sourceLabel}: просмотрено страниц {$stats['progress_pages_scanned']}.");
            $run->forceFill(['stats' => $stats])->save();

            return;
        }

        if ($source === 'driveparts' && preg_match('/(?:Product page|Product|product)\s*#(\d+)/i', $message, $matches) === 1) {
            $current = (int) $matches[1];
            $stats['progress_items_found'] = max((int) ($stats['progress_items_found'] ?? 0), $current);
            $this->markRunning($run, $current, max((int) $run->progress_total, $current), "{$sourceLabel}: обработано товаров из листинга {$stats['progress_items_found']}.");
            $run->forceFill(['stats' => $stats])->save();

            return;
        }

        if (preg_match('/(?:Product page|Product|product)\s*#(\d+)/i', $message, $matches) === 1) {
            $current = (int) $matches[1];
            $stats['progress_detail_current'] = max((int) ($stats['progress_detail_current'] ?? 0), $current);
            $stats['progress_items_found'] = max((int) ($stats['progress_items_found'] ?? 0), $current);
            $this->markRunning($run, $current, max((int) $run->progress_total, $current), "{$sourceLabel}: обработано карточек {$stats['progress_detail_current']}.");
            $run->forceFill(['stats' => $stats])->save();

            return;
        }

        if (preg_match('/^Listing products seen\s+(\d+)$/i', $message, $matches) === 1) {
            $current = (int) $matches[1];
            $stats['progress_items_found'] = max((int) ($stats['progress_items_found'] ?? 0), $current);
            $pagesOpened = (int) ($stats['progress_pages_opened'] ?? 0);
            $this->markRunning($run, max($pagesOpened, 1), max((int) $run->progress_total, max($pagesOpened, 1)), "{$sourceLabel}: просмотрено страниц {$pagesOpened}, найдено товаров в листинге {$stats['progress_items_found']}.");
            $run->forceFill(['stats' => $stats])->save();

            return;
        }

        if (preg_match('/^Opening product page:\s+(.+)$/i', $message, $matches) === 1) {
            $stats['progress_product_url'] = (string) $matches[1];
            $pagesOpened = (int) ($stats['progress_pages_opened'] ?? 0);
            $itemsFound = (int) ($stats['progress_items_found'] ?? 0);
            $this->markRunning($run, max($pagesOpened, 1), max((int) $run->progress_total, max($pagesOpened, 1)), "{$sourceLabel}: открываю карточку товара {$itemsFound}.");
            $run->forceFill(['stats' => $stats])->save();

            return;
        }

        if (preg_match('/^\s*products?:\s*(\d+)/i', $message, $matches) === 1
            || preg_match('/^\s*product links found:\s*(\d+)/i', $message, $matches) === 1) {
            $found = (int) $matches[1];
            $stats['progress_items_found'] = (int) ($stats['progress_items_found'] ?? 0) + $found;
            $this->markRunning(
                $run,
                max((int) $run->progress_current, (int) ($stats['progress_pages_scanned'] ?? 0), (int) ($stats['progress_categories_scanned'] ?? 0)),
                max((int) $run->progress_total, 1),
                "{$sourceLabel}: найдено позиций {$stats['progress_items_found']}."
            );
            $run->forceFill(['stats' => $stats])->save();

            return;
        }

        if ($source === 'teslawestparts' && preg_match('/^#\d+\s+.+/i', $message) === 1) {
            $stats['progress_items_found'] = (int) ($stats['progress_items_found'] ?? 0) + 1;
            $stats['progress_detail_current'] = (int) ($stats['progress_detail_current'] ?? 0) + 1;
            $this->markRunning($run, (int) $stats['progress_detail_current'], max((int) $run->progress_total, (int) $stats['progress_detail_current']), "{$sourceLabel}: обработано карточек {$stats['progress_detail_current']}.");
            $run->forceFill(['stats' => $stats])->save();

            return;
        }

        if (preg_match('/(?:Leaf category|Category|Product page|Page) #(\d+)/i', $message, $matches) === 1) {
            $current = (int) $matches[1];
            $stats['progress_current_model'] = $this->progressModelFromMessage($source, $message)
                ?: ($stats['progress_current_model'] ?? null);
            $run->forceFill(['stats' => $stats])->save();
            $this->markRunning($run, $current, max((int) $run->progress_total, $current), $message);

            return;
        }

        if (preg_match('/^\s*(created|updated|saved):/i', $message, $matches) === 1) {
            $stats = $run->stats ? (array) $run->stats : [];
            $stats['catalog_products_saved'] = (int) ($stats['catalog_products_saved'] ?? 0) + 1;
            $stats['progress_products_saved'] = (int) ($stats['progress_products_saved'] ?? 0) + 1;

            if (strtolower($matches[1]) === 'created') {
                $stats['catalog_products_created'] = (int) ($stats['catalog_products_created'] ?? 0) + 1;
            } elseif (strtolower($matches[1]) === 'updated') {
                $stats['catalog_products_updated'] = (int) ($stats['catalog_products_updated'] ?? 0) + 1;

                if (str_contains($message, '[price_amount]')) {
                    $stats['prices_changed'] = (int) ($stats['prices_changed'] ?? 0) + 1;
                }
            }

            $run->forceFill([
                'message' => Str::limit($message, 255, ''),
                'stats' => $stats,
            ])->save();

            return;
        }

        $this->updateMessage($run, $message ?: 'Парсю каталог '.$this->sourceLabel($source).'.');
    }

    protected function progressModelFromMessage(string $source, string $message): ?string
    {
        if (preg_match('/:\s*(https?:\/\/\S+)$/i', $message, $matches) === 1) {
            return $this->progressModelFromTarget($source, (string) $matches[1]);
        }

        if (preg_match('/:\s*(.+)$/', $message, $matches) === 1) {
            return $this->progressModelFromTarget($source, (string) $matches[1]);
        }

        return null;
    }

    protected function progressModelFromTarget(string $source, string $target): ?string
    {
        $target = trim($target);
        if ($target === '') {
            return null;
        }

        $category = PartCatalogCategory::query()
            ->where('source', $source)
            ->where(function ($query) use ($target): void {
                $query
                    ->where('source_url', $target)
                    ->orWhere('name', $target);
            })
            ->first(['name', 'model_label', 'model_name']);

        $category ??= PartCatalogCategory::query()
            ->where('source', $source)
            ->whereNotNull('source_url')
            ->get(['name', 'model_label', 'model_name', 'source_url'])
            ->filter(fn (PartCatalogCategory $category): bool => str_starts_with($target, (string) $category->source_url))
            ->sortByDesc(fn (PartCatalogCategory $category): int => strlen((string) $category->source_url))
            ->first();

        if ($category !== null) {
            return $this->cleanProgressModel($category->model_label ?: ($category->model_name ?: $category->name));
        }

        return $this->modelLabelFromUrl($target);
    }

    protected function modelLabelFromUrl(string $url): ?string
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        $path = str_replace(['_', '+'], '-', $path);

        $patterns = [
            'model-3-highland' => 'Model 3 Highland',
            'tesla-model-3-highland' => 'Model 3 Highland',
            'model-s-plaid' => 'Model S Plaid',
            'tesla-model-s-plaid' => 'Model S Plaid',
            'model-x-plaid' => 'Model X Plaid',
            'tesla-model-x-plaid' => 'Model X Plaid',
            'model-s-after-2016' => 'Model S after 2016',
            'model-s-before-2016' => 'Model S before 2016',
            's-2016' => 'Model S 2016',
            'model-3' => 'Model 3',
            'tesla-model-3' => 'Model 3',
            '/3-1/' => 'Model 3',
            'model-s' => 'Model S',
            'tesla-model-s' => 'Model S',
            'model-x' => 'Model X',
            'tesla-model-x' => 'Model X',
            'model-y' => 'Model Y',
            'tesla-model-y' => 'Model Y',
        ];

        foreach ($patterns as $needle => $label) {
            if (str_contains($path, $needle)) {
                return $label;
            }
        }

        return null;
    }

    protected function cleanProgressModel(string $model): string
    {
        return trim(preg_replace('/\s+/', ' ', $model) ?: $model);
    }

    protected function clearCatalogCache(string $source): void
    {
        foreach (['items-count', 'categories-count', 'model-options'] as $type) {
            Cache::forget('part-catalog:'.$type.':'.$source);
        }
    }
}
