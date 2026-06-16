<?php

namespace App\Services;

use App\Models\CompetitorCatalogRun;
use App\Models\PartCatalogItem;
use App\Models\ProductPriceHistory;

class PartCatalogCompetitorRefreshPayload
{
    public function __construct(
        private readonly CompetitorCatalogPartsUpdater $updater,
        private readonly PartCatalogCompetitorRefreshService $refresh,
    ) {}

    public function make(
        string $source,
        ?int $itemsCount,
        ?int $totalProductsCount,
        callable $displayItemName,
        callable $routePrefix,
        ?CompetitorCatalogRun $run = null,
    ): ?array {
        if (! $this->updater->isSupported($source)) {
            return null;
        }

        $run ??= CompetitorCatalogRun::query()
            ->where('source', $source)
            ->latest('id')
            ->first();

        if ($run === null) {
            return [
                'source' => $source,
                'status' => null,
                'is_running' => false,
                'progress_percent' => 0,
                'message' => 'Готов к обновлению каталога конкурента.',
                'finished_at' => null,
                'finished_label' => '-',
                ...$this->catalogCounts($itemsCount, $totalProductsCount),
                'progress_current_model' => null,
                'created_catalog_items' => [],
                'price_changes' => [],
            ];
        }

        $stats = $run->stats instanceof \ArrayObject
            ? $run->stats->getArrayCopy()
            : (array) $run->stats;
        $progressTotal = max((int) $run->progress_total, 0);
        $progressCurrent = max((int) $run->progress_current, 0);
        $progressPercent = $progressTotal > 0
            ? min(100, (int) round(($progressCurrent / $progressTotal) * 100))
            : ($run->status === 'done' ? 100 : 0);
        $createdCatalogItemsCount = $this->createdItemsCount($source, $run);
        $priceChangesCount = $this->priceChangesCount($source, $run);

        return [
            ...$stats,
            'catalog_products_created' => max((int) ($stats['catalog_products_created'] ?? 0), $createdCatalogItemsCount),
            'prices_changed' => max((int) ($stats['prices_changed'] ?? 0), $priceChangesCount),
            'source' => $source,
            'status' => $run->isStopped() ? 'stopped' : $run->status,
            'is_running' => $run->isRunning(),
            'progress_current' => $progressCurrent,
            'progress_total' => $progressTotal,
            'progress_percent' => $progressPercent,
            'message' => $run->message ?: 'Готов к обновлению каталога конкурента.',
            'error' => $run->error,
            'stopped_message' => $run->isStopped() ? 'Остановлено: обновление давно не отвечает, можно продолжить запуск.' : null,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'finished_label' => $run->finished_at?->format('d.m.Y H:i') ?? '-',
            'next_available_at' => $run->cooldownAvailableAt()?->toIso8601String(),
            ...$this->catalogCounts($itemsCount, $totalProductsCount),
            'crawl_duration_label' => $this->refresh->durationLabel($run),
            'created_catalog_items' => $this->createdItems($source, $run, $displayItemName, $routePrefix),
            'price_changes' => $this->priceChanges($source, $run, $displayItemName, $routePrefix),
        ];
    }

    private function catalogCounts(?int $itemsCount, ?int $totalProductsCount): array
    {
        if ($itemsCount === null || $totalProductsCount === null) {
            return [];
        }

        return [
            'items_count' => $itemsCount,
            'total_products_count' => $totalProductsCount,
        ];
    }

    private function createdItemsCount(string $source, CompetitorCatalogRun $run): int
    {
        if ($run->started_at === null) {
            return 0;
        }

        return PartCatalogItem::query()
            ->where('source', $source)
            ->where('created_at', '>=', $run->started_at)
            ->count();
    }

    private function createdItems(string $source, CompetitorCatalogRun $run, callable $displayItemName, callable $routePrefix): array
    {
        if ($run->started_at === null) {
            return [];
        }

        return PartCatalogItem::query()
            ->where('source', $source)
            ->where('created_at', '>=', $run->started_at)
            ->latest('id')
            ->limit(20)
            ->get(['id', 'source', 'part_number', 'name', 'name_ru', 'name_ua'])
            ->map(fn (PartCatalogItem $item): array => [
                'part_number' => $item->part_number,
                'name' => $displayItemName($item),
                'url' => route($routePrefix($source).'.show', $item),
            ])
            ->all();
    }

    private function priceChangesCount(string $source, CompetitorCatalogRun $run): int
    {
        if ($run->started_at === null) {
            return 0;
        }

        return ProductPriceHistory::query()
            ->where('source', $source)
            ->where('changed_at', '>=', $run->started_at)
            ->distinct('part_catalog_item_id')
            ->count('part_catalog_item_id');
    }

    private function priceChanges(string $source, CompetitorCatalogRun $run, callable $displayItemName, callable $routePrefix): array
    {
        if ($run->started_at === null) {
            return [];
        }

        return ProductPriceHistory::query()
            ->with('partCatalogItem:id,source,part_number,name,name_ru,name_ua')
            ->where('source', $source)
            ->where('changed_at', '>=', $run->started_at)
            ->latest('changed_at')
            ->limit(20)
            ->get()
            ->map(function (ProductPriceHistory $history) use ($displayItemName, $routePrefix, $source): array {
                $item = $history->partCatalogItem;

                return [
                    'name' => $item ? $displayItemName($item) : 'Позиция каталога',
                    'old_price' => $history->old_price,
                    'new_price' => $history->new_price,
                    'url' => $item ? route($routePrefix($source).'.show', $item) : '#',
                ];
            })
            ->all();
    }
}
