<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'source_part_catalog_item_id')) {
                $table->unsignedBigInteger('source_part_catalog_item_id')
                    ->nullable()
                    ->after('donor_car_id')
                    ->index();
            }

            if (! Schema::hasColumn('products', 'is_auto_generated')) {
                $table->boolean('is_auto_generated')->default(false)->after('source_part_catalog_item_id');
            }

            if (! Schema::hasColumn('products', 'storage_status')) {
                $table->string('storage_status')->default('in_stock')->after('is_auto_generated')->index();
            }

            if (! Schema::hasColumn('products', 'generated_at')) {
                $table->timestamp('generated_at')->nullable()->after('storage_status');
            }
        });

        Schema::table('products', function (Blueprint $table) {
            $table->unique(['donor_car_id', 'source_part_catalog_item_id'], 'products_donor_catalog_item_unique');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('products_donor_catalog_item_unique');
        });

        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'source_part_catalog_item_id')) {
                $table->dropIndex(['source_part_catalog_item_id']);
                $table->dropColumn('source_part_catalog_item_id');
            }

            if (Schema::hasColumn('products', 'generated_at')) {
                $table->dropColumn('generated_at');
            }

            if (Schema::hasColumn('products', 'storage_status')) {
                $table->dropColumn('storage_status');
            }

            if (Schema::hasColumn('products', 'is_auto_generated')) {
                $table->dropColumn('is_auto_generated');
            }
        });
    }
};
