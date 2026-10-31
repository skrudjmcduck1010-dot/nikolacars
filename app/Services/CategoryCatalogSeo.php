<?php

namespace App\Services;

final class CategoryCatalogSeo
{
    private const SECTIONS = [
        'wheels' => [
            'model' => 'model-3-highland-01-2024',
            'uk' => 'diski-i-sini/diski/diski',
            'ru' => 'diski-i-siny/diski/diski',
            'heading_uk' => 'Диски та ковпаки Tesla Model 3 Highland',
            'heading_ru' => 'Диски и колпаки Tesla Model 3 Highland',
        ],
        'plate' => [
            'model' => '',
            'uk' => 'zovnisnia-furnitura/podium-nomernogo-znaka/podium-nomernogo-znaka',
            'ru' => 'naruznaia-furnitura/podium-nomernogo-znaka/podium-nomernogo-znaka',
            'heading_uk' => 'Подіуми та кронштейни номерного знака Tesla',
            'heading_ru' => 'Подиумы и кронштейны номерного знака Tesla',
        ],
        'rear_brakes' => [
            'model' => '',
            'uk' => 'galma/galmivni-diski-i-suporta/zadni-tormozni-diski-suporta-i-kolodki',
            'ru' => 'tormoza/tormoznye-diski-i-supporta/zadnie-tormoznye-diski-supporta-i-kolodki',
            'heading_uk' => 'Задні гальмівні диски, супорти та колодки Tesla',
            'heading_ru' => 'Задние тормозные диски, суппорты и колодки Tesla',
        ],
    ];

    public static function forSection(string $model, string $path, string $locale): ?array
    {
        $path = CatalogPath::preferred($path, $model);
        foreach (self::SECTIONS as $section) {
            if ($section['model'] === $model && $section[$locale] === $path) {
                $heading = $section['heading_'.$locale];

                return [
                    'heading' => $heading,
                    'title' => $heading.($locale === 'ru' ? ' в Киеве' : ' у Києві'),
                    'description' => $heading.($locale === 'ru'
                        ? ': каталог NikolaCars, артикулы, фото и состояние деталей. Проверяйте наличие и комплектность в карточке; совместимость уточняйте по VIN.'
                        : ': каталог NikolaCars, артикули, фото та стан деталей. Перевіряйте наявність і комплектність у картці; сумісність уточнюйте за VIN.'),
                ];
            }
        }

        return null;
    }

}
