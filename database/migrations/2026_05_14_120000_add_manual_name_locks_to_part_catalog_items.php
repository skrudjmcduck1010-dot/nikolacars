<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! $this->hasColumn('name_ru_manually_locked_at')) {
            Schema::table('part_catalog_items', function (Blueprint $table) {
                $table->timestamp('name_ru_manually_locked_at')->nullable()->after('name_ua');
            });
        }

        // UA locks are stored in raw_attributes to avoid another table rebuild on large MySQL tables.
    }

    public function down(): void
    {
        foreach (['name_ru_manually_locked_at'] as $column) {
            if ($this->hasColumn($column)) {
                Schema::table('part_catalog_items', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }

    private function hasColumn(string $column): bool
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            return DB::table('information_schema.columns')
                ->where('table_schema', DB::getDatabaseName())
                ->where('table_name', 'part_catalog_items')
                ->where('column_name', $column)
                ->exists();
        }

        return Schema::hasColumn('part_catalog_items', $column);
    }
};
