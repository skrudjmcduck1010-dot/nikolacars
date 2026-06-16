<?php

namespace App\Services;

use App\Models\Location;
use App\Models\Movement;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\StockItem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StockService
{
    public function intake(array $data): StockItem
    {
        return DB::transaction(function () use ($data): StockItem {
            $location = Location::query()->with('warehouse')->findOrFail($data['location_id']);
            $product = Product::query()->findOrFail($data['product_id']);

            if (! empty($data['warehouse_id']) && (int) $data['warehouse_id'] !== (int) $location->warehouse_id) {
                throw new InvalidArgumentException('Выбранная ячейка не относится к выбранному складу.');
            }

            $stockItem = StockItem::query()->firstOrNew([
                'product_id' => $product->id,
                'location_id' => $location->id,
                'testing_status' => $product->testing_status,
            ]);

            $stockItem->warehouse_id = $location->warehouse_id;
            $stockItem->quantity = (int) $stockItem->quantity + (int) $data['quantity'];
            $stockItem->reserved_quantity = (int) $stockItem->reserved_quantity;
            $stockItem->received_at = now();
            $stockItem->syncAvailableQuantity();
            $stockItem->save();

            $this->logMovement($product, $stockItem, [
                'type' => 'intake',
                'quantity' => $data['quantity'],
                'to_location_id' => $location->id,
                'counterparty_id' => $data['counterparty_id'] ?? null,
                'document_number' => $data['document_number'] ?? null,
                'comment' => $data['comment'] ?? null,
            ]);

            return $stockItem->fresh(['product', 'warehouse', 'location']);
        });
    }

    public function move(StockItem $stockItem, int $quantity, int $toLocationId, array $meta = []): array
    {
        return DB::transaction(function () use ($stockItem, $quantity, $toLocationId, $meta): array {
            $stockItem->refresh();
            $this->assertPositiveQuantity($quantity);
            $this->assertEnoughAvailable($stockItem, $quantity, 'Перемещать можно только доступный остаток.');

            $toLocation = Location::query()->findOrFail($toLocationId);
            $target = StockItem::query()->firstOrNew([
                'product_id' => $stockItem->product_id,
                'location_id' => $toLocation->id,
                'testing_status' => $stockItem->testing_status,
            ]);

            $target->warehouse_id = $toLocation->warehouse_id;
            $target->quantity = (int) $target->quantity + $quantity;
            $target->reserved_quantity = (int) $target->reserved_quantity;
            $target->received_at = $stockItem->received_at ?? now();
            $target->syncAvailableQuantity();
            $target->save();

            $stockItem->quantity -= $quantity;
            $stockItem->syncAvailableQuantity();
            $stockItem->save();

            $this->logMovement($stockItem->product, $stockItem, [
                'type' => 'move',
                'quantity' => $quantity,
                'from_location_id' => $stockItem->location_id,
                'to_location_id' => $toLocation->id,
                'document_number' => $meta['document_number'] ?? null,
                'comment' => $meta['comment'] ?? null,
            ]);

            return [$stockItem->fresh(), $target->fresh()];
        });
    }

    public function reserve(StockItem $stockItem, array $data): Reservation
    {
        return DB::transaction(function () use ($stockItem, $data): Reservation {
            $stockItem->refresh();
            $quantity = (int) $data['quantity'];

            if ((int) ($data['product_id'] ?? $stockItem->product_id) !== (int) $stockItem->product_id) {
                throw new InvalidArgumentException('Товар в резерве не совпадает с выбранным остатком.');
            }

            $this->assertPositiveQuantity($quantity);
            $this->assertEnoughAvailable($stockItem, $quantity, '   .');

            $stockItem->reserved_quantity += $quantity;
            $stockItem->syncAvailableQuantity();
            $stockItem->save();

            $reservation = Reservation::query()->create([
                'product_id' => $stockItem->product_id,
                'stock_item_id' => $stockItem->id,
                'customer_order_id' => $data['customer_order_id'] ?? null,
                'quantity' => $quantity,
                'status' => 'active',
                'expires_at' => $data['expires_at'] ?? null,
                'comment' => $data['comment'] ?? null,
            ]);

            $this->logMovement($stockItem->product, $stockItem, [
                'type' => 'reserve',
                'quantity' => $quantity,
                'to_location_id' => $stockItem->location_id,
                'comment' => $data['comment'] ?? null,
                'document_number' => $data['customer_order_id'] ?? null,
            ]);

            return $reservation->fresh(['product', 'stockItem.location']);
        });
    }

    public function unreserve(StockItem $stockItem, int $quantity, array $meta = []): void
    {
        DB::transaction(function () use ($stockItem, $quantity, $meta): void {
            $stockItem->refresh();
            $this->assertPositiveQuantity($quantity);

            if ($stockItem->reserved_quantity < $quantity) {
                throw new InvalidArgumentException('Нельзя снять резерв больше зарезервированного количества.');
            }

            $activeReservations = Reservation::query()
                ->where('stock_item_id', $stockItem->id)
                ->where('status', 'active')
                ->orderBy('id')
                ->get();

            $remaining = $quantity;

            foreach ($activeReservations as $reservation) {
                if ($remaining <= 0) {
                    break;
                }

                if ($reservation->quantity <= $remaining) {
                    $remaining -= $reservation->quantity;
                    $reservation->update([
                        'status' => 'released',
                        'comment' => $meta['comment'] ?? $reservation->comment,
                    ]);

                    continue;
                }

                $reservation->quantity -= $remaining;
                $reservation->save();
                $remaining = 0;
            }

            $stockItem->reserved_quantity -= $quantity;
            $stockItem->syncAvailableQuantity();
            $stockItem->save();

            $this->logMovement($stockItem->product, $stockItem, [
                'type' => 'unreserve',
                'quantity' => $quantity,
                'to_location_id' => $stockItem->location_id,
                'comment' => $meta['comment'] ?? null,
                'document_number' => $meta['document_number'] ?? null,
            ]);
        });
    }

    public function sale(StockItem $stockItem, int $quantity, array $meta = []): void
    {
        DB::transaction(function () use ($stockItem, $quantity, $meta): void {
            $stockItem->refresh();
            $this->assertPositiveQuantity($quantity);
            $this->assertEnoughAvailable($stockItem, $quantity, 'Продажа превышает доступный остаток.');

            $stockItem->quantity -= $quantity;
            $stockItem->syncAvailableQuantity();
            $stockItem->save();

            $this->logMovement($stockItem->product, $stockItem, [
                'type' => 'sale',
                'quantity' => $quantity,
                'from_location_id' => $stockItem->location_id,
                'counterparty_id' => $meta['counterparty_id'] ?? null,
                'document_number' => $meta['document_number'] ?? null,
                'comment' => $meta['comment'] ?? null,
            ]);
        });
    }

    public function writeoff(StockItem $stockItem, int $quantity, array $meta = []): void
    {
        DB::transaction(function () use ($stockItem, $quantity, $meta): void {
            $stockItem->refresh();
            $this->assertPositiveQuantity($quantity);
            $this->assertEnoughAvailable($stockItem, $quantity, 'Списание превышает доступный остаток.');

            $stockItem->quantity -= $quantity;
            $stockItem->syncAvailableQuantity();
            $stockItem->save();

            $this->logMovement($stockItem->product, $stockItem, [
                'type' => 'writeoff',
                'quantity' => $quantity,
                'from_location_id' => $stockItem->location_id,
                'reason' => $meta['reason'] ?? null,
                'document_number' => $meta['document_number'] ?? null,
                'comment' => $meta['comment'] ?? null,
            ]);
        });
    }

    public function adjust(StockItem $stockItem, int $targetQuantity, array $meta = []): void
    {
        DB::transaction(function () use ($stockItem, $targetQuantity, $meta): void {
            $stockItem->refresh();

            if ($targetQuantity < $stockItem->reserved_quantity) {
                throw new InvalidArgumentException('Итоговое количество не может быть меньше зарезервированного.');
            }

            $delta = $targetQuantity - $stockItem->quantity;

            if ($delta === 0) {
                return;
            }

            $stockItem->quantity = $targetQuantity;
            $stockItem->syncAvailableQuantity();
            $stockItem->save();

            $this->logMovement($stockItem->product, $stockItem, [
                'type' => 'adjustment',
                'quantity' => abs($delta),
                'from_location_id' => $delta < 0 ? $stockItem->location_id : null,
                'to_location_id' => $delta > 0 ? $stockItem->location_id : null,
                'reason' => $meta['reason'] ?? null,
                'document_number' => $meta['document_number'] ?? null,
                'comment' => trim(sprintf(
                    'Корректировка с %d до %d. %s',
                    $stockItem->getOriginal('quantity'),
                    $targetQuantity,
                    $meta['comment'] ?? ''
                )),
            ]);
        });
    }

    protected function assertPositiveQuantity(int $quantity): void
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Количество должно быть не меньше 1.');
        }
    }

    protected function assertEnoughAvailable(StockItem $stockItem, int $quantity, string $message): void
    {
        if ($stockItem->available_quantity < $quantity) {
            throw new InvalidArgumentException($message);
        }
    }

    protected function logMovement(Product $product, ?StockItem $stockItem, array $data): Movement
    {
        return Movement::query()->create([
            'product_id' => $product->id,
            'stock_item_id' => $stockItem?->id,
            'from_location_id' => $data['from_location_id'] ?? null,
            'to_location_id' => $data['to_location_id'] ?? null,
            'user_id' => Auth::id(),
            'counterparty_id' => $data['counterparty_id'] ?? null,
            'type' => $data['type'],
            'quantity' => $data['quantity'],
            'reason' => $data['reason'] ?? null,
            'document_number' => $data['document_number'] ?? null,
            'comment' => $data['comment'] ?? null,
            'created_at' => now()->utc(),
        ]);
    }
}
