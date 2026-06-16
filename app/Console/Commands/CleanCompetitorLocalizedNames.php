<?php

namespace App\Console\Commands;

use App\Models\PartCatalogItem;
use App\Services\TskCatalogImporter;
use App\Support\PartCatalogRawAttributes;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CleanCompetitorLocalizedNames extends Command
{
    protected $signature = 'catalog:clean-competitor-localized-names
        {--apply : Save cleanup changes}
        {--source= : Limit cleanup to one source}
        {--limit=0 : Maximum items to inspect}';

    protected $description = 'Clear competitor catalog RU/UA names that do not match the source page language.';

    protected const SOURCES = [
        'dkparts',
        'driveparts',
        'erazborka',
        'stock-tesla',
        'tcarservice',
        'teslacompany',
        'teslahelp',
        'teslapartsukraine',
        'toprazborka',
        'teslawestparts',
        'tsk',
    ];

    protected const RU_PATH_SOURCES = [
        'dkparts',
        'driveparts',
        'stock-tesla',
        'tcarservice',
        'tsk',
    ];

    protected const UA_PATH_SOURCES = [
        'teslacompany',
        'erazborka',
        'teslawestparts',
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $source = trim((string) $this->option('source'));
        $limit = max(0, (int) $this->option('limit'));

        if ($source !== '' && ! in_array($source, self::SOURCES, true)) {
            $this->error('Unsupported source. Allowed: '.implode(', ', self::SOURCES));

            return self::FAILURE;
        }

        $stats = [
            'items_seen' => 0,
            'items_changed' => 0,
            'name_ru_cleared' => 0,
            'name_ua_cleared' => 0,
        ];

        $bySource = [];

        $this->query($source, $limit)->chunkById(500, function (Collection $items) use ($apply, &$stats, &$bySource): void {
            foreach ($items as $item) {
                $stats['items_seen']++;
                $rawAttributes = $this->rawAttributes($item);
                $updates = [];

                foreach (['ru', 'ua'] as $locale) {
                    $column = 'name_'.$locale;
                    $name = trim((string) $item->{$column});

                    if ($name === '') {
                        continue;
                    }

                    if ($this->shouldClear($item, $rawAttributes, $locale, $name)) {
                        $updates[$column] = null;
                        $rawAttributes = $this->withoutLocaleNameSource($rawAttributes, $locale);
                        $stats[$column.'_cleared']++;
                    }
                }

                if ($updates === []) {
                    continue;
                }

                $stats['items_changed']++;
                $bySource[$item->source] ??= ['items_changed' => 0, 'name_ru_cleared' => 0, 'name_ua_cleared' => 0];
                $bySource[$item->source]['items_changed']++;
                $bySource[$item->source]['name_ru_cleared'] += array_key_exists('name_ru', $updates) ? 1 : 0;
                $bySource[$item->source]['name_ua_cleared'] += array_key_exists('name_ua', $updates) ? 1 : 0;

                if ($apply) {
                    $updates['raw_attributes'] = $rawAttributes;
                    $item->forceFill($updates)->save();
                }
            }
        });

        $this->table(
            ['metric', 'count'],
            collect($stats)->map(fn (int $count, string $metric): array => [$metric, $count])->values()->all()
        );

        if ($bySource !== []) {
            $this->table(
                ['source', 'items_changed', 'name_ru_cleared', 'name_ua_cleared'],
                collect($bySource)
                    ->map(fn (array $row, string $source): array => [$source, $row['items_changed'], $row['name_ru_cleared'], $row['name_ua_cleared']])
                    ->values()
                    ->all()
            );
        }

        if (! $apply) {
            $this->warn('Dry run only. Re-run with --apply to clear these fields.');
        }

        return self::SUCCESS;
    }

    protected function query(string $source, int $limit)
    {
        return PartCatalogItem::query()
            ->whereIn('source', $source !== '' ? [$source] : self::SOURCES)
            ->where(function ($query): void {
                $query
                    ->whereNotNull('name_ru')
                    ->where('name_ru', '!=', '')
                    ->orWhere(function ($query): void {
                        $query->whereNotNull('name_ua')->where('name_ua', '!=', '');
                    });
            })
            ->when($limit > 0, fn ($query) => $query->limit($limit))
            ->orderBy('id')
            ->select(array_values(array_filter([
                'id',
                'source',
                'source_url',
                'name',
                'name_ru',
                'name_ua',
                'raw_attributes',
                Schema::hasColumn('part_catalog_items', 'name_ru_manually_locked_at') ? 'name_ru_manually_locked_at' : null,
            ])));
    }

    protected function shouldClear(PartCatalogItem $item, array $rawAttributes, string $locale, string $name): bool
    {
        if ($this->hasForeignNameSource($item, $rawAttributes, $locale)) {
            return true;
        }

        if ($item->source === 'tsk') {
            $payload = app(TskCatalogImporter::class)->localizedNamePayload((string) $item->name);
            $column = 'name_'.$locale;

            return ! array_key_exists($column, $payload)
                || trim((string) $payload[$column]) !== $name;
        }

        return ! $this->canHaveLocale($item, $rawAttributes, $locale);
    }

    protected function canHaveLocale(PartCatalogItem $item, array $rawAttributes, string $locale): bool
    {
        $source = (string) $item->source;

        if ($source === 'teslapartsukraine') {
            return $locale === 'ua';
        }

        if ($source === 'teslahelp') {
            return $locale === 'ru';
        }

        if (in_array($source, array_merge(self::RU_PATH_SOURCES, self::UA_PATH_SOURCES), true)) {
            return true;
        }

        if ($this->explicitLocaleUrl($rawAttributes, $locale) !== null) {
            return true;
        }

        foreach ($this->candidateUrls($item, $rawAttributes) as $url) {
            if ($this->localeFromUrl($source, $url) === $locale) {
                return true;
            }
        }

        return false;
    }

    protected function hasForeignNameSource(PartCatalogItem $item, array $rawAttributes, string $locale): bool
    {
        $sourceItemId = data_get($rawAttributes, 'name_source_item_id_'.$locale);

        if ($sourceItemId === null || (string) $sourceItemId === '') {
            return false;
        }

        if ((int) $sourceItemId === (int) $item->id) {
            return false;
        }

        $sourceItem = PartCatalogItem::query()->find((int) $sourceItemId, ['id', 'source']);

        return ! $sourceItem instanceof PartCatalogItem
            || (string) $sourceItem->source !== (string) $item->source;
    }

    protected function withoutLocaleNameSource(array $rawAttributes, string $locale): array
    {
        unset(
            $rawAttributes['name_source_url_'.$locale],
            $rawAttributes['name_source_site_'.$locale],
            $rawAttributes['name_source_item_id_'.$locale]
        );

        if ($locale === 'ru') {
            unset($rawAttributes['name_source_url'], $rawAttributes['name_source_site']);
        }

        return array_filter($rawAttributes, fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    protected function explicitLocaleUrl(array $rawAttributes, string $locale): ?string
    {
        foreach ([
            'source_url_'.$locale,
            'url_'.$locale,
            'name_source_url_'.$locale,
        ] as $key) {
            $url = data_get($rawAttributes, $key);

            if (is_string($url) && Str::startsWith($url, ['http://', 'https://'])) {
                return $url;
            }
        }

        return null;
    }

    protected function candidateUrls(PartCatalogItem $item, array $rawAttributes): array
    {
        return collect([
            $item->source_url,
            data_get($rawAttributes, 'page_url'),
            data_get($rawAttributes, 'product_url'),
            data_get($rawAttributes, 'buy_url'),
            data_get($rawAttributes, 'teslahelp_page_url'),
            data_get($rawAttributes, 'competitor_source_url'),
        ])
            ->merge((array) data_get($rawAttributes, 'source_urls', []))
            ->merge((array) data_get($rawAttributes, 'product_source_urls', []))
            ->filter(fn (mixed $url): bool => is_string($url) && Str::startsWith($url, ['http://', 'https://']))
            ->map(fn (string $url): string => $url)
            ->unique()
            ->values()
            ->all();
    }

    protected function localeFromUrl(string $source, string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (in_array($source, self::UA_PATH_SOURCES, true)) {
            return str_starts_with($path, '/ua/') ? 'ua' : 'ru';
        }

        if (in_array($source, self::RU_PATH_SOURCES, true)) {
            return str_starts_with($path, '/ru/') ? 'ru' : 'ua';
        }

        return 'ua';
    }

    protected function rawAttributes(PartCatalogItem $item): array
    {
        return PartCatalogRawAttributes::from($item);
    }
}
