<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'products';

    private const COLUMNS = [
        'is_competitor_part',
        'competitor_source',
        'competitor_source_url',
        'competitor_available',
        'competitor_last_seen_at',
    ];

    private const INDEXES = [
        'products_is_competitor_part_index',
        'products_competitor_source_index',
        'products_competitor_available_index',
        'products_competitor_last_seen_at_index',
    ];

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $columns = $this->existingColumns(self::COLUMNS);

        if ($columns === []) {
            return;
        }

        $this->ensureNoCompetitorProductData($columns);

        Schema::table(self::TABLE, function (Blueprint $table): void {
            foreach (self::INDEXES as $index) {
                if ($this->indexExists(self::TABLE, $index)) {
                    $table->dropIndex($index);
                }
            }

            $table->dropColumn($this->existingColumns(self::COLUMNS));
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $missingColumns = collect(self::COLUMNS)
            ->reject(fn (string $column): bool => Schema::hasColumn(self::TABLE, $column))
            ->values()
            ->all();

        if ($missingColumns === []) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($missingColumns): void {
            if (in_array('is_competitor_part', $missingColumns, true)) {
                $table->boolean('is_competitor_part')->default(false)->index();
            }

            if (in_array('competitor_source', $missingColumns, true)) {
                $table->string('competitor_source')->nullable()->index();
            }

            if (in_array('competitor_source_url', $missingColumns, true)) {
                $table->string('competitor_source_url')->nullable();
            }

            if (in_array('competitor_available', $missingColumns, true)) {
                $table->boolean('competitor_available')->default(true)->index();
            }

            if (in_array('competitor_last_seen_at', $missingColumns, true)) {
                $table->timestamp('competitor_last_seen_at')->nullable()->index();
            }
        });
    }

    private function ensureNoCompetitorProductData(array $columns): void
    {
        $query = DB::table(self::TABLE);
        $hasCondition = false;

        if (in_array('is_competitor_part', $columns, true)) {
            $query->where('is_competitor_part', true);
            $hasCondition = true;
        }

        if (in_array('competitor_source', $columns, true)) {
            $hasCondition
                ? $query->orWhereNotNull('competitor_source')
                : $query->whereNotNull('competitor_source');
            $hasCondition = true;
        }

        if (in_array('competitor_source_url', $columns, true)) {
            $query->orWhereNotNull('competitor_source_url');
            $hasCondition = true;
        }

        if (in_array('competitor_available', $columns, true)) {
            $query->orWhere('competitor_available', false);
            $hasCondition = true;
        }

        if (in_array('competitor_last_seen_at', $columns, true)) {
            $query->orWhereNotNull('competitor_last_seen_at');
            $hasCondition = true;
        }

        if ($hasCondition && $query->exists()) {
            throw new RuntimeException('Refusing to drop products competitor columns because at least one product contains competitor data.');
        }
    }

    private function existingColumns(array $columns): array
    {
        return collect($columns)
            ->filter(fn (string $column): bool => Schema::hasColumn(self::TABLE, $column))
            ->values()
            ->all();
    }

    private function indexExists(string $table, string $index): bool
    {
        return match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => DB::table('information_schema.statistics')
                ->where('table_schema', DB::getDatabaseName())
                ->where('table_name', $table)
                ->where('index_name', $index)
                ->exists(),
            'sqlite' => collect(DB::select("PRAGMA index_list('{$table}')"))
                ->contains(fn (object $row): bool => ($row->name ?? null) === $index),
            default => false,
        };
    }
};
