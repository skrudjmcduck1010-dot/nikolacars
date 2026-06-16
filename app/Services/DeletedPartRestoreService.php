<?php

namespace App\Services;

use App\Models\DeletedPart;
use App\Models\PartCatalogItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class DeletedPartRestoreService
{
    protected array $columnsByTable = [];

    protected array $generatedColumnsByTable = [];

    public function restore(DeletedPart $deletedPart): void
    {
        try {
            DB::transaction(function () use ($deletedPart): void {
                $catalogItem = $this->restoreCatalogItem($deletedPart->part_catalog_item_snapshot);
                $products = collect();
                $relatedProductSnapshots = collect($deletedPart->related_product_snapshots ?: [])
                    ->filter(fn (mixed $snapshot): bool => is_array($snapshot) && $snapshot !== []);

                if ($relatedProductSnapshots->isNotEmpty()) {
                    $products = $relatedProductSnapshots
                        ->map(fn (array $snapshot): Product => $this->restoreProduct($snapshot, $catalogItem));
                } elseif (is_array($deletedPart->product_snapshot) && $deletedPart->product_snapshot !== []) {
                    $products = collect([
                        $this->restoreProduct($deletedPart->product_snapshot, $catalogItem),
                    ]);
                }

                if ($catalogItem === null && $products->isEmpty()) {
                    throw ValidationException::withMessages([
                        'restore' => 'Нет сохраненного снимка для восстановления.',
                    ]);
                }

                if ($products->isNotEmpty() && $catalogItem?->source !== NikolaCarsProductInventorySyncService::SOURCE) {
                    $products->each(fn (Product $product): array => app(NikolaCarsProductInventorySyncService::class)->syncProduct($product->refresh()));
                }

                $deletedPart->delete();
            });
        } catch (QueryException $exception) {
            throw ValidationException::withMessages([
                'restore' => 'Не удалось восстановить запчасть: часть данных уже занята или больше недоступна.',
            ]);
        }
    }

    protected function restoreCatalogItem(?array $snapshot): ?PartCatalogItem
    {
        if (! is_array($snapshot) || $snapshot === []) {
            return null;
        }

        $originalId = $this->positiveInt($snapshot['id'] ?? null);
        if ($originalId !== null && ($existing = PartCatalogItem::query()->find($originalId)) !== null) {
            return $existing;
        }

        $this->ensureUnique(PartCatalogItem::class, 'source_url', $snapshot['source_url'] ?? null, $originalId, 'ссылка каталога');

        $attributes = $this->modelAttributes(new PartCatalogItem, $snapshot);
        $this->nullMissingForeign($attributes, 'part_catalog_category_id', 'part_catalog_categories');

        return PartCatalogItem::unguarded(fn (): PartCatalogItem => PartCatalogItem::query()->create($attributes));
    }

    protected function restoreProduct(array $snapshot, ?PartCatalogItem $catalogItem): Product
    {
        $originalId = $this->positiveInt($snapshot['id'] ?? null);
        if ($originalId !== null && ($existing = Product::query()->find($originalId)) !== null) {
            return $existing;
        }

        foreach (['sku' => 'код', 'slug' => 'slug', 'barcode' => 'штрихкод', 'qr_code' => 'QR-код'] as $column => $label) {
            $this->ensureUnique(Product::class, $column, $snapshot[$column] ?? null, $originalId, $label);
        }

        $attributes = $this->modelAttributes(new Product, $snapshot);
        $this->nullMissingForeign($attributes, 'category_id', 'categories');
        $this->nullMissingForeign($attributes, 'brand_id', 'brands');
        $this->nullMissingForeign($attributes, 'donor_car_id', 'donor_cars');
        $this->nullMissingForeign($attributes, 'created_by', 'users');
        $this->nullMissingForeign($attributes, 'updated_by', 'users');

        $sourceItemId = $this->positiveInt($attributes['source_part_catalog_item_id'] ?? null);
        if ($sourceItemId !== null && ! $this->idExists('part_catalog_items', $sourceItemId)) {
            $attributes['source_part_catalog_item_id'] = $catalogItem?->id;
        } elseif ($sourceItemId === null && $catalogItem?->source === NikolaCarsProductInventorySyncService::SOURCE) {
            $attributes['source_part_catalog_item_id'] = $catalogItem->id;
        }

        $this->ensureUniqueProductSourceLink($attributes, $originalId);

        return Product::unguarded(fn (): Product => Product::query()->create($attributes));
    }

    protected function modelAttributes(Model $model, array $snapshot): array
    {
        $table = $model->getTable();
        $columns = array_flip(array_diff($this->columnsFor($table), $this->generatedColumnsFor($table)));

        return collect($snapshot)
            ->filter(fn (mixed $value, string $key): bool => isset($columns[$key]))
            ->map(fn (mixed $value): mixed => $value instanceof \ArrayObject ? $value->getArrayCopy() : $value)
            ->all();
    }

    protected function ensureUnique(string $modelClass, string $column, mixed $value, ?int $originalId, string $label): void
    {
        if ($value === null || $value === '') {
            return;
        }

        /** @var class-string<Model> $modelClass */
        $query = $modelClass::query()->where($column, $value);

        if ($originalId !== null) {
            $query->whereKeyNot($originalId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'restore' => "Нельзя восстановить запчасть: {$label} уже используется.",
            ]);
        }
    }

    protected function ensureUniqueProductSourceLink(array $attributes, ?int $originalId): void
    {
        $donorCarId = $this->positiveInt($attributes['donor_car_id'] ?? null);
        $sourceItemId = $this->positiveInt($attributes['source_part_catalog_item_id'] ?? null);

        if ($donorCarId === null || $sourceItemId === null) {
            return;
        }

        $query = Product::query()
            ->where('donor_car_id', $donorCarId)
            ->where('source_part_catalog_item_id', $sourceItemId);

        if ($originalId !== null) {
            $query->whereKeyNot($originalId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'restore' => 'Нельзя восстановить запчасть: у этого донора уже есть запчасть, связанная с той же строкой каталога.',
            ]);
        }
    }

    protected function nullMissingForeign(array &$attributes, string $column, string $table): void
    {
        $id = $this->positiveInt($attributes[$column] ?? null);

        if ($id !== null && ! $this->idExists($table, $id)) {
            $attributes[$column] = null;
        }
    }

    protected function idExists(string $table, int $id): bool
    {
        return DB::table($table)->where('id', $id)->exists();
    }

    protected function columnsFor(string $table): array
    {
        return $this->columnsByTable[$table] ??= Schema::getColumnListing($table);
    }

    protected function generatedColumnsFor(string $table): array
    {
        if (array_key_exists($table, $this->generatedColumnsByTable)) {
            return $this->generatedColumnsByTable[$table];
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            return $this->generatedColumnsByTable[$table] = collect(DB::select(
                "select column_name
                 from information_schema.columns
                 where table_schema = database()
                   and table_name = ?
                   and extra like '%GENERATED%'",
                [$table]
            ))
                ->map(fn (object $column): string => (string) ($column->column_name ?? $column->COLUMN_NAME ?? ''))
                ->filter()
                ->values()
                ->all();
        }

        if ($driver === 'sqlite') {
            $quotedTable = str_replace("'", "''", $table);

            return $this->generatedColumnsByTable[$table] = collect(DB::select("pragma table_xinfo('{$quotedTable}')"))
                ->filter(fn (object $column): bool => (int) ($column->hidden ?? 0) > 1)
                ->map(fn (object $column): string => (string) $column->name)
                ->filter()
                ->values()
                ->all();
        }

        return $this->generatedColumnsByTable[$table] = [];
    }

    protected function positiveInt(mixed $value): ?int
    {
        $id = (int) $value;

        return $id > 0 ? $id : null;
    }
}
