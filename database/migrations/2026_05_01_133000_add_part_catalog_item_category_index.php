<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('part_catalog_items', function (Blueprint $table) {
            $table->index('part_catalog_category_id', 'part_catalog_items_category_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('part_catalog_items', function (Blueprint $table) {
            $table->dropIndex('part_catalog_items_category_id_index');
        });
    }
};
