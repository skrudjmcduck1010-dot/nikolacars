<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('part_catalog_categories', function (Blueprint $table) {
            if (! Schema::hasColumn('part_catalog_categories', 'children_scanned_at')) {
                $table->timestamp('children_scanned_at')->nullable()->after('sort_order');
            }
        });
    }

    public function down(): void
    {
        Schema::table('part_catalog_categories', function (Blueprint $table) {
            if (Schema::hasColumn('part_catalog_categories', 'children_scanned_at')) {
                $table->dropColumn('children_scanned_at');
            }
        });
    }
};
