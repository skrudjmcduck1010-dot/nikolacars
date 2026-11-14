<?php

namespace App\Console\Commands;

use App\Services\ExpiredStorefrontCache;
use Illuminate\Cache\FileStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class PruneStorefrontCache extends Command
{
    protected $signature = 'storefront:prune-expired-cache {--dry-run : Inspect one slice without writing files}';
    protected $description = 'Remove expired public storefront cache entries in bounded batches';

    public function handle(ExpiredStorefrontCache $pruner): int
    {
        $storage = realpath(storage_path());
        $expected = $storage.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'data';
        $store = Cache::store()->getStore();
        if ($storage === false || ! $store instanceof FileStore || realpath($expected) !== $expected
            || realpath($store->getDirectory()) !== $expected) {
            $this->error('Refusing to operate outside the configured storefront file cache.');
            return self::FAILURE;
        }
        $state = $storage.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'storefront-cache-prune.json';
        $dry = (bool) $this->option('dry-run');
        if ($dry && ! file_exists($state) && ! is_link($state)) {
            $this->line(json_encode($pruner->run($expected, 0, '', false), JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        }
        clearstatcache(true, $state);
        if (is_link($state)) {
            throw new RuntimeException('Invalid cache pruning state path.');
        }
        $handle = @fopen($state, $dry ? 'rb' : 'c+b');
        if (! $handle) {
            throw new RuntimeException('Could not open the cache pruning state.');
        }
        try {
            if (! flock($handle, ($dry ? LOCK_SH : LOCK_EX) | LOCK_NB)) {
                $this->line('{"status":"already_running"}');
                return self::SUCCESS;
            }
            $held = fstat($handle);
            clearstatcache(true, $state);
            $entry = lstat($state);
            if (! $held || ! $entry || ($entry['mode'] & 0170000) !== 0100000 || $held['nlink'] !== 1
                || $held['dev'] !== $entry['dev'] || $held['ino'] !== $entry['ino'] || $held['size'] > 4096
                || (function_exists('posix_geteuid') && $held['uid'] !== posix_geteuid())) {
                throw new RuntimeException('Invalid cache pruning state file.');
            }
            $previous = json_decode(stream_get_contents($handle) ?: '{}', true);
            $bucket = $previous['next_bucket'] ?? 0;
            $after = $previous['after'] ?? '';
            if (! is_int($bucket) || $bucket < 0 || $bucket >= 65536 || ! is_string($after)
                || ($after !== '' && ! preg_match('/^[a-f0-9]{40}$/D', $after))) {
                $bucket = 0;
                $after = '';
            }
            $result = $pruner->run($expected, $bucket, $after, ! $dry);
            $json = json_encode($result, JSON_THROW_ON_ERROR);
            if (! $dry) {
                rewind($handle);
                if (! ftruncate($handle, 0) || fwrite($handle, $json) !== strlen($json) || ! fflush($handle)) {
                    throw new RuntimeException('Could not record the cache pruning result.');
                }
            }
            $this->line($json);
            return self::SUCCESS;
        } finally {
            fclose($handle);
        }
    }
}
