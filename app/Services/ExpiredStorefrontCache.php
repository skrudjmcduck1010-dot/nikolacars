<?php

namespace App\Services;

use RuntimeException;

final class ExpiredStorefrontCache
{
    private const GRACE_SECONDS = 48 * 3600;
    private const MAX_BUCKETS = 4096;
    private const MAX_FILES = 6000;
    private const MAX_REMOVALS = 2000;
    private const MAX_SECONDS = 10;

    /**
     * Visit part of the 256 x 256 FileStore tree. A persisted cursor lets the next
     * run continue even if one unusually busy bucket consumes most of the budget.
     */
    public function run(string $root, int $bucket, string $after, bool $apply): array
    {
        $started = hrtime(true);
        $deadline = $started + self::MAX_SECONDS * 1_000_000_000;
        $cutoff = time() - self::GRACE_SECONDS;
        $inspected = $eligible = $removed = $bytes = 0;
        for ($visited = 0; $visited < self::MAX_BUCKETS; $visited++) {
            if (hrtime(true) >= $deadline) {
                break;
            }
            $shards = sprintf('%04x', $bucket);
            $parent = $root.DIRECTORY_SEPARATOR.substr($shards, 0, 2);
            $dir = $parent.DIRECTORY_SEPARATOR.substr($shards, 2);
            if (is_dir($dir) && ! is_link($parent) && ! is_link($dir) && realpath($dir) === $dir) {
                $files = scandir($dir);
                if ($files === false) {
                    throw new RuntimeException('Cannot list a storefront cache bucket.');
                }
                foreach ($files as $name) {
                    if (! str_starts_with($name, $shards) || ! preg_match('/^[a-f0-9]{40}$/D', $name) || strcmp($name, $after) <= 0) {
                        continue;
                    }
                    if ($inspected >= self::MAX_FILES || $eligible >= self::MAX_REMOVALS || hrtime(true) >= $deadline) {
                        break 2;
                    }
                    $after = $name;
                    $inspected++;
                    $size = $this->process($dir.DIRECTORY_SEPARATOR.$name, $dir, $cutoff, $apply);
                    if ($size !== null) {
                        $eligible++;
                        $bytes += $size;
                        if ($apply) {
                            $removed++;
                        }
                    }
                }
            }
            $bucket = ($bucket + 1) % 65536;
            $after = '';
        }

        return [
            'next_bucket' => $bucket, 'after' => $after, 'inspected' => $inspected,
            'eligible' => $eligible, 'removed' => $removed, 'eligible_bytes' => $bytes,
            'completed_utc' => gmdate(DATE_ATOM), 'elapsed_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
        ];
    }

    private function process(string $path, string $dir, int $cutoff, bool $apply): ?int
    {
        clearstatcache(true, $path);
        $entry = @lstat($path);
        if (! $entry || ($entry['mode'] & 0170000) !== 0100000 || $entry['nlink'] !== 1
            || $entry['mtime'] > $cutoff || (function_exists('posix_geteuid') && $entry['uid'] !== posix_geteuid())) {
            return null;
        }
        $handle = @fopen($path, 'rb');
        if (! $handle) {
            return null;
        }
        try {
            if (! flock($handle, ($apply ? LOCK_EX : LOCK_SH) | LOCK_NB)) {
                return null;
            }
            $held = fstat($handle);
            $header = fread($handle, 100);
            if (! $held || ! $this->sameFile($held, $entry) || ! is_string($header) || ! $this->expiredPublicValue($header, $held, $cutoff)) {
                return null;
            }
            if ($apply) {
                clearstatcache(true, $path);
                clearstatcache(true, $dir);
                if (realpath($dir) !== $dir || ! $this->sameFile($held, @lstat($path))) {
                    return null;
                }
                if (! @unlink($path)) {
                    throw new RuntimeException('Could not remove a verified expired storefront cache entry.');
                }
            }

            return $held['size'];
        } finally {
            fclose($handle);
        }
    }

    private function sameFile(array $held, array|false $entry): bool
    {
        if ($entry === false) {
            return false;
        }
        foreach (['dev', 'ino', 'mode', 'size', 'mtime', 'ctime', 'nlink', 'uid'] as $field) {
            if ($held[$field] !== $entry[$field]) {
                return false;
            }
        }
        return true;
    }

    private function expiredPublicValue(string $header, array $stat, int $cutoff): bool
    {
        $expiry = substr($header, 0, 10);
        if (! preg_match('/^[1-9][0-9]{9}$/D', $expiry) || (int) $expiry > $cutoff) {
            return false;
        }
        $value = substr($header, 10);
        if (preg_match('/^a:2:\\{s:6:"status";i:(200|201|204|404|410|422);s:4:"data";(?:a:[0-9]+:\\{|N;)/', $value)) {
            return true;
        }
        // Cache::flexible stores its creation timestamp in a second file. Its
        // write time and hard TTL identify it without unserializing cached data.
        if (! preg_match('/^i:([1-9][0-9]{9});$/D', $value, $matches) || $stat['size'] !== strlen($header)) {
            return false;
        }
        $created = (int) $matches[1];
        return abs($stat['mtime'] - $created) <= 2
            && in_array((int) $expiry - $created, [300, 301, 86400, 86401, 604800, 604801], true);
    }
}
