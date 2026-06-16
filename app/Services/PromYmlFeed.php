<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Support\ProductPhotoNormalizer;
use App\Support\PublicStorageUrl;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use XMLWriter;

class PromYmlFeed
{
    public function __construct(private readonly ExchangeRateService $exchangeRateService) {}

    public function content(): string
    {
        $products = $this->products();
        $categories = $this->categories($products);
        $usdRate = $this->exchangeRateService->currentUsdRate();

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
            $xml->writeAttribute('id', (string) $this->categoryId($category));
            $xml->text($category?->name ?: 'Запчасти Tesla');
            $xml->endElement();
        }
        $xml->endElement();

        $xml->startElement('offers');
        foreach ($products as $product) {
            $this->writeOffer($xml, $product, $usdRate);
        }
        $xml->endElement();

        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }

    protected function products(): EloquentCollection
    {
        return Product::query()
            ->with(['category', 'brand', 'donorCar'])
            ->withSum('stockItems as available_quantity_sum', 'available_quantity')
            ->whereNotNull('donor_car_id')
            ->where('is_active', true)
            ->where('selling_price', '>', 0)
            ->whereIn('storage_status', [
                Product::STORAGE_STATUS_IN_STOCK,
                Product::STORAGE_STATUS_ON_DONOR,
            ])
            ->orderBy('id')
            ->get()
            ->filter(fn (Product $product): bool => $this->pictures($product) !== []);
    }

    protected function categories(EloquentCollection $products): array
    {
        $categories = $products
            ->map(fn (Product $product): ?Category => $product->category)
            ->filter()
            ->unique('id')
            ->values()
            ->all();

        if ($products->contains(fn (Product $product): bool => $product->category === null)) {
            $categories[] = null;
        }

        return $categories;
    }

    protected function writeOffer(XMLWriter $xml, Product $product, array $usdRate): void
    {
        $quantity = $this->quantity($product);
        $available = $quantity > 0;

        $xml->startElement('offer');
        $xml->writeAttribute('id', $product->sku);
        $xml->writeAttribute('available', $available ? 'true' : 'false');
        $xml->writeAttribute('in_stock', $available ? 'true' : 'false');
        $xml->writeAttribute('selling_type', 'r');
        $xml->writeElement('name', $this->name($product));
        $xml->writeElement('categoryId', (string) $this->categoryId($product->category));
        $xml->writeElement('price', $this->priceUah($product, $usdRate));
        $xml->writeElement('currencyId', 'UAH');
        $xml->writeElement('quantity_in_stock', (string) $quantity);

        if ($product->brand?->name) {
            $xml->writeElement('vendor', $product->brand->name);
        } else {
            $xml->writeElement('vendor', 'Tesla');
        }

        if ($product->external_sku) {
            $xml->writeElement('vendorCode', $product->external_sku);
        }

        foreach ($this->pictures($product) as $picture) {
            $xml->writeElement('picture', $picture);
        }

        $xml->writeElement('description', $this->description($product));

        foreach ($this->params($product) as $name => $value) {
            $xml->startElement('param');
            $xml->writeAttribute('name', $name);
            $xml->text($value);
            $xml->endElement();
        }

        $xml->endElement();
    }

    protected function categoryId(?Category $category): int
    {
        return $category?->id ?: 1;
    }

    protected function name(Product $product): string
    {
        return trim(collect([
            $product->name,
            $product->external_sku ? 'арт. '.$product->external_sku : null,
            $product->donorCar?->display_model,
            $product->donorCar?->year,
        ])->filter()->join(' '));
    }

    protected function priceUah(Product $product, array $usdRate): string
    {
        $price = $this->exchangeRateService->productSellingPriceUah(
            (float) $product->selling_price,
            $product->currency,
            $usdRate,
        );

        return number_format(max(0.01, $price), 2, '.', '');
    }

    protected function quantity(Product $product): int
    {
        $stockQuantity = (int) ($product->available_quantity_sum ?? 0);

        if ($stockQuantity > 0) {
            return $stockQuantity;
        }

        return $product->storage_status === Product::STORAGE_STATUS_ON_DONOR ? 1 : 0;
    }

    protected function pictures(Product $product): array
    {
        return ProductPhotoNormalizer::productPhotos($product)
            ->map(fn (string $path): string => $this->absoluteUrl(PublicStorageUrl::url($path) ?? $path))
            ->values()
            ->all();
    }

    protected function absoluteUrl(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return rtrim(config('app.url'), '/').'/'.ltrim($path, '/');
    }

    protected function description(Product $product): string
    {
        return collect([
            $product->description,
            $product->compatibility ? 'Совместимость: '.$product->compatibility : null,
            $product->condition_type ? 'Состояние: '.$this->conditionLabel($product) : null,
            $product->donorCar ? 'Снято с донора: '.$product->donorCar->brand.' '.$product->donorCar->display_model.' '.$product->donorCar->year : null,
            $product->notes,
        ])->filter()->join(PHP_EOL.PHP_EOL);
    }

    protected function params(Product $product): array
    {
        return collect([
            'Артикул' => $product->external_sku,
            'Внутренний SKU' => $product->sku,
            'Марка авто' => $product->donorCar?->brand ?: $product->brand?->name,
            'Модель авто' => $product->donorCar?->display_model ?: $product->model,
            'Год авто' => $product->donorCar?->year ? (string) $product->donorCar->year : null,
            'Состояние' => $product->condition_type ? $this->conditionLabel($product) : null,
            'Сторона' => $product->side,
            'Цвет' => $product->color,
        ])->filter(fn ($value): bool => trim((string) $value) !== '')->all();
    }

    protected function conditionLabel(Product $product): string
    {
        return Product::CONDITION_TYPE_LABELS[$product->condition_type] ?? (string) $product->condition_type;
    }

    protected function usedConditionLabel(string $condition): string
    {
        return match ($condition) {
            'good' => Product::USED_CONDITION_LABELS['good'],
            'with_nuances' => 'б/у, с нюансами',
            'defective' => 'б/у, дефект',
            default => $condition,
        };
    }
}
