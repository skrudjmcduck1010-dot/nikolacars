<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || $this->columnExists()) {
            return;
        }

        DB::statement(
            "alter table part_catalog_items
                add column part_number_compact varchar(191)
                    generated always as (replace(replace(upper(part_number), '-', ''), ' ', '')) stored"
        );

        DB::statement(
            'create index part_catalog_items_source_part_number_compact_index
                on part_catalog_items (source, part_number_compact)'
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! $this->columnExists()) {
            return;
        }

        DB::statement('drop index part_catalog_items_source_part_number_compact_index on part_catalog_items');
        DB::statement('alter table part_catalog_items drop column part_number_compact');
    }

    private function columnExists(): bool
    {
        return DB::selectOne("SHOW COLUMNS FROM part_catalog_items LIKE 'part_number_compact'") !== null;
    }
};
