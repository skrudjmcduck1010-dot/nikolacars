<?php

namespace App\Services;

use App\Models\PartCatalogItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PartCatalogCsvExportService
{
    public const SUPPORTED_SOURCES = [
        'tcarservice',
        'teslapartsukraine',
        'tsk',
        'stock-tesla',
        'teslahelp',
        'driveparts',
        'dkparts',
        'erazborka',
        'toprazborka',
        'teslawestparts',
        'teslacompany',
        'tesla_official',
    ];

    public function filename(string $source): string
    {
        return $source.'-parts-catalog-'.now()->format('Ymd-His').'.csv';
    }

    public function stream(string $source, callable $filterQuery, callable $displayItemName, callable $displayCategoryName, callable $displayItemCondition): void
    {
        $handle = fopen('php://output', 'w');

        if ($handle === false) {
            return;
        }

        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, [
            'Источник',
            'Артикул',
            'Название',
            'Название RU',
            'Название UA',
            'Модель',
            'Категория',
            'Подкатегория',
            'Узел',
            'Цена',
            'Валюта',
            'Наличие',
            'Состояние',
            'Качество',
            'Ссылка',
        ], ';');

        PartCatalogItem::query()
            ->with('category:id,name,name_ru,name_ua')
            ->tap(fn (Builder $builder) => $filterQuery($builder, $source))
            ->orderBy('model_label')
            ->orderBy('part_number')
            ->orderBy('name')
            ->chunkById(500, function (Collection $items) use ($handle, $displayCategoryName, $displayItemCondition, $displayItemName): void {
                foreach ($items as $item) {
                    fputcsv($handle, [
                        $item->source,
                        $item->part_number,
                        $displayItemName($item),
                        $item->name_ru,
                        $item->name_ua,
                        $item->model_label ?: $item->model_name,
                        $item->main_category_name ?: ($item->category ? $displayCategoryName($item->category) : ''),
                        $item->subcategory_name,
                        $item->node_name,
                        $item->price_amount,
                        $item->currency,
                        $item->availability,
                        $displayItemCondition($item),
                        $item->quality,
                        $item->source_url,
                    ], ';');
                }
            });

        fclose($handle);
    }
}
