#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$gitPath = $root.'/.git';
$sourceHookPath = $root.'/.githooks/pre-commit';

if (! is_dir($gitPath) && ! is_file($gitPath)) {
    exit(0);
}

if (! is_file($sourceHookPath)) {
    fwrite(STDERR, "Skipping git hook install: .githooks/pre-commit is missing.\n");
    exit(0);
}

$hooksDirectory = gitOutput($root, ['git', '-C', $root, 'rev-parse', '--git-path', 'hooks']);
$hooksDirectory = trim($hooksDirectory);
if ($hooksDirectory === '') {
    fwrite(STDERR, "Skipping git hook install: could not resolve .git/hooks.\n");
    exit(0);
}

if (! isAbsolutePath($hooksDirectory)) {
    $hooksDirectory = $root.'/'.$hooksDirectory;
}

if (! is_dir($hooksDirectory)) {
    mkdir($hooksDirectory, 0775, true);
}

$targetHookPath = $hooksDirectory.'/pre-commit';

copy($sourceHookPath, $targetHookPath);
@chmod($sourceHookPath, 0755);
@chmod($targetHookPath, 0755);

gitOutput($root, ['git', '-C', $root, 'config', '--unset', 'core.hooksPath'], allowFailure: true);

fwrite(STDOUT, "Git pre-commit hook installed in .git/hooks\n");

function gitOutput(string $root, array $command, bool $allowFailure = false): string
{
    $escaped = array_map('escapeshellarg', $command);
    $output = [];
    $status = 0;
    exec(implode(' ', $escaped).' 2>&1', $output, $status);

    if ($status !== 0 && ! $allowFailure) {
        throw new RuntimeException(trim(implode("\n", $output)) ?: 'Git command failed.');
    }

    return trim(implode("\n", $output));
}

function isAbsolutePath(string $path): bool
{
    return preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
        || str_starts_with($path, '/')
        || str_starts_with($path, '\\\\');
}
