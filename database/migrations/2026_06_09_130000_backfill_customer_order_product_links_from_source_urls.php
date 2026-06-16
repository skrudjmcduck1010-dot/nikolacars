<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('customer_order_items', 'product_id')) {
            return;
        }

        DB::table('customer_order_items')
            ->whereNull('product_id')
            ->whereNotNull('source_url')
            ->orderBy('id')
            ->select(['id', 'source_url'])
            ->chunkById(500, function ($items): void {
                foreach ($items as $item) {
                    $productId = $this->productIdFromSourceUrl((string) $item->source_url);

                    if ($productId !== null) {
                        DB::table('customer_order_items')
                            ->where('id', $item->id)
                            ->update(['product_id' => $productId]);
                    }
                }
            });
    }

    public function down(): void
    {
        //
    }

    private function productIdFromSourceUrl(string $sourceUrl): ?int
    {
        if (preg_match('~(?:^|/)admin/products/(\d+)(?:$|[/?#])~', (string) (parse_url($sourceUrl, PHP_URL_PATH) ?: $sourceUrl), $matches) !== 1) {
            return null;
        }

        $id = (int) $matches[1];

        return DB::table('products')->where('id', $id)->exists() ? $id : null;
    }
};
