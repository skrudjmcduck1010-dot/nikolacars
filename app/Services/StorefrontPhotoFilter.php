<?php
namespace App\Services;

class StorefrontPhotoFilter
{
    public static function blocked(mixed $url): bool
    {
        if (! is_string($url)) return false;
        $url = strtolower(rawurldecode($url));
        return preg_match('~(?:^|[/.])tesla\.com(?:[/:?#]|$)~', $url) === 1
            || str_contains($url, 'tesla-official/')
            || str_contains($url, 'competitor-catalog/tesla_official/');
    }

    public static function clean(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) $data[$key] = self::clean($value);
        }
        if (isset($data['images']) && is_array($data['images'])) {
            $data['images'] = array_values(array_filter($data['images'], fn ($url) => ! self::blocked($url)));
        }
        if (self::blocked($data['image_url'] ?? null)) {
            $data['image_url'] = $data['images'][0] ?? null;
            // Generated previews may have opaque filenames: discard the preview of a blocked original too.
            $data['thumbnail_url'] = $data['image_url'];
        }
        if (self::blocked($data['thumbnail_url'] ?? null)) $data['thumbnail_url'] = $data['image_url'] ?? null;
        return $data;
    }
}
