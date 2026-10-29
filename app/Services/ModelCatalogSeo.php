<?php

namespace App\Services;

final class ModelCatalogSeo
{
    public static function forModel(string $modelSlug, string $locale): ?array
    {
        $models = [
            'model-3-06-2017-12-2023' => ['name' => 'Model 3', 'years' => '2017–2023', 'period' => '06.2017–12.2023'],
            'model-y-01-2020-01-2025' => ['name' => 'Model Y', 'years' => '2020–2025', 'period' => '01.2020–01.2025'],
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
            'intro' => $isRu
                ? 'Каталог для Tesla '.$model['name'].' периода '.$model['period'].'. Выберите узел или найдите деталь по названию и артикулу. Цена, фото и состояние указаны в карточке товара.'
                : 'Каталог для Tesla '.$model['name'].' періоду '.$model['period'].'. Оберіть вузол або знайдіть деталь за назвою та артикулом. Ціна, фото та стан зазначені в картці товару.',
            'fitment' => $isRu
                ? 'Перед заказом сверяйте номер и ревизию детали, сторону установки и разъёмы. Совпадения модели и года недостаточно: совместимость уточняйте по VIN.'
                : 'Перед замовленням звіряйте номер і ревізію деталі, бік встановлення та роз’єми. Збігу моделі й року недостатньо: сумісність уточнюйте за VIN.',
            'links_label' => $isRu ? 'Разделы каталога' : 'Розділи каталогу',
        ];
    }
}
