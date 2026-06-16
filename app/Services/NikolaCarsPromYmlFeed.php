<?php

namespace App\Services;

use App\Models\PartCatalogItem;
use App\Models\Product;
use App\Support\ProductPhotoNormalizer;
use App\Support\PublicStorageUrl;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use XMLWriter;

class NikolaCarsPromYmlFeed
{
    private ?Collection $productQuantities = null;

    private ?Collection $productsById = null;

    public function __construct(private readonly ExchangeRateService $exchangeRateService) {}

    public function content(): string
    {
        $usdRate = $this->exchangeRateService->currentUsdRate();
        $groups = $this->groups($usdRate);
        $categories = $this->categories($groups);

        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->writeDtd('yml_catalog', null, 'shops.dtd');
        $xml->startElement('yml_catalog');
        $xml->writeAttribute('date', Carbon::now()->format('Y-m-d H:i'));
        $xml->startElement('shop');
        $xml->writeElement('name', (string) config('prom.shop_name'));
        $xml->writeElement('company', (string) config('prom.company_name'));
        $xml->writeElement('url', rtrim(config('app.url'), '/'));

        $xml->startElement('currencies');
        $xml->startElement('currency');
        $xml->writeAttribute('id', 'UAH');
        $xml->writeAttribute('rate', '1');
        $xml->endElement();
        $xml->endElement();

        $xml->startElement('categories');
        foreach ($categories as $category) {
            $xml->startElement('category');
            $xml->writeAttribute('id', $this->categoryId($category));
            $xml->text($category);
            $xml->endElement();
        }
        $xml->endElement();

        $xml->startElement('offers');
        foreach ($groups as $group) {
            $this->writeOffer($xml, $group, $usdRate);
        }
        $xml->endElement();

        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }

    public function exportableGroups(?array $usdRate = null): Collection
    {
        return $this->groups($usdRate ?? $this->exchangeRateService->displayUsdRate());
    }

    protected function groups(array $usdRate): Collection
    {
        $inventoryService = app(NikolaCarsInventoryService::class);
        $items = $inventoryService->activeItemsQuery()
            ->orderBy('name_ua')
            ->orderBy('name')
            ->get();

        $this->productQuantities = $inventoryService->productQuantitiesForItems($items);
        $this->productsById = $this->productsForItems($items);

        try {
            return $items
                ->map(fn (PartCatalogItem $item): array => $this->groupPayload(collect([$item]), $usdRate))
                ->filter(fn (array $group): bool => $group['image_urls']->isNotEmpty()
                    && (float) $group['quantity'] > 0
                    && (float) $group['price_usd'] > 0)
                ->sortBy(fn (array $group): string => Str::lower($group['name']))
                ->values();
        } finally {
            $this->productQuantities = null;
            $this->productsById = null;
        }
    }

    protected function groupPayload(Collection $items, array $usdRate): array
    {
        /** @var PartCatalogItem $first */
        $first = $items->first();
        $priceValues = $items
            ->map(fn (PartCatalogItem $item): ?float => $this->itemPriceAmountUsd($item, $usdRate))
            ->filter(fn (?float $price): bool => $price !== null && $price > 0)
            ->values();
        $minPrice = $priceValues->isNotEmpty() ? round((float) $priceValues->min(), 2) : null;
        $maxPrice = $priceValues->isNotEmpty() ? round((float) $priceValues->max(), 2) : null;
        $quantity = round($items->sum(fn (PartCatalogItem $item): float => $this->quantity($item)), 3);
        $totalValueUsd = round($items->sum(function (PartCatalogItem $item) use ($usdRate): float {
            $price = $this->itemPriceAmountUsd($item, $usdRate);

            return $price !== null ? $price * $this->quantity($item) : 0.0;
        }), 2);
        $priceUah = $this->promPriceUah((float) ($priceValues->isNotEmpty() ? $priceValues->max() : 0.0), $usdRate);
        $partNumber = $this->itemPartNumber($first);
        $descriptionUa = $this->withoutPartNumber(trim((string) ($first->notes_ua ?: data_get($first->raw_attributes, 'prom_description', ''))), $partNumber);
        $descriptionRu = $this->withoutPartNumber(trim((string) $first->notes_ru), $partNumber);

        return [
            'item' => $first,
            'items' => $items->values(),
            'count' => $items->count(),
            'id' => $this->offerId($first),
            'name' => $this->displayItemName($first),
            'part_number' => $partNumber,
            'part_numbers' => $items
                ->map(fn (PartCatalogItem $item): string => $this->itemPartNumber($item))
                ->filter()
                ->unique()
                ->values(),
            'names' => $items
                ->map(fn (PartCatalogItem $item): string => $this->displayItemName($item))
                ->filter()
                ->unique()
                ->values(),
            'codes' => $items->map(fn (PartCatalogItem $item): string => (string) data_get($item->raw_attributes, 'code', ''))->filter()->unique()->values(),
            'locations' => $items
                ->map(fn (PartCatalogItem $item): string => (string) (
                    data_get($item->raw_attributes, 'donor_vin')
                    ?: data_get($item->raw_attributes, 'category_display')
                    ?: data_get($item->raw_attributes, 'category_path')
                    ?: ''
                ))
                ->filter()
                ->unique()
                ->values(),
            'main_categories' => $items
                ->pluck('main_category_name')
                ->filter()
                ->unique()
                ->values(),
            'image_urls' => $items
                ->flatMap(fn (PartCatalogItem $item): Collection => $this->itemImageUrls($item))
                ->filter()
                ->unique()
                ->values(),
            'category' => $this->categoryName($items),
            'description_uk' => $descriptionUa,
            'description_ru' => $descriptionRu,
            'quantity' => $quantity,
            'quantity_text' => $this->quantityText($quantity),
            'unit_price_value' => $minPrice !== null && $minPrice === $maxPrice ? $minPrice : null,
            'unit_price_text' => $this->unitPriceText($priceValues),
            'price_usd' => $priceValues->isNotEmpty() ? round((float) $priceValues->max(), 2) : 0.0,
            'price_uah' => $priceUah,
            'price_uah_text' => $priceUah > 0 ? number_format($priceUah, 0, '.', ' ').' грн' : '-',
            'total_value_usd' => $totalValueUsd,
            'total_value_text' => $totalValueUsd > 0 ? number_format($totalValueUsd, 2, '.', ' ').' USD' : '-',
        ];
    }

    protected function writeOffer(XMLWriter $xml, array $group, array $usdRate): void
    {
        $xml->startElement('offer');
        $xml->writeAttribute('id', $group['id']);
        $xml->writeAttribute('available', 'true');
        $xml->writeAttribute('in_stock', 'true');
        $xml->writeAttribute('selling_type', 'r');
        $xml->writeElement('name', $this->offerName($group));
        $xml->writeElement('categoryId', $this->categoryId($group['category']));
        $xml->writeElement('price', (string) $group['price_uah']);
        $xml->writeElement('currencyId', 'UAH');
        $xml->writeElement('quantity_in_stock', (string) $group['quantity']);
        $xml->writeElement('vendor', 'Tesla');

        if ($group['part_number'] !== '') {
            $xml->writeElement('vendorCode', $group['part_number']);
        }

        foreach ($group['image_urls'] as $picture) {
            $xml->writeElement('picture', $picture);
        }

        $xml->writeElement('description', $this->description($group));

        foreach ($this->params($group) as $name => $value) {
            $xml->startElement('param');
            $xml->writeAttribute('name', $name);
            $xml->text($value);
            $xml->endElement();
        }

        $xml->endElement();
    }

    protected function categories(Collection $groups): Collection
    {
        return $groups
            ->pluck('category')
            ->filter()
            ->unique()
            ->values();
    }

    protected function categoryName(Collection $items): string
    {
        return (string) ($items
            ->pluck('main_category_name')
            ->filter()
            ->first()
            ?: $items->map(fn (PartCatalogItem $item): ?string => data_get($item->raw_attributes, 'category_display'))->filter()->first()
            ?: 'Запчасти Tesla');
    }

    protected function categoryId(string $category): string
    {
        return (string) sprintf('%u', crc32($category));
    }

    protected function offerId(PartCatalogItem $item): string
    {
        $code = trim((string) data_get($item->raw_attributes, 'code', ''));

        return $code !== '' ? 'nikolacars-'.$code : 'nikolacars-'.$item->id;
    }

    protected function offerName(array $group): string
    {
        return trim(collect([
            $group['name'],
            $group['part_number'] !== '' ? 'арт. '.$group['part_number'] : null,
        ])->filter()->join(' '));
    }

    protected function description(array $group): string
    {
        $description = trim((string) ($group['description_ru'] ?: $group['description_uk'] ?: ''));
        if ($description !== '') {
            return $description;
        }

        return collect([
            'Б/у оригинальная запчасть Tesla.',
            $group['locations']->isNotEmpty() ? 'Наличие: '.$group['locations']->take(5)->implode(', ') : null,
            $group['codes']->isNotEmpty() ? 'Коды учета: '.$group['codes']->take(10)->implode(', ') : null,
        ])->filter()->join(PHP_EOL.PHP_EOL);
    }

    protected function params(array $group): array
    {
        return collect([
            'Артикул' => $group['part_number'],
            'Марка авто' => 'Tesla',
            'Категория учета' => $group['category'],
        ])->filter(fn ($value): bool => trim((string) $value) !== '')->all();
    }

    protected function displayItemName(PartCatalogItem $item): string
    {
        return $this->withoutPartNumber(
            trim((string) ($item->name_ua ?: $item->name_ru ?: $item->name_en ?: $item->name)),
            $this->itemPartNumber($item)
        );
    }

    protected function productsForItems(Collection $items): Collection
    {
        $productIds = $items
            ->map(fn (PartCatalogItem $item): int => $this->productIdFromItem($item))
            ->filter()
            ->unique()
            ->values();

        if ($productIds->isEmpty()) {
            return collect();
        }

        return Product::query()
            ->whereIn('id', $productIds->all())
            ->get(['id', 'external_sku', 'selling_price', 'currency', 'main_image', 'images_json'])
            ->keyBy('id');
    }

    protected function productForItem(PartCatalogItem $item): ?Product
    {
        $productId = $this->productIdFromItem($item);

        if ($productId <= 0) {
            return null;
        }

        if ($this->productsById instanceof Collection) {
            $product = $this->productsById->get($productId);

            return $product instanceof Product ? $product : null;
        }

        return Product::query()
            ->whereKey($productId)
            ->first(['id', 'external_sku', 'selling_price', 'currency', 'main_image', 'images_json']);
    }

    protected function productIdFromItem(PartCatalogItem $item): int
    {
        $productId = (int) data_get($item->raw_attributes, 'product_id');

        if ($productId > 0) {
            return $productId;
        }

        return preg_match('~^nikolacars://(?:donor-product|inventory-product)/(\d+)$~', (string) $item->source_url, $matches) === 1
            ? (int) $matches[1]
            : 0;
    }

    protected function itemPartNumber(PartCatalogItem $item): string
    {
        $product = $this->productForItem($item);

        return trim((string) ($product?->external_sku ?: $item->part_number));
    }

    protected function itemPriceAmountUsd(PartCatalogItem $item, array $usdRate): ?float
    {
        $product = $this->productForItem($item);

        if ($product instanceof Product && $product->selling_price !== null) {
            $price = (float) $product->selling_price;
            $currency = Str::upper((string) ($product->currency ?: 'USD'));

            if ($currency === 'UAH') {
                $rate = (float) ($usdRate['rate'] ?? 0);

                return $rate > 0 ? round($price / $rate, 2) : null;
            }

            return round($price, 2);
        }

        return $item->priceAmountUsd($usdRate);
    }

    protected function itemImageUrls(PartCatalogItem $item): Collection
    {
        $product = $this->productForItem($item);

        return ($product instanceof Product ? ProductPhotoNormalizer::productPhotos($product) : collect())
            ->merge((array) data_get($item->raw_attributes, 'image_urls', []))
            ->merge((array) data_get($item->raw_attributes, 'part_image_urls', []))
            ->map(fn (mixed $url): string => trim((string) $url))
            ->filter()
            ->reject(fn (string $url): bool => ProductPhotoNormalizer::isCatalogSchemeImage($url))
            ->unique(fn (string $url): string => ProductPhotoNormalizer::imageKey($url))
            ->map(fn (string $url): string => $this->absoluteUrl(PublicStorageUrl::url($url) ?? $url))
            ->values();
    }

    protected function withoutPartNumber(string $text, string $partNumber): string
    {
        $partNumber = trim($partNumber);

        if ($text === '' || $partNumber === '') {
            return $text;
        }

        $partNumberPattern = preg_quote($partNumber, '/');
        $partNumberLabelPattern = '(?:арт\.?|артикул(?:ы)?|part\s*(?:no\.?|number)?|vendor\s*code)\s*[:№#-]?\s*';
        $cleaned = (string) preg_replace('/(?:^|[\s,;]+)'.$partNumberLabelPattern.$partNumberPattern.'(?:[\s,;]+|$)/iu', ' ', $text);
        $cleaned = (string) preg_replace('/(?:^|[\s,;]+)'.$partNumberPattern.'(?:[\s,;]+|$)/iu', ' ', $cleaned);

        if ($cleaned === $text) {
            return $text;
        }

        $cleaned = trim((string) preg_replace('/\s{2,}/u', ' ', $cleaned));

        return trim($cleaned, " \t\n\r\0\x0B,;.-");
    }

    protected function quantity(PartCatalogItem $item): float
    {
        return app(NikolaCarsInventoryService::class)->itemInventoryQuantity($item, $this->productQuantities);
    }

    protected function quantityText(float $quantity): string
    {
        return $quantity > 0
            ? rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.').' шт'
            : '-';
    }

    protected function unitPriceText(Collection $prices): string
    {
        $prices = $prices
            ->filter(fn (?float $price): bool => $price !== null && $price > 0)
            ->values();

        if ($prices->isEmpty()) {
            return '-';
        }

        $min = round((float) $prices->min(), 2);
        $max = round((float) $prices->max(), 2);

        if ($min === $max) {
            return number_format($min, 2, '.', ' ').' USD';
        }

        return number_format($min, 2, '.', ' ').' - '.number_format($max, 2, '.', ' ').' USD';
    }

    protected function promPriceUah(float $priceUsd, array $usdRate): int
    {
        $price = max(0.01, $priceUsd * (float) $usdRate['rate']);
        $step = $price >= 100000 ? 100 : 10;

        return (int) (ceil($price / $step) * $step);
    }

    protected function absoluteUrl(string $url): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return rtrim(config('app.url'), '/').'/'.ltrim($url, '/');
    }
}
