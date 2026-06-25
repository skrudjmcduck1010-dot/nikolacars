<?php

namespace Tests\Feature;

use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Services\NikolaCarsInventoryService;
use App\Services\NikolaCarsTeslaCategoryResolver;
use App\Services\NikolaCarsTeslaCategoryTreeSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NikolaCarsTeslaCategoryResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_nikolacars_item_category_is_resolved_from_tesla_official_part_prefix(): void
    {
        $category = PartCatalogCategory::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-official://category/body',
            'depth' => 0,
            'name' => 'Body',
            'model_label' => 'Model S',
        ]);
        PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-official://1034344-20-B',
            'part_number' => '1034344-20-B',
            'name' => 'Tesla official part',
            'part_catalog_category_id' => $category->id,
            'model_label' => 'Model S 02.2012-03.2016',
            'model_name' => 'Model S',
            'year_from' => 2012,
            'year_to' => 2016,
            'main_category_name' => 'Body',
            'subcategory_name' => 'Closures',
            'node_name' => 'Hood',
        ]);
        $nikolaCarsItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/1',
            'part_number' => '1034344-99-C',
            'name' => 'NikolaCars part',
            'raw_attributes' => ['code' => '1'],
        ]);

        $result = app(NikolaCarsTeslaCategoryResolver::class)->resolveItem($nikolaCarsItem);
        $fresh = $nikolaCarsItem->fresh();

        $this->assertSame('Body / Closures / Hood', $result['category']);
        $this->assertNull(data_get($fresh->raw_attributes, 'category_display'));
        $this->assertSame("\u{041A}\u{0443}\u{0437}\u{043E}\u{0432} / \u{0414}\u{0432}\u{0435}\u{0440}\u{0438}, \u{043A}\u{0430}\u{043F}\u{043E}\u{0442} \u{0438} \u{0431}\u{0430}\u{0433}\u{0430}\u{0436}\u{043D}\u{0438}\u{043A} / \u{041A}\u{0430}\u{043F}\u{043E}\u{0442}", app(NikolaCarsInventoryService::class)->displayCategory($fresh));
        $this->assertSame('matched', data_get($fresh->raw_attributes, 'tesla_category_match.status'));
        $this->assertSame('seven_digit_prefix', data_get($fresh->raw_attributes, 'tesla_category_match.match_type'));
        $this->assertSame('Body', $fresh->main_category_name);
        $this->assertSame('Closures', $fresh->subcategory_name);
        $this->assertSame('Hood', $fresh->node_name);
        $this->assertSame('Model S 02.2012-03.2016', $fresh->model_label);
        $this->assertSame('Model S', $fresh->model_name);
        $this->assertSame(2012, $fresh->year_from);
        $this->assertSame(2016, $fresh->year_to);
        $this->assertDatabaseHas('part_catalog_categories', [
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://tesla-category/'.$category->id,
            'name' => 'Body',
        ]);
        $this->assertSame(
            PartCatalogCategory::query()->where('source_url', 'nikolacars://tesla-category/'.$category->id)->value('id'),
            $fresh->part_catalog_category_id
        );
    }

    public function test_nikolacars_item_category_is_undetermined_when_part_number_is_invalid(): void
    {
        $nikolaCarsItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/no-article',
            'part_number' => '5YJSA1H1XEFP59563',
            'name' => 'NikolaCars part',
            'raw_attributes' => ['code' => '2'],
        ]);

        app(NikolaCarsTeslaCategoryResolver::class)->resolveItem($nikolaCarsItem);
        $fresh = $nikolaCarsItem->fresh();

        $this->assertNull(data_get($fresh->raw_attributes, 'category_display'));
        $this->assertSame(NikolaCarsTeslaCategoryResolver::UNDETERMINED, app(NikolaCarsInventoryService::class)->displayCategory($fresh));
        $this->assertSame('not_found', data_get($fresh->raw_attributes, 'tesla_category_match.status'));
        $this->assertSame("\u{041D}\u{0435} \u{043E}\u{043F}\u{0440}\u{0435}\u{0434}\u{0435}\u{043B}\u{0435}\u{043D}\u{043E}", $fresh->main_category_name);
        $this->assertDatabaseHas('part_catalog_categories', [
            'source' => 'nikolacars',
            'name' => NikolaCarsTeslaCategoryResolver::UNDETERMINED,
        ]);
    }

    public function test_nikolacars_item_category_accepts_letter_in_middle_part_number_block(): void
    {
        $category = PartCatalogCategory::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-official://category/closure-panels',
            'depth' => 0,
            'name' => 'Closure Panels',
            'model_label' => 'Model 3',
        ]);
        PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-official://1081440-E0-C',
            'part_number' => '1081440-E0-C',
            'name' => 'ASSEMBLY - REAR DOOR - RIGHT HAND',
            'part_catalog_category_id' => $category->id,
            'main_category_name' => 'BODY',
            'subcategory_name' => 'Body Panels',
            'node_name' => 'Closure Panels',
        ]);
        $nikolaCarsItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/609',
            'part_number' => '1081440-E0-C',
            'name' => 'Rear right door',
            'raw_attributes' => ['code' => '609'],
        ]);

        app(NikolaCarsTeslaCategoryResolver::class)->resolveItem($nikolaCarsItem);
        $fresh = $nikolaCarsItem->fresh();

        $this->assertNull(data_get($fresh->raw_attributes, 'category_display'));
        $this->assertSame("\u{041A}\u{0443}\u{0437}\u{043E}\u{0432} / \u{041A}\u{0443}\u{0437}\u{043E}\u{0432}\u{043D}\u{044B}\u{0435} \u{043F}\u{0430}\u{043D}\u{0435}\u{043B}\u{0438} / \u{0414}\u{0432}\u{0435}\u{0440}\u{0438}, \u{043A}\u{0430}\u{043F}\u{043E}\u{0442} \u{0438} \u{043A}\u{0440}\u{044B}\u{0448}\u{043A}\u{0430} \u{0431}\u{0430}\u{0433}\u{0430}\u{0436}\u{043D}\u{0438}\u{043A}\u{0430}", app(NikolaCarsInventoryService::class)->displayCategory($fresh));
        $this->assertSame('matched', data_get($fresh->raw_attributes, 'tesla_category_match.status'));
        $this->assertSame('exact', data_get($fresh->raw_attributes, 'tesla_category_match.match_type'));
        $this->assertSame('1081440', data_get($fresh->raw_attributes, 'tesla_category_match.part_prefix'));
    }

    public function test_nikolacars_item_prefers_localized_tesla_official_category_tree(): void
    {
        $model = PartCatalogCategory::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-official://category/model-3',
            'depth' => 0,
            'name' => 'Model 3',
            'model_label' => 'Model 3',
        ]);
        $body = PartCatalogCategory::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-official://category/body',
            'parent_id' => $model->id,
            'depth' => 1,
            'code' => '10',
            'name' => 'BODY',
            'name_ru' => "10 - \u{041A}\u{0423}\u{0417}\u{041E}\u{0412}",
            'model_label' => 'Model 3',
        ]);
        $closures = PartCatalogCategory::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-official://category/closures',
            'parent_id' => $body->id,
            'depth' => 2,
            'code' => '1020',
            'name' => 'CLOSURE COMPONENTS',
            'name_ru' => "1020 - \u{041A}\u{041E}\u{041C}\u{041F}\u{041E}\u{041D}\u{0415}\u{041D}\u{0422}\u{042B} \u{0417}\u{0410}\u{041A}\u{0420}\u{042B}\u{0422}\u{0418}\u{042F}",
            'model_label' => 'Model 3',
        ]);
        PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-official://1034344-20-B',
            'part_number' => '1034344-20-B',
            'name' => 'Tesla official part',
            'part_catalog_category_id' => $closures->id,
            'main_category_name' => 'BODY',
            'subcategory_name' => 'CLOSURE COMPONENTS',
        ]);
        $nikolaCarsItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/3',
            'part_number' => '1034344-99-C',
            'name' => 'NikolaCars part',
            'raw_attributes' => ['code' => '3'],
        ]);

        app(NikolaCarsTeslaCategoryResolver::class)->resolveItem($nikolaCarsItem);
        $fresh = $nikolaCarsItem->fresh();

        $this->assertSame(
            "\u{041A}\u{0443}\u{0437}\u{043E}\u{0432} / \u{041A}\u{043E}\u{043C}\u{043F}\u{043E}\u{043D}\u{0435}\u{043D}\u{0442}\u{044B} \u{0437}\u{0430}\u{043A}\u{0440}\u{044B}\u{0442}\u{0438}\u{044F}",
            app(NikolaCarsInventoryService::class)->displayCategory($fresh)
        );
        $this->assertNull(data_get($fresh->raw_attributes, 'category_display'));
        $this->assertSame("\u{041A}\u{0443}\u{0437}\u{043E}\u{0432}", $fresh->main_category_name);
        $mirrorModel = PartCatalogCategory::query()->where('source_url', 'nikolacars://tesla-category/'.$model->id)->firstOrFail();
        $mirrorBody = PartCatalogCategory::query()->where('source_url', 'nikolacars://tesla-category/'.$body->id)->firstOrFail();
        $mirrorClosures = PartCatalogCategory::query()->where('source_url', 'nikolacars://tesla-category/'.$closures->id)->firstOrFail();

        $this->assertNull($mirrorModel->parent_id);
        $this->assertSame($mirrorModel->id, $mirrorBody->parent_id);
        $this->assertSame($mirrorBody->id, $mirrorClosures->parent_id);
        $this->assertSame($mirrorClosures->id, $fresh->part_catalog_category_id);
        $this->assertSame("\u{041A}\u{0423}\u{0417}\u{041E}\u{0412}", $mirrorBody->name_ru);
        $this->assertSame("\u{041A}\u{041E}\u{041C}\u{041F}\u{041E}\u{041D}\u{0415}\u{041D}\u{0422}\u{042B} \u{0417}\u{0410}\u{041A}\u{0420}\u{042B}\u{0422}\u{0418}\u{042F}", $mirrorClosures->name_ru);
    }

    public function test_nikolacars_display_category_prefers_tesla_official_path_over_stale_donor_category(): void
    {
        $model = PartCatalogCategory::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-official://category/model-3-interior',
            'depth' => 0,
            'name' => 'Model 3',
            'model_label' => 'Model 3',
        ]);
        $interior = PartCatalogCategory::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-official://category/interior-trim',
            'parent_id' => $model->id,
            'depth' => 1,
            'name' => 'INTERIOR TRIM',
            'name_ru' => "\u{0412}\u{043D}\u{0443}\u{0442}\u{0440}\u{0435}\u{043D}\u{043D}\u{044F}\u{044F} \u{043E}\u{0442}\u{0434}\u{0435}\u{043B}\u{043A}\u{0430}",
            'model_label' => 'Model 3',
        ]);
        $pillar = PartCatalogCategory::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-official://category/pillar-and-sill-trim',
            'parent_id' => $interior->id,
            'depth' => 2,
            'name' => 'Pillar and Sill Trim',
            'name_ru' => "\u{041E}\u{0431}\u{0448}\u{0438}\u{0432}\u{043A}\u{0430} \u{0441}\u{0442}\u{043E}\u{0435}\u{043A} A-B-C \u{0438} \u{043F}\u{043E}\u{0440}\u{043E}\u{0433}\u{043E}\u{0432}",
            'model_label' => 'Model 3',
        ]);
        $official = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=1086255-01-J',
            'part_number' => '1086255-01-J',
            'name' => 'B-PILLAR UPPER TRIM ASSEMBLY - RIGHT HAND - PREMIUM',
            'part_catalog_category_id' => $pillar->id,
            'main_category_name' => 'INTERIOR TRIM',
            'subcategory_name' => 'Pillar and Sill Trim',
            'node_name' => 'A-B-C Post Interior Trim',
        ]);
        $staleCategory = PartCatalogCategory::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://donor-car/18/category/stale',
            'depth' => 1,
            'name' => 'Stale donor category',
            'name_ru' => 'Старая донорская категория',
            'model_label' => 'Model 3',
        ]);
        $nikolaCarsItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://donor-product/96851',
            'part_number' => '1086255-01-J',
            'name' => 'Donor trim',
            'part_catalog_category_id' => $staleCategory->id,
            'main_category_name' => "\u{0412}\u{043D}\u{0443}\u{0442}\u{0440}\u{0435}\u{043D}\u{043D}\u{044F}\u{044F} \u{043E}\u{0442}\u{0434}\u{0435}\u{043B}\u{043A}\u{0430}",
            'subcategory_name' => "\u{041E}\u{0431}\u{0448}\u{0438}\u{0432}\u{043A}\u{0430} \u{0441}\u{0442}\u{043E}\u{0435}\u{043A} a-b-c \u{0438} \u{043F}\u{043E}\u{0440}\u{043E}\u{0433}\u{043E}\u{0432}",
            'node_name' => "\u{0421}\u{0442}\u{0430}\u{0440}\u{044B}\u{0439} / DriveParts / \u{043F}\u{0443}\u{0442}\u{044C}",
            'raw_attributes' => [
                'source_catalog_item_id' => $official->id,
                'source_catalog_source' => 'tesla_official',
            ],
        ]);

        $this->assertSame(
            "\u{0412}\u{043D}\u{0443}\u{0442}\u{0440}\u{0435}\u{043D}\u{043D}\u{044F}\u{044F} \u{043E}\u{0442}\u{0434}\u{0435}\u{043B}\u{043A}\u{0430} / \u{041E}\u{0431}\u{0448}\u{0438}\u{0432}\u{043A}\u{0430} \u{0441}\u{0442}\u{043E}\u{0435}\u{043A} A-B-C \u{0438} \u{043F}\u{043E}\u{0440}\u{043E}\u{0433}\u{043E}\u{0432}",
            app(NikolaCarsInventoryService::class)->displayCategory($nikolaCarsItem)
        );
    }

    public function test_nikolacars_tesla_category_tree_sync_updates_localized_names(): void
    {
        $model = PartCatalogCategory::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-official://category/model-y',
            'depth' => 0,
            'name' => 'Model Y',
            'name_ru' => 'Model Y RU',
            'name_ua' => 'Model Y UA',
            'model_label' => 'Model Y',
        ]);
        $body = PartCatalogCategory::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-official://category/body-y',
            'parent_id' => $model->id,
            'depth' => 1,
            'name' => 'Body',
            'name_ru' => "\u{041A}\u{0443}\u{0437}\u{043E}\u{0432}",
            'name_ua' => "\u{041A}\u{0443}\u{0437}\u{043E}\u{0432} UA",
            'model_label' => 'Model Y',
        ]);

        app(NikolaCarsTeslaCategoryTreeSyncService::class)->syncAll();

        $body->forceFill([
            'name_ru' => "\u{041A}\u{0443}\u{0437}\u{043E}\u{0432} updated",
            'name_ua' => "\u{041A}\u{0443}\u{0437}\u{043E}\u{0432} \u{043E}\u{043D}\u{043E}\u{0432}\u{043B}\u{0435}\u{043D}\u{043E}",
        ])->save();

        app(NikolaCarsTeslaCategoryTreeSyncService::class)->syncAll();
        $mirrorBody = PartCatalogCategory::query()->where('source_url', 'nikolacars://tesla-category/'.$body->id)->firstOrFail();

        $this->assertSame(
            PartCatalogCategory::query()->where('source_url', 'nikolacars://tesla-category/'.$model->id)->value('id'),
            $mirrorBody->parent_id
        );
        $this->assertSame("\u{041A}\u{0443}\u{0437}\u{043E}\u{0432} updated", $mirrorBody->name_ru);
        $this->assertSame("\u{041A}\u{0443}\u{0437}\u{043E}\u{0432} \u{043E}\u{043D}\u{043E}\u{0432}\u{043B}\u{0435}\u{043D}\u{043E}", $mirrorBody->name_ua);
    }

    public function test_resolve_all_can_update_only_undetermined_nikolacars_categories_by_seven_digit_prefix(): void
    {
        $category = PartCatalogCategory::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-official://category/interior',
            'depth' => 0,
            'name' => 'Interior',
            'model_label' => 'Model Y',
        ]);
        PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-official://1501462-12-A',
            'part_number' => '1501462-12-A',
            'name' => 'Tesla official part',
            'part_catalog_category_id' => $category->id,
            'main_category_name' => 'Interior',
            'subcategory_name' => 'Seats',
            'node_name' => 'Front seat',
        ]);
        $undetermined = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/150',
            'part_number' => '1501462-99-C',
            'name' => 'NikolaCars undetermined part',
            'main_category_name' => "\u{041D}\u{0435} \u{043E}\u{043F}\u{0440}\u{0435}\u{0434}\u{0435}\u{043B}\u{0435}\u{043D}\u{043E}",
            'raw_attributes' => [
                'code' => '150',
                'category_display' => "\u{041D}\u{0435} \u{043E}\u{043F}\u{0440}\u{0435}\u{0434}\u{0435}\u{043B}\u{0435}\u{043D}\u{043E}",
            ],
        ]);
        $alreadyCategorized = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/151',
            'part_number' => '1501462-88-B',
            'name' => 'NikolaCars categorized part',
            'main_category_name' => 'Manual category',
            'raw_attributes' => [
                'code' => '151',
                'category_display' => 'Manual category',
            ],
        ]);

        $stats = app(NikolaCarsTeslaCategoryResolver::class)->resolveAll(['missing_only' => true]);

        $this->assertSame(2, $stats['items_seen']);
        $this->assertSame(1, $stats['items_skipped']);
        $this->assertSame(1, $stats['items_updated']);
        $freshUndetermined = $undetermined->fresh();
        $this->assertNull(data_get($freshUndetermined->raw_attributes, 'category_display'));
        $this->assertSame("\u{0418}\u{043D}\u{0442}\u{0435}\u{0440}\u{044C}\u{0435}\u{0440} / \u{0421}\u{0438}\u{0434}\u{0435}\u{043D}\u{044C}\u{044F} / Front seat", app(NikolaCarsInventoryService::class)->displayCategory($freshUndetermined));
        $this->assertSame('matched', data_get($freshUndetermined->raw_attributes, 'tesla_category_match.status'));
        $this->assertSame('Manual category', data_get($alreadyCategorized->fresh()->raw_attributes, 'category_display'));
    }

    public function test_resolve_all_treats_legacy_mojibake_undetermined_categories_as_missing(): void
    {
        $legacyUndetermined = mb_convert_encoding(
            NikolaCarsTeslaCategoryResolver::UNDETERMINED,
            'UTF-8',
            'Windows-1251'
        );
        $nikolaCarsItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/legacy-undetermined',
            'part_number' => 'not-a-tesla-part',
            'name' => 'NikolaCars legacy undetermined part',
            'main_category_name' => $legacyUndetermined,
            'raw_attributes' => [
                'code' => 'legacy-undetermined',
                'category_display' => $legacyUndetermined,
            ],
        ]);

        $stats = app(NikolaCarsTeslaCategoryResolver::class)->resolveAll(['missing_only' => true]);
        $fresh = $nikolaCarsItem->fresh();

        $this->assertSame(1, $stats['items_seen']);
        $this->assertSame(0, $stats['items_skipped']);
        $this->assertSame(1, $stats['items_updated']);
        $this->assertNull(data_get($fresh->raw_attributes, 'category_display'));
        $this->assertSame(NikolaCarsTeslaCategoryResolver::UNDETERMINED, app(NikolaCarsInventoryService::class)->displayCategory($fresh));
        $this->assertSame(NikolaCarsTeslaCategoryResolver::UNDETERMINED, $fresh->main_category_name);
    }

}
