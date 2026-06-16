<?php

namespace App\Console\Commands;

use App\Support\CatalogTextEncoding;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class AuditTextEncoding extends Command
{
    protected $signature = 'encoding:audit
        {--path=* : Relative path to scan; defaults to source directories}
        {--fail-on-issues : Return a non-zero exit code when issues are found; kept for compatibility and now enabled by default}
        {--report-only : Return a zero exit code when issues are found}
        {--max-issues=200 : Stop reporting after this many issues}';

    protected $description = 'Scan project source files for invalid UTF-8 and common mojibake markers.';

    protected const DEFAULT_PATHS = [
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

    protected const SKIPPED_DIRECTORIES = [
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

    protected const TEXT_EXTENSIONS = [
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

    public function handle(): int
    {
        $maxIssues = max(1, (int) $this->option('max-issues'));
        $issues = [];
        $filesScanned = 0;

        foreach ($this->scanRoots() as $root) {
            foreach ($this->filesFor($root) as $file) {
                if (count($issues) >= $maxIssues) {
                    break 2;
                }

                $contents = file_get_contents($file->getPathname());
                if ($contents === false) {
                    continue;
                }

                if ($this->looksBinary($contents)) {
                    continue;
                }

                $filesScanned++;
                $this->inspectFile($file, $contents, $issues, $maxIssues);
            }
        }

        $this->line("Scanned files: {$filesScanned}");

        if ($issues === []) {
            $this->info('No encoding issues found.');

            return self::SUCCESS;
        }

        $this->table(['file', 'line', 'issue', 'sample'], $issues);
        $this->warn('Encoding issues found: '.count($issues));

        if ((bool) $this->option('report-only')) {
            $this->warn('Report-only mode. Remove --report-only to make this command fail.');

            return self::SUCCESS;
        }

        return self::FAILURE;
    }

    protected function scanRoots(): array
    {
        $paths = (array) $this->option('path');
        $paths = $paths !== [] ? $paths : self::DEFAULT_PATHS;

        return collect($paths)
            ->map(fn (string $path): string => base_path(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path)))
            ->filter(fn (string $path): bool => is_file($path) || is_dir($path))
            ->values()
            ->all();
    }

    /**
     * @return iterable<SplFileInfo>
     */
    protected function filesFor(string $root): iterable
    {
        if (is_file($root)) {
            $file = new SplFileInfo($root);
            if ($this->isScannable($file)) {
                yield $file;
            }

            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }

            if ($this->isSkipped($file->getPathname()) || ! $this->isScannable($file)) {
                continue;
            }

            yield $file;
        }
    }

    protected function inspectFile(SplFileInfo $file, string $contents, array &$issues, int $maxIssues): void
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $issues[] = [$this->relativePath($file), 1, 'UTF-8 BOM', $this->sample(substr($contents, 3))];

            if (count($issues) >= $maxIssues) {
                return;
            }
        }

        if (! mb_check_encoding($contents, 'UTF-8')) {
            $issues[] = [$this->relativePath($file), 1, 'invalid UTF-8 bytes', $this->sample($contents)];

            return;
        }

        $lines = preg_split('/\R/u', $contents) ?: [];
        foreach ($lines as $lineNumber => $line) {
            if (count($issues) >= $maxIssues) {
                return;
            }

            $issue = $this->lineIssue($line);
            if ($issue === null) {
                continue;
            }

            $issues[] = [$this->relativePath($file), $lineNumber + 1, $issue, $this->sample($line)];
        }
    }

    protected function lineIssue(string $line): ?string
    {
        if (str_contains($line, "\u{FFFD}")) {
            return 'replacement character';
        }

        if (CatalogTextEncoding::looksLikeMojibake($line)) {
            return 'possible mojibake';
        }

        return null;
    }

    protected function isScannable(SplFileInfo $file): bool
    {
        $name = $file->getFilename();
        $path = $file->getPathname();

        if ($this->isSkipped($path)) {
            return false;
        }

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

    protected function isSkipped(string $path): bool
    {
        $relative = str_replace('\\', '/', $this->relativePath(new SplFileInfo($path)));

        foreach (self::SKIPPED_DIRECTORIES as $directory) {
            if ($relative === $directory || str_starts_with($relative, $directory.'/')) {
                return true;
            }
        }

        return false;
    }

    protected function looksBinary(string $contents): bool
    {
        return str_contains(substr($contents, 0, 4096), "\0");
    }

    protected function sample(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return mb_strlen($value) > 120 ? mb_substr($value, 0, 117).'...' : $value;
    }

    protected function relativePath(SplFileInfo $file): string
    {
        $path = str_replace('\\', '/', $file->getPathname());
        $base = str_replace('\\', '/', base_path()).'/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
