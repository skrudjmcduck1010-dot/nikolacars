<?php

namespace Tests\Unit;

use App\Models\PartCatalogCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartCatalogCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_names_store_code_only_in_code_column(): void
    {
        $category = PartCatalogCategory::query()->create([
            'source_url' => 'https://parts.tesla.com/catalogs/model-3/closures',
            'depth' => 1,
            'code' => '11',
            'name' => '11 - Closure Components',
            'name_en' => '11 - Closure Components',
            'name_ru' => '11 - Компоненты Закрытия',
            'name_ua' => '11 - Компоненти Закриття',
        ]);

        $category->refresh();

        $this->assertSame('11', $category->code);
        $this->assertSame('Closure Components', $category->name);
        $this->assertSame('Closure Components', $category->name_en);
        $this->assertSame('Компоненты Закрытия', $category->name_ru);
        $this->assertSame('Компоненти Закриття', $category->name_ua);
    }

    public function test_category_name_keeps_different_leading_numbers(): void
    {
        $category = PartCatalogCategory::query()->create([
            'source_url' => 'https://parts.tesla.com/catalogs/model-3/special',
            'depth' => 1,
            'code' => '11',
            'name' => '1120 - Trunk Lid Equipment',
        ]);

        $category->refresh();

        $this->assertSame('1120 - Trunk Lid Equipment', $category->name);
    }
}
