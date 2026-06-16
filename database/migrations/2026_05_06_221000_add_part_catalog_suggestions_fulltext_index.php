<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || $this->indexExists()) {
            return;
        }

        Schema::table('part_catalog_items', function (Blueprint $table): void {
            $table->fullText(
                ['name', 'name_en', 'name_ru', 'name_ua', 'part_number'],
                'part_catalog_items_suggestions_fulltext'
            );
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! $this->indexExists()) {
            return;
        }

        Schema::table('part_catalog_items', function (Blueprint $table): void {
            $table->dropFullText('part_catalog_items_suggestions_fulltext');
        });
    }

    private function indexExists(): bool
    {
        return DB::selectOne(
            "SHOW INDEX FROM part_catalog_items WHERE Key_name = 'part_catalog_items_suggestions_fulltext'"
        ) !== null;
    }
};
