<?php

namespace App\Http\Controllers;

use App\Services\SkladStorefrontClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class PartsController extends Controller
{
    public function index(Request $request, SkladStorefrontClient $client): View
    {
        $locale = $request->route('locale') === 'ru' ? 'ru' : 'uk';
        $modelSlug = (string) $request->route('modelSlug', '');
        $categorySlug = (string) $request->route('categorySlug', '');
        $page = max(1, $request->integer('page', 1));
        $query = trim((string) $request->query('q', ''));
        $sort = (string) $request->query('sort', 'newest');
        if (! in_array($sort, ['newest', 'price_asc', 'price_desc', 'name'], true)) {
            $sort = 'newest';
        }

        try {
            $response = $client->catalog(array_filter([
                'locale' => $locale,
                'model_slug' => $modelSlug,
                'category_slug' => $categorySlug,
                'q' => $query,
                'sort' => $sort,
                'page' => $page,
                'per_page' => 24,
            ], fn (mixed $value): bool => $value !== ''));
        } catch (ConnectionException|RuntimeException $exception) {
            report($exception);
            abort(503, 'Склад временно недоступен.');
        }

        abort_unless($response->successful() && is_array($response->json()), 404);

        $initialCatalog = $response->json();
        $lastPage = (int) ($initialCatalog['pagination']['last_page'] ?? 1);
        abort_if($page > max(1, $lastPage), 404);

        $selection = $initialCatalog['selection'] ?? [];
        $sectionName = trim(implode(' — ', array_filter([
            $selection['category'] ?? '',
            $selection['model'] ?? '',
        ])));
        $baseTitle = $locale === 'ru' ? 'Запчасти Tesla' : 'Запчастини Tesla';
        $pageLabel = $page > 1 ? ($locale === 'ru' ? 'Страница '.$page : 'Сторінка '.$page) : '';
        $seoTitle = implode(' — ', array_filter([$baseTitle, $sectionName, $pageLabel, 'NikolaCars']));
        $seoDescription = $locale === 'ru'
            ? 'Оригинальные запчасти Tesla в наличии в Киеве'.($sectionName !== '' ? ': '.$sectionName : '').($page > 1 ? '. Страница '.$page : '').'.'
            : 'Оригінальні запчастини Tesla в наявності у Києві'.($sectionName !== '' ? ': '.$sectionName : '').($page > 1 ? '. Сторінка '.$page : '').'.';
        $seoNoindex = $query !== '' || $request->has('sort') || $request->has('model') || $request->has('category');
        $seoPage = $seoNoindex ? 1 : $page;

        return view('parts.index', compact(
            'locale',
            'modelSlug',
            'categorySlug',
            'initialCatalog',
            'seoTitle',
            'seoDescription',
            'seoNoindex',
            'seoPage',
        ));
    }

    public function catalog(Request $request, SkladStorefrontClient $client, string $locale = 'uk'): JsonResponse
    {
        return $this->proxy(fn () => $client->catalog($request->query() + ['locale' => $locale]));
    }

    public function show(SkladStorefrontClient $client, int $product, string $locale = 'uk'): View
    {
        $locale = $locale === 'ru' ? 'ru' : 'uk';

        try {
            $response = $client->product($product, $locale);
        } catch (ConnectionException|RuntimeException $exception) {
            report($exception);
            abort(503, 'Склад временно недоступен.');
        }

        abort_unless($response->successful() && is_array($response->json()), 404);
        $productData = $response->json();

        $name = trim((string) ($productData['name'] ?? ''));
        $model = trim((string) ($productData['model'] ?? ''));
        $model = trim((string) preg_replace('/^tesla\s+/iu', '', $model));
        $vehicle = trim('Tesla '.$model);
        $article = trim((string) ($productData['part_number'] ?? ''));
        if ($article === '') {
            $article = trim((string) ($productData['sku'] ?? $productData['id'] ?? $product));
        }

        $productTitle = $name;
        if ($vehicle !== '' && mb_stripos($productTitle, $vehicle) === false) {
            $productTitle = trim($productTitle.' '.$vehicle);
        }
        $seoTitle = $productTitle.' — '.$article.' | NikolaCars';
        $price = number_format((float) ($productData['price_uah'] ?? 0), 0, '.', ' ');
        $seoDescription = $locale === 'ru'
            ? $productTitle.', артикул '.$article.' — '.$price.' грн. В наличии в NikolaCars, Киев.'
            : $productTitle.', артикул '.$article.' — '.$price.' грн. В наявності у NikolaCars, Київ.';

        $baseUrl = 'https://nikolacars.kiev.ua';
        $catalogPath = $locale === 'ru' ? '/ru/parts/' : '/parts/';
        $productUrl = $baseUrl.$catalogPath.$product.'/';
        $images = collect($productData['images'] ?? [])
            ->push($productData['image_url'] ?? null)
            ->filter()
            ->unique()
            ->values()
            ->all();
        $condition = mb_strtolower(trim((string) ($productData['condition'] ?? '')), 'UTF-8');
        $itemCondition = match (true) {
            preg_match('/(^|\s)(new|нов|нова|новая)(\s|$)/u', $condition) === 1 => 'https://schema.org/NewCondition',
            preg_match('/(used|б\/у|вживан|уживан)/u', $condition) === 1 => 'https://schema.org/UsedCondition',
            default => null,
        };
        $offer = array_filter([
            '@type' => 'Offer',
            'url' => $productUrl,
            'priceCurrency' => 'UAH',
            'price' => number_format((float) ($productData['price_uah'] ?? 0), 2, '.', ''),
            'availability' => (int) ($productData['quantity'] ?? 0) > 0
                ? 'https://schema.org/InStock'
                : 'https://schema.org/OutOfStock',
            'itemCondition' => $itemCondition,
            'seller' => ['@type' => 'Organization', 'name' => 'NikolaCars'],
        ]);
        $productSchema = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $productTitle,
            'image' => $images,
            'description' => trim((string) ($productData['description'] ?? '')) ?: $seoDescription,
            'sku' => trim((string) ($productData['sku'] ?? '')) ?: $article,
            'mpn' => trim((string) ($productData['part_number'] ?? '')) ?: null,
            'brand' => ['@type' => 'Brand', 'name' => 'Tesla'],
            'category' => trim((string) ($productData['category_path'] ?? '')) ?: null,
            'offers' => $offer,
        ]);

        $breadcrumbItems = [[
            '@type' => 'ListItem',
            'position' => 1,
            'name' => $locale === 'ru' ? 'Запчасти Tesla' : 'Запчастини Tesla',
            'item' => $baseUrl.$catalogPath,
        ]];
        $modelSlug = trim((string) ($productData['model_slug'] ?? ''));
        if ($modelSlug !== '') {
            $breadcrumbItems[] = [
                '@type' => 'ListItem',
                'position' => count($breadcrumbItems) + 1,
                'name' => (string) ($productData['model'] ?? ''),
                'item' => $baseUrl.$catalogPath.$modelSlug.'/',
            ];
        }
        $categorySlug = trim((string) ($productData['category_slug'] ?? ''));
        if ($modelSlug !== '' && $categorySlug !== '') {
            $breadcrumbItems[] = [
                '@type' => 'ListItem',
                'position' => count($breadcrumbItems) + 1,
                'name' => (string) ($productData['category'] ?? ''),
                'item' => $baseUrl.$catalogPath.$modelSlug.'/'.$categorySlug.'/',
            ];
        }
        $breadcrumbItems[] = [
            '@type' => 'ListItem',
            'position' => count($breadcrumbItems) + 1,
            'name' => $name,
            'item' => $productUrl,
        ];

        return view('parts.show', [
            'locale' => $locale,
            'product' => $productData,
            'seoTitle' => $seoTitle,
            'seoDescription' => $seoDescription,
            'seoStructuredData' => [
                $productSchema,
                [
                    '@context' => 'https://schema.org',
                    '@type' => 'BreadcrumbList',
                    'itemListElement' => $breadcrumbItems,
                ],
            ],
        ]);
    }

    public function cities(Request $request, SkladStorefrontClient $client): JsonResponse
    {
        return $this->proxy(fn () => $client->cities($request->query()));
    }

    public function warehouses(Request $request, SkladStorefrontClient $client): JsonResponse
    {
        return $this->proxy(fn () => $client->warehouses($request->query()));
    }

    public function storeOrder(Request $request, SkladStorefrontClient $client, string $locale = 'uk'): JsonResponse
    {
        return $this->proxy(fn () => $client->createOrder($request->all() + ['locale' => $locale]));
    }

    protected function proxy(callable $callback): JsonResponse
    {
        try {
            $response = $callback();
            $payload = $response->json();

            return response()->json(is_array($payload) ? $payload : ['message' => 'Invalid warehouse response.'], $response->status());
        } catch (ConnectionException|RuntimeException $exception) {
            report($exception);

            return response()->json(['message' => 'Склад временно недоступен. Попробуйте немного позже.'], 503);
        }
    }
}
