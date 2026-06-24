<?php

namespace App\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class SourceEncodingScanner
{
    public const DEFAULT_PATHS = [
        'app',
        'config',
        'database/migrations',
        'docs',
        'resources',
        'routes',
        'scripts',
        'AGENTS.md',
        'README.md',
    ];

    public const SKIPPED_DIRECTORIES = [
        '.git',
        '.codex-tmp',
        'bootstrap/cache',
        'database/backups',
        'database/dumps',
        'node_modules',
        'outputs',
        'public/build',
        'storage',
        'vendor',
    ];

    public const TEXT_EXTENSIONS = [
        'bat',
        'blade.php',
        'css',
        'csv',
        'env',
        'example',
        'html',
        'js',
        'json',
        'md',
        'mjs',
        'php',
        'py',
        'sql',
        'txt',
        'xml',
        'yml',
        'yaml',
    ];

    public function __construct(protected string $basePath)
    {
        $this->basePath = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $basePath), DIRECTORY_SEPARATOR);
    }

    /**
     * @return iterable<SplFileInfo>
     */
    public function filesForPaths(array $paths = []): iterable
    {
        $paths = $paths !== [] ? $paths : self::DEFAULT_PATHS;

        foreach ($paths as $path) {
            $root = $this->resolvePath((string) $path);

            if (is_file($root)) {
                $file = new SplFileInfo($root);
                if ($this->isScannablePath($file->getPathname())) {
                    yield $file;
                }

                continue;
            }

            if (! is_dir($root)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                    continue;
                }

                if (! $this->isScannablePath($file->getPathname())) {
                    continue;
                }

                yield $file;
            }
        }
    }

    public function inspectContents(string $path, string $contents, int $maxIssues = 200): array
    {
        $issues = [];

        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $issues[] = [$path, 1, 'UTF-8 BOM', $this->sample(substr($contents, 3))];

            if (count($issues) >= $maxIssues) {
                return $issues;
            }
        }

        if (! mb_check_encoding($contents, 'UTF-8')) {
            $issues[] = [$path, 1, 'invalid UTF-8 bytes', $this->sample($contents)];

            return $issues;
        }

        $lines = preg_split('/\R/u', $contents) ?: [];
        foreach ($lines as $lineNumber => $line) {
            if (count($issues) >= $maxIssues) {
                return $issues;
            }

            $issue = $this->lineIssue($line);
            if ($issue === null) {
                continue;
            }

            $issues[] = [$path, $lineNumber + 1, $issue, $this->sample($line)];
        }

        return $issues;
    }

    public function lineIssue(string $line): ?string
    {
        if (str_contains($line, "\u{FFFD}")) {
            return 'replacement character';
        }

        if (CatalogTextEncoding::looksLikeMojibake($line)) {
            return 'possible mojibake';
        }

        return null;
    }

    public function isScannablePath(string $path): bool
    {
        if ($this->isSkippedPath($path)) {
            return false;
        }

        $name = basename(str_replace('\\', '/', $path));

        if (in_array($name, ['.editorconfig', '.env.example', '.env.production.example'], true)) {
            return true;
        }

        foreach (self::TEXT_EXTENSIONS as $extension) {
            if (str_ends_with($name, '.'.$extension)) {
                return true;
            }
        }

        return false;
    }

    public function isSkippedPath(string $path): bool
    {
        $relative = str_replace('\\', '/', $this->relativePath($path));

        foreach (self::SKIPPED_DIRECTORIES as $directory) {
            if ($relative === $directory || str_starts_with($relative, $directory.'/')) {
                return true;
            }
        }

        return false;
    }

    public function looksBinary(string $contents): bool
    {
        return str_contains(substr($contents, 0, 4096), "\0");
    }

    public function sample(string $value): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = preg_replace('/[^\x20-\x7E]/', '?', $value) ?? $value;
            $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);

            return strlen($value) > 120 ? substr($value, 0, 117).'...' : $value;
        }

        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return mb_strlen($value) > 120 ? mb_substr($value, 0, 117).'...' : $value;
    }

    public function relativePath(string|SplFileInfo $file): string
    {
        $path = $file instanceof SplFileInfo ? $file->getPathname() : $file;
        $path = str_replace('\\', '/', $path);
        $base = str_replace('\\', '/', $this->basePath).'/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    protected function resolvePath(string $path): string
    {
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

        if ($this->isAbsolutePath($normalized)) {
            return $normalized;
        }

        return $this->basePath.DIRECTORY_SEPARATOR.$normalized;
    }

    protected function isAbsolutePath(string $path): bool
    {
        return preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
            || str_starts_with($path, DIRECTORY_SEPARATOR)
            || str_starts_with($path, '\\\\');
    }
}
