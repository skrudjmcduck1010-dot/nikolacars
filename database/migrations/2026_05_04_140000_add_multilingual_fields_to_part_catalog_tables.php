<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('part_catalog_categories', function (Blueprint $table) {
            if (! Schema::hasColumn('part_catalog_categories', 'name_en')) {
                $table->string('name_en')->nullable()->after('name');
            }

            if (! Schema::hasColumn('part_catalog_categories', 'name_ru')) {
                $table->string('name_ru')->nullable()->after('name_en');
            }

            if (! Schema::hasColumn('part_catalog_categories', 'name_ua')) {
                $table->string('name_ua')->nullable()->after('name_ru');
            }
        });

        Schema::table('part_catalog_items', function (Blueprint $table) {
            if (! Schema::hasColumn('part_catalog_items', 'name_en')) {
                $table->string('name_en')->nullable()->after('name');
            }

            if (! Schema::hasColumn('part_catalog_items', 'name_ru')) {
                $table->string('name_ru')->nullable()->after('name_en');
            }

            if (! Schema::hasColumn('part_catalog_items', 'name_ua')) {
                $table->string('name_ua')->nullable()->after('name_ru');
            }

            if (! Schema::hasColumn('part_catalog_items', 'notes_en')) {
                $table->text('notes_en')->nullable()->after('compatibility_text');
            }

            if (! Schema::hasColumn('part_catalog_items', 'notes_ru')) {
                $table->text('notes_ru')->nullable()->after('notes_en');
            }

            if (! Schema::hasColumn('part_catalog_items', 'notes_ua')) {
                $table->text('notes_ua')->nullable()->after('notes_ru');
            }
        });
    }

    public function down(): void
    {
        Schema::table('part_catalog_items', function (Blueprint $table) {
            foreach (['name_en', 'name_ru', 'name_ua', 'notes_en', 'notes_ru', 'notes_ua'] as $column) {
                if (Schema::hasColumn('part_catalog_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('part_catalog_categories', function (Blueprint $table) {
            foreach (['name_en', 'name_ru', 'name_ua'] as $column) {
                if (Schema::hasColumn('part_catalog_categories', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
