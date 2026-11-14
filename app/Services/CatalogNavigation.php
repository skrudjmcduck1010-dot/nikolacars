<?php

namespace App\Services;

final class CatalogNavigation
{
    public static function items(array $catalog, string $locale): array
    {
        $selection = $catalog['selection'] ?? [];
        $model = (string) ($selection['model_slug'] ?? '');
        $base = ($locale === 'ru' ? '/ru/parts/' : '/parts/').($model !== '' ? $model.'/' : '');
        $selectedPath = isset($catalog['category_navigation']) ? ($selection['category_path_slug'] ?? '') : '';
        $selected = CatalogPath::localized((string) ($selectedPath ?: ($selection['category_slug'] ?? '')), $locale);
        $selectedUrl = $base.($selected !== '' ? CatalogPath::preferred($selected, $model).'/' : '');
        $nodes = $catalog['category_navigation'] ?? array_map(static fn (array $category): array => [
            'category_path_slug' => $category['slug'], 'parent_path_slug' => '',
            'label' => $category['label'], 'count' => $category['count'], 'has_children' => false,
        ], $catalog['categories'] ?? []);
        $items = [];
        $byUrl = [];
        $byPath = [];
        foreach ($nodes as $node) {
            $path = CatalogPath::localized((string) ($node['category_path_slug'] ?? ''), $locale);
            $label = trim((string) ($node['label'] ?? ''));
            if ($path === '' || $label === '' || preg_match('~^[a-z0-9-]+(?:/[a-z0-9-]+)*$~', $path) !== 1) continue;
            $url = $base.CatalogPath::preferred($path, $model).'/';
            $parent = CatalogPath::localized((string) ($node['parent_path_slug'] ?? ''), $locale);
            $active = $url === $selectedUrl;
            $ancestor = !$active && $selected !== '' && str_starts_with($selected, $path.'/');
            if (isset($byUrl[$url])) {
                // Only reviewed canonical aliases may merge a visual row. Never merge by name.
                $index = $byUrl[$url];
                $items[$index]['active'] = $items[$index]['active'] || $active;
                $items[$index]['ancestor'] = !$items[$index]['active'] && ($items[$index]['ancestor'] || $ancestor);
                $items[$index]['has_children'] = (bool) ($node['has_children'] ?? false);
                $byPath[$path] = $index;
                continue;
            }
            $index = count($items);
            $items[] = [
                'url' => $url, 'label' => $label, 'count' => max(0, (int) ($node['count'] ?? 0)),
                'depth' => isset($byPath[$parent]) ? $items[$byPath[$parent]]['depth'] + 1 : 0,
                'active' => $active, 'ancestor' => $ancestor, 'has_children' => (bool) ($node['has_children'] ?? false),
            ];
            $byUrl[$url] = $index;
            $byPath[$path] = $index;
        }

        return $items;
    }
}
