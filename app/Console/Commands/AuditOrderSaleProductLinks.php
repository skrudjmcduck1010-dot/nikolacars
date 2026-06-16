<?php

namespace App\Console\Commands;

use App\Models\CustomerOrderItem;
use App\Models\DonorCar;
use App\Models\PartCatalogItem;
use App\Models\PartSale;
use App\Models\Product;
use App\Services\NikolaCarsInventoryService;
use App\Services\NikolaCarsProductInventorySyncService;
use App\Support\PartCatalogRawAttributes;
use App\Support\PartNumberNormalizer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuditOrderSaleProductLinks extends Command
{
    protected $signature = 'parts:audit-order-sale-product-links
        {--table=all : Scope to inspect: all, orders, or sales}
        {--limit=0 : Maximum rows per table to inspect}
        {--examples=10 : Problem examples to show}
        {--repair : Fill missing/broken product_id when the product is unambiguous}
        {--create-missing-sale-products : With --repair, create sold products for unresolved NikolaCars sales when donor and part number are unambiguous}
        {--dry-run : Force read-only mode, even with --repair}';

    protected $description = 'Audit customer order items and NikolaCars sales for product-first links.';

    public function handle(): int
    {
        $scope = (string) $this->option('table');
        if (! in_array($scope, ['all', 'orders', 'sales'], true)) {
            $this->error('Invalid --table value. Use all, orders, or sales.');

            return self::FAILURE;
        }

        $repair = (bool) $this->option('repair') && ! (bool) $this->option('dry-run');
        $allowCreateMissingSaleProducts = (bool) $this->option('create-missing-sale-products');
        $limit = max(0, (int) $this->option('limit'));
        $examplesLimit = max(0, (int) $this->option('examples'));
        $stats = [
            'order_items_seen' => 0,
            'order_items_ok' => 0,
            'order_items_problem' => 0,
            'order_items_missing_product_id' => 0,
            'order_items_broken_product_id' => 0,
            'order_items_unresolved' => 0,
            'order_items_product_mismatch' => 0,
            'order_items_catalog_mismatch' => 0,
            'order_items_would_repair' => 0,
            'order_items_repaired' => 0,
            'order_items_conflict' => 0,
            'sales_seen' => 0,
            'sales_ok' => 0,
            'sales_problem' => 0,
            'sales_missing_product_id' => 0,
            'sales_broken_product_id' => 0,
            'sales_unresolved' => 0,
            'sales_product_mismatch' => 0,
            'sales_catalog_mismatch' => 0,
            'sales_raw_product_id_mismatch' => 0,
            'sales_would_repair' => 0,
            'sales_repaired' => 0,
            'sales_would_create_product' => 0,
            'sales_created_product' => 0,
            'sales_conflict' => 0,
        ];
        $examples = [];

        if ($scope !== 'sales') {
            $this->inspectOrderItems($repair, $limit, $examplesLimit, $stats, $examples);
        }

        if ($scope !== 'orders') {
            $this->inspectSales($repair, $allowCreateMissingSaleProducts, $limit, $examplesLimit, $stats, $examples);
        }

        $this->info(($repair ? 'Repaired' : 'Scanned').' order/sale product links.');
        $this->table(
            ['metric', 'count'],
            collect($stats)->map(fn (int $count, string $metric): array => [$metric, $count])->values()->all()
        );

        if ($examples !== []) {
            $this->newLine();
            $this->warn('Examples');
            $this->table(
                ['table', 'row_id', 'part_catalog_item_id', 'current_product_id', 'candidate_product_id', 'issues', 'action'],
                $examples
            );
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $stats
     * @param  array<int, array<string, mixed>>  $examples
     */
    protected function inspectOrderItems(bool $repair, int $limit, int $examplesLimit, array &$stats, array &$examples): void
    {
        $query = CustomerOrderItem::query()
            ->with(['partCatalogItem', 'product'])
            ->orderBy('id');

        $inspect = function (CustomerOrderItem $item) use ($repair, $examplesLimit, &$stats, &$examples): void {
            $resolution = $this->resolutionForOrderItem($item);
            $this->inspectRow($item, 'order_items', 'customer_order_items', $resolution, $repair, false, $stats, $examples, $examplesLimit);
        };

        if ($limit > 0) {
            $query->limit($limit)->get()->each($inspect);
        } else {
            $query->chunkById(300, fn ($items) => $items->each($inspect));
        }
    }

    /**
     * @param  array<string, int>  $stats
     * @param  array<int, array<string, mixed>>  $examples
     */
    protected function inspectSales(
        bool $repair,
        bool $allowCreateMissingSaleProducts,
        int $limit,
        int $examplesLimit,
        array &$stats,
        array &$examples
    ): void
    {
        $query = PartSale::query()
            ->with(['donorCar', 'partCatalogItem', 'product'])
            ->where(function (Builder $query): void {
                $query
                    ->where('source', NikolaCarsInventoryService::SOURCE)
                    ->orWhereHas('partCatalogItem', fn (Builder $itemQuery) => $itemQuery->where('source', NikolaCarsInventoryService::SOURCE));
            })
            ->orderBy('id');

        $inspect = function (PartSale $sale) use ($repair, $allowCreateMissingSaleProducts, $examplesLimit, &$stats, &$examples): void {
            $resolution = $this->resolutionForSale($sale);
            $this->inspectRow($sale, 'sales', 'part_sales', $resolution, $repair, $allowCreateMissingSaleProducts, $stats, $examples, $examplesLimit);
        };

        if ($limit > 0) {
            $query->limit($limit)->get()->each($inspect);
        } else {
            $query->chunkById(300, fn ($sales) => $sales->each($inspect));
        }
    }

    /**
     * @param  array<string, mixed>  $resolution
     * @param  array<string, int>  $stats
     * @param  array<int, array<string, mixed>>  $examples
     */
    protected function inspectRow(
        Model $row,
        string $prefix,
        string $table,
        array $resolution,
        bool $repair,
        bool $allowCreateMissingSaleProducts,
        array &$stats,
        array &$examples,
        int $examplesLimit
    ): void {
        $stats[$prefix.'_seen']++;
        $issues = $resolution['issues'];

        foreach ($issues as $issue) {
            $key = $prefix.'_'.$issue;
            if (array_key_exists($key, $stats)) {
                $stats[$key]++;
            }
        }

        if ($issues === []) {
            $stats[$prefix.'_ok']++;

            return;
        }

        $stats[$prefix.'_problem']++;
        $candidateProductId = $resolution['candidate_product_id'];
        $action = $this->plannedAction($resolution, $allowCreateMissingSaleProducts);

        if ($action === 'repair_product_id' && $repair) {
            $this->repairProductId($row, (int) $candidateProductId);
            $stats[$prefix.'_repaired']++;
            $action = 'repaired';
        } elseif ($action === 'create_missing_sale_product' && $repair && $row instanceof PartSale) {
            $product = $this->createMissingSaleProduct($row, $resolution['missing_sale_product'] ?? []);
            $candidateProductId = $product->id;
            $stats[$prefix.'_created_product']++;
            $action = 'created_product';
        } elseif ($action === 'repair_product_id') {
            $stats[$prefix.'_would_repair']++;
        } elseif ($action === 'create_missing_sale_product') {
            $stats[$prefix.'_would_create_product']++;
        } elseif ($action === 'conflict') {
            $stats[$prefix.'_conflict']++;
        }

        if (count($examples) < $examplesLimit) {
            $examples[] = [
                'table' => $table,
                'row_id' => $row->id,
                'part_catalog_item_id' => $row->part_catalog_item_id,
                'current_product_id' => $row->product_id,
                'candidate_product_id' => $candidateProductId,
                'issues' => implode(', ', $issues),
                'action' => $action,
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolutionForOrderItem(CustomerOrderItem $item): array
    {
        return $this->resolutionForRow(
            currentProductId: (int) $item->product_id,
            catalogItem: $item->partCatalogItem,
            sourceUrl: (string) $item->source_url,
            rawProductId: null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolutionForSale(PartSale $sale): array
    {
        $rawProductId = (int) data_get(PartCatalogRawAttributes::fromValue($sale->raw_attributes), 'product_id');
        $resolution = $this->resolutionForRow(
            currentProductId: (int) $sale->product_id,
            catalogItem: $sale->partCatalogItem,
            sourceUrl: '',
            rawProductId: $rawProductId > 0 ? $rawProductId : null,
        );

        if ($rawProductId > 0 && (int) $sale->product_id > 0 && $rawProductId !== (int) $sale->product_id) {
            $resolution['issues'][] = 'raw_product_id_mismatch';
            $resolution['issues'] = array_values(array_unique($resolution['issues']));
        }

        $resolution['missing_sale_product'] = $this->missingSaleProductPayload($sale, $resolution);

        return $resolution;
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolutionForRow(
        int $currentProductId,
        ?PartCatalogItem $catalogItem,
        string $sourceUrl,
        ?int $rawProductId
    ): array {
        $issues = [];
        $currentProduct = $currentProductId > 0 ? Product::query()->find($currentProductId) : null;
        $candidateIds = collect();

        if ($rawProductId !== null) {
            $candidateIds->push($rawProductId);
        }

        $sourceUrlProductId = $this->productIdFromSourceUrl($sourceUrl);
        if ($sourceUrlProductId !== null) {
            $candidateIds->push($sourceUrlProductId);
        }

        $catalogProductId = $catalogItem instanceof PartCatalogItem
            ? $this->productIdForCatalogItem($catalogItem)
            : null;
        if ($catalogProductId !== null) {
            $candidateIds->push($catalogProductId);
        }

        $candidateIds = $candidateIds
            ->filter(fn (int $productId): bool => Product::query()->whereKey($productId)->exists())
            ->unique()
            ->values();
        $candidateProductId = $candidateIds->count() === 1 ? (int) $candidateIds->first() : null;

        if ($currentProductId <= 0) {
            $issues[] = 'missing_product_id';
        } elseif (! $currentProduct instanceof Product) {
            $issues[] = 'broken_product_id';
        }

        if ($candidateIds->count() > 1) {
            $issues[] = 'product_mismatch';
        } elseif ($currentProduct instanceof Product && $candidateProductId !== null && $candidateProductId !== (int) $currentProduct->id) {
            $issues[] = 'product_mismatch';
        }

        $effectiveProduct = $currentProduct instanceof Product
            ? $currentProduct
            : ($candidateProductId !== null ? Product::query()->find($candidateProductId) : null);

        if ($catalogItem instanceof PartCatalogItem && $effectiveProduct instanceof Product && ! $this->catalogItemBelongsToProduct($catalogItem, $effectiveProduct)) {
            $issues[] = 'catalog_mismatch';
        }

        if (($currentProductId <= 0 || ! $currentProduct instanceof Product) && $candidateProductId === null) {
            $issues[] = 'unresolved';
        }

        return [
            'issues' => array_values(array_unique($issues)),
            'candidate_product_id' => $candidateProductId,
        ];
    }

    /**
     * @param  array<string, mixed>  $resolution
     */
    protected function plannedAction(array $resolution, bool $allowCreateMissingSaleProducts = false): string
    {
        $issues = $resolution['issues'];

        if (in_array('product_mismatch', $issues, true)
            || in_array('catalog_mismatch', $issues, true)
            || in_array('raw_product_id_mismatch', $issues, true)) {
            return 'conflict';
        }

        if ((in_array('missing_product_id', $issues, true) || in_array('broken_product_id', $issues, true))
            && $resolution['candidate_product_id'] !== null) {
            return 'repair_product_id';
        }

        if ($allowCreateMissingSaleProducts
            && (in_array('missing_product_id', $issues, true) || in_array('broken_product_id', $issues, true))
            && in_array('unresolved', $issues, true)
            && is_array($resolution['missing_sale_product'] ?? null)
            && $resolution['missing_sale_product'] !== []) {
            return 'create_missing_sale_product';
        }

        return 'unresolved';
    }

    protected function repairProductId(Model $row, int $productId): void
    {
        $row->forceFill(['product_id' => $productId])->save();
    }

    protected function productIdFromSourceUrl(string $sourceUrl): ?int
    {
        if (preg_match('~(?:^|/)admin/products/(\d+)(?:$|[/?#])~', (string) (parse_url($sourceUrl, PHP_URL_PATH) ?: $sourceUrl), $matches) !== 1) {
            return null;
        }

        $id = (int) $matches[1];

        return Product::query()->whereKey($id)->exists() ? $id : null;
    }

    protected function productIdForCatalogItem(PartCatalogItem $catalogItem): ?int
    {
        $product = Product::query()
            ->where('source_part_catalog_item_id', $catalogItem->id)
            ->orderBy('id')
            ->first(['id']);

        if ($product instanceof Product) {
            return (int) $product->id;
        }

        $productId = (int) data_get(PartCatalogRawAttributes::from($catalogItem), 'product_id');

        return $productId > 0 && Product::query()->whereKey($productId)->exists()
            ? $productId
            : null;
    }

    protected function catalogItemBelongsToProduct(PartCatalogItem $catalogItem, Product $product): bool
    {
        if ((int) $product->source_part_catalog_item_id === (int) $catalogItem->id) {
            return true;
        }

        $rawAttributes = PartCatalogRawAttributes::from($catalogItem);
        if ((int) data_get($rawAttributes, 'product_id') === (int) $product->id) {
            return true;
        }

        return preg_match(
            '~^nikolacars://(?:donor-product|inventory-product)/'.preg_quote((string) $product->id, '~').'$~',
            (string) $catalogItem->source_url,
        ) === 1;
    }

    /**
     * @param  array<string, mixed>  $resolution
     * @return array<string, mixed>
     */
    protected function missingSaleProductPayload(PartSale $sale, array $resolution): array
    {
        if ($resolution['candidate_product_id'] !== null) {
            return [];
        }

        if ((int) $sale->product_id > 0 && Product::query()->whereKey((int) $sale->product_id)->exists()) {
            return [];
        }

        $issues = $resolution['issues'] ?? [];
        if (! in_array('unresolved', $issues, true)) {
            return [];
        }

        $partNumber = $this->normalizedPartNumber((string) $sale->part_number);
        if ($partNumber === '') {
            return [];
        }

        $donorCar = $this->donorCarForSale($sale);
        if (! $donorCar instanceof DonorCar) {
            return [];
        }

        if ($this->donorProductExists($donorCar, $partNumber)) {
            return [];
        }

        $sku = $this->uniqueProductValue('sku', $this->saleProductSkuBase($sale));
        $slug = $this->uniqueProductValue('slug', Str::slug($sku) ?: 'nikolacars-sale-'.$sale->id);

        return [
            'donor_car_id' => $donorCar->id,
            'donor_vin' => $donorCar->vin,
            'part_number' => $partNumber,
            'sku' => $sku,
            'slug' => $slug,
            'source_part_catalog_item_id' => $this->officialSourceItemForPartNumber($partNumber)?->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function createMissingSaleProduct(PartSale $sale, array $payload): Product
    {
        return DB::transaction(function () use ($sale, $payload): Product {
            $sale->refresh();
            $donorCar = DonorCar::query()->findOrFail((int) $payload['donor_car_id']);
            $partNumber = (string) $payload['part_number'];
            $sku = (string) $payload['sku'];
            $name = trim((string) ($sale->name ?: $partNumber ?: $sku));
            $sourcePartCatalogItemId = (int) ($payload['source_part_catalog_item_id'] ?? 0) ?: null;

            $product = Product::query()->create([
                'sku' => $sku,
                'external_sku' => $partNumber,
                'name' => $name,
                'slug' => (string) $payload['slug'],
                'category_id' => null,
                'brand_id' => null,
                'donor_car_id' => $donorCar->id,
                'part_origin' => Product::PART_ORIGIN_ORIGINAL,
                'source_part_catalog_item_id' => $sourcePartCatalogItemId,
                'is_auto_generated' => false,
                'storage_status' => Product::STORAGE_STATUS_SOLD,
                'generated_at' => null,
                'description' => $this->legacySaleDescription($sale),
                'compatibility' => $donorCar->vin,
                'model' => $donorCar->model,
                'color' => $donorCar->color,
                'condition_type' => 'used',
                'testing_status' => 'tested',
                'unit' => 'pcs',
                'purchase_price' => 0,
                'selling_price' => $sale->unit_price !== null ? round((float) $sale->unit_price, 2) : 0,
                'currency' => $sale->currency ?: 'USD',
                'barcode' => $this->uniqueNullableProductValue('barcode', $sku),
                'qr_code' => $this->uniqueNullableProductValue('qr_code', $sku),
                'notes' => NikolaCarsProductInventorySyncService::CHECKED_DAMAGE_STATUSES[0],
                'is_active' => false,
            ]);

            $syncResult = app(NikolaCarsProductInventorySyncService::class)->syncProduct($product->refresh());
            $catalogItem = $syncResult['item'] ?? null;
            $rawAttributes = PartCatalogRawAttributes::fromValue($sale->raw_attributes);
            $rawAttributes['product_id'] = $product->id;

            if ($catalogItem instanceof PartCatalogItem) {
                $rawAttributes['part_catalog_item_id'] = $catalogItem->id;
            }

            $sale->forceFill([
                'product_id' => $product->id,
                'part_catalog_item_id' => $catalogItem instanceof PartCatalogItem ? $catalogItem->id : $sale->part_catalog_item_id,
                'donor_car_id' => $donorCar->id,
                'donor_vin' => $sale->donor_vin ?: $donorCar->vin,
                'raw_attributes' => $rawAttributes,
            ])->save();

            return $product->refresh();
        });
    }

    protected function donorCarForSale(PartSale $sale): ?DonorCar
    {
        if ($sale->donorCar instanceof DonorCar) {
            return $sale->donorCar;
        }

        if ((int) $sale->donor_car_id > 0) {
            $donorCar = DonorCar::query()->find((int) $sale->donor_car_id);
            if ($donorCar instanceof DonorCar) {
                return $donorCar;
            }
        }

        $vin = Str::upper(trim((string) ($sale->donor_vin ?: data_get($sale->raw_attributes, 'donor_vin'))));
        if ($vin === '') {
            return null;
        }

        return DonorCar::query()
            ->whereRaw('upper(vin) = ?', [$vin])
            ->first();
    }

    protected function donorProductExists(DonorCar $donorCar, string $partNumber): bool
    {
        $compactPartNumber = Str::upper(str_replace('-', '', trim($partNumber)));

        return Product::query()
            ->where('donor_car_id', $donorCar->id)
            ->where(function (Builder $query) use ($partNumber, $compactPartNumber): void {
                $query
                    ->where('external_sku', $partNumber)
                    ->orWhereRaw("replace(upper(external_sku), '-', '') = ?", [$compactPartNumber]);
            })
            ->exists();
    }

    protected function officialSourceItemForPartNumber(string $partNumber): ?PartCatalogItem
    {
        return PartCatalogItem::query()
            ->where('source', 'tesla_official')
            ->where('part_number', $partNumber)
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('source_url')
                    ->orWhere('source_url', 'not like', '%vin=%');
            })
            ->orderByRaw('CASE WHEN source_url LIKE ? THEN 0 ELSE 1 END', ['https://parts.tesla.com/%'])
            ->orderBy('id')
            ->first();
    }

    protected function saleProductSkuBase(PartSale $sale): string
    {
        $code = trim((string) $sale->code);
        $code = preg_replace('/^NC-/i', '', $code) ?: $code;

        return $code !== '' ? 'NC-'.$code : 'NC-SALE-'.$sale->id;
    }

    protected function normalizedPartNumber(string $partNumber): string
    {
        return trim(PartNumberNormalizer::normalize($partNumber));
    }

    protected function legacySaleDescription(PartSale $sale): ?string
    {
        $parts = collect([
            'Created from legacy NikolaCars sale.',
            $sale->source_file ? 'Source file: '.$sale->source_file : null,
            $sale->source_row_number ? 'Source row: '.$sale->source_row_number : null,
            $sale->document_number ? 'Document: '.$sale->document_number : null,
        ])->filter()->implode(' ');

        return $parts !== '' ? $parts : null;
    }

    protected function uniqueProductValue(string $column, string $base): string
    {
        $base = trim($base) !== '' ? trim($base) : 'nikolacars-sale';
        $value = Str::limit($base, 255, '');
        $counter = 2;

        while (Product::query()->where($column, $value)->exists()) {
            $suffix = '-'.$counter;
            $value = Str::limit($base, 255 - strlen($suffix), '').$suffix;
            $counter++;
        }

        return $value;
    }

    protected function uniqueNullableProductValue(string $column, ?string $base): ?string
    {
        $base = trim((string) $base);
        if ($base === '') {
            return null;
        }

        return $this->uniqueProductValue($column, $base);
    }
}
