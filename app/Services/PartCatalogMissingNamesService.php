<?php

namespace App\Services;

use App\Models\PartCatalogItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PartCatalogMissingNamesService
{
    protected const CYBERTRUCK_MODEL_LABEL = 'Cybertruck';

    protected static array $blankNameColumns = [];

    public function whereBlank(Builder $query, string $column): Builder
    {
        $blankColumn = match ($column) {
            'name_ru' => 'name_ru_blank',
            'name_ua' => 'name_ua_blank',
            default => null,
        };

        if ($blankColumn !== null
            && DB::connection()->getDriverName() === 'mysql'
            && $this->hasBlankNameColumn($blankColumn)) {
            return $query->where($blankColumn, 1);
        }

        return $query->where(fn (Builder $builder) => $builder
            ->whereNull($column)
            ->orWhere($column, ''));
    }

    public function whereLongPartNumber(Builder $query, string $driver): Builder
    {
        $lengthExpression = $driver === 'mysql'
            ? 'CHAR_LENGTH(TRIM(part_number))'
            : 'LENGTH(TRIM(part_number))';

        return $query
            ->whereNotNull('part_number')
            ->whereRaw($lengthExpression.' > ?', [12]);
    }

    public function canUsePaginator(string $query, array $missingNames, array $productFilters, string $nameSource, ?string $priceSort): bool
    {
        return $query === ''
            && $productFilters === []
            && $nameSource === ''
            && $priceSort === null
            && in_array('ru', $missingNames, true)
            && in_array('ua', $missingNames, true)
            && DB::connection()->getDriverName() === 'mysql'
            && $this->hasBlankNameColumn('name_ru_blank')
            && $this->hasBlankNameColumn('name_ua_blank');
    }

    public function paginator(array $filterModels, bool $includeCybertruck, array $columns, callable $modelOptions): Paginator
    {
        $perPage = 100;
        $pageName = 'catalog_items_page';
        $currentPage = Paginator::resolveCurrentPage($pageName);
        $currentPage = max(1, (int) $currentPage);
        $offset = ($currentPage - 1) * $perPage;
        $models = $this->paginatorModels($filterModels, $includeCybertruck, $modelOptions);
        $items = collect();

        $counts = $this->countsByModel($models);

        foreach ($models as $model) {
            $count = (int) ($counts[$model] ?? 0);

            if ($count <= $offset) {
                $offset -= $count;

                continue;
            }

            $needed = $perPage + 1 - $items->count();
            $chunk = PartCatalogItem::query()
                ->where('source', 'tesla_official')
                ->where('name_ru_blank', 1)
                ->where('name_ua_blank', 1)
                ->where('model_label', $model)
                ->orderBy('name')
                ->orderBy('part_number')
                ->skip($offset)
                ->take($needed)
                ->get($columns);

            $items = $items->merge($chunk);
            $offset = 0;

            if ($items->count() > $perPage) {
                break;
            }
        }

        $hasMore = $items->count() > $perPage;

        return (new Paginator(
            $items->take($perPage)->values(),
            $perPage,
            $currentPage,
            [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => $pageName,
            ]
        ))->hasMorePagesWhen($hasMore)->withQueryString();
    }

    protected function paginatorModels(array $filterModels, bool $includeCybertruck, callable $modelOptions): array
    {
        $models = $filterModels !== []
            ? $filterModels
            : $modelOptions('all');

        if ($includeCybertruck && ! in_array(self::CYBERTRUCK_MODEL_LABEL, $models, true)) {
            $models[] = self::CYBERTRUCK_MODEL_LABEL;
        }

        if (! $includeCybertruck) {
            $models = array_values(array_filter($models, fn (string $model): bool => $model !== self::CYBERTRUCK_MODEL_LABEL));
        }

        sort($models, SORT_STRING);

        return $models;
    }

    protected function countsByModel(array $models): array
    {
        if ($models === []) {
            return [];
        }

        return PartCatalogItem::query()
            ->where('source', 'tesla_official')
            ->where('name_ru_blank', 1)
            ->where('name_ua_blank', 1)
            ->whereIn('model_label', $models)
            ->select('model_label', DB::raw('count(*) as aggregate'))
            ->groupBy('model_label')
            ->pluck('aggregate', 'model_label')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    protected function hasBlankNameColumn(string $column): bool
    {
        return self::$blankNameColumns[$column] ??= Schema::hasColumn('part_catalog_items', $column);
    }
}
