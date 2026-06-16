<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_order_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('customer_order_items', 'product_id')) {
                $table->foreignId('product_id')
                    ->nullable()
                    ->after('part_catalog_item_id')
                    ->constrained()
                    ->nullOnDelete();
            }
        });

        Schema::table('part_sales', function (Blueprint $table): void {
            if (! Schema::hasColumn('part_sales', 'product_id')) {
                $table->foreignId('product_id')
                    ->nullable()
                    ->after('part_catalog_item_id')
                    ->constrained()
                    ->nullOnDelete();
            }
        });

        $this->backfillCustomerOrderProducts();
        $this->backfillPartSaleProducts();
    }

    public function down(): void
    {
        Schema::table('part_sales', function (Blueprint $table): void {
            if (Schema::hasColumn('part_sales', 'product_id')) {
                $table->dropConstrainedForeignId('product_id');
            }
        });

        Schema::table('customer_order_items', function (Blueprint $table): void {
            if (Schema::hasColumn('customer_order_items', 'product_id')) {
                $table->dropConstrainedForeignId('product_id');
            }
        });
    }

    private function backfillCustomerOrderProducts(): void
    {
        DB::table('customer_order_items')
            ->whereNull('product_id')
            ->where(function ($query): void {
                $query
                    ->whereNotNull('part_catalog_item_id')
                    ->orWhereNotNull('source_url');
            })
            ->orderBy('id')
            ->select(['id', 'part_catalog_item_id', 'source_url'])
            ->chunkById(500, function ($items): void {
                foreach ($items as $item) {
                    $productId = $this->productIdFromSourceUrl((string) $item->source_url)
                        ?: $this->productIdForCatalogItem((int) $item->part_catalog_item_id);

                    if ($productId !== null) {
                        DB::table('customer_order_items')
                            ->where('id', $item->id)
                            ->update(['product_id' => $productId]);
                    }
                }
            });
    }

    private function backfillPartSaleProducts(): void
    {
        DB::table('part_sales')
            ->whereNull('product_id')
            ->whereNotNull('part_catalog_item_id')
            ->orderBy('id')
            ->select(['id', 'part_catalog_item_id', 'raw_attributes'])
            ->chunkById(500, function ($sales): void {
                foreach ($sales as $sale) {
                    $rawAttributes = json_decode((string) $sale->raw_attributes, true);
                    $productId = (int) data_get(is_array($rawAttributes) ? $rawAttributes : [], 'product_id');
                    $productId = $productId > 0
                        ? $productId
                        : $this->productIdForCatalogItem((int) $sale->part_catalog_item_id);

                    if ($productId > 0) {
                        DB::table('part_sales')
                            ->where('id', $sale->id)
                            ->update(['product_id' => $productId]);
                    }
                }
            });
    }

    private function productIdFromSourceUrl(string $sourceUrl): ?int
    {
        if (preg_match('~(?:^|/)admin/products/(\d+)(?:$|[/?#])~', (string) (parse_url($sourceUrl, PHP_URL_PATH) ?: $sourceUrl), $matches) !== 1) {
            return null;
        }

        $id = (int) $matches[1];

        return DB::table('products')->where('id', $id)->exists() ? $id : null;
    }

    private function productIdForCatalogItem(int $catalogItemId): ?int
    {
        if ($catalogItemId <= 0) {
            return null;
        }

        $productId = DB::table('products')
            ->where('source_part_catalog_item_id', $catalogItemId)
            ->orderBy('id')
            ->value('id');

        if ($productId !== null) {
            return (int) $productId;
        }

        $rawAttributes = DB::table('part_catalog_items')
            ->where('id', $catalogItemId)
            ->value('raw_attributes');
        $rawAttributes = json_decode((string) $rawAttributes, true);
        $productId = (int) data_get(is_array($rawAttributes) ? $rawAttributes : [], 'product_id');

        return $productId > 0 && DB::table('products')->where('id', $productId)->exists()
            ? $productId
            : null;
    }
};
