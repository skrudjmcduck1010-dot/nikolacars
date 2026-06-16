<?php

namespace Tests\Unit;

use App\Services\TcarserviceCatalogImporter;
use Illuminate\Http\Client\Factory as HttpFactory;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class TcarserviceCatalogImporterTest extends TestCase
{
    public function test_product_links_include_service_part_numbers_with_letters_in_revision_segment(): void
    {
        $html = <<<'HTML'
            <a href="/ru/kompjuter-avtomobilja-amd-s-versiej-avtopilota-3-0-evropejskij-tesla-model-3-2023-1681271-s0-j">Service computer</a>
            <a href="/ru/kompjuter-avtomobilnyj-mcu-rev3-tesla-model-3-1681271-00-g">Standard computer</a>
            <a href="/klapan-ventilyaci-salonu-v-zadnomu-bagazhnomu-vidsiku-tesla-model-s2012-2021-s-plaid-x2015-2021-x-plaid-6007556">Bare part number</a>
            <a href="/ru/zapchasty/model-3-326">Category</a>
        HTML;

        $importer = new TcarserviceCatalogImporter(new HttpFactory);
        $page = $this->invokeProtected($importer, 'page', [$html]);
        $links = $this->invokeProtected($importer, 'productLinks', [$page, 'https://tcarservice.com']);

        $this->assertContains(
            'https://tcarservice.com/ru/kompjuter-avtomobilja-amd-s-versiej-avtopilota-3-0-evropejskij-tesla-model-3-2023-1681271-s0-j',
            $links
        );
        $this->assertContains(
            'https://tcarservice.com/ru/kompjuter-avtomobilnyj-mcu-rev3-tesla-model-3-1681271-00-g',
            $links
        );
        $this->assertContains(
            'https://tcarservice.com/klapan-ventilyaci-salonu-v-zadnomu-bagazhnomu-vidsiku-tesla-model-s2012-2021-s-plaid-x2015-2021-x-plaid-6007556',
            $links
        );
        $this->assertNotContains('https://tcarservice.com/ru/zapchasty/model-3-326', $links);
    }

    public function test_child_category_links_only_include_descendants_of_current_category(): void
    {
        $html = <<<'HTML'
            <a href="/zapchasty/model-s-321">Model S</a>
            <a href="/zapchasty/model-s-321/13-seats-8">Parent section</a>
            <a href="/zapchasty/model-s-321/13-seats-8/1307-front-seat-covers-pads-and-trims-48">Sibling section</a>
            <a href="/zapchasty/model-s-321/13-seats-8/1306-3rd-row-seat-assemblies-and-hardware-47/3rd-row-seat-assemblies-180">Child section</a>
            <a href="/some-product-1234567-00-a">Product</a>
        HTML;

        $importer = new TcarserviceCatalogImporter(new HttpFactory);
        $page = $this->invokeProtected($importer, 'page', [$html]);
        $links = $this->invokeProtected($importer, 'childCategoryLinks', [
            $page,
            'https://tcarservice.com/zapchasty/model-s-321/13-seats-8/1306-3rd-row-seat-assemblies-and-hardware-47',
            'https://tcarservice.com',
        ]);

        $this->assertSame([
            'https://tcarservice.com/zapchasty/model-s-321/13-seats-8/1306-3rd-row-seat-assemblies-and-hardware-47/3rd-row-seat-assemblies-180',
        ], $links);
    }

    public function test_excluded_urls_include_rivian_catalog_paths_with_language_prefix(): void
    {
        $importer = new TcarserviceCatalogImporter(new HttpFactory);

        $this->assertTrue($this->invokeProtected($importer, 'isExcludedUrl', [
            'https://tcarservice.com/ru/zapchasty/rivian-2157',
            ['/zapchasty/rivian-'],
        ]));
    }

    public function test_attributes_stop_at_next_known_label_even_when_labels_are_reordered(): void
    {
        $text = 'Наявність (запчастини) Під замовлення Сумісність (запчастини) Model S 02.2012 - 03.2016, Model S2 04.2016 - 01.2021 Опис Деталі';

        $importer = new TcarserviceCatalogImporter(new HttpFactory);
        $attributes = $this->invokeProtected($importer, 'attributes', [$text]);

        $this->assertSame('Під замовлення', $attributes['Наявність (запчастини)']);
        $this->assertSame(
            'Model S 02.2012 - 03.2016, Model S2 04.2016 - 01.2021',
            $attributes['Сумісність (запчастини)']
        );
    }

    public function test_price_does_not_merge_bare_part_number_with_amount(): void
    {
        $importer = new TcarserviceCatalogImporter(new HttpFactory);

        $price = $this->invokeProtected($importer, 'price', ["Part #: 6007556 652 \xE2\x82\xB4 Add"]);

        $this->assertSame(652.0, $price);
    }

    public function test_listing_price_prefers_card_data_sum(): void
    {
        $html = <<<'HTML'
            <div class="column card-parts" data-sum="2392">
                <p class="price">2 392 ₴</p>
                <a href="https://tcarservice.com/zamok-krishki-bagazhnika-1500604-00-b">Product</a>
            </div>
        HTML;

        $importer = new TcarserviceCatalogImporter(new HttpFactory);
        $page = $this->invokeProtected($importer, 'page', [$html]);
        $card = $page['xpath']->query('//*[contains(concat(" ", normalize-space(@class), " "), " card-parts ")]')->item(0);

        $price = $this->invokeProtected($importer, 'listingPrice', [$page, $card]);

        $this->assertSame(2392.0, $price);
    }

    public function test_tcars_storage_protocol_relative_images_use_site_host(): void
    {
        $importer = new TcarserviceCatalogImporter(new HttpFactory);

        $url = $this->invokeProtected($importer, 'absoluteUrl', [
            '//storage/editor/fotos/a6a57d450a0bcdd3c4d9616ad46c9a7a_1779183362.jpg',
            'https://tcarservice.com',
        ]);

        $this->assertSame(
            'https://tcarservice.com/storage/editor/fotos/a6a57d450a0bcdd3c4d9616ad46c9a7a_1779183362.jpg',
            $url
        );
    }

    public function test_product_image_urls_skip_empty_size_placeholder_paths(): void
    {
        $html = <<<'HTML'
            <img src="/storage/editor/fotos/1000x0/.jpg">
            <img src="/storage/editor/fotos/a60859ed1b4081e152789c9facddc3d1_1729065978.jpg">
        HTML;

        $importer = new TcarserviceCatalogImporter(new HttpFactory);
        $page = $this->invokeProtected($importer, 'page', [$html]);
        $urls = $this->invokeProtected($importer, 'productImageUrls', [
            $page,
            'https://tcarservice.com/example',
        ]);

        $this->assertSame([
            'https://tcarservice.com/storage/editor/fotos/a60859ed1b4081e152789c9facddc3d1_1729065978.jpg',
        ], $urls);
    }

    public function test_condition_year_is_moved_to_note(): void
    {
        $importer = new TcarserviceCatalogImporter(new HttpFactory);

        [$condition, $note] = $this->invokeProtected($importer, 'conditionAndNote', ['б/у Рік 2016']);

        $this->assertSame('б/у', $condition);
        $this->assertSame('Рік 2016', $note);
    }

    protected function invokeProtected(object $object, string $method, array $args): mixed
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $args);
    }
}
