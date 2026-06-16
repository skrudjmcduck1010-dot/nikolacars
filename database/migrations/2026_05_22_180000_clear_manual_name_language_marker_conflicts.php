<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('part_catalog_items')) {
            return;
        }

        foreach (['ru', 'ua'] as $locale) {
            $this->clearLocaleConflict($locale);
        }
    }

    public function down(): void
    {
        //
    }

    private function clearLocaleConflict(string $locale): void
    {
        $driver = DB::connection()->getDriverName();
        $lockColumn = 'name_'.$locale.'_manually_locked_at';
        $conflictKey = 'name_language_marker_conflict_'.$locale;
        $hasLockColumn = Schema::hasColumn('part_catalog_items', $lockColumn);
        $lockSql = $hasLockColumn
            ? "{$lockColumn} is not null or {$this->manualLockSql($driver, $locale)}"
            : $this->manualLockSql($driver, $locale);

        if ($driver === 'pgsql') {
            DB::statement(
                "update part_catalog_items
                    set raw_attributes = (raw_attributes::jsonb - ?)::json
                    where raw_attributes is not null
                        and raw_attributes::jsonb ? '{$conflictKey}'
                        and ({$lockSql})",
                [$conflictKey]
            );

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement(
                "update part_catalog_items
                    set raw_attributes = json_remove(raw_attributes, ?)
                    where raw_attributes is not null
                        and json_valid(raw_attributes)
                        and json_type(raw_attributes, ?) is not null
                        and ({$lockSql})",
                [
                    '$.name_language_marker_conflict_'.$locale,
                    '$.name_language_marker_conflict_'.$locale,
                ]
            );

            return;
        }

        DB::statement(
            "update part_catalog_items
                set raw_attributes = json_remove(raw_attributes, ?)
                where raw_attributes is not null
                    and json_valid(raw_attributes)
                    and json_contains_path(raw_attributes, 'one', ?)
                    and ({$lockSql})",
            [
                '$.name_language_marker_conflict_'.$locale,
                '$.name_language_marker_conflict_'.$locale,
            ]
        );
    }

    private function manualLockSql(string $driver, string $locale): string
    {
        return match ($driver) {
            'pgsql' => "nullif(trim(coalesce(raw_attributes::jsonb #>> '{manual_name_locks,{$locale}}', '')), '') is not null",
            'sqlite' => "nullif(trim(coalesce(json_extract(raw_attributes, '$.manual_name_locks.{$locale}'), '')), '') is not null",
            default => "nullif(trim(coalesce(json_unquote(json_extract(raw_attributes, '$.manual_name_locks.{$locale}')), '')), '') is not null",
        };
    }
};
