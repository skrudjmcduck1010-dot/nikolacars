<?php

namespace App\Console\Commands;

use App\Models\PartCatalogItem;
use App\Models\Product;
use App\Services\NikolaCarsInventoryService;
use App\Services\NikolaCarsProductInventorySyncService;
use App\Support\PartCatalogRawAttributes;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AuditNikolaCarsCatalogIntegrity extends Command
{
    protected $signature = 'parts:audit-nikolacars-catalog-integrity
        {--item-id=* : Limit to one or more NikolaCars catalog item IDs}
        {--active-only : Inspect only rows visible in the active /admin/zapchasti list}
        {--limit=0 : Maximum catalog rows to inspect}
        {--examples=10 : Problem examples to show}
        {--repair : Repair safe product/projection link drift}
        {--dry-run : Force read-only mode, even with --repair}';

    protected $description = 'Audit NikolaCars catalog rows from the projection side and verify product-first integrity.';

    public function handle(
        NikolaCarsInventoryService $inventory,
        NikolaCarsProductInventorySyncService $syncService
    ): int {
        $repair = (bool) $this->option('repair') && ! (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));
        $examplesLimit = max(0, (int) $this->option('examples'));
        $itemIds = collect((array) $this->option('item-id'))
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();
        $activeItemIds = $inventory->activeItemsQuery()
            ->when($itemIds->isNotEmpty(), fn (Builder $query) => $query->whereIn('id', $itemIds->all()))
            ->pluck('id')
            ->mapWithKeys(fn (mixed $id): array => [(int) $id => true]);

        $stats = [
            'items_seen' => 0,
            'active_items_seen' => 0,
            'ok' => 0,
            'problem_items' => 0,
            'would_repair' => 0,
            'repaired' => 0,
            'skipped_conflict' => 0,
            'missing_linked_product' => 0,
            'active_missing_linked_product' => 0,
            'raw_product_id_missing' => 0,
            'raw_product_id_missing_on_active' => 0,
            'raw_product_id_missing_or_broken' => 0,
            'source_url_product_missing_or_broken' => 0,
            'raw_product_id_mismatch' => 0,
            'source_url_product_id_mismatch' => 0,
            'noncanonical_source_url' => 0,
            'product_source_link_mismatch' => 0,
            'part_number_mismatch' => 0,
            'linked_product_not_mirrorable' => 0,
            'active_linked_product_not_sellable' => 0,
            'target_conflict' => 0,
        ];
        $examples = [];

        $query = PartCatalogItem::query()
            ->where('source', NikolaCarsInventoryService::SOURCE)
            ->when($itemIds->isNotEmpty(), fn (Builder $query) => $query->whereIn('id', $itemIds->all()))
            ->when((bool) $this->option('active-only'), fn (Builder $query) => $query->whereIn('id', $activeItemIds->keys()->all()))
            ->orderBy('id');

        $inspect = function (PartCatalogItem $item) use ($syncService, $repair, $activeItemIds, &$stats, &$examples, $examplesLimit): void {
            $this->inspectItem($item, $syncService, $repair, $activeItemIds->has((int) $item->id), $stats, $examples, $examplesLimit);
        };

        if ($limit > 0) {
            $query->limit($limit)->get()->each($inspect);
        } else {
            $query->chunkById(300, fn (Collection $items) => $items->each($inspect));
        }

        $this->info(($repair ? 'Repaired' : 'Scanned').' NikolaCars catalog integrity.');
        $this->table(
            ['metric', 'count'],
            collect($stats)->map(fn (int $count, string $metric): array => [$metric, $count])->values()->all()
        );

        if ($examples !== []) {
            $this->newLine();
            $this->warn('Examples');
            $this->table(
                ['item_id', 'part_number', 'source_url', 'raw_product_id', 'linked_product_id', 'issues', 'action'],
                $examples
            );
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $stats
     * @param  array<int, array<string, mixed>>  $examples
     */
    protected function inspectItem(
        PartCatalogItem $item,
        NikolaCarsProductInventorySyncService $syncService,
        bool $repair,
        bool $isActive,
        array &$stats,
        array &$examples,
        int $examplesLimit
    ): void {
        $stats['items_seen']++;
        if ($isActive) {
            $stats['active_items_seen']++;
        }

        $rawAttributes = PartCatalogRawAttributes::from($item);
        $rawProductId = (int) data_get($rawAttributes, 'product_id');
        $sourceUrlProductId = $this->sourceUrlProductId((string) $item->source_url);
        $linkedProduct = $this->linkedProductForItem($item, $rawProductId, $sourceUrlProductId);
        $issues = $this->issuesForItem($item, $linkedProduct, $rawProductId, $sourceUrlProductId, $isActive, $syncService, $stats);

        if ($issues === []) {
            $stats['ok']++;

            return;
        }

        $stats['problem_items']++;
        $action = $this->plannedAction($item, $linkedProduct, $syncService);

        if ($action === 'conflict') {
            $stats['skipped_conflict']++;
        } elseif ($repair && $action === 'repair_link') {
            $repaired = $this->repairItemLink($item, $linkedProduct, $syncService);
            $stats[$repaired ? 'repaired' : 'skipped_conflict']++;
            $action = $repaired ? 'repaired' : 'conflict';
        } else {
            $stats['would_repair'] += $action === 'repair_link' ? 1 : 0;
        }

        if (count($examples) < $examplesLimit) {
            $examples[] = [
                'item_id' => $item->id,
                'part_number' => $item->part_number,
                'source_url' => $item->source_url,
                'raw_product_id' => $rawProductId ?: null,
                'linked_product_id' => $linkedProduct?->id,
                'issues' => implode(', ', $issues),
                'action' => $action,
            ];
        }
    }

    /**
     * @param  array<string, int>  $stats
     * @return array<int, string>
     */
    protected function issuesForItem(
        PartCatalogItem $item,
        ?Product $product,
        int $rawProductId,
        ?int $sourceUrlProductId,
        bool $isActive,
        NikolaCarsProductInventorySyncService $syncService,
        array &$stats
    ): array {
        $issues = [];

        if ($rawProductId <= 0) {
            $stats['raw_product_id_missing']++;
            $issues[] = 'raw_product_id_missing';

            if ($isActive) {
                $stats['raw_product_id_missing_on_active']++;
            }
        } elseif (! Product::query()->whereKey($rawProductId)->exists()) {
            $stats['raw_product_id_missing_or_broken']++;
            $issues[] = 'raw_product_id_missing_or_broken';
        }

        if ($sourceUrlProductId !== null && ! Product::query()->whereKey($sourceUrlProductId)->exists()) {
            $stats['source_url_product_missing_or_broken']++;
            $issues[] = 'source_url_product_missing_or_broken';
        }

        if (! $product instanceof Product) {
            $stats['missing_linked_product']++;
            $issues[] = 'missing_linked_product';

            if ($isActive) {
                $stats['active_missing_linked_product']++;
                $issues[] = 'active_missing_linked_product';
            }

            return array_values(array_unique($issues));
        }

        $expectedUrl = $this->expectedSourceUrl($product);

        if ($rawProductId > 0 && $rawProductId !== (int) $product->id) {
            $stats['raw_product_id_mismatch']++;
            $issues[] = 'raw_product_id_mismatch';
        }

        if ($sourceUrlProductId !== null && $sourceUrlProductId !== (int) $product->id) {
            $stats['source_url_product_id_mismatch']++;
            $issues[] = 'source_url_product_id_mismatch';
        }

        if ((string) $item->source_url !== $expectedUrl) {
            $stats['noncanonical_source_url']++;
            $issues[] = 'noncanonical_source_url';
        }

        if ((int) $product->source_part_catalog_item_id !== (int) $item->id) {
            $stats['product_source_link_mismatch']++;
            $issues[] = 'product_source_link_mismatch';
        }

        $productPartNumber = trim((string) $product->external_sku);
        if ($productPartNumber !== '' && trim((string) $item->part_number) !== $productPartNumber) {
            $stats['part_number_mismatch']++;
            $issues[] = 'part_number_mismatch';
        }

        if (! $syncService->shouldMirrorProduct($product)) {
            $stats['linked_product_not_mirrorable']++;
            $issues[] = 'linked_product_not_mirrorable';
        }

        if ($isActive && ! $syncService->isSellableProduct($product)) {
            $stats['active_linked_product_not_sellable']++;
            $issues[] = 'active_linked_product_not_sellable';
        }

        $target = $this->targetItem($expectedUrl);
        if ($target instanceof PartCatalogItem && (int) $target->id !== (int) $item->id && ! $this->itemCanBelongToProduct($target, $product)) {
            $stats['target_conflict']++;
            $issues[] = 'target_conflict';
        }

        return array_values(array_unique($issues));
    }

    protected function plannedAction(
        PartCatalogItem $item,
        ?Product $product,
        NikolaCarsProductInventorySyncService $syncService
    ): string {
        if (! $product instanceof Product) {
            return 'needs_product_sync';
        }

        if (! $syncService->shouldMirrorProduct($product)) {
            return 'conflict';
        }

        $expectedUrl = $this->expectedSourceUrl($product);
        $target = $this->targetItem($expectedUrl);

        if ($target instanceof PartCatalogItem && (int) $target->id !== (int) $item->id) {
            return $this->itemCanBelongToProduct($target, $product) ? 'conflict' : 'conflict';
        }

        return $this->itemCanBelongToProduct($item, $product) ? 'repair_link' : 'conflict';
    }

    protected function repairItemLink(
        PartCatalogItem $item,
        Product $product,
        NikolaCarsProductInventorySyncService $syncService
    ): bool {
        return DB::transaction(function () use ($item, $product, $syncService): bool {
            /** @var PartCatalogItem|null $lockedItem */
            $lockedItem = PartCatalogItem::query()
                ->where('source', NikolaCarsInventoryService::SOURCE)
                ->lockForUpdate()
                ->find($item->id);

            /** @var Product|null $lockedProduct */
            $lockedProduct = Product::query()
                ->with(['donorCar', 'sourcePartCatalogItem'])
                ->lockForUpdate()
                ->find($product->id);

            if (! $lockedItem instanceof PartCatalogItem
                || ! $lockedProduct instanceof Product
                || ! $syncService->shouldMirrorProduct($lockedProduct)
                || ! $this->itemCanBelongToProduct($lockedItem, $lockedProduct)) {
                return false;
            }

            $expectedUrl = $this->expectedSourceUrl($lockedProduct);
            $target = $this->targetItem($expectedUrl);
            if ($target instanceof PartCatalogItem && (int) $target->id !== (int) $lockedItem->id) {
                return false;
            }

            $rawAttributes = PartCatalogRawAttributes::from($lockedItem);
            $rawAttributes['product_id'] = $lockedProduct->id;

            $lockedItem->forceFill([
                'source_url' => $expectedUrl,
                'raw_attributes' => $rawAttributes,
            ])->save();

            if ((int) $lockedProduct->source_part_catalog_item_id !== (int) $lockedItem->id) {
                $lockedProduct->forceFill(['source_part_catalog_item_id' => $lockedItem->id])->saveQuietly();
            }

            $lockedProduct->setRelation('sourcePartCatalogItem', $lockedItem);
            $syncService->syncProduct($lockedProduct->refresh());

            return true;
        });
    }

    protected function linkedProductForItem(PartCatalogItem $item, int $rawProductId, ?int $sourceUrlProductId): ?Product
    {
        $product = $rawProductId > 0
            ? Product::query()->with(['donorCar', 'sourcePartCatalogItem'])->find($rawProductId)
            : null;

        if ($product instanceof Product) {
            return $product;
        }

        $product = $sourceUrlProductId !== null
            ? Product::query()->with(['donorCar', 'sourcePartCatalogItem'])->find($sourceUrlProductId)
            : null;

        if ($product instanceof Product) {
            return $product;
        }

        return Product::query()
            ->with(['donorCar', 'sourcePartCatalogItem'])
            ->where('source_part_catalog_item_id', $item->id)
            ->orderBy('id')
            ->first();
    }

    protected function targetItem(string $expectedUrl): ?PartCatalogItem
    {
        return PartCatalogItem::query()
            ->where('source', NikolaCarsInventoryService::SOURCE)
            ->where('source_url', $expectedUrl)
            ->first();
    }

    protected function itemCanBelongToProduct(PartCatalogItem $item, Product $product): bool
    {
        $rawProductId = (int) data_get(PartCatalogRawAttributes::from($item), 'product_id');
        if ($rawProductId > 0 && $rawProductId !== (int) $product->id) {
            return false;
        }

        return ! Product::query()
            ->where('source_part_catalog_item_id', $item->id)
            ->whereKeyNot($product->id)
            ->exists();
    }

    protected function sourceUrlProductId(string $sourceUrl): ?int
    {
        return preg_match('~^nikolacars://(?:donor-product|inventory-product)/(\d+)$~', $sourceUrl, $matches) === 1
            ? (int) $matches[1]
            : null;
    }

    protected function expectedSourceUrl(Product $product): string
    {
        return $product->donor_car_id !== null
            ? 'nikolacars://donor-product/'.$product->id
            : 'nikolacars://inventory-product/'.$product->id;
    }
}
