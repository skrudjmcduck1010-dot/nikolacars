<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockItem;
use Illuminate\Support\Facades\DB;

class SoldProductStockAdjustmentService
{
    public function zeroStock(Product $product, array $meta = []): void
    {
        DB::transaction(function () use ($product, $meta): void {
            $product->loadMissing('stockItems');

            $product->stockItems
                ->filter(fn (StockItem $stockItem): bool => (int) $stockItem->quantity > 0)
                ->each(function (StockItem $stockItem) use ($meta): void {
                    if ((int) $stockItem->reserved_quantity > 0) {
                        app(StockService::class)->unreserve($stockItem, (int) $stockItem->reserved_quantity, [
                            'document_number' => $meta['document_number'] ?? null,
                            'comment' => $meta['comment'] ?? 'Sold product stock reservation released.',
                        ]);
                        $stockItem->refresh();
                    }

                    app(StockService::class)->adjust($stockItem, 0, [
                        'reason' => $meta['reason'] ?? 'sold_product_stock_cleanup',
                        'document_number' => $meta['document_number'] ?? null,
                        'comment' => $meta['comment'] ?? 'Sold product stock corrected to zero.',
                    ]);
                });
        });
    }
}
