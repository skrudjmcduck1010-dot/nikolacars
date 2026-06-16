<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        if (! $this->columnExists('name_ru_blank')) {
            DB::statement(
                "alter table part_catalog_items
                    add column name_ru_blank tinyint(1)
                        generated always as (if(coalesce(name_ru, '') = '', 1, 0)) stored"
            );
        }

        if (! $this->columnExists('name_ua_blank')) {
            DB::statement(
                "alter table part_catalog_items
                    add column name_ua_blank tinyint(1)
                        generated always as (if(coalesce(name_ua, '') = '', 1, 0)) stored"
            );
        }

        if (! $this->indexExists('pci_missing_names_filter_idx')) {
            DB::statement(
                'create index pci_missing_names_filter_idx
                    on part_catalog_items (source, name_ru_blank, name_ua_blank, model_label, name(120), part_number(80))'
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        if ($this->indexExists('pci_missing_names_filter_idx')) {
            DB::statement('drop index pci_missing_names_filter_idx on part_catalog_items');
        }

        foreach (['name_ua_blank', 'name_ru_blank'] as $column) {
            if ($this->columnExists($column)) {
                DB::statement("alter table part_catalog_items drop column {$column}");
            }
        }
    }

    private function columnExists(string $column): bool
    {
        return Schema::hasColumn('part_catalog_items', $column);
    }

    private function indexExists(string $index): bool
    {
        return DB::selectOne('SHOW INDEX FROM part_catalog_items WHERE Key_name = ?', [$index]) !== null;
    }
};
