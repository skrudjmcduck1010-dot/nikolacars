<?php

// Saved public taxonomy labels, synthetic quantities/prices/images. No database or remote calls.
foreach (['StorefrontCatalogTaxonomy','StorefrontCategoryNavigation','StorefrontCatalogQuery'] as $class) {
    if (!class_exists('App\\Services\\'.$class)) {
        require (getenv('NC_TREE_BACKEND') ?: 'C:/Projects/sklad-zapchastey').'/app/Services/'.$class.'.php';
    }
}
function treeCatalogFixture(array $query): array
{
    $locale = ($query['locale'] ?? 'uk') === 'ru' ? 'ru' : 'uk';
    $rows = collect(json_decode(file_get_contents(__DIR__.'/../fixtures/catalog-tree-rows.json'),true,512,JSON_THROW_ON_ERROR)[$locale]);
    $taxonomy = new App\Services\StorefrontCatalogTaxonomy;
    $rows = $rows->map(fn (array $row): array => $taxonomy->canonicalRow($row,$locale));
    $available = $rows->filter(fn (array $row): bool => $row['quantity'] > 0)->values();
    $sold = $rows->filter(fn (array $row): bool => $row['quantity'] === 0)->values();
    return (new App\Services\StorefrontCatalogQuery)->catalog($query,$available,fn()=>$sold,function (int $id,int $index): array {
        $all=json_decode(file_get_contents(__DIR__.'/../fixtures/catalog-tree-rows.json'),true,512,JSON_THROW_ON_ERROR);
        $slugs=[];
        foreach (['uk','ru'] as $locale) {
            $row=collect($all[$locale])->firstWhere('id',$id);
            $slugs[$locale]=$row['category_breadcrumbs'][$index]['slug']??'';
        }
        return $slugs;
    },fn(array $row):array=>$row);
}
