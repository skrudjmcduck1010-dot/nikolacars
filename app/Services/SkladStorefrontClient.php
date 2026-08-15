<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SkladStorefrontClient
{
    public function catalog(array $query): Response
    {
        return $this->request()->get($this->url('catalog'), $query);
    }

    public function cities(array $query): Response
    {
        return $this->request()->get($this->url('nova-poshta/cities'), $query);
    }

    public function warehouses(array $query): Response
    {
        return $this->request()->get($this->url('nova-poshta/warehouses'), $query);
    }

    public function createOrder(array $payload): Response
    {
        return $this->request()->post($this->url('orders'), $payload);
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
            ->timeout((int) config('services.sklad_storefront.timeout', 20));
    }

    protected function url(string $path): string
    {
        return rtrim((string) config('services.sklad_storefront.base_url'), '/').'/'.ltrim($path, '/');
    }
}
