<?php

namespace App\Services;

final class ProductCatalogFacts
{
    // Tesla Model S Parts Manual (North America), confirmed against the items on sale.
    // An ID and the full revision must both match. A seven-digit prefix is not fitment evidence.
    // Pages 120, 260, 337/146, 78 and 221 respectively; source links in the catalog report.
    private const REVIEWED = [
        105004 => [
            'article' => '1004419-00-H',
            'ru' => 'Проводка передней левой двери Model S с левым рулём и комплектацией Tech. Для этой ревизии Tesla указывает два периода выпуска: 01.05–28.05.2013 с кодами REEU и X031 или 29.05.2013–06.01.2014 с кодом X031. Для замены другой ревизии сверьте VIN и исходный номер проводки',
            'uk' => 'Проводка передніх лівих дверей Model S із лівим кермом і комплектацією Tech. Для цієї ревізії Tesla вказує два періоди випуску: 01.05–28.05.2013 з кодами REEU і X031 або 29.05.2013–06.01.2014 з кодом X031. Для заміни іншої ревізії звірте VIN і початковий номер проводки',
        ],
        91256 => [
            'article' => '1004419-00-M',
            'check_missing_connector' => true,
            'ru' => 'Проводка передней левой двери Model S с левым рулём и комплектацией Tech. Для этой ревизии Tesla указывает два периода выпуска: 05.05–11.07.2014 с кодами X031 и DRLH или 14.07.2014–15.09.2015 с кодом DRLH. Для замены другой ревизии сверьте VIN и исходный номер проводки',
            'uk' => 'Проводка передніх лівих дверей Model S із лівим кермом і комплектацією Tech. Для цієї ревізії Tesla вказує два періоди випуску: 05.05–11.07.2014 з кодами X031 і DRLH або 14.07.2014–15.09.2015 з кодом DRLH. Для заміни іншої ревізії звірте VIN і початковий номер проводки',
        ],
        102415 => [
            'article' => '1004424-00-G',
            'ru' => 'Передняя правая дверь Model S. По каталогу Tesla для Северной Америки — автомобили, выпущенные до 1 июня 2013 года, с кодом региона RENA или RENC',
            'uk' => 'Передні праві двері Model S. За каталогом Tesla для Північної Америки — автомобілі, випущені до 1 червня 2013 року, з кодом регіону RENA або RENC',
        ],
        103745 => [
            'article' => '1007325-00-B',
            'ru' => 'Увеличенный передний багажник Model S с преобразователем DC-DC в колёсной нише. Для автомобилей, выпущенных до 11 октября 2013 года. В каталоге Tesla для позиции 1007325-00-C допускается ревизия B',
            'uk' => 'Збільшений передній багажник Model S із перетворювачем DC-DC у колісній ніші. Для автомобілів, випущених до 11 жовтня 2013 року. У каталозі Tesla для позиції 1007325-00-C допускається ревізія B',
        ],
        113113 => [
            'article' => '1008471-00-A',
            'assembly' => true,
            'name' => ['ru' => 'Рычаг переключения передач рулевой колонки', 'uk' => 'Важіль перемикання передач кермової колонки'],
            'ru' => 'Продаётся отдельный рычаг. Номер 1008471-00-A в каталоге Tesla относится к модулю рулевой колонки первого поколения в сборе. Для подбора сверьте разъём, установленный модуль и VIN автомобиля',
            'uk' => 'Продається окремий важіль. Номер 1008471-00-A в каталозі Tesla належить модулю кермової колонки першого покоління в зборі. Для підбору звірте роз’єм, установлений модуль і VIN автомобіля',
        ],
        103584 => [
            'article' => '1005855-00-C',
            'name' => ['ru' => 'Нижняя внутренняя металлическая панель передней правой двери', 'uk' => 'Нижня внутрішня металева панель передніх правих дверей'],
            'ru' => 'Передняя правая дверь Model S. В каталоге Tesla ревизия C допускается для нижней панели 1005855-00-D. Перед заказом сверьте крепления и ревизию панели',
            'uk' => 'Передні праві двері Model S. У каталозі Tesla ревізія C допускається для нижньої панелі 1005855-00-D. Перед замовленням звірте кріплення й ревізію панелі',
        ],
        103646 => [
            'article' => '1008573-00-E',
            'name' => ['ru' => 'Передняя накладка центральной консоли', 'uk' => 'Передня накладка центральної консолі'],
            // No exact EPC fitment for this stock item. Never expand a donor label into a model year range.
            'family_only' => true,
        ],
        107696 => [
            'article' => '1007566-00-C',
            'name' => ['ru' => 'Левый адаптер воздуховода', 'uk' => 'Лівий адаптер повітроводу'],
        ],
    ];

    public static function localizeCategory(array $row, string $locale): array
    {
        if ($locale !== 'ru') {
            return $row;
        }

        // Warehouse node 36771. Only its public label changes; breadcrumb and filter slugs stay intact.
        $original = 'Електропроводка дверей и крышки багажника';
        $translated = 'Электропроводка дверей и крышки багажника';
        if (($row['label'] ?? null) === $original) {
            $row['label'] = $translated;
        }
        if (isset($row['category_path'])) {
            $labels = explode(' / ', $row['category_path']);
            $row['category_path'] = implode(' / ', array_map(
                static fn (string $label): string => $label === $original ? $translated : $label, $labels
            ));
        }
        if (isset($row['category_breadcrumbs'])) {
            foreach ($row['category_breadcrumbs'] as $index => $breadcrumb) {
                if (($breadcrumb['label'] ?? null) === $original) {
                    $row['category_breadcrumbs'][$index]['label'] = $translated;
                }
            }
        }

        return $row;
    }

    public static function apply(array $product, string $locale): array
    {
        $product = self::localizeCategory($product, $locale);
        $rawVin = trim((string) ($product['vin'] ?? ''));
        if (array_key_exists('vin', $product)) {
            $vin = strtoupper($rawVin);
            $product['vin'] = preg_match('/^[A-HJ-NPR-Z0-9]{17}$/D', $vin) === 1 ? $vin : '';
        }
        $fact = self::REVIEWED[(int) ($product['id'] ?? 0)] ?? null;
        if ($fact === null || strtoupper(trim((string) ($product['part_number'] ?? ''))) !== $fact['article']) {
            return $product;
        }
        $locale = $locale === 'ru' ? 'ru' : 'uk';
        if (isset($fact['name'])) {
            $product['name'] = $fact['name'][$locale];
        }
        if (isset($fact[$locale]) || ! empty($fact['family_only'])) {
            $product['catalog_model'] = $product['model'] ?? '';
            $product['model'] = 'Model S';
            $product['compatibility'] = $fact[$locale] ?? '';
            $product['reviewed_fitment'] = isset($fact[$locale]);
        }
        if (! empty($fact['check_missing_connector']) && in_array(mb_strtolower(trim((string) ($product['damage_status'] ?? ''))), ['', 'без повреждений', 'без пошкоджень'], true)) {
            $note = trim((string) ($product['damage_description'] ?? ''));
            $evidence = $note !== '' ? $note : (string) ($product['name'] ?? '');
            if (preg_match('~без (?:одной фишки|одного разъ[её]ма|однієї фішки|одного роз[’\x{0027}ʼ]єму)~iu', $evidence)) {
                $product['damage_status'] = $locale === 'ru' ? 'С повреждением' : 'З пошкодженням';
                if ($note === '') {
                    $product['damage_description'] = $locale === 'ru' ? 'Отсутствует один разъём двери.' : 'Відсутній один роз’єм дверей.';
                }
            }
        }
        if (isset($fact['assembly'])) {
            $product['article_label'] = 'Номер модуля';
            $product['display_part_number'] = $product['article_label'].': '.$product['part_number'];
        }

        return $product;
    }
}
