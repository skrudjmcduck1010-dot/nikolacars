<?php

namespace App\Http\Controllers;

use App\Services\SkladStorefrontClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class PartsController extends Controller
{
    private const STOREFRONT_CACHE_FRESH_SECONDS = 300;

    private const STOREFRONT_CACHE_STALE_SECONDS = 86400;

    private const CATEGORY_LOCALE_SLUGS = [
        ['uk' => 'informaciino-rozvazalna-sistema', 'ru' => 'informacionno-razvlekatelnaia-sistema'],
        ['uk' => 'bezpeka-i-zaxist', 'ru' => 'bezopasnost-i-zashhita'],
        ['uk' => 'visokovoltna-batareia', 'ru' => 'visokovoltna-batareia'],
        ['uk' => 'visokovoltna-sistema', 'ru' => 'vysokovoltnaia-sistema'],
        ['uk' => 'vnutrisnje-ozdoblennia', 'ru' => 'vnutrenniaia-otdelka'],
        ['uk' => 'galma', 'ru' => 'tormoza'],
        ['uk' => 'diski-i-sini', 'ru' => 'diski-i-siny'],
        ['uk' => 'elektrika', 'ru' => 'elektrika'],
        ['uk' => 'zadnii-motor', 'ru' => 'zadnii-motor'],
        ['uk' => 'zovnisnia-furnitura', 'ru' => 'naruznaia-furnitura'],
        ['uk' => 'komponenti-zakrittia', 'ru' => 'komponenty-zakrytiia'],
        ['uk' => 'krisa', 'ru' => 'krysa'],
        ['uk' => 'kuzov', 'ru' => 'kuzov'],
        ['uk' => 'panel-priladiv', 'ru' => 'pribornaia-panel'],
        ['uk' => 'perednii-motor', 'ru' => 'perednii-motor'],
        ['uk' => 'pidviska', 'ru' => 'podveska'],
        ['uk' => 'rulyovii-mexanizm', 'ru' => 'rulevoi-mexanizm'],
        ['uk' => 'sidinnia', 'ru' => 'sidenia'],
        ['uk' => 'upravlinnia-temperaturnim-rezimom', 'ru' => 'upravlenie-temperaturnym-rezimom'],
    ];

    public function index(Request $request, SkladStorefrontClient $client): View|RedirectResponse
    {
        $locale = $request->route('locale') === 'ru' ? 'ru' : 'uk';
        $modelSlug = (string) $request->route('modelSlug', '');
        $categorySlug = (string) $request->route('categorySlug', '');
        $categoryPathSlug = (string) $request->route('categoryPathSlug', '');
        $categoryPath = trim((string) $request->route('categoryPath', ''), '/');
        $legacySubcategoryUrl = $categoryPathSlug !== ''
            && str_contains((string) $request->route()?->getName(), 'subcategory');

        if ($categoryPath !== '') {
            $pathSegments = array_values(array_filter(explode('/', $categoryPath)));
            if (count($pathSegments) === 1) {
                $categorySlug = $pathSegments[0];
            } else {
                $categoryPathSlug = implode('--', $pathSegments);
            }
        }

        if ($modelSlug === 'model-s2-04-2016-01-2021') {
            $target = ($locale === 'ru' ? '/ru/parts/' : '/parts/').'model-s-04-2016-01-2021/';
            if ($categoryPathSlug !== '') {
                $target .= str_replace('--', '/', $categoryPathSlug).'/';
            } elseif ($categorySlug !== '') {
                $target .= $categorySlug.'/';
            }
            $queryString = http_build_query($request->query());
            $targetUrl = rtrim(url($target), '/').'/';

            return redirect()->away($targetUrl.($queryString !== '' ? '?'.$queryString : ''), 301);
        }

        $page = max(1, $request->integer('page', 1));
        $query = trim((string) $request->query('q', ''));
        $sort = (string) $request->query('sort', 'newest');
        if (! in_array($sort, ['newest', 'price_asc', 'price_desc', 'name'], true)) {
            $sort = 'newest';
        }

        $catalogQuery = array_filter([
            'locale' => $locale,
            'model_slug' => $modelSlug,
            'category_slug' => $categorySlug,
            'category_path_slug' => $categoryPathSlug,
            'q' => $query,
            'sort' => $sort,
            'page' => $page,
            'per_page' => 24,
        ], fn (mixed $value): bool => $value !== '');

        try {
            $initialCatalog = $this->cachedStorefrontPayload(
                'storefront:catalog:v3:'.sha1(json_encode($catalogQuery, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                fn (): Response => $client->catalog($catalogQuery),
                [404, 410, 422],
            );
            $initialCatalog = $this->withProductUrlSlugs($initialCatalog);
        } catch (ConnectionException|RuntimeException $exception) {
            if ($exception instanceof HttpExceptionInterface) {
                if ($exception->getStatusCode() === 404 && $categoryPathSlug !== '') {
                    $target = ($locale === 'ru' ? '/ru/parts/' : '/parts/')
                        .($modelSlug !== '' ? $modelSlug.'/' : '');
                    $queryString = http_build_query($request->query());

                    return redirect()->away(
                        rtrim(url($target), '/').'/'.($queryString !== '' ? '?'.$queryString : ''),
                        301,
                    );
                }

                throw $exception;
            }

            report($exception);
            abort(503, 'Склад временно недоступен.');
        }

        if ($legacySubcategoryUrl) {
            $targetPath = str_replace('--', '/', $categoryPathSlug);
            $target = ($locale === 'ru' ? '/ru/parts/' : '/parts/')
                .($modelSlug !== '' ? $modelSlug.'/' : 'category/')
                .$targetPath.'/';
            $queryString = http_build_query($request->query());

            return redirect()->away(
                rtrim(url($target), '/').'/'.($queryString !== '' ? '?'.$queryString : ''),
                301,
            );
        }

        $lastPage = (int) ($initialCatalog['pagination']['last_page'] ?? 1);
        abort_if($page > max(1, $lastPage), 404);

        $selection = $initialCatalog['selection'] ?? [];
        $baseTitle = $locale === 'ru' ? 'Запчасти Tesla' : 'Запчастини Tesla';
        $categoryName = collect($selection['category_breadcrumbs'] ?? [])->last()['label'] ?? '';
        if ($categoryName === '') {
            $categoryPath = trim((string) ($selection['category_path'] ?? ''));
            $categoryParts = preg_split('/\s*\/\s*/u', $categoryPath, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $categoryName = trim((string) (end($categoryParts) ?: ($selection['category'] ?? '')));
        }

        $modelName = trim((string) ($selection['model'] ?? ''));
        $vehicleName = $modelName !== ''
            ? 'Tesla '.trim((string) preg_replace('/^tesla\s+/iu', '', $modelName))
            : '';

        if ($categoryName !== '' && $vehicleName !== '') {
            $catalogTitle = $categoryName.' — '.$vehicleName;
        } elseif ($categoryName !== '') {
            $catalogTitle = $categoryName.' — '.$baseTitle;
        } elseif ($vehicleName !== '') {
            $catalogTitle = $baseTitle.' — '.$vehicleName;
        } else {
            $catalogTitle = $baseTitle;
        }

        $pageLabel = $page > 1 ? ($locale === 'ru' ? 'Страница '.$page : 'Сторінка '.$page) : '';
        $seoTitle = implode(' — ', array_filter([$catalogTitle, $pageLabel, 'NikolaCars']));
        $sectionName = trim(implode(' — ', array_filter([$categoryName, $vehicleName])));
        $seoDescription = $locale === 'ru'
            ? 'Оригинальные запчасти Tesla в наличии в Киеве'.($sectionName !== '' ? ': '.$sectionName : '').($page > 1 ? '. Страница '.$page : '').'.'
            : 'Оригінальні запчастини Tesla в наявності у Києві'.($sectionName !== '' ? ': '.$sectionName : '').($page > 1 ? '. Сторінка '.$page : '').'.';
        $seoNoindex = $query !== '' || $request->has('sort') || $request->has('model') || $request->has('category');
        $seoPage = $seoNoindex ? 1 : $page;
        $localeUrls = $this->partsLocaleUrls(
            $modelSlug,
            $categorySlug,
            (array) ($selection['category_path_locale_slugs'] ?? []),
        );

        return view('parts.index', compact(
            'locale',
            'modelSlug',
            'categorySlug',
            'categoryPathSlug',
            'initialCatalog',
            'catalogTitle',
            'seoTitle',
            'seoDescription',
            'seoNoindex',
            'seoPage',
            'localeUrls',
        ));
    }

    /** @return array{uk: string, ru: string} */
    private function partsLocaleUrls(string $modelSlug, string $categorySlug, array $categoryPathLocaleSlugs = []): array
    {
        $categorySlugs = ['uk' => $categorySlug, 'ru' => $categorySlug];
        foreach (self::CATEGORY_LOCALE_SLUGS as $localizedSlugs) {
            if (in_array($categorySlug, $localizedSlugs, true)) {
                $categorySlugs = $localizedSlugs;
                break;
            }
        }

        $urls = [];
        foreach (['uk', 'ru'] as $locale) {
            $base = $locale === 'ru' ? '/ru/parts' : '/parts';
            $categoryPathSlug = trim((string) ($categoryPathLocaleSlugs[$locale] ?? ''));
            $categoryPath = str_replace('--', '/', $categoryPathSlug);
            if ($modelSlug !== '' && $categoryPathSlug !== '') {
                $urls[$locale] = $base.'/'.$modelSlug.'/'.$categoryPath.'/';
            } elseif ($categoryPathSlug !== '') {
                $urls[$locale] = $base.'/category/'.$categoryPath.'/';
            } elseif ($modelSlug !== '' && $categorySlugs[$locale] !== '') {
                $urls[$locale] = $base.'/'.$modelSlug.'/'.$categorySlugs[$locale].'/';
            } elseif ($modelSlug !== '') {
                $urls[$locale] = $base.'/'.$modelSlug.'/';
            } elseif ($categorySlugs[$locale] !== '') {
                $urls[$locale] = $base.'/category/'.$categorySlugs[$locale].'/';
            } else {
                $urls[$locale] = $base.'/';
            }
        }

        return $urls;
    }

    public function catalog(Request $request, SkladStorefrontClient $client, string $locale = 'uk'): JsonResponse
    {
        return $this->proxy(
            fn () => $client->catalog($request->query() + ['locale' => $locale]),
            fn (array $payload): array => $this->withProductUrlSlugs($payload),
        );
    }

    public function show(SkladStorefrontClient $client, string $productSlug, string $locale = 'uk'): View|RedirectResponse
    {
        $locale = $locale === 'ru' ? 'ru' : 'uk';
        $product = ctype_digit($productSlug)
            ? (int) $productSlug
            : (preg_match('/-(\d+)$/', $productSlug, $matches) === 1 ? (int) $matches[1] : 0);
        abort_if($product <= 0, 404);

        try {
            $productData = $this->cachedStorefrontPayload(
                'storefront:product:v5:'.$locale.':'.$product,
                fn (): Response => $client->product($product, $locale),
                [404, 410, 422],
            );
        } catch (ConnectionException|RuntimeException $exception) {
            if ($exception instanceof HttpExceptionInterface) {
                throw $exception;
            }

            report($exception);
            abort(503, 'Склад временно недоступен.');
        }

        $canonicalSegment = $this->productUrlSlug($productData, $product);
        $productData['url_slug'] = $canonicalSegment;
        $productData = $this->withProductUrlSlugs($productData);
        $catalogPath = $locale === 'ru' ? '/ru/parts/' : '/parts/';
        if ($productSlug !== $canonicalSegment) {
            return redirect()->away(rtrim(url($catalogPath.$canonicalSegment), '/').'/', 301);
        }

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
        $priceValue = (float) ($productData['price_uah'] ?? 0);
        $hasPrice = $priceValue > 0;
        $hasStock = (int) ($productData['quantity'] ?? 0) > 0;
        $price = number_format($priceValue, 0, '.', ' ');
        $priceDescription = $hasPrice
            ? $price.' грн'
            : ($locale === 'ru' ? 'цену уточняйте' : 'ціну уточнюйте');
        $availabilityDescription = $hasStock
            ? ($locale === 'ru' ? 'В наличии в NikolaCars, Киев.' : 'В наявності у NikolaCars, Київ.')
            : ($locale === 'ru' ? 'Временно нет в наличии.' : 'Тимчасово немає в наявності.');
        $seoDescription = $productTitle.', артикул '.$article.' — '.$priceDescription.'. '.$availabilityDescription;
        $productData['description'] = $this->generateProductDescription(
            $productData,
            $locale,
            $name,
            $vehicle,
            $article,
            $price,
            $hasPrice,
        );

        $baseUrl = 'https://nikolacars.kiev.ua';
        $productUrl = $baseUrl.$catalogPath.$canonicalSegment.'/';
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
        $offer = $hasPrice ? array_filter([
            '@type' => 'Offer',
            'url' => $productUrl,
            'priceCurrency' => 'UAH',
            'price' => number_format((float) ($productData['price_uah'] ?? 0), 2, '.', ''),
            'availability' => (int) ($productData['quantity'] ?? 0) > 0
                ? 'https://schema.org/InStock'
                : 'https://schema.org/OutOfStock',
            'itemCondition' => $itemCondition,
            'seller' => ['@type' => 'Organization', 'name' => 'NikolaCars'],
        ]) : null;
        $productSchema = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $productTitle,
            'image' => $images,
            'description' => $productData['description'],
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
        $categoryBreadcrumbs = collect($productData['category_breadcrumbs'] ?? [])
            ->filter(fn (mixed $breadcrumb): bool => is_array($breadcrumb)
                && trim((string) ($breadcrumb['label'] ?? '')) !== ''
                && trim((string) ($breadcrumb['slug'] ?? '')) !== '')
            ->values();
        if ($categoryBreadcrumbs->isEmpty() && $categorySlug !== '') {
            $categoryBreadcrumbs = collect([[
                'label' => (string) ($productData['category'] ?? ''),
                'slug' => $categorySlug,
            ]]);
        }
        foreach ($categoryBreadcrumbs as $index => $breadcrumb) {
            $slug = trim((string) $breadcrumb['slug']);
            $path = str_replace('--', '/', $slug);
            $categoryPath = $index === 0
                ? ($modelSlug !== '' ? $modelSlug.'/'.$slug.'/' : 'category/'.$slug.'/')
                : ($modelSlug !== '' ? $modelSlug.'/'.$path.'/' : 'category/'.$path.'/');
            $breadcrumbItems[] = [
                '@type' => 'ListItem',
                'position' => count($breadcrumbItems) + 1,
                'name' => (string) $breadcrumb['label'],
                'item' => $baseUrl.$catalogPath.$categoryPath,
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
            'localeUrls' => [
                'uk' => '/parts/'.$canonicalSegment.'/',
                'ru' => '/ru/parts/'.$canonicalSegment.'/',
            ],
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

    protected function productUrlSlug(array $product, int $id): string
    {
        $urlSlug = trim((string) ($product['url_slug'] ?? ''));
        if ($urlSlug !== '') {
            return $urlSlug;
        }

        $source = trim((string) ($product['part_number'] ?? '')) ?: trim((string) ($product['sku'] ?? ''));
        $slug = Str::slug($source);

        return ($slug !== '' ? $slug : 'part').'-'.$id;
    }

    protected function withProductUrlSlugs(array $payload): array
    {
        foreach (['products', 'similar_products', 'subcategory_products'] as $key) {
            if (! is_array($payload[$key] ?? null)) {
                continue;
            }

            $payload[$key] = array_map(function (mixed $product): mixed {
                if (! is_array($product) || empty($product['id'])) {
                    return $product;
                }

                $product['url_slug'] = $this->productUrlSlug($product, (int) $product['id']);

                return $product;
            }, $payload[$key]);
        }

        return $payload;
    }

    /**
     * Cache read-only storefront responses so crawlers do not trigger a remote
     * warehouse request for every catalog and product page view.
     *
     * @param  callable(): Response  $fetch
     * @param  list<int>  $notFoundStatuses
     */
    private function cachedStorefrontPayload(string $cacheKey, callable $fetch, array $notFoundStatuses = [404]): array
    {
        $result = Cache::flexible(
            $cacheKey,
            [self::STOREFRONT_CACHE_FRESH_SECONDS, self::STOREFRONT_CACHE_STALE_SECONDS],
            function () use ($fetch, $notFoundStatuses): array {
                $response = $fetch();
                $status = $response->status();
                $data = $response->json();

                if (! in_array($status, $notFoundStatuses, true) && ($status < 200 || $status >= 300 || ! is_array($data))) {
                    throw new RuntimeException('Warehouse storefront returned HTTP '.$status.'.');
                }

                return [
                    'status' => $status,
                    'data' => $data,
                ];
            },
        );

        $status = (int) ($result['status'] ?? 500);
        if (in_array($status, $notFoundStatuses, true)) {
            abort(404);
        }
        if ($status < 200 || $status >= 300 || ! is_array($result['data'] ?? null)) {
            throw new RuntimeException('Warehouse storefront returned HTTP '.$status.'.');
        }

        return $result['data'];
    }

    protected function generateProductDescription(
        array $product,
        string $locale,
        string $name,
        string $vehicle,
        string $article,
        string $price,
        bool $hasPrice,
    ): string {
        $category = trim((string) ($product['category_path'] ?? $product['category'] ?? ''));
        $compatibility = trim((string) ($product['compatibility'] ?? ''));
        $condition = $this->localizedProductAttribute((string) ($product['condition'] ?? ''), $locale, 'condition');
        $color = $this->localizedProductAttribute((string) ($product['color'] ?? ''), $locale, 'color');
        $quantity = max(0, (int) ($product['quantity'] ?? 0));
        $compactArticle = preg_replace('/[^\p{L}\p{N}]+/u', '', $article) ?: $article;

        if ($locale === 'ru') {
            $details = [
                $name.' — запчасть для '.$vehicle.'.',
                'Артикул: '.$article.($compactArticle !== $article ? ' ('.$compactArticle.')' : '').'.',
            ];
            if ($category !== '') {
                $details[] = 'Категория: '.$category.'.';
            }
            if ($compatibility !== '') {
                $details[] = 'Совместимость: '.$compatibility.'.';
            }
            if ($condition !== '') {
                $details[] = 'Состояние: '.$condition.'.';
            }
            if ($color !== '') {
                $details[] = 'Цвет: '.$color.'.';
            }

            $availability = $quantity > 0
                ? 'Запчасть в наличии на складе NikolaCars: '.$quantity.' шт.'
                : 'Наличие запчасти уточняйте у менеджера NikolaCars.';

            $priceSentence = $hasPrice
                ? 'Купить запчасть можно по цене '.$price.' грн.'
                : 'Цену запчасти уточняйте у менеджера NikolaCars.';

            return implode(' ', $details)."\n\n".$availability.' '.$priceSentence.' Перед заказом сверьте артикул и совместимость детали с вашим автомобилем Tesla.';
        }

        $details = [
            $name.' — запчастина для '.$vehicle.'.',
            'Артикул: '.$article.($compactArticle !== $article ? ' ('.$compactArticle.')' : '').'.',
        ];
        if ($category !== '') {
            $details[] = 'Категорія: '.$category.'.';
        }
        if ($compatibility !== '') {
            $details[] = 'Сумісність: '.$compatibility.'.';
        }
        if ($condition !== '') {
            $details[] = 'Стан: '.$condition.'.';
        }
        if ($color !== '') {
            $details[] = 'Колір: '.$color.'.';
        }

        $availability = $quantity > 0
            ? 'Запчастина є в наявності на складі NikolaCars: '.$quantity.' шт.'
            : 'Наявність запчастини уточнюйте у менеджера NikolaCars.';

        $priceSentence = $hasPrice
            ? 'Купити запчастину можна за ціною '.$price.' грн.'
            : 'Ціну запчастини уточнюйте у менеджера NikolaCars.';

        return implode(' ', $details)."\n\n".$availability.' '.$priceSentence.' Перед замовленням звірте артикул і сумісність деталі з вашим автомобілем Tesla.';
    }

    protected function localizedProductAttribute(string $value, string $locale, string $attribute): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        if ($value === '' || $locale === 'ru') {
            return $value;
        }

        $translations = $attribute === 'condition'
            ? [
                'new' => 'нова',
                'новая' => 'нова',
                'новый' => 'новий',
                'used' => 'вживана',
                'б/у' => 'вживана',
            ]
            : [
                'black' => 'чорний',
                'черный' => 'чорний',
                'чёрный' => 'чорний',
                'white' => 'білий',
                'белый' => 'білий',
                'red' => 'червоний',
                'красный' => 'червоний',
                'blue' => 'синій',
                'синий' => 'синій',
                'grey' => 'сірий',
                'gray' => 'сірий',
                'серый' => 'сірий',
                'silver' => 'сріблястий',
                'серебристый' => 'сріблястий',
                'green' => 'зелений',
                'зеленый' => 'зелений',
                'brown' => 'коричневий',
                'коричневый' => 'коричневий',
                'beige' => 'бежевий',
                'бежевый' => 'бежевий',
                'yellow' => 'жовтий',
                'желтый' => 'жовтий',
                'orange' => 'помаранчевий',
                'оранжевый' => 'помаранчевий',
            ];

        return strtr($value, $translations);
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

    protected function proxy(callable $callback, ?callable $transform = null): JsonResponse
    {
        try {
            $response = $callback();
            $payload = $response->json();
            if (is_array($payload) && $transform !== null) {
                $payload = $transform($payload);
            }

            return response()->json(is_array($payload) ? $payload : ['message' => 'Invalid warehouse response.'], $response->status());
        } catch (ConnectionException|RuntimeException $exception) {
            report($exception);

            return response()->json(['message' => 'Склад временно недоступен. Попробуйте немного позже.'], 503);
        }
    }
}
