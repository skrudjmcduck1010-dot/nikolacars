<?php

namespace App\Console\Commands;

use App\Models\CompetitorCatalogRun;
use App\Services\CompetitorCatalogPartsUpdater;
use App\Services\TcarserviceCompetitorPartsUpdater;
use Illuminate\Console\Command;

class RefreshTcarserviceCompetitorParts extends Command
{
    protected $signature = 'parts:refresh-tcarservice-competitor {runId?}';

    protected $description = 'Refresh TCARS competitor catalog parts.';

    public function handle(CompetitorCatalogPartsUpdater $updater): int
    {
        $runId = (int) ($this->argument('runId') ?: 0);

        if ($runId === 0) {
            $cooldownRun = CompetitorCatalogRun::latestRefreshForCooldown(TcarserviceCompetitorPartsUpdater::SOURCE);

            if ($cooldownRun?->isInRefreshCooldown()) {
                $this->error('TCARS competitor catalog can be refreshed only once per 24 hours. Next run: '
                    .$cooldownRun->cooldownAvailableAt()?->format('Y-m-d H:i:s'));

                return self::FAILURE;
            }
        }

        $run = $runId > 0
            ? CompetitorCatalogRun::query()->findOrFail($runId)
            : CompetitorCatalogRun::query()->create([
                'source' => TcarserviceCompetitorPartsUpdater::SOURCE,
                'status' => 'pending',
                'message' => 'Запуск обновления TCARS.',
            ]);

        $hasAnotherRunning = CompetitorCatalogRun::query()
            ->where('source', TcarserviceCompetitorPartsUpdater::SOURCE)
            ->active()
            ->where('id', '!=', $run->id)
            ->exists();

        if ($hasAnotherRunning) {
            $run->forceFill([
                'status' => 'failed',
                'message' => 'Обновление TCARS уже запущено.',
                'error' => 'Another TCARS competitor refresh is already running.',
                'finished_at' => now(),
            ])->save();

            $this->warn('Another TCARS competitor refresh is already running.');

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
                'message' => 'Ошибка обновления TCARS: '.$exception->getMessage(),
                'error' => $exception->getMessage(),
                'finished_at' => now(),
            ])->save();

            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
