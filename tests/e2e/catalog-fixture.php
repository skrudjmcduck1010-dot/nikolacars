<?php

function cartCatalogFixture(string $locale): array
{
    $product = [
        'id' => 1001, 'url_slug' => 'synthetic-part-1001',
        'name' => $locale === 'ru' ? 'Тестовая запчасть' : 'Тестова запчастина',
        'part_number' => 'TEST-001', 'sku' => 'SYNTHETIC-001', 'vin' => '',
        'model' => 'Model 3', 'model_slug' => 'model-3-06-2017-12-2023',
        'category' => '', 'category_slug' => '', 'category_path' => '', 'category_breadcrumbs' => [],
        'price_uah' => 125, 'quantity' => 3, 'availability_status' => 'available',
        'image_url' => '/__cart_e2e/part.svg', 'thumbnail_url' => '/__cart_e2e/part.svg',
        'images' => ['/__cart_e2e/part.svg'],
    ];

    return [
        'models' => [], 'categories' => [], 'selection' => [],
        'products' => [
            $product,
            array_replace($product, ['id' => 1002, 'url_slug' => 'inquiry-1002', 'name' => 'Inquiry fixture', 'price_uah' => 0]),
            array_replace($product, ['id' => 1003, 'url_slug' => 'sold-1003', 'name' => 'Sold fixture', 'quantity' => 0, 'availability_status' => 'sold']),
        ],
        'pagination' => ['page' => 1, 'last_page' => 1, 'total' => 3, 'per_page' => 24],
    ];
}

function compatibilityProductFixture(string $locale): array
{
    return array_replace(cartCatalogFixture($locale)['products'][0], [
        'id' => 100001, 'url_slug' => 'test-001-100001',
        'name' => $locale === 'ru' ? 'Совместимая тестовая запчасть' : 'Сумісна тестова запчастина',
        'description' => $locale === 'ru' ? 'Синтетическое описание товара.' : 'Синтетичний опис товару.',
        'condition' => 'used', 'length_cm' => 20, 'width_cm' => 15, 'height_cm' => 5,
        'similar_products' => [], 'subcategory_products' => [],
    ]);
}
