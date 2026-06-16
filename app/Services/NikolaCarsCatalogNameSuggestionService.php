<?php

namespace App\Services;

use App\Models\PartCatalogItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NikolaCarsCatalogNameSuggestionService
{
    public function suggestions(string $query, callable $displayItemName, callable $displayDescription): Collection
    {
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            return collect();
        }

        $normalizedQuery = mb_strtolower($query);
        $likeQuery = '%'.$normalizedQuery.'%';
        $prefixQuery = $normalizedQuery.'%';
        $partNumberQuery = app(PartCatalogSearchService::class)->compactPartNumberSearch($query);
        $items = collect();

        foreach ($this->sourceGroups() as $group => $sourceList) {
            $builder = $group === 'nikolacars'
                ? app(NikolaCarsInventoryService::class)->activeItemsQuery()
                : PartCatalogItem::query()->whereIn('source', $sourceList);

            if ($group === 'tesla_official') {
                app(PartCatalogSourceQueryService::class)->whereRealTeslaOfficialItem($builder);
            }

            $builder
                ->where(function (Builder $search) use ($likeQuery, $partNumberQuery): void {
                    $this->whereTextMatch($search, [
                        'name',
                        'name_ru',
                        'name_ua',
                        'name_en',
                        'part_number',
                        'model_label',
                        'main_category_name',
                        'subcategory_name',
                        'node_name',
                    ], $likeQuery);

                    if ($partNumberQuery !== '') {
                        $search->orWhereRaw("lower(replace(replace(part_number, '-', ''), ' ', '')) like ?", ['%'.mb_strtolower($partNumberQuery).'%']);
                    }
                })
                ->orderByRaw(
                    $this->prefixRankSql(['part_number', 'name_ua', 'name_ru', 'name', 'name_en']),
                    array_fill(0, 5, $prefixQuery)
                )
                ->orderByRaw('part_number is null')
                ->orderBy('part_number')
                ->orderBy('name')
                ->limit(8)
                ->get([
                    'id',
                    'source',
                    'part_number',
                    'name',
                    'name_ru',
                    'name_ua',
                    'name_en',
                    'price_amount',
                    'currency',
                    'model_label',
                    'model_name',
                    'main_category_name',
                    'subcategory_name',
                    'node_name',
                    'notes_ru',
                    'notes_ua',
                    'raw_attributes',
                ])
                ->each(fn (PartCatalogItem $item) => $items->push($item));
        }

        $seen = [];

        return $items
            ->map(fn (PartCatalogItem $item): array => $this->payload($item, $displayItemName, $displayDescription))
            ->filter(function (array $item) use (&$seen): bool {
                $key = Str::lower(trim(($item['part_number'] ?: '').'|'.$item['name'].'|'.$item['source_group']));

                if (isset($seen[$key])) {
                    return false;
                }

                $seen[$key] = true;

                return true;
            })
            ->take(18)
            ->values();
    }

    protected function payload(PartCatalogItem $item, callable $displayItemName, callable $displayDescription): array
    {
        $category = collect([$item->main_category_name, $item->subcategory_name, $item->node_name])
            ->filter()
            ->implode(' / ');
        $price = $item->price_amount !== null
            ? number_format((float) $item->price_amount, 2, '.', ' ').' '.($item->currency ?: 'USD')
            : null;
        $sourceGroup = match ($item->source) {
            'nikolacars' => 'nikolacars',
            'tesla_official' => 'tesla_official',
            default => 'competitor',
        };

        return [
            'id' => $item->id,
            'source' => $item->source,
            'source_group' => $sourceGroup,
            'source_label' => $this->sourceLabel($item->source),
            'name' => $displayItemName($item),
            'part_number' => $item->part_number,
            'price_amount' => $item->price_amount,
            'currency' => $item->currency ?: 'USD',
            'price_text' => $price,
            'model' => $item->model_label ?: $item->model_name,
            'category' => $category,
            'description' => $displayDescription($item, $item->notes_ru ?: $item->notes_ua ?: $category),
        ];
    }

    protected function sourceGroups(): array
    {
        return [
            'nikolacars' => ['nikolacars'],
            'tesla_official' => ['tesla_official'],
            'competitors' => collect(config('catalog_sources.sources', []))
                ->keys()
                ->reject(fn (string $source): bool => in_array($source, ['nikolacars', 'tesla_official'], true))
                ->values()
                ->all(),
        ];
    }

    protected function whereTextMatch(Builder $query, array $columns, string $likeQuery): void
    {
        $grammar = DB::connection()->getQueryGrammar();

        foreach (array_values($columns) as $index => $column) {
            $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';

            $query->{$method}('lower('.$grammar->wrap($column).') like ?', [$likeQuery]);
        }
    }

    protected function prefixRankSql(array $columns): string
    {
        $grammar = DB::connection()->getQueryGrammar();
        $checks = collect($columns)
            ->map(fn (string $column): string => 'lower('.$grammar->wrap($column).') like ?')
            ->implode(' or ');

        return 'case when '.$checks.' then 0 else 1 end';
    }

    protected function sourceLabel(string $source): string
    {
        return match ($source) {
            'nikolacars' => 'NikolaCars',
            'tesla_official' => 'Tesla.com',
            'tcarservice' => 'TCARS',
            'teslapartsukraine' => 'TeslaPartsUkraine',
            'stock-tesla' => 'Stock Tesla',
            'teslahelp' => 'TeslaHelp',
            'driveparts' => 'DriveParts',
            'dkparts' => 'DK-Parts',
            'erazborka' => 'Erazborka',
            'toprazborka' => 'TopRazborka',
            'teslawestparts' => 'Tesla West Parts',
            'teslacompany' => 'TeslaCompany',
            'tsk' => 'TSK',
            default => $source,
        };
    }
}
