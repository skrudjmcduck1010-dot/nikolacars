<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('part_catalog_categories', function (Blueprint $table) {
            if (! Schema::hasColumn('part_catalog_categories', 'products_scanned_at')) {
                $table->timestamp('products_scanned_at')->nullable()->after('children_scanned_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('part_catalog_categories', function (Blueprint $table) {
            if (Schema::hasColumn('part_catalog_categories', 'products_scanned_at')) {
                $table->dropColumn('products_scanned_at');
            }
        });
    }
};
