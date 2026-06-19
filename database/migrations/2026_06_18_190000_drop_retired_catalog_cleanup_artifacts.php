<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const RETIRED_TABLES = [
        'backup_part_catalog_categories_20260515_model_rollback',
        'backup_part_catalog_items_20260515_model_rollback',
        'part_catalog_nikolacars_category_preview_backup_20260507',
        'part_catalog_nikolacars_category_ru_name_backup_20260507',
        'part_catalog_nikolacars_raw_attributes_backup_20260507',
        'part_catalog_nikolacars_ru_name_backup_20260507',
        'part_catalog_self_translation_backup_20260507',
        'part_catalog_self_translation_refresh_ru_backup_20260514',
        'part_catalog_translation_backup_20260507',
        'part_catalog_trimmed_part_backup_20260507',
        'part_catalog_ua_translation_fix_backup_20260514',
        'part_catalog_uk_translation_fix_backup_20260514',
        'translation_contaminated_words',
        'translation_dictionary_entries',
        'translation_name_pairs',
    ];

    public function up(): void
    {
        if (Schema::hasTable('donor_cars') && Schema::hasColumn('donor_cars', 'last_official_catalog_downloaded_at')) {
            Schema::table('donor_cars', function (Blueprint $table): void {
                $table->dropColumn('last_official_catalog_downloaded_at');
            });
        }

        Schema::disableForeignKeyConstraints();

        try {
            foreach (self::RETIRED_TABLES as $table) {
                Schema::dropIfExists($table);
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('donor_cars') && ! Schema::hasColumn('donor_cars', 'last_official_catalog_downloaded_at')) {
            Schema::table('donor_cars', function (Blueprint $table): void {
                $table->timestamp('last_official_catalog_downloaded_at')->nullable();
            });
        }

        // Retired backup/translation tables contained one-off repair artifacts.
        // Their dropped data is intentionally not recreated on rollback.
    }
};
