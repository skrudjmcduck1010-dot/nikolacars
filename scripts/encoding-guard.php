#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Support\SourceEncodingScanner;
use Symfony\Component\Process\Process;

$root = dirname(__DIR__);
$autoload = $root.'/vendor/autoload.php';

if (! is_file($autoload)) {
    fwrite(STDERR, "Missing vendor/autoload.php. Run composer install before encoding guard.\n");
    exit(2);
}

require $autoload;

$staged = false;
$maxIssues = 200;
$paths = [];

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--staged') {
        $staged = true;

        continue;
    }

    if (str_starts_with($argument, '--max-issues=')) {
        $maxIssues = max(1, (int) substr($argument, strlen('--max-issues=')));

        continue;
    }

    $paths[] = $argument;
}

$scanner = new SourceEncodingScanner($root);
$issues = [];
$filesScanned = 0;

try {
    if ($staged) {
        foreach (stagedPaths($root) as $path) {
            if (count($issues) >= $maxIssues || ! $scanner->isScannablePath($path)) {
                continue;
            }

            $contents = gitStagedBlob($root, $path);
            if ($scanner->looksBinary($contents)) {
                continue;
            }

            $filesScanned++;
            $fileIssues = $scanner->inspectContents($path, $contents, $maxIssues - count($issues));
            array_push($issues, ...$fileIssues);
        }
    } else {
        foreach ($scanner->filesForPaths($paths) as $file) {
            if (count($issues) >= $maxIssues) {
                break;
            }

            $contents = file_get_contents($file->getPathname());
            if ($contents === false || $scanner->looksBinary($contents)) {
                continue;
            }

            $filesScanned++;
            $fileIssues = $scanner->inspectContents(
                $scanner->relativePath($file),
                $contents,
                $maxIssues - count($issues),
            );

            array_push($issues, ...$fileIssues);
        }
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(2);
}

fwrite(STDOUT, "Scanned files: {$filesScanned}\n");

if ($issues === []) {
    fwrite(STDOUT, "No encoding issues found.\n");

    exit(0);
}

foreach ($issues as [$file, $line, $issue, $sample]) {
    fwrite(STDERR, "{$file}:{$line} {$issue}: {$sample}\n");
}

fwrite(STDERR, 'Encoding issues found: '.count($issues)."\n");

exit(1);

/**
 * @return array<int, string>
 */
function stagedPaths(string $root): array
{
    $output = processOutput([
        'git',
        'diff',
        '--cached',
        '--name-only',
        '-z',
        '--diff-filter=ACMR',
    ], $root);

    return array_values(array_filter(explode("\0", $output), fn (string $path): bool => $path !== ''));
}

function gitStagedBlob(string $root, string $path): string
{
    return processOutput(['git', 'show', ':'.$path], $root);
}

function processOutput(array $command, string $cwd): string
{
    $process = new Process($command, $cwd);
    $process->run();

    if (! $process->isSuccessful()) {
        throw new RuntimeException(trim($process->getErrorOutput()) ?: 'Command failed: '.implode(' ', $command));
    }

    return $process->getOutput();
}
