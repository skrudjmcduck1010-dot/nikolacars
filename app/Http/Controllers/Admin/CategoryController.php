<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CategoryRequest;
use App\Models\Category;
use App\Models\PartCatalogCategory;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function index(): View
    {
        $catalogPaths = $this->catalogCategoryPaths();
        $existingSlugs = Category::query()->pluck('slug')->all();
        $categories = Category::query()
            ->withCount('products')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('admin.categories.index', [
            'categoryRows' => $this->categoryTreeRows($categories),
            'catalogCategoriesCount' => $catalogPaths->count(),
            'missingCatalogCategoriesCount' => $catalogPaths
                ->reject(fn (array $category): bool => in_array($category['slug'], $existingSlugs, true))
                ->count(),
        ]);
    }

    public function create(): View
    {
        return view('admin.categories.form', [
            'category' => new Category,
        ]);
    }

    public function store(CategoryRequest $request): RedirectResponse
    {
        Category::query()->create($this->payload($request));

        return redirect()->route('admin.categories.index')->with('status', 'Категория создана.');
    }

    public function syncTcars(): RedirectResponse
    {
        $created = 0;
        $movedProducts = 0;
        $removedDuplicates = 0;
        $catalogCategories = $this->catalogCategoryPaths();
        $syncedCategoriesBySlug = collect();

        DB::transaction(function () use ($catalogCategories, &$created, &$movedProducts, &$removedDuplicates, &$syncedCategoriesBySlug): void {
            foreach ($catalogCategories as $index => $catalogCategory) {
                $category = Category::query()->firstOrCreate(
                    ['slug' => $catalogCategory['slug']],
                    [
                        'name' => $catalogCategory['name'],
                        'description' => $catalogCategory['description'],
                        'is_active' => true,
                        'sort_order' => $index + 1,
                    ],
                );

                if ($category->wasRecentlyCreated) {
                    $created++;
                }

                $syncedCategoriesBySlug->put($category->slug, $category);
            }

            [$movedProducts, $removedDuplicates] = $this->mergeModelSpecificTcarsCategories($syncedCategoriesBySlug);
        });

        return redirect()
            ->route('admin.categories.index')
            ->with('status', "Категории TCARS синхронизированы. Добавлено: {$created}. Перенесено товаров: {$movedProducts}. Удалено дублей: {$removedDuplicates}.");
    }

    public function show(Category $category): View
    {
        return view('admin.categories.show', [
            'category' => $category->load('products'),
        ]);
    }

    public function edit(Category $category): View
    {
        return view('admin.categories.form', compact('category'));
    }

    public function update(CategoryRequest $request, Category $category): RedirectResponse
    {
        $category->update($this->payload($request));

        return redirect()->route('admin.categories.index')->with('status', 'Категория обновлена.');
    }

    public function destroy(Category $category): RedirectResponse
    {
        $category->delete();

        return redirect()->route('admin.categories.index')->with('status', 'Категория удалена.');
    }

    protected function payload(CategoryRequest $request): array
    {
        return [
            ...$request->validated(),
            'sort_order' => (int) ($request->validated()['sort_order'] ?? 0),
            'is_active' => $request->boolean('is_active'),
        ];
    }

    protected function categoryTreeRows(Collection $categories): Collection
    {
        return $categories
            ->sortBy('name', SORT_NATURAL)
            ->values()
            ->map(function (Category $category): array {
                $parts = collect(explode(' / ', $category->name))
                    ->map(fn (string $part): string => trim($part))
                    ->filter()
                    ->values();

                return [
                    'category' => $category,
                    'depth' => max(0, $parts->count() - 1),
                    'title' => $parts->last() ?: $category->name,
                ];
            });
    }

    protected function catalogCategoryPaths(): Collection
    {
        $categories = PartCatalogCategory::query()
            ->orderBy('model_label')
            ->orderBy('depth')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->orderBy('name')
            ->get(['id', 'parent_id', 'code', 'name', 'model_label', 'depth', 'sort_order']);

        if ($categories->isEmpty()) {
            return collect();
        }

        $byId = $categories->keyBy('id');

        return $categories
            ->filter(fn (PartCatalogCategory $category): bool => (int) $category->depth > 0)
            ->map(function (PartCatalogCategory $category) use ($byId): array {
                $trail = collect();
                $current = $category;

                while ($current && (int) $current->depth > 0) {
                    $title = trim(collect([$current->code, $current->name])->filter()->join(' - '));
                    $trail->prepend($title);
                    $current = $current->parent_id ? $byId->get($current->parent_id) : null;
                }

                $name = $trail->filter()->implode(' / ');
                $slugBase = Str::slug($name) ?: 'category';

                return [
                    'name' => Str::limit($name, 255, ''),
                    'slug' => Str::limit('tcars-'.$slugBase, 255, ''),
                    'description' => null,
                ];
            })
            ->unique('slug')
            ->values();
    }

    protected function mergeModelSpecificTcarsCategories(Collection $syncedCategoriesBySlug): array
    {
        $movedProducts = 0;
        $removedCategories = 0;

        Category::query()
            ->where('slug', 'like', 'tcars-%')
            ->get()
            ->filter(fn (Category $category): bool => Str::startsWith($category->name, 'Model ') && Str::contains($category->name, ' / '))
            ->each(function (Category $category) use ($syncedCategoriesBySlug, &$movedProducts, &$removedCategories): void {
                $genericName = Str::contains($category->name, ' / ')
                    ? Str::after($category->name, ' / ')
                    : preg_replace('/^tcars-[0-9]+-/', '', $category->slug);
                $genericSlug = Str::limit('tcars-'.(Str::slug((string) $genericName) ?: 'category'), 255, '');
                $target = $syncedCategoriesBySlug->get($genericSlug);

                if (! $target || $target->is($category)) {
                    return;
                }

                $movedProducts += Product::query()
                    ->where('category_id', $category->id)
                    ->update(['category_id' => $target->id]);

                $category->delete();
                $removedCategories++;
            });

        return [$movedProducts, $removedCategories];
    }
}
