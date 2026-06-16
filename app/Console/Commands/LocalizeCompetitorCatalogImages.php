<?php

namespace App\Console\Commands;

use App\Services\CompetitorCatalogImageLocalizer;
use Illuminate\Console\Command;

class LocalizeCompetitorCatalogImages extends Command
{
    protected $signature = 'catalog:localize-competitor-images {source? : Source key} {--limit=0 : Max items per source}';

    protected $description = 'Download competitor catalog images to local public storage and replace image_urls with local paths.';

    public function handle(CompetitorCatalogImageLocalizer $localizer): int
    {
        $source = $this->argument('source');
        $sources = $source !== null && $source !== ''
            ? [(string) $source]
            : CompetitorCatalogImageLocalizer::SOURCES;
        $limit = max(0, (int) $this->option('limit'));

        foreach ($sources as $source) {
            $this->line("Localizing {$source} images...");

            $stats = $localizer->localizeSource($source, [
                'limit' => $limit,
                'progress' => fn (string $message) => $this->line($message),
            ]);

            $this->line(json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }

        return self::SUCCESS;
    }
}
