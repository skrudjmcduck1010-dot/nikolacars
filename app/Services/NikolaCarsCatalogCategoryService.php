<?php

namespace App\Services;

use App\Models\PartCatalogCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NikolaCarsCatalogCategoryService
{
    public function search(string $query, int $limit = 20): Collection
    {
        $query = trim($query);

        if (mb_strlen($query) < 1) {
            return collect();
        }

        $likeQueries = collect([
            $query,
            ...app(NikolaCarsInventoryService::class)->categorySearchAliases($query),
        ])
            ->map(fn (string $value): string => trim($value))
            ->filter()
            ->unique(fn (string $value): string => Str::lower($value))
            ->map(fn (string $value): string => '%'.$value.'%')
            ->values();
        $driver = DB::connection()->getDriverName();
        $operator = $driver === 'pgsql' ? 'ilike' : 'like';
        $undetermined = NikolaCarsTeslaCategoryResolver::UNDETERMINED;

        return PartCatalogCategory::query()
            ->where('source', 'nikolacars')
            ->where(function (Builder $builder) use ($operator, $likeQueries): void {
                foreach ($likeQueries as $likeQuery) {
                    $builder->orWhere(function (Builder $queryBuilder) use ($operator, $likeQuery): void {
                        $queryBuilder
                            ->where('name', $operator, $likeQuery)
                            ->orWhere('name_ru', $operator, $likeQuery)
                            ->orWhere('name_ua', $operator, $likeQuery)
                            ->orWhere('name_en', $operator, $likeQuery)
                            ->orWhere('model_label', $operator, $likeQuery)
                            ->orWhere('code', $operator, $likeQuery);
                    });
                }
            })
            ->where(function (Builder $builder) use ($undetermined): void {
                $builder
                    ->whereNull('name')
                    ->orWhere('name', '!=', $undetermined);
            })
            ->orderBy('depth')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'parent_id', 'source', 'name', 'name_en', 'name_ru', 'name_ua', 'code', 'source_url', 'depth', 'model_label'])
            ->map(fn (PartCatalogCategory $category): array => [
                'id' => $category->id,
                'name' => $this->displayLabel($category),
                'model' => $category->model_label,
            ])
            ->values();
    }

    public function displayLabel(PartCatalogCategory $category): string
    {
        $trail = app(PartCatalogCategoryRouteService::class)->categoryTrail($category);
        $withoutModelRoot = $trail
            ->filter(fn (PartCatalogCategory $trailCategory): bool => (int) $trailCategory->depth > 0)
            ->values();

        return ($withoutModelRoot->isNotEmpty() ? $withoutModelRoot : $trail)
            ->map(fn (PartCatalogCategory $trailCategory): string => $this->categoryName($trailCategory))
            ->map(fn (string $label): string => trim($label))
            ->filter()
            ->unique()
            ->implode(' / ');
    }

    protected function categoryName(PartCatalogCategory $category): string
    {
        $name = $category->name_ru ?: $category->name_ua ?: $category->name_en ?: $category->name ?: '';

        return app(NikolaCarsInventoryService::class)->translateCategoryPart($name);
    }
}
