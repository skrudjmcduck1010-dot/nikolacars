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
            'intro_uk' => 'Колісний диск і ковпак — різні деталі. У цьому розділі вони можуть бути представлені окремими картками: перевіряйте назву, фото та фактичну наявність потрібного типу деталі.',
            'intro_ru' => 'Колёсный диск и колпак — разные детали. В этом разделе они могут быть представлены отдельными карточками: проверяйте название, фото и фактическое наличие нужного типа детали.',
            'fitment_uk' => 'Для підбору звіряйте артикул, діаметр і спосіб кріплення. Позначення Highland саме по собі не підтверджує сумісність: уточнюйте комплектацію та VIN перед замовленням.',
            'fitment_ru' => 'Для подбора сверяйте артикул, диаметр и способ крепления. Обозначение Highland само по себе не подтверждает совместимость: уточняйте комплектацию и VIN перед заказом.',
        ],
        'plate' => [
            'model' => '',
            'uk' => 'zovnisnia-furnitura/podium-nomernogo-znaka/podium-nomernogo-znaka',
            'ru' => 'naruznaia-furnitura/podium-nomernogo-znaka/podium-nomernogo-znaka',
            'heading_uk' => 'Подіуми та кронштейни номерного знака Tesla',
            'heading_ru' => 'Подиумы и кронштейны номерного знака Tesla',
            'intro_uk' => 'Підбирайте кронштейн за моделлю та місцем встановлення: передній і задній подіуми мають різне призначення. Звіряйте формат номерного знака, зокрема для деталей з позначенням USA.',
            'intro_ru' => 'Подбирайте кронштейн по модели и месту установки: передний и задний подиумы имеют разное назначение. Сверяйте формат номерного знака, в том числе для деталей с обозначением USA.',
            'fitment_uk' => 'Перевіряйте артикул, кріплення та комплектність на фото. Наявність гвинтів чи інших елементів уточнюйте для конкретної картки; сумісність — за VIN.',
            'fitment_ru' => 'Проверяйте артикул, крепления и комплектность на фото. Наличие винтов или других элементов уточняйте для конкретной карточки; совместимость — по VIN.',
        ],
        'rear_brakes' => [
            'model' => '',
            'uk' => 'galma/galmivni-diski-i-suporta/zadni-tormozni-diski-suporta-i-kolodki',
            'ru' => 'tormoza/tormoznye-diski-i-supporta/zadnie-tormoznye-diski-supporta-i-kolodki',
            'heading_uk' => 'Задні гальмівні диски, супорти та колодки Tesla',
            'heading_ru' => 'Задние тормозные диски, суппорты и колодки Tesla',
            'intro_uk' => 'У розділі зібрані компоненти задніх гальм: тип деталі, сторона та комплектність вказані в конкретній картці. Диск, супорт, колодки й елементи стоянкового гальма не замінюють один одного.',
            'intro_ru' => 'В разделе собраны компоненты задних тормозов: тип детали, сторона и комплектность указаны в конкретной карточке. Диск, суппорт, колодки и элементы стояночного тормоза не заменяют друг друга.',
            'fitment_uk' => 'Перед замовленням звіряйте артикул і версію гальмівної системи, ліву або праву сторону. Стан вживаної деталі та придатність до встановлення потрібно оцінити до монтажу; підбір уточнюйте за VIN.',
            'fitment_ru' => 'Перед заказом сверяйте артикул и версию тормозной системы, левую или правую сторону. Состояние бывшей в употреблении детали и пригодность к установке нужно оценить до монтажа; подбор уточняйте по VIN.',
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
                    'intro' => $section['intro_'.$locale],
                    'fitment' => $section['fitment_'.$locale],
                ];
            }
        }

        return null;
    }

    public static function links(string $model, string $category, string $path, string $locale): array
    {
        if ($path !== '') return [];
        $links = [];
        foreach (self::SECTIONS as $section) {
            $root = explode('/', $section[$locale])[0];
            if (($model === '' && $category === '') || ($model === $section['model'] && ($category === '' || $category === $root))) {
                $links[] = [
                    'url' => ($locale === 'ru' ? '/ru/parts/' : '/parts/').($section['model'] !== '' ? $section['model'].'/' : '').$section[$locale].'/',
                    'label' => $section['heading_'.$locale],
                ];
            }
        }

        return $links;
    }
}
