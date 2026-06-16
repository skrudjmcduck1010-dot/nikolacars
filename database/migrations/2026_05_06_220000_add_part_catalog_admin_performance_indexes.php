<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('part_catalog_categories', function (Blueprint $table): void {
            $table->index(['source', 'parent_id', 'depth', 'model_label'], 'pcc_admin_source_parent_depth_model_idx');
        });

        Schema::table('part_catalog_items', function (Blueprint $table): void {
            $table->index(['source', 'part_catalog_category_id'], 'pci_admin_source_category_idx');
            $table->index(['source', 'model_label', 'part_catalog_category_id'], 'pci_admin_source_model_category_idx');
            $table->index(['source', 'price_amount'], 'pci_admin_source_price_idx');
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('create index pci_admin_catalog_page_sort_idx on part_catalog_items (source, model_label, name(120), part_number(80))');
        } else {
            Schema::table('part_catalog_items', function (Blueprint $table): void {
                $table->index(['source', 'model_label', 'name', 'part_number'], 'pci_admin_catalog_page_sort_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::table('part_catalog_items', function (Blueprint $table): void {
            $table->dropIndex('pci_admin_catalog_page_sort_idx');
            $table->dropIndex('pci_admin_source_price_idx');
            $table->dropIndex('pci_admin_source_model_category_idx');
            $table->dropIndex('pci_admin_source_category_idx');
        });

        Schema::table('part_catalog_categories', function (Blueprint $table): void {
            $table->dropIndex('pcc_admin_source_parent_depth_model_idx');
        });
    }
};
