<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PublicStorageUrl
{
    public static function url(?string $path): ?string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return static::localStorageAbsoluteUrl($path) ?? $path;
        }

        if (Str::startsWith($path, '/storage/')) {
            return static::fromStoragePath(Str::after($path, '/storage/'));
        }

        if (Str::startsWith($path, static::legacyPublicStoragePrefixes())) {
            return static::fromStoragePath(ltrim($path, '/'));
        }

        if (Str::startsWith($path, ['/'])) {
            return $path;
        }

        return static::fromStoragePath($path);
    }

    protected static function localStorageAbsoluteUrl(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (! Str::startsWith($path, '/storage/')) {
            return null;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        $appHost = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $publicStorageHost = (string) parse_url(static::publicStorageBaseUrl(), PHP_URL_HOST);

        if ($host !== '' && ! in_array($host, array_filter([$appHost, $publicStorageHost]), true)) {
            return null;
        }

        return static::fromStoragePath(Str::after($path, '/storage/'));
    }

    protected static function fromStoragePath(string $path): string
    {
        $path = ltrim($path, '/');

        if ($path !== '' && Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->url($path);
        }

        $fallbackBaseUrl = static::publicStorageFallbackBaseUrl();

        return ($fallbackBaseUrl ?: static::publicStorageBaseUrl()).'/'.$path;
    }

    protected static function publicStorageBaseUrl(): string
    {
        return rtrim((string) config('filesystems.disks.public.url'), '/');
    }

    protected static function publicStorageFallbackBaseUrl(): ?string
    {
        $url = trim((string) config('filesystems.public_fallback_url'));

        return $url !== '' ? rtrim($url, '/') : null;
    }

    protected static function legacyPublicStoragePrefixes(): array
    {
        return [
            '/competitor-catalog/',
            '/donor-cars/',
            '/driveparts/',
            '/nikolacars/',
            '/product-photos/',
            '/tesla-official/',
            '/toprazborka/',
        ];
    }
}
