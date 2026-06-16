<?php

namespace App\Console\Commands;

use App\Services\TeslaOfficialCatalogImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ImportTeslaOfficialVinSnapshot extends Command
{
    protected $signature = 'parts:import-tesla-official-vin-snapshot
        {snapshotPath}
        {--recommendation-type=* : Import only selected recommendation types, for example NOT_RECOMMENDED}
        {--missing-only : Skip part numbers already present in the Tesla official catalog}
        {--dry-run : Show what would change without writing to the database}';

    protected $description = 'Import Tesla official catalog items from a saved VIN catalog snapshot.';

    public function handle(TeslaOfficialCatalogImporter $importer): int
    {
        $snapshotPath = $this->snapshotPath((string) $this->argument('snapshotPath'));
        if (! is_file($snapshotPath)) {
            $this->error("Snapshot file not found: {$snapshotPath}");

            return self::FAILURE;
        }

        $contents = (string) file_get_contents($snapshotPath);
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $snapshot = json_decode($contents, true);
        if (! is_array($snapshot)) {
            $this->error('Snapshot JSON is invalid.');

            return self::FAILURE;
        }

        $recommendationTypes = collect((array) $this->option('recommendation-type'))
            ->map(fn (mixed $type): string => Str::upper(trim((string) $type)))
            ->filter()
            ->values()
            ->all();

        $stats = $importer->importBrowserSnapshot($snapshot, [
            'dry_run' => (bool) $this->option('dry-run'),
            'missing_only' => (bool) $this->option('missing-only'),
            'recommendation_types' => $recommendationTypes,
            'skip_translations' => true,
            'raw_attributes_extra' => [
                'vin_snapshot_imported_at' => now()->toIso8601String(),
                'vin_snapshot_vin' => Str::upper(trim((string) ($snapshot['vin'] ?? ''))),
            ],
        ]);

        $this->line('Snapshot VIN: '.Str::upper(trim((string) ($snapshot['vin'] ?? ''))));
        $this->line('Recommendation types: '.($recommendationTypes !== [] ? implode(', ', $recommendationTypes) : 'ALL'));
        $this->line('Missing only: '.($this->option('missing-only') ? 'yes' : 'no'));
        $this->line('Dry run: '.($this->option('dry-run') ? 'yes' : 'no'));

        foreach ($stats as $name => $value) {
            $this->line(" - {$name}: {$value}");
        }

        return self::SUCCESS;
    }

    protected function snapshotPath(string $path): string
    {
        if (preg_match('/^[A-Z]:\\\\/i', $path) === 1 || str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return base_path($path);
    }
}
