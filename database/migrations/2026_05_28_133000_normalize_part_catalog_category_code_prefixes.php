<?php

use App\Models\PartCatalogCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('part_catalog_categories')) {
            return;
        }

        $nameColumns = collect(['name', 'name_en', 'name_ru', 'name_ua'])
            ->filter(fn (string $column): bool => Schema::hasColumn('part_catalog_categories', $column))
            ->values()
            ->all();

        if ($nameColumns === [] || ! Schema::hasColumn('part_catalog_categories', 'code')) {
            return;
        }

        $hasUpdatedAt = Schema::hasColumn('part_catalog_categories', 'updated_at');

        DB::table('part_catalog_categories')
            ->select(array_merge(['id', 'code'], $nameColumns))
            ->whereNotNull('code')
            ->orderBy('id')
            ->chunkById(500, function ($categories) use ($nameColumns, $hasUpdatedAt): void {
                foreach ($categories as $category) {
                    $updates = [];

                    foreach ($nameColumns as $column) {
                        $current = $category->{$column};
                        $normalized = PartCatalogCategory::stripRepeatedCodePrefix(
                            is_string($current) ? $current : null,
                            $category->code,
                        );

                        if ($normalized !== $current) {
                            $updates[$column] = $normalized;
                        }
                    }

                    if ($updates === []) {
                        continue;
                    }

                    if ($hasUpdatedAt) {
                        $updates['updated_at'] = now();
                    }

                    DB::table('part_catalog_categories')
                        ->where('id', $category->id)
                        ->update($updates);
                }
            });
    }

    public function down(): void
    {
        // The original duplicated prefixes cannot be restored reliably.
    }
};
