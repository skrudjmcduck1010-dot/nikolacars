<?php

namespace App\Console\Commands;

use App\Models\PartCatalogItem;
use App\Services\NikolaCarsOfficialPartMatch;
use App\Services\NikolaCarsOfficialPartMatcher;
use App\Services\NikolaCarsProductInventorySyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class SyncNikolaCarsLocalizedNamesFromTeslaOfficial extends Command
{
    protected $signature = 'parts:sync-nikolacars-localized-names-from-tesla-official
        {--apply : Save changes}
        {--limit=0 : Maximum NikolaCars items to inspect}';

    protected $description = 'Synchronize NikolaCars RU/UA part names from Tesla official catalog by exact article or seven digit prefix.';

    public function handle(NikolaCarsOfficialPartMatcher $matcher): int
    {
        $apply = (bool) $this->option('apply');
        $limit = max(0, (int) $this->option('limit'));
        $stats = [
            'items_seen' => 0,
            'official_exact_matches' => 0,
            'official_prefix_matches' => 0,
            'official_missing' => 0,
            'items_changed' => 0,
            'name_ru_changed' => 0,
            'name_ua_changed' => 0,
        ];

        $this->query($limit)->chunkById(500, function (Collection $items) use ($apply, $matcher, &$stats): void {
            foreach ($items as $item) {
                $stats['items_seen']++;

                $match = $matcher->match($item->part_number);
                if (! $match->matched() || ! $match->officialItem instanceof PartCatalogItem) {
                    $stats['official_missing']++;

                    continue;
                }

                if ($match->matchType === NikolaCarsOfficialPartMatch::TYPE_EXACT) {
                    $stats['official_exact_matches']++;
                } elseif ($match->matchType === NikolaCarsOfficialPartMatch::TYPE_SEVEN_DIGIT_PREFIX) {
                    $stats['official_prefix_matches']++;
                }

                $updates = [];
                foreach (['name_ru', 'name_ua'] as $column) {
                    $officialName = trim((string) $match->officialItem->{$column});
                    $currentName = trim((string) $item->{$column});
                    $officialValue = $officialName !== '' ? $officialName : null;

                    if (($currentName !== '' ? $currentName : null) !== $officialValue) {
                        $updates[$column] = $officialValue;
                        $stats[$column.'_changed']++;
                    }
                }

                if ($updates === []) {
                    continue;
                }

                $stats['items_changed']++;

                if ($apply) {
                    $item->forceFill($updates)->save();
                }
            }
        });

        $this->table(
            ['metric', 'count'],
            collect($stats)->map(fn (int $count, string $metric): array => [$metric, $count])->values()->all()
        );

        if (! $apply) {
            $this->warn('Dry run only. Re-run with --apply to save these changes.');
        }

        return self::SUCCESS;
    }

    protected function query(int $limit)
    {
        return PartCatalogItem::query()
            ->where('source', NikolaCarsProductInventorySyncService::SOURCE)
            ->whereNotNull('part_number')
            ->where('part_number', '!=', '')
            ->when($limit > 0, fn ($query) => $query->limit($limit))
            ->orderBy('id')
            ->select(['id', 'source', 'part_number', 'name_ru', 'name_ua']);
    }
}
