<?php

namespace App\Console\Commands;

use App\Models\PartCatalogItem;
use App\Models\Product;
use App\Support\PartCatalogLocalizedNameCleaner;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CleanNikolaCarsLocalizedNames extends Command
{
    protected $signature = 'parts:clean-nikolacars-localized-names
        {--apply : Save cleanup changes}
        {--limit=0 : Maximum items to inspect}';

    protected $description = 'Remove NikolaCars model/year and Tesla markers from RU/UA catalog names.';

    protected const MODEL_MARKER_PATTERN = '/(?<![\pL\pN])(?:M3\s*\/\s*\/\s*MX|MSR\s*[-\s]*2012\s*[-\x{2013}\x{2014}]\s*2015|MSR|[M\x{041C}]S\s*REST|[M\x{041C}]S\s*[-\s]*2012\s*[-\x{2013}\x{2014}]\s*2015|[M\x{041C}]S\s*[-\s]*2015\s*[-\x{2013}\x{2014}]\s*2021|[M\x{041C}]S\s*[-\s]*2016\s*[-\x{2013}\x{2014}]\s*2021|MY\s*07\s*\/\s*2020|\x{041C}\x{0423}\s*07\s*\/\s*2020|\x{041C}\x{0423}\s*09\.21|\x{041C}\x{0423}\s*11\.20|\x{041C}\x{0423}\s*11\.2020|MY\s*2021|MY\s*[-\s]*2020\s*[-\x{2013}\x{2014}]\s*2023|MY|M3\s*[-\s]*2012\s*[-\x{2013}\x{2014}]\s*2020|M3\s*[-\s]*2017\s*[-\x{2013}\x{2014}]\s*2023|M3\s*[-\s]*2018\s*[-\x{2013}\x{2014}]\s*2021|M3\s*[-\s]*2018\s*[-\x{2013}\x{2014}]\s*2023|M3\s*2019|M3\s*2018|M3|MX\s*[-\s]*2015\s*[-\x{2013}\x{2014}]\s*2021|MX\s*[-\s]*2016\s*[-\x{2013}\x{2014}]\s*2021|2015\s*[-\x{2013}\x{2014}]\s*2021|2016\s*[-\x{2013}\x{2014}]\s*2020|2016\s*[-\x{2013}\x{2014}]\s*2021|2020|C|[M\x{041C}]S)(?![\pL\pN])/iu';

    protected const TESLA_MARKER_PATTERN = '/(?<![\pL\pN])(?:Tesla|\x{0422}\x{0435}\x{0441}\x{043B}\x{0430})(?![\pL\pN])/iu';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $limit = max(0, (int) $this->option('limit'));

        $stats = [
            'items_seen' => 0,
            'items_changed' => 0,
            'products_seen' => 0,
            'products_changed' => 0,
            'name_updated' => 0,
            'name_ru_updated' => 0,
            'name_ua_updated' => 0,
            'item_notes_updated' => 0,
            'product_description_updated' => 0,
        ];

        $this->query($limit)->chunkById(500, function (Collection $items) use ($apply, &$stats): void {
            foreach ($items as $item) {
                $stats['items_seen']++;
                $markers = [];
                $updates = [];

                foreach (['name', 'name_ru', 'name_ua'] as $column) {
                    $markers = array_merge($markers ?? [], $this->modelMarkers($item->{$column}));
                    $cleaned = $this->cleanName($item->{$column});

                    if ($cleaned !== $item->{$column}) {
                        $updates[$column] = $cleaned;
                        $stats[$column.'_updated']++;
                    }
                }

                $markers = $this->uniqueMarkers($markers ?? []);
                $notes = $this->descriptionWithMarkers($item->notes_ua, $markers);

                if ($notes !== $item->notes_ua) {
                    $updates['notes_ua'] = $notes;
                    $stats['item_notes_updated']++;
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

        $this->productQuery($limit)->chunkById(500, function (Collection $products) use ($apply, &$stats): void {
            foreach ($products as $product) {
                $stats['products_seen']++;
                $markers = $this->modelMarkers($product->name);
                $cleaned = $this->cleanName($product->name);
                $description = $this->descriptionWithMarkers($product->description, $markers);

                if ($cleaned === $product->name && $description === $product->description) {
                    continue;
                }

                $stats['products_changed']++;

                if ($description !== $product->description) {
                    $stats['product_description_updated']++;
                }

                if ($apply) {
                    $updates = [];

                    if ($cleaned !== $product->name) {
                        $updates['name'] = $cleaned;
                    }

                    if ($description !== $product->description) {
                        $updates['description'] = $description;
                    }

                    $product->forceFill($updates)->save();
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
            ->where('source', 'nikolacars')
            ->where(function ($query): void {
                $query
                    ->where(fn ($query) => $query->whereNotNull('name_ru')->where('name_ru', '!=', ''))
                    ->orWhere(fn ($query) => $query->whereNotNull('name_ua')->where('name_ua', '!=', ''));
            })
            ->when($limit > 0, fn ($query) => $query->limit($limit))
            ->orderBy('id')
            ->select(['id', 'source', 'name', 'name_ru', 'name_ua', 'notes_ua']);
    }

    protected function productQuery(int $limit)
    {
        return Product::query()
            ->whereNotNull('source_part_catalog_item_id')
            ->whereHas(
                'sourcePartCatalogItem',
                fn ($query) => $query->where('source', 'nikolacars')
            )
            ->whereNotNull('name')
            ->where('name', '!=', '')
            ->when($limit > 0, fn ($query) => $query->limit($limit))
            ->orderBy('id')
            ->select(['id', 'name', 'description', 'source_part_catalog_item_id']);
    }

    protected function cleanName(mixed $value): ?string
    {
        $cleaned = PartCatalogLocalizedNameCleaner::clean($value);

        if ($cleaned === null) {
            return null;
        }

        $cleaned = preg_replace(self::MODEL_MARKER_PATTERN, ' ', $cleaned) ?? $cleaned;
        $cleaned = preg_replace(self::TESLA_MARKER_PATTERN, ' ', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\s+([,.;:])/u', '$1', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\s{2,}/u', ' ', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/^[\s,.;:\-\x{2013}]+|[\s,.;:\-\x{2013}]+$/u', '', $cleaned) ?? $cleaned;
        $cleaned = trim($cleaned);

        return $cleaned !== '' ? Str::limit($cleaned, 255, '') : null;
    }

    protected function modelMarkers(mixed $value): array
    {
        $value = (string) $value;

        if ($value === '') {
            return [];
        }

        preg_match_all(self::MODEL_MARKER_PATTERN, $value, $matches);

        return $this->uniqueMarkers($matches[0] ?? []);
    }

    protected function uniqueMarkers(array $markers): array
    {
        return collect($markers)
            ->map(fn (mixed $marker): string => trim((string) preg_replace('/\s+/u', ' ', (string) $marker)))
            ->filter()
            ->unique(fn (string $marker): string => Str::lower($marker))
            ->values()
            ->all();
    }

    protected function descriptionWithMarkers(mixed $description, array $markers): ?string
    {
        $description = trim((string) $description);
        $markers = collect($this->uniqueMarkers($markers))
            ->reject(fn (string $marker): bool => Str::contains(Str::lower($description), Str::lower($marker)))
            ->values();

        if ($markers->isEmpty()) {
            return $description !== '' ? $description : null;
        }

        $line = 'Model: '.$markers->implode(', ');
        $description = $description !== '' ? $description.PHP_EOL.$line : $line;

        return Str::limit($description, 65535, '');
    }
}
