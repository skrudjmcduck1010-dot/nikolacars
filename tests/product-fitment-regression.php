<?php

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/e2e/catalog-fixture.php';

use App\Services\ProductCatalogFacts;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$config = ['105004' => '1004419-00-H', '91256' => '1004419-00-M', '102415' => '1004424-00-G', '113113' => '1008471-00-A', '103745' => '1007325-00-B', '103584' => '1005855-00-C', '103646' => '1008573-00-E', '107696' => '1007566-00-C'];
$fakeRows = [];
foreach ($config as $id => $sku) {
    $fakeRows[$id] = array_replace(cartCatalogFixture('uk')['products'][0], [
        'id' => $id, 'part_number' => $sku, 'url_slug' => strtolower($sku).'-'.$id,
        'name' => 'Original stock name', 'model' => 'Model S 04.2016-01.2021',
        'model_slug' => 'model-s-04-2016-01-2021', 'sku' => 'SHOP-'.$id,
        'vin' => 'TESLA МS 2015 - 2021 залишки', 'compatibility' => 'TESLA МS 2015 - 2021 залишки',
    ]);
}
$control = array_replace($fakeRows[102415], ['id'=>98765, 'url_slug'=>'1004424-00-g-98765', 'name'=>'Control', 'vin'=>'5YJSA1E28HF000001', 'compatibility'=>'Model S 2016']);
$fakeRows[$control['id']] = $control;

Http::preventStrayRequests();
Http::fake(function ($r) use ($fakeRows) {
    $path = (string) parse_url($r->url(), PHP_URL_PATH);
    if ($r->method() !== 'GET') {
        throw new RuntimeException('Unexpected non-GET request.');
    }
    $badLabel = 'Електропроводка дверей и крышки багажника';
    $leaf = 'elektrika--elektroprovodka--elektroprovodka-dverei-i-kryski-bagaznika';
    $trail = [['label' => 'Электрика', 'slug' => 'elektrika'], ['label' => 'Электропроводка', 'slug' => 'elektrika--elektroprovodka'], ['label' => $badLabel, 'slug' => $leaf]];
    $category = ['category_path' => implode(' / ', array_column($trail,'label')), 'category_breadcrumbs' => $trail];
    if (str_ends_with($path, '/catalog')) {
        $payload = cartCatalogFixture($r['locale']);
        $payload['products'] = array_values($fakeRows);
        $payload['category_navigation'] = [['label'=>$badLabel,'category_path_slug'=>$leaf,'parent_path_slug'=>'','count'=>3,'has_children'=>false]];
        if (($r['category_path_slug'] ?? '') === $leaf) {
            $payload['selection'] = $category + [
                'category_path_slug'=>$leaf,
                'category_path_locale_slugs'=>['ru'=>$leaf,'uk'=>$leaf],
            ];
        }
        $payload['pagination']['total'] = count($fakeRows);

        return Http::response($payload);
    }
    if (preg_match('~/products/(\d+)$~D', $path, $m) && isset($fakeRows[(int) $m[1]])) {
        $row = array_replace($fakeRows[(int) $m[1]], $category);
        $row['similar_products'] = $row['id'] !== 113113 ? [$fakeRows[113113]] : [];
        return Http::response($row);
    }
    throw new RuntimeException('Unexpected endpoint: '.$path);
});
$read = function (string $path) use ($kernel): string {
    $request = Request::create('http://127.0.0.1:4327'.$path);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    if ($response->getStatusCode() !== 200) {
        throw new RuntimeException('Unexpected status '.$response->getStatusCode().' for '.$path);
    }
    return $response->getContent();
};
$check = function (bool $ok, string $message): void {
    if (! $ok) {
        throw new RuntimeException($message);
    }
};
foreach (['uk','ru'] as $locale) {
    $base = $locale === 'ru' ? '/ru/parts/' : '/parts/';
    $json = json_decode($read($base.'api/catalog/'), true, flags: JSON_THROW_ON_ERROR);
    $rows = array_column($json['products'], null, 'id');
    $check($rows[98765]['compatibility'] === 'Model S 2016' && $rows[98765]['vin'] === $control['vin'], 'Independent product with same full OEM must retain its compatibility and real VIN');
    $check($rows[113113]['vin'] === '' && str_contains($rows[113113]['display_part_number'], 'модуля'), 'Catalog must not show a pseudo-VIN or imply module number identifies lever');
    $check($rows[103646]['compatibility'] === '' && $rows[103646]['model'] === 'Model S', 'No invented fitment for console trim without exact EPC match');
    $check(str_contains($rows[103745]['compatibility'], '1007325-00-C') && str_contains($rows[103745]['compatibility'], '2013'), 'Carpet must refer to exact supported revision');
    $check(str_contains($rows[107696]['name'], $locale === 'ru' ? 'Левый' : 'Лівий') && str_contains($rows[103584]['name'], $locale === 'ru' ? 'правой' : 'правих'), 'Side and correct part names in both languages');
    $catalog = $read($base);
    if ($locale === 'ru') {
        $bad = 'Електропроводка дверей и крышки багажника';
        $good = 'Электропроводка дверей и крышки багажника';
        $slug = 'elektrika/elektroprovodka/elektroprovodka-dverei-i-kryski-bagaznika/';
        $check($json['navigation'][0]['label'] === $good && str_ends_with($json['navigation'][0]['url'], $slug), 'AJAX category tree must preserve its existing path');
        $categoryHtml = $read($base.$slug);
        $check(str_contains($categoryHtml,$good) && !str_contains($categoryHtml,$bad), 'RU category heading and links use the same corrected label');
        $wireHtml = $read($base.$fakeRows[102415]['url_slug'].'/');
        $check(str_contains($wireHtml,$good) && !str_contains($wireHtml,$bad), 'RU product breadcrumb and summary use corrected label');
    }
    $check(str_contains($catalog, $rows[113113]['display_part_number']) && str_contains($catalog, $rows[107696]['name']), 'First server-rendered catalog page contains localized facts');
    foreach ([102415,113113] as $id) {
        $html = $read($base.$fakeRows[$id]['url_slug'].'/');
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);
        $heading = $xpath->evaluate('string(//h1)');
        $title = $xpath->evaluate('string(//title)');
        $meta = $xpath->evaluate('string(//meta[@name="description"]/@content)');
        $fitment = $xpath->evaluate('string(//*[contains(@class,"product-compatibility")])');
        $check(!str_contains($heading, '2016-01.2021') && !str_contains($title, '2016-01.2021') && !str_contains($html, 'залишки'), 'No implied donor generation or internal label on '.$locale.'/'.$id);
        $check(str_contains($fitment, $id === 102415 ? 'RENC' : '1008471-00-A') && str_contains($meta, $id === 102415 ? 'RENC' : '1008471-00-A'), 'Verified facts in text and SEO for '.$locale.'/'.$id);
        if ($id === 113113) {
            $schema = null;
            foreach ($xpath->query('//script[@type="application/ld+json"]') as $script) {
                $decoded = json_decode($script->textContent, true, flags: JSON_THROW_ON_ERROR);
                if (($decoded['@type'] ?? '') === 'Product') { $schema = $decoded; break; }
            }
            $check(is_array($schema) && !array_key_exists('mpn', $schema) && $schema['sku'] === 'SHOP-113113', 'Assembly MPN cannot be advertised as the lever MPN');
        } else {
            $check(str_contains($html, $rows[113113]['display_part_number']), 'Related parts must also label assembly number');
        }
    }
    $damaged = array_replace($control, ['id'=>91256,'part_number'=>'1004419-00-M','name'=>'Проводка без одного разъёма','damage_description'=>'Одного разъёма нет']);
    $reviewed = ProductCatalogFacts::apply($damaged, $locale);
    $check($reviewed['name'] === $damaged['name'] && $reviewed['damage_description'] === $damaged['damage_description'], 'Preserve damage disclosure and title for 91256');
    foreach ([105004 => 'REEU', 91256 => 'DRLH'] as $harnessId => $option) {
        $check(str_contains($rows[$harnessId]['compatibility'], $option) && str_contains($rows[$harnessId]['compatibility'], 'X031'), 'Exact left harness must include configuration');
        $detail = $read($base.$fakeRows[$harnessId]['url_slug'].'/');
        $check(str_contains($detail, $option) && str_contains($detail, 'X031'), 'Both locales show reviewed option combinations for each harness');
    }
    $stockName = $locale === 'ru' ? 'Передняя накладка центральной консоли' : 'Передня накладка центральної консолі';
    $check($rows[103646]['name'] === $stockName, 'Localized corrected name for only this console trim');
    $newRevision = array_replace($fakeRows[102415], ['part_number'=>'1004424-00-H','compatibility'=>'Known row']);
    $check(ProductCatalogFacts::apply($newRevision,$locale)['compatibility'] === 'Known row', 'Do not reuse evidence across revisions');
}
echo "PASS public product fitment, donor markers, localized lists and offers for both languages.\n";
