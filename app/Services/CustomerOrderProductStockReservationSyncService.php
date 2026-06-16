<?php

namespace App\Services;

use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Movement;
use App\Models\PartCatalogItem;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\StockItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CustomerOrderProductStockReservationSyncService
{
    private const MARKER_PREFIX = 'customer-order:';

    public function syncProduct(Product $product): bool
    {
        return DB::transaction(function () use ($product): bool {
            $product->loadMissing('sourcePartCatalogItem');

            $targetReservations = $this->targetReservations($product);
            $currentReservations = Reservation::query()
                ->with('stockItem.product')
                ->where('product_id', $product->id)
                ->where('status', 'active')
                ->where('customer_order_id', 'like', self::MARKER_PREFIX.'%')
                ->get()
                ->groupBy(fn (Reservation $reservation): string => (string) $reservation->customer_order_id);

            $changed = false;
            $markers = $targetReservations
                ->keys()
                ->merge($currentReservations->keys())
                ->unique()
                ->values();

            foreach ($markers as $marker) {
                $target = $targetReservations->get($marker, [
                    'quantity' => 0,
                    'comment' => null,
                ]);
                $targetQuantity = (int) $target['quantity'];
                $current = $currentReservations->get($marker, collect());
                $currentQuantity = (int) $current->sum('quantity');

                if ($targetQuantity > $currentQuantity) {
                    $this->reserveDelta($product, $targetQuantity - $currentQuantity, (string) $marker, (string) ($target['comment'] ?? ''));
                    $changed = true;
                }

                if ($targetQuantity < $currentQuantity) {
                    $this->releaseDelta($current, $currentQuantity - $targetQuantity);
                    $changed = true;
                }
            }

            return $changed;
        });
    }

    protected function targetReservations(Product $product): Collection
    {
        $catalogItem = $product->sourcePartCatalogItem?->source === NikolaCarsInventoryService::SOURCE
            ? $product->sourcePartCatalogItem
            : null;

        return CustomerOrderItem::query()
            ->with('order:id,number,status,delivery_method')
            ->where(function (Builder $query) use ($product, $catalogItem): void {
                $query->where('product_id', $product->id);

                if ($catalogItem instanceof PartCatalogItem) {
                    $query->orWhere('part_catalog_item_id', $catalogItem->id);
                }
            })
            ->whereHas('order', fn (Builder $query) => $query->reservable())
            ->get()
            ->filter(fn (CustomerOrderItem $item): bool => $item->order instanceof CustomerOrder)
            ->groupBy('customer_order_id')
            ->map(function (Collection $items): array {
                /** @var CustomerOrderItem $first */
                $first = $items->first();
                $quantity = max(0, (int) ceil(round((float) $items->sum(fn (CustomerOrderItem $item): float => (float) $item->quantity), 3)));

                return [
                    'quantity' => $quantity,
                    'comment' => 'Customer order '.$first->order?->number,
                ];
            })
            ->filter(fn (array $reservation): bool => (int) $reservation['quantity'] > 0)
            ->mapWithKeys(fn (array $reservation, int|string $orderId): array => [
                self::MARKER_PREFIX.$orderId => $reservation,
            ]);
    }

    protected function reserveDelta(Product $product, int $quantity, string $marker, string $comment): void
    {
        $remaining = $quantity;
        $stockItems = StockItem::query()
            ->where('product_id', $product->id)
            ->where('available_quantity', '>', 0)
            ->orderByDesc('available_quantity')
            ->orderBy('id')
            ->get();

        foreach ($stockItems as $stockItem) {
            if ($remaining <= 0) {
                break;
            }

            $reserveQuantity = min($remaining, (int) $stockItem->available_quantity);

            if ($reserveQuantity <= 0) {
                continue;
            }

            app(StockService::class)->reserve($stockItem, [
                'product_id' => $product->id,
                'quantity' => $reserveQuantity,
                'customer_order_id' => $marker,
                'comment' => $comment,
            ]);

            $remaining -= $reserveQuantity;
        }

        if ($remaining > 0) {
            throw new RuntimeException('Not enough product stock to reserve customer order quantity.');
        }
    }

    protected function releaseDelta(Collection $reservations, int $quantity): void
    {
        $remaining = $quantity;

        $reservations
            ->sortByDesc('id')
            ->each(function (Reservation $reservation) use (&$remaining): void {
                if ($remaining <= 0 || $reservation->quantity <= 0) {
                    return;
                }

                $releaseQuantity = min($remaining, (int) $reservation->quantity);
                $stockItem = StockItem::query()->lockForUpdate()->find($reservation->stock_item_id);

                if (! $stockItem instanceof StockItem) {
                    return;
                }

                $reservationQuantity = (int) $reservation->quantity;
                if ($releaseQuantity >= $reservationQuantity) {
                    $reservation->forceFill([
                        'quantity' => $reservationQuantity,
                        'status' => 'released',
                    ])->save();
                } else {
                    $reservation->forceFill([
                        'quantity' => $reservationQuantity - $releaseQuantity,
                    ])->save();
                }

                $stockItem->reserved_quantity = max(0, (int) $stockItem->reserved_quantity - $releaseQuantity);
                $stockItem->syncAvailableQuantity();
                $stockItem->save();

                $this->logUnreserveMovement($stockItem, $releaseQuantity, (string) $reservation->customer_order_id, (string) $reservation->comment);
                $remaining -= $releaseQuantity;
            });
    }

    protected function logUnreserveMovement(StockItem $stockItem, int $quantity, string $marker, string $comment): void
    {
        $product = $stockItem->product;

        if (! $product instanceof Product) {
            return;
        }

        Movement::query()->create([
            'product_id' => $product->id,
            'stock_item_id' => $stockItem->id,
            'to_location_id' => $stockItem->location_id,
            'user_id' => Auth::id(),
            'type' => 'unreserve',
            'quantity' => $quantity,
            'document_number' => $marker,
            'comment' => $comment,
            'created_at' => now()->utc(),
        ]);
    }
}
