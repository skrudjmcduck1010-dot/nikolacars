<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PartCatalogItem;
use App\Services\PartCatalogFilterService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ErrorController extends Controller
{
    protected const ARTICLE_PATTERN_EXAMPLE = '1104422-10-K';

    public function __invoke(Request $request, PartCatalogFilterService $partCatalogFilter): View
    {
        $search = trim((string) $request->query('search', ''));
        $source = trim((string) $request->query('source', ''));
        $sources = collect(config('catalog_sources.sources', []))
            ->pluck('source')
            ->values()
            ->all();

        $itemsQuery = PartCatalogItem::query()
            ->select([
                'id',
                'source',
                'source_url',
                'part_number',
                'name',
                'name_ru',
                'name_ua',
                'model_label',
                'raw_attributes',
                'updated_at',
            ])
            ->when(in_array($source, $sources, true), fn (Builder $query) => $query->where('source', $source))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('part_number', 'like', '%'.$search.'%')
                        ->orWhere('name', 'like', '%'.$search.'%')
                        ->orWhere('name_ru', 'like', '%'.$search.'%')
                        ->orWhere('name_ua', 'like', '%'.$search.'%');
                });
            });

        $this->whereLocalizedNameConflict($itemsQuery, $partCatalogFilter);

        $items = $itemsQuery
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        $totalConflictItemsQuery = PartCatalogItem::query();
        $this->whereLocalizedNameConflict($totalConflictItemsQuery, $partCatalogFilter);
        $totalConflictItems = $totalConflictItemsQuery->count();

        return view('admin.errors.index', [
            'items' => $items,
            'search' => $search,
            'source' => $source,
            'sources' => $sources,
            'sourceLabels' => $this->sourceLabels(),
            'totalConflictItems' => $totalConflictItems,
            'articlePatternExample' => self::ARTICLE_PATTERN_EXAMPLE,
            'longSimilarArticleItems' => $this->longSimilarArticleItems(),
            'localizedOriginMarkerItems' => $this->localizedOriginMarkerItems(),
            'teslaNameItems' => $this->teslaNameItems(),
            'modelNameItems' => $this->modelNameItems(),
            'itemUrl' => fn (PartCatalogItem $item): string => $this->itemUrl($item),
        ]);
    }

    protected function whereLocalizedNameConflict(Builder $query, PartCatalogFilterService $partCatalogFilter): Builder
    {
        return $partCatalogFilter->applyCompetitorNameFilter($query, 'conflict');
    }

    protected function sourceLabels(): array
    {
        return collect(config('catalog_sources.sources', []))
            ->mapWithKeys(fn (array $catalog): array => [
                (string) $catalog['source'] => (string) ($catalog['source_label'] ?? $catalog['heading'] ?? $catalog['source']),
            ])
            ->all();
    }

    protected function itemUrl(PartCatalogItem $item): string
    {
        $routePrefix = (string) data_get(config('catalog_sources.sources.'.$item->source), 'route_prefix', 'admin.part-catalog');

        return route($routePrefix.'.show', $item);
    }

    protected function longSimilarArticleItems()
    {
        $query = PartCatalogItem::query()
            ->select([
                'id',
                'source',
                'source_url',
                'part_number',
                'name',
                'name_ru',
                'name_ua',
                'model_label',
                'updated_at',
            ])
            ->whereNotNull('part_number');

        $this->whereLongSimilarArticle($query);

        return $query
            ->orderBy('part_number')
            ->orderByDesc('updated_at')
            ->paginate(50, ['*'], 'long_articles_page')
            ->withQueryString();
    }

    protected function localizedOriginMarkerItems()
    {
        $query = PartCatalogItem::query()
            ->select([
                'id',
                'source',
                'source_url',
                'part_number',
                'name',
                'name_ru',
                'name_ua',
                'model_label',
                'updated_at',
            ]);

        $this->whereLocalizedOriginMarker($query);

        return $query
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(10, ['*'], 'origin_markers_page')
            ->withQueryString();
    }

    protected function teslaNameItems()
    {
        $query = PartCatalogItem::query()
            ->select([
                'id',
                'source',
                'source_url',
                'part_number',
                'name',
                'name_ru',
                'name_ua',
                'model_label',
                'updated_at',
            ]);

        $this->whereTeslaName($query);

        return $query
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(50, ['*'], 'tesla_names_page')
            ->withQueryString();
    }

    protected function modelNameItems()
    {
        $query = PartCatalogItem::query()
            ->select([
                'id',
                'source',
                'source_url',
                'part_number',
                'name',
                'name_ru',
                'name_ua',
                'model_label',
                'updated_at',
            ]);

        $this->whereModelName($query);

        return $query
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(50, ['*'], 'model_names_page')
            ->withQueryString();
    }

    protected function whereTeslaName(Builder $query): Builder
    {
        $terms = $this->teslaNameTerms();
        $columns = ['name_ru', 'name_ua'];

        return $query->where(function (Builder $query) use ($columns, $terms): void {
            foreach ($columns as $column) {
                foreach ($terms as $term) {
                    $query->orWhere($column, 'like', '%'.$term.'%');
                }
            }
        });
    }

    protected function whereModelName(Builder $query): Builder
    {
        $terms = $this->modelNameTerms();
        $columns = ['name_ru', 'name_ua'];

        return $query->where(function (Builder $query) use ($columns, $terms): void {
            foreach ($columns as $column) {
                foreach ($terms as $term) {
                    $query->orWhere($column, 'like', '%'.$term.'%');
                }
            }
        });
    }

    protected function teslaNameTerms(): array
    {
        $terms = [];
        $cyrillicTesla = html_entity_decode('&#1090;&#1077;&#1089;&#1083;&#1072;', ENT_QUOTES, 'UTF-8');

        foreach (['tesla', $cyrillicTesla] as $term) {
            foreach ($this->caseVariants($term) as $variant) {
                $terms[] = $variant;

                if ($term === $cyrillicTesla) {
                    $terms[] = $this->toWindows1251Mojibake($variant);
                }
            }
        }

        return array_values(array_unique(array_filter($terms)));
    }

    protected function modelNameTerms(): array
    {
        $terms = [];
        $cyrillicModel = html_entity_decode('&#1084;&#1086;&#1076;&#1077;&#1083;&#1100;', ENT_QUOTES, 'UTF-8');

        foreach (['model', $cyrillicModel] as $term) {
            foreach ($this->caseVariants($term) as $variant) {
                $terms[] = $variant;

                if ($term === $cyrillicModel) {
                    $terms[] = $this->toWindows1251Mojibake($variant);
                }
            }
        }

        return array_values(array_unique(array_filter($terms)));
    }

    protected function whereLocalizedOriginMarker(Builder $query): Builder
    {
        $terms = [
            'оригинал',
            'Оригинал',
            'ОРИГИНАЛ',
            'оригінал',
            'Оригінал',
            'ОРИГІНАЛ',
            'аналог',
            'Аналог',
            'АНАЛОГ',
        ];
        $usedTerms = $this->usedConditionTerms();

        return $query->where(function (Builder $query) use ($terms, $usedTerms): void {
            foreach (['name_ru', 'name_ua'] as $column) {
                foreach ($terms as $term) {
                    $query->orWhere($column, 'like', '%'.$term.'%');
                }

                foreach ($usedTerms as $term) {
                    $query->orWhere($column, 'like', '% '.$term.' %');
                }
            }
        });
    }

    protected function usedConditionTerms(): array
    {
        $terms = [];

        foreach (['бу', 'б/у'] as $term) {
            foreach ($this->caseVariants($term) as $variant) {
                $terms[] = $variant;
                $terms[] = $this->toWindows1251Mojibake($variant);
            }
        }

        return array_values(array_unique(array_filter($terms)));
    }

    protected function caseVariants(string $term): array
    {
        $variants = [''];
        $letters = preg_split('//u', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($letters as $letter) {
            if (! preg_match('/\pL/u', $letter)) {
                $variants = array_map(fn (string $variant): string => $variant.$letter, $variants);

                continue;
            }

            $next = [];
            foreach ($variants as $variant) {
                $next[] = $variant.mb_strtolower($letter, 'UTF-8');
                $next[] = $variant.mb_strtoupper($letter, 'UTF-8');
            }

            $variants = $next;
        }

        return $variants;
    }

    protected function toWindows1251Mojibake(string $value): ?string
    {
        $mojibake = mb_convert_encoding($value, 'UTF-8', 'Windows-1251');

        return is_string($mojibake) && $mojibake !== '' ? $mojibake : null;
    }

    protected function whereLongSimilarArticle(Builder $query): Builder
    {
        $driver = DB::connection()->getDriverName();
        $lengthExpression = $driver === 'mysql'
            ? 'CHAR_LENGTH(TRIM(part_number))'
            : 'LENGTH(TRIM(part_number))';

        if ($driver === 'pgsql') {
            return $query
                ->whereRaw($lengthExpression.' > ?', [mb_strlen(self::ARTICLE_PATTERN_EXAMPLE)])
                ->whereRaw("UPPER(TRIM(part_number)) ~ '^[0-9]{7}-[0-9]{2}-[A-Z]'");
        }

        if ($driver === 'sqlite') {
            return $query
                ->whereRaw($lengthExpression.' > ?', [mb_strlen(self::ARTICLE_PATTERN_EXAMPLE)])
                ->whereRaw("UPPER(TRIM(part_number)) GLOB '[0-9][0-9][0-9][0-9][0-9][0-9][0-9]-[0-9][0-9]-[A-Z]*'");
        }

        return $query
            ->whereRaw($lengthExpression.' > ?', [mb_strlen(self::ARTICLE_PATTERN_EXAMPLE)])
            ->whereRaw("UPPER(TRIM(part_number)) REGEXP '^[0-9]{7}-[0-9]{2}-[A-Z]'");
    }
}
