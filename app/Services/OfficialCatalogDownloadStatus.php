<?php

namespace App\Services;

use App\Models\DonorCar;
use Illuminate\Support\Facades\Cache;

class OfficialCatalogDownloadStatus
{
    protected const IDS_KEY = 'official_catalog_download:ids';

    protected const KEY_PREFIX = 'official_catalog_download:';

    protected const RUNNING_KEY = 'official_catalog_download:running';

    public function tryStart(DonorCar $donorCar, string $token): ?array
    {
        $status = $this->newRunningStatus($donorCar, $token);

        if (! Cache::add(self::RUNNING_KEY, [
            'donor_car_id' => $donorCar->id,
            'token' => $token,
            'started_at' => $status['started_at'],
        ], now()->addHours(6))) {
            return null;
        }

        $this->put($donorCar->id, $status);
        $this->rememberId((int) $donorCar->id);

        return $status;
    }

    public function start(DonorCar $donorCar, string $token): array
    {
        return $this->tryStart($donorCar, $token) ?? $this->running() ?? $this->newRunningStatus($donorCar, $token);
    }

    protected function newRunningStatus(DonorCar $donorCar, string $token): array
    {
        return [
            'token' => $token,
            'state' => 'running',
            'donor_car_id' => $donorCar->id,
            'vin' => $donorCar->vin,
            'message' => 'Выкачка официального каталога запущена.',
            'created' => null,
            'created_whole' => null,
            'created_damaged' => null,
            'updated_existing' => null,
            'skipped_existing' => null,
            'started_at' => now()->toIso8601String(),
            'finished_at' => null,
        ];
    }

    public function markRunning(DonorCar $donorCar, string $message): void
    {
        $status = $this->forDonor((int) $donorCar->id) ?? [];

        $this->put((int) $donorCar->id, [
            ...$status,
            'state' => 'running',
            'donor_car_id' => $donorCar->id,
            'vin' => $donorCar->vin,
            'message' => $message,
        ]);
    }

    public function complete(DonorCar $donorCar, array $stats): void
    {
        $status = $this->forDonor((int) $donorCar->id) ?? [];
        $created = (int) ($stats['created'] ?? 0);
        $createdWhole = (int) ($stats['created_whole'] ?? 0);
        $createdDamaged = (int) ($stats['created_damaged'] ?? 0);
        $updatedExisting = (int) ($stats['updated_existing'] ?? 0);
        $skippedExisting = (int) ($stats['skipped_existing'] ?? 0);

        $this->put((int) $donorCar->id, [
            ...$status,
            'state' => 'done',
            'donor_car_id' => $donorCar->id,
            'vin' => $donorCar->vin,
            'message' => "Запчасти с официального каталога выкачаны: создано {$created} (целых {$createdWhole}, разбитых {$createdDamaged}), обновлено {$updatedExisting}, уже были {$skippedExisting}.",
            'created' => $created,
            'created_whole' => $createdWhole,
            'created_damaged' => $createdDamaged,
            'updated_existing' => $updatedExisting,
            'skipped_existing' => $skippedExisting,
            'finished_at' => now()->toIso8601String(),
        ]);

        $this->releaseRunning($donorCar);
    }

    public function fail(DonorCar $donorCar, string $message): void
    {
        $status = $this->forDonor((int) $donorCar->id) ?? [];

        $this->put((int) $donorCar->id, [
            ...$status,
            'state' => 'failed',
            'donor_car_id' => $donorCar->id,
            'vin' => $donorCar->vin,
            'message' => $message,
            'finished_at' => now()->toIso8601String(),
        ]);

        $this->releaseRunning($donorCar);
    }

    public function forDonor(int $donorCarId): ?array
    {
        $status = Cache::get($this->key($donorCarId));

        return is_array($status) ? $status : null;
    }

    public function isRunning(int $donorCarId): bool
    {
        return ($this->forDonor($donorCarId)['state'] ?? null) === 'running';
    }

    public function running(): ?array
    {
        $running = Cache::get(self::RUNNING_KEY);

        if (! is_array($running)) {
            return null;
        }

        $donorCarId = (int) ($running['donor_car_id'] ?? 0);
        $status = $donorCarId > 0 ? $this->forDonor($donorCarId) : null;

        if (($status['state'] ?? null) === 'running') {
            return $status;
        }

        Cache::forget(self::RUNNING_KEY);

        return null;
    }

    public function all(): array
    {
        return collect(Cache::get(self::IDS_KEY, []))
            ->map(fn ($id): ?array => $this->forDonor((int) $id))
            ->filter()
            ->values()
            ->all();
    }

    protected function put(int $donorCarId, array $status): void
    {
        Cache::put($this->key($donorCarId), $status, now()->addHours(6));
    }

    protected function rememberId(int $donorCarId): void
    {
        $ids = collect(Cache::get(self::IDS_KEY, []))
            ->push($donorCarId)
            ->unique()
            ->values()
            ->all();

        Cache::put(self::IDS_KEY, $ids, now()->addHours(6));
    }

    protected function key(int $donorCarId): string
    {
        return self::KEY_PREFIX.$donorCarId;
    }

    protected function releaseRunning(DonorCar $donorCar): void
    {
        $running = Cache::get(self::RUNNING_KEY);

        if (! is_array($running) || (int) ($running['donor_car_id'] ?? 0) !== (int) $donorCar->id) {
            return;
        }

        Cache::forget(self::RUNNING_KEY);
    }
}
