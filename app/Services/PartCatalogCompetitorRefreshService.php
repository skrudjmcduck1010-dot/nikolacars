<?php

namespace App\Services;

use App\Models\CompetitorCatalogRun;

class PartCatalogCompetitorRefreshService
{
    public function __construct(private readonly CompetitorCatalogPartsUpdater $updater) {}

    public function start(string $source, callable $payload): array
    {
        $source = $this->updater->normalizeSource($source);

        $runningRun = CompetitorCatalogRun::query()
            ->where('source', $source)
            ->active()
            ->latest('id')
            ->first();

        if ($runningRun !== null) {
            return [
                'payload' => $payload($source, $runningRun),
                'status' => 200,
            ];
        }

        $cooldownRun = CompetitorCatalogRun::latestRefreshForCooldown($source);
        if ($cooldownRun?->isInRefreshCooldown()) {
            return [
                'payload' => [
                    ...$payload($source, $cooldownRun),
                    'next_available_at' => $cooldownRun->cooldownAvailableAt()?->toIso8601String(),
                    'message' => 'Обновлять каталог можно раз в 24 часа. Следующий запуск: '.$cooldownRun->cooldownAvailableAt()?->format('d.m.Y H:i').'.',
                ],
                'status' => 429,
            ];
        }

        $run = CompetitorCatalogRun::query()->create([
            'source' => $source,
            'status' => 'pending',
            'message' => 'Запуск обновления '.$this->updater->sourceLabel($source).'.',
        ]);

        $this->startProcess($source, $run);

        return [
            'payload' => $payload($source, $run),
            'status' => 200,
        ];
    }

    public function status(string $source, callable $payload): array
    {
        $source = $this->updater->normalizeSource($source);

        return $payload($source);
    }

    public function durationLabel(CompetitorCatalogRun $run): ?string
    {
        if ($run->started_at === null) {
            return null;
        }

        $finishedAt = $run->finished_at ?? now();
        $seconds = (int) max(0, $run->started_at->diffInSeconds($finishedAt));

        if ($seconds < 60) {
            return $seconds.' сек.';
        }

        $minutes = intdiv($seconds, 60);
        $remainingSeconds = $seconds % 60;

        return $minutes.' мин. '.$remainingSeconds.' сек.';
    }

    private function startProcess(string $source, CompetitorCatalogRun $run): void
    {
        $php = $this->phpCliBinary();
        $artisan = base_path('artisan');
        $log = storage_path('logs/competitor-refresh-'.$source.'-'.$run->id.'.log');

        if (PHP_OS_FAMILY === 'Windows') {
            $command = 'start /B "" '
                .escapeshellarg($php).' '
                .escapeshellarg($artisan).' parts:refresh-competitor '
                .escapeshellarg($source).' '
                .(int) $run->id
                .' > '.escapeshellarg($log).' 2>&1';

            pclose(popen('cmd /c '.$command, 'r'));

            return;
        }

        $command = escapeshellarg($php).' '.escapeshellarg($artisan).' parts:refresh-competitor '
            .escapeshellarg($source).' '
            .(int) $run->id
            .' > '.escapeshellarg($log).' 2>&1 &';

        exec($command);
    }

    private function phpCliBinary(): string
    {
        $candidates = array_filter([
            PHP_SAPI === 'cli' ? PHP_BINARY : null,
            PHP_BINDIR ? PHP_BINDIR.DIRECTORY_SEPARATOR.'php.exe' : null,
            PHP_BINDIR ? PHP_BINDIR.DIRECTORY_SEPARATOR.'php' : null,
            'C:\\laragon\\bin\\php\\php-8.3.30-Win32-vs16-x64\\php.exe',
        ]);

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && @is_file($candidate)) {
                return $candidate;
            }
        }

        return 'php';
    }
}
