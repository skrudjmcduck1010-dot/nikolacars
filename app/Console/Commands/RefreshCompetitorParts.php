<?php

namespace App\Console\Commands;

use App\Models\CompetitorCatalogRun;
use App\Services\CompetitorCatalogPartsUpdater;
use Illuminate\Console\Command;

class RefreshCompetitorParts extends Command
{
    protected $signature = 'parts:refresh-competitor {source} {runId?}';

    protected $description = 'Refresh competitor catalog parts.';

    public function handle(CompetitorCatalogPartsUpdater $updater): int
    {
        try {
            $source = $updater->normalizeSource((string) $this->argument('source'));
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $runId = (int) ($this->argument('runId') ?: 0);

        if ($runId === 0) {
            $cooldownRun = CompetitorCatalogRun::latestRefreshForCooldown($source);

            if ($cooldownRun?->isInRefreshCooldown()) {
                $this->error('Competitor catalog ['.$source.'] can be refreshed only once per 24 hours. Next run: '
                    .$cooldownRun->cooldownAvailableAt()?->format('Y-m-d H:i:s'));

                return self::FAILURE;
            }
        }

        $run = $runId > 0
            ? CompetitorCatalogRun::query()->findOrFail($runId)
            : CompetitorCatalogRun::query()->create([
                'source' => $source,
                'status' => 'pending',
                'message' => 'Запуск обновления '.$updater->sourceLabel($source).'.',
            ]);

        $hasAnotherRunning = CompetitorCatalogRun::query()
            ->where('source', $source)
            ->active()
            ->where('id', '!=', $run->id)
            ->exists();

        if ($hasAnotherRunning) {
            $run->forceFill([
                'status' => 'failed',
                'message' => 'Обновление '.$updater->sourceLabel($source).' уже запущено.',
                'error' => 'Another competitor refresh is already running.',
                'finished_at' => now(),
            ])->save();

            $this->warn('Another '.$source.' competitor refresh is already running.');

            return self::SUCCESS;
        }

        try {
            $stats = $updater->run($run);

            foreach ($stats as $name => $value) {
                $this->line(" - {$name}: {$value}");
            }

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            report($exception);

            $run->forceFill([
                'status' => 'failed',
                'message' => 'Ошибка обновления '.$updater->sourceLabel($source).': '.$exception->getMessage(),
                'error' => $exception->getMessage(),
                'finished_at' => now(),
            ])->save();

            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
