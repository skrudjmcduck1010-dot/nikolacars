<?php

namespace App\Console\Commands;

use App\Support\TextEncodingNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class AuditDatabaseEncoding extends Command
{
    protected $signature = 'encoding:audit-db
        {--fix : Update changed values in place}
        {--table=* : Limit scan to one or more tables}
        {--chunk=500 : Rows per chunk}
        {--max-samples=200 : Maximum changed values to display}
        {--include-runtime : Include cache/session/queue tables}
        {--include-backups : Include old backup/snapshot tables}';

    protected $description = 'Scan text columns in database tables for mojibake and optionally repair them.';

    protected const RUNTIME_TABLES = [
        'cache',
        'cache_locks',
        'failed_jobs',
        'job_batches',
        'jobs',
        'migrations',
        'password_reset_tokens',
        'personal_access_tokens',
        'sessions',
    ];

    protected const BACKUP_TABLE_PATTERNS = [
        'backup_',
        '_backup_',
    ];

    public function handle(): int
    {
        $fix = (bool) $this->option('fix');
        $chunkSize = max(1, (int) $this->option('chunk'));
        $maxSamples = max(1, (int) $this->option('max-samples'));
        $onlyTables = array_map('strval', (array) $this->option('table'));

        $tables = collect($this->textColumnsByTable())
            ->when($onlyTables !== [], fn ($tables) => $tables->only($onlyTables))
            ->unless((bool) $this->option('include-runtime'), fn ($tables) => $tables->except(self::RUNTIME_TABLES))
            ->unless((bool) $this->option('include-backups'), fn ($tables) => $tables->reject(
                fn (array $columns, string $table): bool => $this->isBackupTable($table),
            ));

        $stats = [
            'tables_scanned' => 0,
            'rows_scanned' => 0,
            'values_changed' => 0,
            'rows_updated' => 0,
        ];
        $tableStats = [];
        $samples = [];

        foreach ($tables as $table => $columns) {
            $primaryKey = $this->primaryKeyFor($table);
            if ($primaryKey === null) {
                $this->warn("Skipping {$table}: no single-column primary key.");

                continue;
            }

            $stats['tables_scanned']++;
            $selectColumns = array_values(array_unique(array_merge([$primaryKey], $columns)));

            DB::table($table)
                ->select($selectColumns)
                ->orderBy($primaryKey)
                ->chunkById($chunkSize, function ($rows) use ($table, $primaryKey, $columns, $fix, &$stats, &$tableStats, &$samples, $maxSamples): void {
                    foreach ($rows as $row) {
                        $stats['rows_scanned']++;
                        $tableStats[$table]['rows_scanned'] = ($tableStats[$table]['rows_scanned'] ?? 0) + 1;
                        $changes = [];

                        foreach ($columns as $column) {
                            $original = $row->{$column} ?? null;
                            if (! is_string($original) || $original === '') {
                                continue;
                            }

                            $normalized = $this->normalizeDatabaseValue($original);
                            if ($normalized === $original) {
                                continue;
                            }

                            $changes[$column] = $normalized;
                            $stats['values_changed']++;
                            $tableStats[$table]['values_changed'] = ($tableStats[$table]['values_changed'] ?? 0) + 1;

                            if (count($samples) < $maxSamples) {
                                $samples[] = [
                                    'table' => $table,
                                    'id' => (string) $row->{$primaryKey},
                                    'column' => $column,
                                    'before' => $this->sample($original),
                                    'after' => $this->sample($normalized),
                                ];
                            }
                        }

                        if ($fix && $changes !== []) {
                            DB::table($table)
                                ->where($primaryKey, $row->{$primaryKey})
                                ->update($changes);
                            $stats['rows_updated']++;
                            $tableStats[$table]['rows_updated'] = ($tableStats[$table]['rows_updated'] ?? 0) + 1;
                        }
                    }
                }, $primaryKey);
        }

        $this->line("Tables scanned: {$stats['tables_scanned']}");
        $this->line("Rows scanned: {$stats['rows_scanned']}");
        $this->line("Changed values: {$stats['values_changed']}");

        if ($samples !== []) {
            $this->table(['table', 'id', 'column', 'before', 'after'], $samples);
        }

        $changedTables = collect($tableStats)
            ->filter(fn (array $row): bool => ($row['values_changed'] ?? 0) > 0)
            ->map(fn (array $row, string $table): array => [
                'table' => $table,
                'rows_scanned' => $row['rows_scanned'] ?? 0,
                'values_changed' => $row['values_changed'] ?? 0,
                'rows_updated' => $row['rows_updated'] ?? 0,
            ])
            ->values()
            ->all();

        if ($changedTables !== []) {
            $this->table(['table', 'rows_scanned', 'values_changed', 'rows_updated'], $changedTables);
        }

        if ($fix) {
            $this->info("Rows updated: {$stats['rows_updated']}");
        } elseif ($stats['values_changed'] > 0) {
            $this->warn('Report-only mode. Re-run with --fix to update database values.');
        } else {
            $this->info('No database encoding issues found.');
        }

        return self::SUCCESS;
    }

    protected function textColumnsByTable(): array
    {
        $columns = DB::select(
            <<<'SQL'
                select table_name as table_name, column_name as column_name
                from information_schema.columns
                where table_schema = ?
                  and data_type in ('char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'json')
                order by table_name, ordinal_position
            SQL,
            [DB::getDatabaseName()],
        );

        return collect($columns)
            ->groupBy(fn (object $column): string => (string) ($column->table_name ?? $column->TABLE_NAME))
            ->map(fn ($items) => $items
                ->map(fn (object $column): string => (string) ($column->column_name ?? $column->COLUMN_NAME))
                ->values()
                ->all())
            ->all();
    }

    protected function primaryKeyFor(string $table): ?string
    {
        $keys = DB::select(
            <<<'SQL'
                select column_name as column_name
                from information_schema.key_column_usage
                where table_schema = ?
                  and table_name = ?
                  and constraint_name = 'PRIMARY'
                order by ordinal_position
            SQL,
            [DB::getDatabaseName(), $table],
        );

        return count($keys) === 1 ? (string) ($keys[0]->column_name ?? $keys[0]->COLUMN_NAME) : null;
    }

    protected function isBackupTable(string $table): bool
    {
        foreach (self::BACKUP_TABLE_PATTERNS as $pattern) {
            if (str_starts_with($table, $pattern) || str_contains($table, $pattern)) {
                return true;
            }
        }

        return false;
    }

    protected function normalizeDatabaseValue(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed !== '' && Str::startsWith($trimmed, ['{', '['])) {
            try {
                $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
                $normalized = TextEncodingNormalizer::normalizeArray($decoded);
                if ($normalized === $decoded) {
                    return $value;
                }

                $encoded = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                if (is_string($encoded)) {
                    return $encoded;
                }
            } catch (Throwable) {
                // Fall through to plain-text normalization.
            }
        }

        return TextEncodingNormalizer::normalize($value) ?? $value;
    }

    protected function sample(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return mb_strlen($value) > 80 ? mb_substr($value, 0, 77).'...' : $value;
    }
}
