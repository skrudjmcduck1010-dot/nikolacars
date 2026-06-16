<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('part_catalog_items', function (Blueprint $table): void {
            $table->index(['source', 'name'], 'part_catalog_items_source_name_index');
            $table->index(['source', 'name_en'], 'part_catalog_items_source_name_en_index');
            $table->index(['source', 'name_ru'], 'part_catalog_items_source_name_ru_index');
            $table->index(['source', 'name_ua'], 'part_catalog_items_source_name_ua_index');

            if (DB::connection()->getDriverName() === 'mysql') {
                $table->fullText(
                    ['name', 'name_en', 'name_ru', 'name_ua', 'part_number'],
                    'part_catalog_items_suggestions_fulltext'
                );
            }
        });
    }

    public function down(): void
    {
        Schema::table('part_catalog_items', function (Blueprint $table): void {
            $table->dropIndex('part_catalog_items_source_name_index');
            $table->dropIndex('part_catalog_items_source_name_en_index');
            $table->dropIndex('part_catalog_items_source_name_ru_index');
            $table->dropIndex('part_catalog_items_source_name_ua_index');

            if (DB::connection()->getDriverName() === 'mysql') {
                $table->dropFullText('part_catalog_items_suggestions_fulltext');
            }
        });
    }
};
