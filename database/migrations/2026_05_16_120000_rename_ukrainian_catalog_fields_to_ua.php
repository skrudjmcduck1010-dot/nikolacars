<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->renamePartCatalogItems();
        $this->renameColumnIfNeeded('part_catalog_categories', 'name_uk', 'name_ua');
        $this->renameBackupTables();
        $this->renameRawAttributeKeys();
    }

    public function down(): void
    {
        $this->renameColumnIfNeeded('part_catalog_categories', 'name_ua', 'name_uk');

        if (Schema::hasTable('part_catalog_items')) {
            if ($this->indexExists('part_catalog_items', 'part_catalog_items_source_name_ua_index')) {
                DB::statement('alter table part_catalog_items drop index part_catalog_items_source_name_ua_index');
            }

            if ($this->indexExists('part_catalog_items', 'pci_missing_names_filter_idx')) {
                DB::statement('alter table part_catalog_items drop index pci_missing_names_filter_idx');
            }

            if ($this->indexExists('part_catalog_items', 'part_catalog_items_suggestions_fulltext')) {
                DB::statement('alter table part_catalog_items drop index part_catalog_items_suggestions_fulltext');
            }

            if (Schema::hasColumn('part_catalog_items', 'name_ua_blank')) {
                DB::statement('alter table part_catalog_items drop column name_ua_blank');
            }

            $this->renameColumnIfNeeded('part_catalog_items', 'name_ua', 'name_uk');
            $this->renameColumnIfNeeded('part_catalog_items', 'notes_ua', 'notes_uk');

            if ($this->isMysql() && Schema::hasColumn('part_catalog_items', 'name_uk') && ! Schema::hasColumn('part_catalog_items', 'name_uk_blank')) {
                DB::statement("alter table part_catalog_items add column name_uk_blank tinyint(1) generated always as (if(coalesce(name_uk, '') = '', 1, 0)) stored after name_ru_blank");
            }

            if ($this->isMysql() && Schema::hasColumn('part_catalog_items', 'name_uk') && ! $this->indexExists('part_catalog_items', 'part_catalog_items_source_name_uk_index')) {
                DB::statement('alter table part_catalog_items add index part_catalog_items_source_name_uk_index (source, name_uk)');
            }

            if ($this->isMysql() && Schema::hasColumn('part_catalog_items', 'name_uk_blank') && ! $this->indexExists('part_catalog_items', 'pci_missing_names_filter_idx')) {
                DB::statement('alter table part_catalog_items add index pci_missing_names_filter_idx (source, name_ru_blank, name_uk_blank, model_label, name(120), part_number(80))');
            }

            if ($this->isMysql() && Schema::hasColumn('part_catalog_items', 'name_uk') && ! $this->indexExists('part_catalog_items', 'part_catalog_items_suggestions_fulltext')) {
                DB::statement('alter table part_catalog_items add fulltext index part_catalog_items_suggestions_fulltext (name, name_en, name_ru, name_uk, part_number)');
            }
        }

        if (Schema::hasTable('part_catalog_ua_translation_fix_backup_20260514')) {
            DB::statement('rename table part_catalog_ua_translation_fix_backup_20260514 to part_catalog_uk_translation_fix_backup_20260514');
        }
    }

    private function renamePartCatalogItems(): void
    {
        if (! Schema::hasTable('part_catalog_items')) {
            return;
        }

        if ($this->isMysql()) {
            foreach ([
                'part_catalog_items_source_name_uk_index',
                'pci_missing_names_filter_idx',
                'part_catalog_items_suggestions_fulltext',
            ] as $index) {
                if ($this->indexExists('part_catalog_items', $index)) {
                    DB::statement("alter table part_catalog_items drop index {$index}");
                }
            }

            if (Schema::hasColumn('part_catalog_items', 'name_uk_blank')) {
                DB::statement('alter table part_catalog_items drop column name_uk_blank');
            }
        }

        $this->renameColumnIfNeeded('part_catalog_items', 'name_uk', 'name_ua');
        $this->renameColumnIfNeeded('part_catalog_items', 'notes_uk', 'notes_ua');

        if (! $this->isMysql()) {
            return;
        }

        if (Schema::hasColumn('part_catalog_items', 'name_ua') && ! Schema::hasColumn('part_catalog_items', 'name_ua_blank')) {
            DB::statement("alter table part_catalog_items add column name_ua_blank tinyint(1) generated always as (if(coalesce(name_ua, '') = '', 1, 0)) stored after name_ru_blank");
        }

        if (Schema::hasColumn('part_catalog_items', 'name_ua') && ! $this->indexExists('part_catalog_items', 'part_catalog_items_source_name_ua_index')) {
            DB::statement('alter table part_catalog_items add index part_catalog_items_source_name_ua_index (source, name_ua)');
        }

        if (Schema::hasColumn('part_catalog_items', 'name_ua_blank') && ! $this->indexExists('part_catalog_items', 'pci_missing_names_filter_idx')) {
            DB::statement('alter table part_catalog_items add index pci_missing_names_filter_idx (source, name_ru_blank, name_ua_blank, model_label, name(120), part_number(80))');
        }

        if (Schema::hasColumn('part_catalog_items', 'name_ua') && ! $this->indexExists('part_catalog_items', 'part_catalog_items_suggestions_fulltext')) {
            DB::statement('alter table part_catalog_items add fulltext index part_catalog_items_suggestions_fulltext (name, name_en, name_ru, name_ua, part_number)');
        }
    }

    private function renameBackupTables(): void
    {
        foreach ([
            'backup_part_catalog_categories_20260515_model_rollback',
            'backup_part_catalog_items_20260515_model_rollback',
            'part_catalog_nikolacars_ru_name_backup_20260507',
            'part_catalog_self_translation_backup_20260507',
            'part_catalog_self_translation_refresh_ru_backup_20260514',
            'part_catalog_translation_backup_20260507',
            'part_catalog_trimmed_part_backup_20260507',
            'part_catalog_ua_translation_fix_backup_20260514',
        ] as $table) {
            $this->renameColumnIfNeeded($table, 'name_uk', 'name_ua');
        }

        if (Schema::hasTable('part_catalog_uk_translation_fix_backup_20260514')) {
            DB::statement('rename table part_catalog_uk_translation_fix_backup_20260514 to part_catalog_ua_translation_fix_backup_20260514');
        }
    }

    private function renameRawAttributeKeys(): void
    {
        if (! $this->isMysql() || ! Schema::hasTable('part_catalog_items') || ! Schema::hasColumn('part_catalog_items', 'raw_attributes')) {
            return;
        }

        foreach ([
            'source_url',
            'name_source_url',
            'name_source_site',
            'name_source_item_id',
        ] as $key) {
            DB::statement(
                "update part_catalog_items
                    set raw_attributes = json_remove(json_set(raw_attributes, '$.{$key}_ua', json_extract(raw_attributes, '$.{$key}_uk')), '$.{$key}_uk')
                    where raw_attributes is not null and json_contains_path(raw_attributes, 'one', '$.{$key}_uk')"
            );
        }

        DB::statement(
            "update part_catalog_items
                set raw_attributes = json_remove(json_set(raw_attributes, '$.manual_name_locks.ua', json_extract(raw_attributes, '$.manual_name_locks.uk')), '$.manual_name_locks.uk')
                where raw_attributes is not null and json_contains_path(raw_attributes, 'one', '$.manual_name_locks.uk')"
        );
    }

    private function renameColumnIfNeeded(string $table, string $from, string $to): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $from) || Schema::hasColumn($table, $to)) {
            return;
        }

        DB::statement("alter table {$table} rename column {$from} to {$to}");
    }

    private function indexExists(string $table, string $index): bool
    {
        if (! $this->isMysql()) {
            return false;
        }

        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }

    private function isMysql(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }
};
