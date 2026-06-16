<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class PublicStorageReferenceAuditService
{
    protected const PUBLIC_PATH_PREFIXES = [
        'competitor-catalog/',
        'competitor-catalog-occurrences/',
        'competitor-catalog-category/',
        'driveparts/',
        'tesla-official/',
        'erazborka/',
        'toprazborka/',
        'donor-cars/',
        'product-photos/',
    ];

    public function audit(array $options = []): array
    {
        $delete = (bool) ($options['delete'] ?? false);
        $sampleLimit = max(0, (int) ($options['sample'] ?? 30));
        $includeDotfiles = (bool) ($options['include_dotfiles'] ?? false);
        $scanPrefixes = $this->scanPrefixes((array) ($options['prefixes'] ?? []));
        $referencedPaths = $this->referencedPublicPaths();
        $referencedPathLookup = array_change_key_case($referencedPaths, CASE_LOWER);
        $disk = Storage::disk('public');
        $basePath = $disk->path('');

        $stats = [
            'referenced_paths' => count($referencedPaths),
            'public_files_seen' => 0,
            'referenced_files_seen' => 0,
            'case_mismatched_referenced_files_seen' => 0,
            'unreferenced_files_seen' => 0,
            'unreferenced_bytes_seen' => 0,
            'files_deleted' => 0,
            'bytes_deleted' => 0,
            'directories_deleted' => 0,
            'missing_referenced_files' => 0,
            'unreferenced_by_prefix' => [],
            'sample_unreferenced' => [],
        ];

        foreach ($scanPrefixes as $prefix) {
            $root = $basePath.$prefix;
            if ($prefix !== '' && ! is_dir($root)) {
                continue;
            }

            $this->scanFiles($root, $basePath, function (SplFileInfo $file, string $relativePath) use (
                $delete,
                $includeDotfiles,
                $sampleLimit,
                $referencedPaths,
                $referencedPathLookup,
                $disk,
                &$stats
            ): void {
                if (! $includeDotfiles && basename($relativePath)[0] === '.') {
                    return;
                }

                $stats['public_files_seen']++;

                if (isset($referencedPaths[$relativePath])) {
                    $stats['referenced_files_seen']++;

                    return;
                }

                if (isset($referencedPathLookup[strtolower($relativePath)])) {
                    $stats['referenced_files_seen']++;
                    $stats['case_mismatched_referenced_files_seen']++;

                    return;
                }

                $size = $file->getSize();
                $stats['unreferenced_files_seen']++;
                $stats['unreferenced_bytes_seen'] += $size;

                $bucket = $this->bucketFor($relativePath);
                $stats['unreferenced_by_prefix'][$bucket] ??= ['files' => 0, 'bytes' => 0];
                $stats['unreferenced_by_prefix'][$bucket]['files']++;
                $stats['unreferenced_by_prefix'][$bucket]['bytes'] += $size;

                if (count($stats['sample_unreferenced']) < $sampleLimit) {
                    $stats['sample_unreferenced'][] = $relativePath;
                }

                if (! $delete) {
                    return;
                }

                if ($disk->delete($relativePath)) {
                    $stats['files_deleted']++;
                    $stats['bytes_deleted'] += $size;
                }
            });
        }

        foreach ($referencedPaths as $path => $_) {
            if (! $disk->exists($path)) {
                $stats['missing_referenced_files']++;
            }
        }

        if ($delete) {
            foreach ($scanPrefixes as $prefix) {
                $root = $basePath.$prefix;
                if (is_dir($root)) {
                    $stats['directories_deleted'] += $this->deleteEmptyDirectories($root, $basePath);
                }
            }
        }

        uasort(
            $stats['unreferenced_by_prefix'],
            fn (array $left, array $right): int => $right['files'] <=> $left['files']
        );

        $stats['unreferenced_megabytes_seen'] = round($stats['unreferenced_bytes_seen'] / 1048576, 2);
        $stats['megabytes_deleted'] = round($stats['bytes_deleted'] / 1048576, 2);
        $stats['unreferenced_by_prefix'] = array_map(
            fn (array $row): array => [
                'files' => $row['files'],
                'megabytes' => round($row['bytes'] / 1048576, 2),
            ],
            $stats['unreferenced_by_prefix']
        );

        return $stats;
    }

    protected function referencedPublicPaths(): array
    {
        $paths = [];
        $add = function (mixed $value) use (&$paths): void {
            $path = $this->normalizePublicPath($value);
            if ($path !== null) {
                $paths[$path] = true;
            }
        };
        $walk = function (mixed $value) use (&$walk, $add): void {
            if ($value instanceof \ArrayObject) {
                $value = $value->getArrayCopy();
            }

            if (is_array($value)) {
                foreach ($value as $child) {
                    $walk($child);
                }

                return;
            }

            $add($value);
        };

        foreach ([['part_catalog_items', 'raw_attributes'], ['part_catalog_item_occurrences', 'raw_attributes']] as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            DB::table($table)
                ->select('id', $column)
                ->orderBy('id')
                ->chunkById(1000, function ($rows) use ($column, $walk): void {
                    foreach ($rows as $row) {
                        $walk($this->decodedJsonValue($row->{$column}));
                    }
                });
        }

        if (Schema::hasTable('part_catalog_categories') && Schema::hasColumn('part_catalog_categories', 'preview_image_url')) {
            DB::table('part_catalog_categories')
                ->select('id', 'preview_image_url')
                ->whereNotNull('preview_image_url')
                ->orderBy('id')
                ->chunkById(1000, function ($rows) use ($add): void {
                    foreach ($rows as $row) {
                        $add($row->preview_image_url);
                    }
                });
        }

        if (Schema::hasTable('products')) {
            DB::table('products')
                ->select('id', 'main_image', 'images_json')
                ->orderBy('id')
                ->chunkById(1000, function ($rows) use ($add, $walk): void {
                    foreach ($rows as $row) {
                        $add($row->main_image);
                        $walk($this->decodedJsonValue($row->images_json));
                    }
                });
        }

        if (Schema::hasTable('donor_cars') && Schema::hasColumn('donor_cars', 'photos')) {
            DB::table('donor_cars')
                ->select('id', 'photos')
                ->orderBy('id')
                ->chunkById(1000, function ($rows) use ($walk): void {
                    foreach ($rows as $row) {
                        $walk($this->decodedJsonValue($row->photos));
                    }
                });
        }

        return $paths;
    }

    protected function decodedJsonValue(mixed $value): mixed
    {
        if (! is_string($value) || trim($value) === '') {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    protected function normalizePublicPath(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('~^https?://~i', $value) === 1) {
            $urlPath = parse_url($value, PHP_URL_PATH);
            if (! is_string($urlPath) || $urlPath === '') {
                return null;
            }

            $value = $urlPath;
        }

        $value = str_replace('\\', '/', $value);
        $value = (string) preg_replace('~^https?://[^/]+~i', '', $value);
        $value = ltrim($value, '/');

        foreach (['storage/app/public/', 'public/storage/', 'storage/'] as $prefix) {
            if (str_starts_with($value, $prefix)) {
                $value = substr($value, strlen($prefix));
            }
        }

        $value = ltrim($value, '/');

        foreach (self::PUBLIC_PATH_PREFIXES as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return $value;
            }
        }

        return null;
    }

    protected function scanPrefixes(array $prefixes): array
    {
        $prefixes = array_values(array_unique(array_filter(array_map(
            fn (mixed $prefix): string => trim(str_replace('\\', '/', (string) $prefix), '/'),
            $prefixes,
        ))));

        if ($prefixes === []) {
            return [''];
        }

        return array_values(array_filter(
            array_map(fn (string $prefix): ?string => str_contains($prefix, '..') ? null : $prefix.'/', $prefixes)
        ));
    }

    protected function scanFiles(string $root, string $basePath, callable $callback): void
    {
        if (! is_dir($root)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $relativePath = str_replace('\\', '/', substr($file->getPathname(), strlen($basePath)));
            $callback($file, ltrim($relativePath, '/'));
        }
    }

    protected function deleteEmptyDirectories(string $root, string $basePath): int
    {
        $deleted = 0;
        $paths = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isDir()) {
                continue;
            }

            $path = $file->getPathname();
            if ($path === $basePath || $path === rtrim($basePath, DIRECTORY_SEPARATOR)) {
                continue;
            }

            $paths[] = $path;
        }

        unset($iterator);

        usort($paths, fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        foreach (array_unique($paths) as $path) {
            if ($this->isEmptyDirectory($path) && @rmdir($path)) {
                $deleted++;
            }
        }

        if ($root !== $basePath && $this->isEmptyDirectory($root) && @rmdir($root)) {
            $deleted++;
        }

        return $deleted;
    }

    protected function isEmptyDirectory(string $path): bool
    {
        if (! is_dir($path)) {
            return false;
        }

        $contents = scandir($path);

        return $contents === ['.', '..'];
    }

    protected function bucketFor(string $path): string
    {
        $parts = explode('/', $path);

        if (in_array($parts[0] ?? '', ['competitor-catalog', 'driveparts', 'tesla-official', 'erazborka', 'toprazborka'], true)
            && isset($parts[1])) {
            return $parts[0].'/'.$parts[1];
        }

        return $parts[0] ?? $path;
    }
}
