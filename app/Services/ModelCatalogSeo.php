<?php

namespace App\Services;

final class ModelCatalogSeo
{
    public static function forModel(string $modelSlug, string $locale): ?array
    {
        $models = [
            'model-3-06-2017-12-2023' => ['name' => 'Model 3', 'years' => '2017–2023', 'period' => '06.2017–12.2023'],
            'model-y-01-2020-01-2025' => ['name' => 'Model Y', 'years' => '2020–2025', 'period' => '01.2020–01.2025'],
            'model-s-04-2016-01-2021' => ['name' => 'Model S', 'years' => '2016–2021', 'period' => '04.2016–01.2021'],
        ];
        if (!isset($models[$modelSlug])) return null;
        $model = $models[$modelSlug];
        $isRu = $locale === 'ru';
        $heading = ($isRu ? 'Запчасти Tesla ' : 'Запчастини Tesla ').$model['name'].' '.$model['years'];

        return [
            'heading' => $heading,
            'title' => $heading.($isRu ? ' в Киеве' : ' у Києві'),
            'description' => $heading.($isRu
                ? ': каталог NikolaCars, артикулы, цены и фото деталей. Подбор по номеру детали; совместимость уточняйте по VIN перед заказом.'
                : ': каталог NikolaCars, артикули, ціни та фото деталей. Підбір за номером деталі; сумісність уточнюйте за VIN перед замовленням.'),
        ];
    }
}
