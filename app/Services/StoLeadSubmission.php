<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use Throwable;

/** Durable delivery state, separate from cache: never expire an uncertain Telegram claim. */
class StoLeadSubmission
{
    public function submit(string $id, array $payload, callable $crm, callable $telegram): array
    {
        $directory = storage_path('app/private/sto-lead-submissions');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Lead receipt storage unavailable.');
        }
        $key = hash('sha256', $id);
        // Fixed shards bound inode use to 64 ledgers + 64 locks, independent of request count.
        $receipt = $directory.'/'.sprintf('%02x', hexdec(substr($key, 0, 2)) % 64).'.ledger';
        $file = fopen($receipt.'.lock', 'c+b');
        if ($file === false) {
            throw new RuntimeException('Lead receipt unavailable.');
        }
        try {
            // OS lock has no TTL and is released on process exit; concurrent callers do no work.
            if (! flock($file, LOCK_EX | LOCK_NB)) {
                return ['ok' => false, 'submission_status' => 'pending', 'http_status' => 409];
            }
            $fingerprint = hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), (string) config('app.key'));
            $exists = is_file($receipt);
            $bytes = $exists ? file_get_contents($receipt) : '';
            if ($bytes === false) {
                throw new RuntimeException('Lead receipt unreadable.');
            }
            $ledger = ! $exists ? ['version' => 1, 'requests' => []]
                : json_decode(Crypt::decryptString($bytes), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($ledger) || ($ledger['version'] ?? null) !== 1 || ! is_array($ledger['requests'] ?? null)) {
                throw new RuntimeException('Lead ledger invalid; reconciliation required.');
            }
            $state = array_key_exists($key, $ledger['requests']) ? $ledger['requests'][$key]
                : ['version' => 1, 'fingerprint' => $fingerprint, 'crm' => 'new', 'telegram' => 'new'];
            if (! is_array($state) || ($state['version'] ?? null) !== 1
                || ! in_array($state['crm'] ?? null, ['new', 'pending', 'confirmed', 'unconfirmed'], true)
                || ! in_array($state['telegram'] ?? null, ['new', 'claimed', 'confirmed', 'unconfirmed', 'retryable'], true)
                || ! is_string($state['fingerprint'] ?? null)) {
                throw new RuntimeException('Lead receipt invalid; reconciliation required.');
            }
            if (! hash_equals($state['fingerprint'], $fingerprint)) {
                return ['ok' => false, 'submission_status' => 'conflict', 'http_status' => 409];
            }
            $commit = function () use ($receipt, &$ledger, $key, &$state): void {
                $ledger['requests'][$key] = $state;
                $this->save($receipt, $ledger);
            };
            if ($state['crm'] !== 'confirmed') {
                $state['crm'] = 'pending';
                $commit();
                // Warehouse external_id is durable/idempotent, so an unknown outcome can be read back by retry.
                try {
                    $state['crm'] = $crm() === true ? 'confirmed' : 'unconfirmed';
                } catch (Throwable) {
                    $state['crm'] = 'unconfirmed';
                }
                $commit();
            }
            if (in_array($state['telegram'], ['new', 'retryable'], true)) {
                $state['telegram'] = 'claimed';
                $commit(); // Before POST. Even a crash must block blind repeats.
                try {
                    $outcome = $telegram();
                    $state['telegram'] = in_array($outcome, ['confirmed', 'retryable'], true) ? $outcome : 'unconfirmed';
                } catch (Throwable) {
                    $state['telegram'] = 'unconfirmed';
                }
                $commit();
            }
            $accepted = $state['crm'] === 'confirmed' || $state['telegram'] === 'confirmed';

            return [
                'ok' => $accepted, 'crm_saved' => $state['crm'] === 'confirmed',
                'submission_status' => $accepted ? 'accepted' : 'unconfirmed',
                'http_status' => $accepted ? 200 : 503,
            ];
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }

    private function save(string $receipt, array $state): void
    {
        $bytes = Crypt::encryptString(json_encode($state, JSON_THROW_ON_ERROR));
        // One stage per shard, protected by its lock. Stale stages are never replayed/promoted.
        $temporary = $receipt.'.stage';
        $file = fopen($temporary, 'wb');
        if ($file === false) {
            throw new RuntimeException('Lead receipt stage unavailable.');
        }
        try {
            if (fwrite($file, $bytes) !== strlen($bytes) || ! fflush($file) || ! fsync($file)) {
                throw new RuntimeException('Lead receipt could not be committed.');
            }
            fclose($file);
            $file = null;
            if (! rename($temporary, $receipt)) {
                throw new RuntimeException('Lead receipt promotion failed.');
            }
        } finally {
            if (is_resource($file)) fclose($file);
            if (is_file($temporary)) @unlink($temporary);
        }
    }
}
