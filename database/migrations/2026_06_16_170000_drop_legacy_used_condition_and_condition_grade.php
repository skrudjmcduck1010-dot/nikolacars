<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('products', 'condition_grade')) {
            DB::table('products')
                ->select(['id', 'description', 'condition_grade'])
                ->whereNotNull('condition_grade')
                ->whereRaw("TRIM(condition_grade) <> ''")
                ->orderBy('id')
                ->lazyById()
                ->each(function (object $product): void {
                    $gradeLine = 'Грейд состояния: '.trim((string) $product->condition_grade);
                    $description = trim((string) ($product->description ?? ''));

                    if (str_contains($description, $gradeLine)) {
                        return;
                    }

                    DB::table('products')
                        ->where('id', $product->id)
                        ->update([
                            'description' => $description !== ''
                                ? $description.PHP_EOL.PHP_EOL.$gradeLine
                                : $gradeLine,
                        ]);
                });
        }

        if (Schema::hasColumn('stock_items', 'condition_grade')) {
            DB::table('stock_items')
                ->selectRaw('product_id, location_id, testing_status, COUNT(*) as duplicates')
                ->groupBy('product_id', 'location_id', 'testing_status')
                ->havingRaw('COUNT(*) > 1')
                ->orderBy('product_id')
                ->get()
                ->each(function (object $group): void {
                    $items = DB::table('stock_items')
                        ->where('product_id', $group->product_id)
                        ->where('location_id', $group->location_id)
                        ->where('testing_status', $group->testing_status)
                        ->orderBy('id')
                        ->get();

                    $keeper = $items->first();
                    $quantity = (int) $items->sum('quantity');
                    $reservedQuantity = (int) $items->sum('reserved_quantity');

                    DB::table('stock_items')
                        ->where('id', $keeper->id)
                        ->update([
                            'quantity' => $quantity,
                            'reserved_quantity' => $reservedQuantity,
                            'available_quantity' => max(0, $quantity - $reservedQuantity),
                            'updated_at' => now(),
                        ]);

                    DB::table('stock_items')
                        ->whereIn('id', $items->skip(1)->pluck('id')->all())
                        ->delete();
                });

            Schema::table('stock_items', function (Blueprint $table): void {
                if (! $this->hasIndex('stock_items', 'stock_items_product_id_index')) {
                    $table->index('product_id', 'stock_items_product_id_index');
                }

                if (! $this->hasIndex('stock_items', 'stock_items_location_id_index')) {
                    $table->index('location_id', 'stock_items_location_id_index');
                }
            });

            Schema::table('stock_items', function (Blueprint $table): void {
                if ($this->hasForeignKey('stock_items', 'stock_items_product_id_foreign')) {
                    $table->dropForeign('stock_items_product_id_foreign');
                }

                if ($this->hasForeignKey('stock_items', 'stock_items_location_id_foreign')) {
                    $table->dropForeign('stock_items_location_id_foreign');
                }
            });

            Schema::table('stock_items', function (Blueprint $table): void {
                $table->dropUnique('stock_items_unique_slot');
            });

            Schema::table('stock_items', function (Blueprint $table): void {
                $table->dropColumn('condition_grade');
                $table->unique(['product_id', 'location_id', 'testing_status'], 'stock_items_unique_slot');

                if (! $this->hasForeignKey('stock_items', 'stock_items_product_id_foreign')) {
                    $table->foreign('product_id', 'stock_items_product_id_foreign')
                        ->references('id')
                        ->on('products')
                        ->cascadeOnDelete();
                }

                if (! $this->hasForeignKey('stock_items', 'stock_items_location_id_foreign')) {
                    $table->foreign('location_id', 'stock_items_location_id_foreign')
                        ->references('id')
                        ->on('locations')
                        ->cascadeOnDelete();
                }
            });
        }

        Schema::table('products', function (Blueprint $table): void {
            if (Schema::hasColumn('products', 'used_condition')) {
                $table->dropColumn('used_condition');
            }

            if (Schema::hasColumn('products', 'condition_grade')) {
                $table->dropColumn('condition_grade');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            if (! Schema::hasColumn('products', 'used_condition')) {
                $table->string('used_condition')->nullable()->after('condition_type');
            }

            if (! Schema::hasColumn('products', 'condition_grade')) {
                $table->string('condition_grade')->default('A')->after('used_condition');
            }
        });

        Schema::table('stock_items', function (Blueprint $table): void {
            if ($this->hasForeignKey('stock_items', 'stock_items_product_id_foreign')) {
                $table->dropForeign('stock_items_product_id_foreign');
            }

            if ($this->hasForeignKey('stock_items', 'stock_items_location_id_foreign')) {
                $table->dropForeign('stock_items_location_id_foreign');
            }

            $table->dropUnique('stock_items_unique_slot');
        });

        Schema::table('stock_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('stock_items', 'condition_grade')) {
                $table->string('condition_grade')->default('A')->after('available_quantity');
            }

            $table->unique(['product_id', 'location_id', 'condition_grade', 'testing_status'], 'stock_items_unique_slot');

            if (! $this->hasForeignKey('stock_items', 'stock_items_product_id_foreign')) {
                $table->foreign('product_id', 'stock_items_product_id_foreign')
                    ->references('id')
                    ->on('products')
                    ->cascadeOnDelete();
            }

            if (! $this->hasForeignKey('stock_items', 'stock_items_location_id_foreign')) {
                $table->foreign('location_id', 'stock_items_location_id_foreign')
                    ->references('id')
                    ->on('locations')
                    ->cascadeOnDelete();
            }
        });
    }

    private function hasIndex(string $table, string $index): bool
    {
        $schema = Schema::getConnection()->getSchemaBuilder();

        if (method_exists($schema, 'getIndexes')) {
            return collect($schema->getIndexes($table))
                ->contains(fn (array $schemaIndex): bool => ($schemaIndex['name'] ?? null) === $index);
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            $quotedTable = str_replace("'", "''", $table);

            return collect(DB::select("PRAGMA index_list('{$quotedTable}')"))
                ->contains(fn (object $schemaIndex): bool => ($schemaIndex->name ?? null) === $index);
        }

        return collect(DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index]))->isNotEmpty();
    }

    private function hasForeignKey(string $table, string $constraint): bool
    {
        $schema = Schema::getConnection()->getSchemaBuilder();

        if (method_exists($schema, 'getForeignKeys')) {
            return collect($schema->getForeignKeys($table))
                ->contains(fn (array $schemaForeignKey): bool => ($schemaForeignKey['name'] ?? null) === $constraint);
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            return false;
        }

        return collect(DB::select(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$table, $constraint]
        ))->isNotEmpty();
    }
};
