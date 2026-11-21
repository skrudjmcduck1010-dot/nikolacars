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
        if (isset($data['image_items']) && is_array($data['image_items'])) {
            $data['image_items'] = array_values(array_filter(
                $data['image_items'],
                static fn ($item) => is_array($item) && ! self::blocked($item['url'] ?? null),
            ));
            foreach ($data['image_items'] as &$item) {
                // Opaque thumbnails and resized variants belong to their original image.
                // A blocked original must remove the entire item before rendering the gallery.
                if (self::blocked($item['thumbnail_url'] ?? null)) {
                    $item['thumbnail_url'] = $item['url'] ?? null;
                }
                if (isset($item['variants']) && is_array($item['variants'])) {
                    $item['variants'] = array_values(array_filter(
                        $item['variants'],
                        static fn ($variant) => is_array($variant) && ! self::blocked($variant['url'] ?? null),
                    ));
                }
            }
            unset($item);
        }
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
