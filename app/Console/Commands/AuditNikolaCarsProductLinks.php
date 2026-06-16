<?php

namespace App\Console\Commands;

use App\Models\PartCatalogItem;
use App\Models\Product;
use App\Services\NikolaCarsProductInventorySyncService;
use App\Support\PartCatalogRawAttributes;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class AuditNikolaCarsProductLinks extends Command
{
    protected $signature = 'parts:audit-nikolacars-product-links
        {--product-id=* : Limit to one or more product IDs}
        {--limit=0 : Maximum products to inspect}
        {--examples=10 : Problem examples to show}
        {--repair : Repair safe stale links}
        {--dry-run : Force read-only mode, even with --repair}';

    protected $description = 'Audit and repair products whose primary NikolaCars catalog projection is stale.';

    public function handle(NikolaCarsProductInventorySyncService $syncService): int
    {
        $repair = (bool) $this->option('repair') && ! (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));
        $examplesLimit = max(0, (int) $this->option('examples'));
        $productIds = collect((array) $this->option('product-id'))
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        $stats = [
            'products_seen' => 0,
            'mirrorable_seen' => 0,
            'skipped_non_mirrorable' => 0,
            'ok' => 0,
            'problem_products' => 0,
            'would_repair' => 0,
            'repaired' => 0,
            'skipped_conflict' => 0,
            'missing_source_item' => 0,
            'non_nikolacars_source_item' => 0,
            'noncanonical_source_url' => 0,
            'raw_product_id_mismatch' => 0,
            'part_number_mismatch' => 0,
            'target_conflict' => 0,
        ];
        $examples = [];

        $query = Product::query()
            ->with(['donorCar', 'sourcePartCatalogItem'])
            ->when($productIds->isNotEmpty(), fn (Builder $query) => $query->whereIn('id', $productIds->all()))
            ->orderBy('id');

        $inspect = function (Product $product) use ($syncService, $repair, &$stats, &$examples, $examplesLimit): void {
            $this->inspectProduct($product, $syncService, $repair, $stats, $examples, $examplesLimit);
        };

        if ($limit > 0) {
            $query->limit($limit)->get()->each($inspect);
        } else {
            $query->chunkById(300, fn ($products) => $products->each($inspect));
        }

        $this->info(($repair ? 'Repaired' : 'Scanned').' NikolaCars product links.');
        $this->table(
            ['metric', 'count'],
            collect($stats)->map(fn (int $count, string $metric): array => [$metric, $count])->values()->all()
        );

        if ($examples !== []) {
            $this->newLine();
            $this->warn('Examples');
            $this->table(
                ['product_id', 'sku', 'expected_url', 'current_item_id', 'current_source', 'current_url', 'issues', 'action'],
                $examples
            );
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $stats
     * @param  array<int, array<string, mixed>>  $examples
     */
    protected function inspectProduct(
        Product $product,
        NikolaCarsProductInventorySyncService $syncService,
        bool $repair,
        array &$stats,
        array &$examples,
        int $examplesLimit,
    ): void {
        $stats['products_seen']++;
        $product->loadMissing(['donorCar', 'sourcePartCatalogItem']);

        if (! $syncService->shouldMirrorProduct($product)) {
            $stats['skipped_non_mirrorable']++;

            return;
        }

        $stats['mirrorable_seen']++;
        $issues = $this->issuesForProduct($product, $stats);

        if ($issues === []) {
            $stats['ok']++;

            return;
        }

        $stats['problem_products']++;
        $expectedUrl = $this->expectedSourceUrl($product);
        $currentItem = $product->sourcePartCatalogItem;
        $action = $this->plannedAction($product, $expectedUrl);

        if ($action === 'conflict') {
            $stats['skipped_conflict']++;
        } elseif ($repair) {
            $repaired = $this->repairProduct($product, $syncService, $expectedUrl);
            $stats[$repaired ? 'repaired' : 'skipped_conflict']++;
            $action = $repaired ? 'repaired' : 'conflict';
        } else {
            $stats['would_repair']++;
        }

        if (count($examples) < $examplesLimit) {
            $examples[] = [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'expected_url' => $expectedUrl,
                'current_item_id' => $currentItem?->id,
                'current_source' => $currentItem?->source,
                'current_url' => $currentItem?->source_url,
                'issues' => implode(', ', $issues),
                'action' => $action,
            ];
        }
    }

    /**
     * @param  array<string, int>  $stats
     * @return array<int, string>
     */
    protected function issuesForProduct(Product $product, array &$stats): array
    {
        $issues = [];
        $expectedUrl = $this->expectedSourceUrl($product);
        $item = $product->sourcePartCatalogItem;

        if (! $item instanceof PartCatalogItem) {
            $stats['missing_source_item']++;

            return ['missing_source_item'];
        }

        if ($item->source !== NikolaCarsProductInventorySyncService::SOURCE) {
            $stats['non_nikolacars_source_item']++;
            $issues[] = 'non_nikolacars_source_item';
        } else {
            $rawProductId = (int) data_get(PartCatalogRawAttributes::from($item), 'product_id');
            if ($rawProductId > 0 && $rawProductId !== (int) $product->id) {
                $stats['raw_product_id_mismatch']++;
                $issues[] = 'raw_product_id_mismatch';
            }

            if ((string) $item->source_url !== $expectedUrl) {
                $stats['noncanonical_source_url']++;
                $issues[] = 'noncanonical_source_url';
            }

            $productPartNumber = trim((string) $product->external_sku);
            if ($productPartNumber !== '' && trim((string) $item->part_number) !== $productPartNumber) {
                $stats['part_number_mismatch']++;
                $issues[] = 'part_number_mismatch';
            }
        }

        $target = $this->targetItem($expectedUrl);
        if ($target instanceof PartCatalogItem && ! $this->itemCanBelongToProduct($target, $product)) {
            $stats['target_conflict']++;
            $issues[] = 'target_conflict';
        }

        return array_values(array_unique($issues));
    }

    protected function repairProduct(
        Product $product,
        NikolaCarsProductInventorySyncService $syncService,
        string $expectedUrl,
    ): bool {
        return DB::transaction(function () use ($product, $syncService, $expectedUrl): bool {
            /** @var Product|null $lockedProduct */
            $lockedProduct = Product::query()
                ->with(['donorCar', 'sourcePartCatalogItem'])
                ->lockForUpdate()
                ->find($product->id);

            if (! $lockedProduct instanceof Product || ! $syncService->shouldMirrorProduct($lockedProduct)) {
                return false;
            }

            $target = $this->targetItem($expectedUrl);
            if ($target instanceof PartCatalogItem) {
                if (! $this->itemCanBelongToProduct($target, $lockedProduct)) {
                    return false;
                }

                $this->linkProduct($lockedProduct, $target);

                return (bool) $syncService->syncProduct($lockedProduct->refresh())['saved'];
            }

            $currentItem = $lockedProduct->sourcePartCatalogItem;
            if ($currentItem instanceof PartCatalogItem && $currentItem->source === NikolaCarsProductInventorySyncService::SOURCE) {
                if (! $this->canRehomeCurrentItem($currentItem, $lockedProduct)) {
                    return false;
                }

                $onlyRehomeUrl = $this->onlyNeedsSourceUrlRehome($lockedProduct, $currentItem, $expectedUrl);
                $rawAttributes = PartCatalogRawAttributes::from($currentItem);
                $rawAttributes['product_id'] = $lockedProduct->id;

                $currentItem->forceFill([
                    'source_url' => $expectedUrl,
                    'raw_attributes' => $rawAttributes,
                ])->save();
                $this->linkProduct($lockedProduct, $currentItem);

                if ($onlyRehomeUrl) {
                    return true;
                }
            }

            return (bool) $syncService->syncProduct($lockedProduct->refresh())['saved'];
        });
    }

    protected function plannedAction(Product $product, string $expectedUrl): string
    {
        $target = $this->targetItem($expectedUrl);
        if ($target instanceof PartCatalogItem) {
            return $this->itemCanBelongToProduct($target, $product) ? 'link_existing_projection' : 'conflict';
        }

        $currentItem = $product->sourcePartCatalogItem;
        if ($currentItem instanceof PartCatalogItem && $currentItem->source === NikolaCarsProductInventorySyncService::SOURCE) {
            return $this->canRehomeCurrentItem($currentItem, $product) ? 'rehome_current_projection' : 'conflict';
        }

        return 'create_projection';
    }

    protected function targetItem(string $expectedUrl): ?PartCatalogItem
    {
        return PartCatalogItem::query()
            ->where('source', NikolaCarsProductInventorySyncService::SOURCE)
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

    protected function canRehomeCurrentItem(PartCatalogItem $item, Product $product): bool
    {
        if (! $this->itemCanBelongToProduct($item, $product)) {
            return false;
        }

        $sourceUrlProductId = $this->sourceUrlProductId((string) $item->source_url);

        return $sourceUrlProductId === null || $sourceUrlProductId === (int) $product->id;
    }

    protected function onlyNeedsSourceUrlRehome(Product $product, PartCatalogItem $item, string $expectedUrl): bool
    {
        if ($item->source !== NikolaCarsProductInventorySyncService::SOURCE || (string) $item->source_url === $expectedUrl) {
            return false;
        }

        $rawProductId = (int) data_get(PartCatalogRawAttributes::from($item), 'product_id');
        if ($rawProductId > 0 && $rawProductId !== (int) $product->id) {
            return false;
        }

        $productPartNumber = trim((string) $product->external_sku);

        return $productPartNumber === '' || trim((string) $item->part_number) === $productPartNumber;
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

    protected function linkProduct(Product $product, PartCatalogItem $item): void
    {
        if ((int) $product->source_part_catalog_item_id !== (int) $item->id) {
            $product->forceFill(['source_part_catalog_item_id' => $item->id])->saveQuietly();
        }

        $product->setRelation('sourcePartCatalogItem', $item);
    }
}
