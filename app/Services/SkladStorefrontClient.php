<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SkladStorefrontClient
{
    public function catalog(array $query): Response
    {
        $response = $this->read('catalog', $query);
        $path = trim((string) ($query['category_path_slug'] ?? ''));
        if ($path === '' || ! in_array($response->status(), [404, 422], true)) {
            return $response;
        }

        // Resolve old shortened paths from the actual taxonomy, never by guessing
        // or changing warehouse categories. A valid exact path always wins.
        $paths = Cache::remember('storefront:category-path-aliases:v1', now()->addMinutes(5), function (): array {
            $index = $this->seoIndex();
            if (! $index->successful() || ! is_array($index->json('locales'))) {
                throw new RuntimeException('Warehouse category index is unavailable.');
            }

            $paths = [];
            foreach (['uk', 'ru'] as $locale) {
                $paths[$locale] = $index->json('locales.'.$locale.'.category_path_sections', []);
            }

            return $paths;
        });

        $matches = [];
        foreach ($paths[$query['locale'] ?? 'uk'] ?? [] as $row) {
            if (! is_array($row) || (! empty($query['model_slug']) && ($row['model_slug'] ?? '') !== $query['model_slug'])) {
                continue;
            }
            $candidate = (string) ($row['category_path_slug'] ?? '');
            if ($candidate !== '' && CatalogPath::legacyCollapsed($candidate) === CatalogPath::legacyCollapsed($path)) {
                $matches[$candidate] = true;
            }
        }

        if (count($matches) !== 1) {
            return $response;
        }

        $query['category_path_slug'] = array_key_first($matches);

        return $this->read('catalog', $query);
    }

    public function cities(array $query): Response
    {
        return $this->read('nova-poshta/cities', $query);
    }

    public function product(int $productId, string $locale): Response
    {
        return $this->read('products/'.$productId, ['locale' => $locale]);
    }

    public function seoIndex(): Response
    {
        return $this->read('seo-index');
    }

    public function warehouses(array $query): Response
    {
        return $this->read('nova-poshta/warehouses', $query);
    }

    public function createOrder(array $payload): Response
    {
        return $this->request()->post($this->url('orders'), $payload);
    }

    public function createStoWebsiteRequest(array $payload): Response
    {
        return $this->request()
            ->retry(2, 200, throw: false)
            ->post($this->url('sto-website-requests'), $payload);
    }

    protected function request(): PendingRequest
    {
        $token = trim((string) config('services.sklad_storefront.token'));
        if ($token === '') {
            throw new RuntimeException('Storefront connection is not configured.');
        }

        return Http::acceptJson()
            ->asJson()
            ->withToken($token)
            ->connectTimeout(5)
            ->timeout((int) config('services.sklad_storefront.timeout', 20));
    }

    private function read(string $path, array $query = []): Response
    {
        $started = hrtime(true);
        $status = null;
        try {
            $response = $this->request()->get($this->url($path), $query);
            $status = $response->status();

            return $response;
        } finally {
            $duration = round((hrtime(true) - $started) / 1_000_000);
            if ($duration >= 2000 || $status === null || $status >= 500) {
                Log::warning('Warehouse read slow or failed.', [
                    'endpoint' => preg_replace('~/[0-9]+~', '/{id}', $path),
                    'status' => $status,
                    'duration_ms' => $duration,
                ]);
            }
        }
    }

    protected function url(string $path): string
    {
        return rtrim((string) config('services.sklad_storefront.base_url'), '/').'/'.ltrim($path, '/');
    }
}
