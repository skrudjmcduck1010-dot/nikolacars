<?php

namespace App\Console\Commands;

use App\Models\PartCatalogItem;
use App\Models\PartSale;
use App\Models\Product;
use App\Services\NikolaCarsInterimDonorResolver;
use App\Services\NikolaCarsInventoryService;
use App\Services\NikolaCarsProductInventorySyncService;
use App\Services\SoldProductStockAdjustmentService;
use App\Support\PartCatalogRawAttributes;
use App\Support\TextEncodingNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ImportNikolaCarsSales extends Command
{
    protected $signature = 'nikolacars:sales:import {path : CSV file exported from sales XLS} {--source-file= : Original file label}';

    protected $description = 'Import NikolaCars part sales and link them to catalog items by code and to donors by VIN.';

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            $this->error("Unable to open file: {$path}");

            return self::FAILURE;
        }

        $headers = fgetcsv($handle, 0, ';');
        if ($headers === false) {
            fclose($handle);
            $this->error('CSV file is empty.');

            return self::FAILURE;
        }

        $headers = array_map(fn (string $header): string => $this->cleanBom($header), $headers);
        $stats = [
            'rows_read' => 0,
            'sales_saved' => 0,
            'items_linked' => 0,
            'donors_linked' => 0,
            'rows_skipped' => 0,
        ];

        $donorResolver = app(NikolaCarsInterimDonorResolver::class);

        DB::transaction(function () use ($handle, $headers, $path, $donorResolver, &$stats): void {
            $rowNumber = 1;

            while (($values = fgetcsv($handle, 0, ';')) !== false) {
                $rowNumber++;
                $stats['rows_read']++;

                $row = [];
                foreach ($headers as $index => $header) {
                    $row[$header] = TextEncodingNormalizer::normalize($values[$index] ?? '') ?? '';
                }

                $code = $this->clean((string) ($row['Код'] ?? ''));
                $name = $this->clean((string) ($row['Наименование'] ?? ''));
                if ($code === '' || $name === '') {
                    $stats['rows_skipped']++;

                    continue;
                }

                $partNumber = $this->normalizePartNumber($this->clean((string) ($row['Артикул'] ?? '')));
                $categoryPath = $this->clean((string) ($row['Категория'] ?? ''));
                $donorVin = $this->extractVin($categoryPath.' '.$name);
                $item = $this->findCatalogItem($code, $partNumber, $donorVin);
                $donorVin ??= $this->catalogDonorVin($item);
                $donor = $donorResolver->resolve($item, $donorVin);
                $quantity = $this->decimal($row['Количество'] ?? null) ?? 1.0;
                $unitPrice = $this->decimal($row['Цена'] ?? null);
                $soldAt = $this->date($row['Дата'] ?? null);
                $documentNumber = $this->clean((string) ($row['Номер'] ?? ''));
                $counterparty = $this->clean((string) ($row['Контрагент'] ?? ''));
                $hash = hash('sha256', implode('|', [
                    'nikolacars',
                    $code,
                    $partNumber,
                    $quantity,
                    $unitPrice,
                    $soldAt?->format('Y-m-d H:i:s') ?? '',
                    $documentNumber,
                    $counterparty,
                    $name,
                ]));

                $sale = PartSale::query()->updateOrCreate(
                    ['source_row_hash' => $hash],
                    [
                        'part_catalog_item_id' => $item?->id,
                        'donor_car_id' => $donor?->id,
                        'source' => 'nikolacars',
                        'code' => $code,
                        'part_number' => $partNumber !== '' ? $partNumber : null,
                        'name' => $name,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'currency' => 'USD',
                        'sold_at' => $soldAt,
                        'document_number' => $documentNumber !== '' ? $documentNumber : null,
                        'counterparty' => $counterparty !== '' ? $counterparty : null,
                        'donor_vin' => $donorVin,
                        'category_path' => $categoryPath !== '' ? $categoryPath : null,
                        'raw_attributes' => collect($row)->map(fn ($value) => is_string($value) ? $this->clean($value) : $value)->all(),
                        'source_file' => (string) ($this->option('source-file') ?: basename($path)),
                        'source_row_number' => $rowNumber,
                    ],
                );
                $this->markLinkedInventorySold($item, $sale);

                $stats['sales_saved']++;
                $stats['items_linked'] += $item ? 1 : 0;
                $stats['donors_linked'] += $donor ? 1 : 0;
            }
        });

        fclose($handle);

        $this->info(json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    protected function findCatalogItem(string $code, string $partNumber, ?string $donorVin): ?PartCatalogItem
    {
        $query = PartCatalogItem::query()->where('source', 'nikolacars');

        $byCode = (clone $query)
            ->where(fn (Builder $builder): Builder => $this->whereJsonAttribute($builder, 'code', $code))
            ->when($donorVin, fn (Builder $builder) => $this->whereJsonAttribute($builder, 'donor_vin', $donorVin))
            ->first();

        if ($byCode instanceof PartCatalogItem) {
            return $byCode;
        }

        $byCode = (clone $query)
            ->where(fn (Builder $builder): Builder => $this->whereJsonAttribute($builder, 'code', $code))
            ->first();

        if ($byCode instanceof PartCatalogItem || $partNumber === '') {
            return $byCode;
        }

        return (clone $query)
            ->where('part_number', $partNumber)
            ->when($donorVin, fn (Builder $builder) => $this->whereJsonAttribute($builder, 'donor_vin', $donorVin))
            ->first()
            ?: (clone $query)->where('part_number', $partNumber)->first();
    }

    protected function markLinkedInventorySold(?PartCatalogItem $item, PartSale $sale): void
    {
        if (! $item instanceof PartCatalogItem) {
            return;
        }

        $rawAttributes = PartCatalogRawAttributes::from($item);

        if (! array_key_exists('stock_quantity_before_sale', $rawAttributes)) {
            $rawAttributes['stock_quantity_before_sale'] = data_get($rawAttributes, 'stock_quantity');
        }

        $rawAttributes['stock_quantity'] = 0;
        $rawAttributes['storage_status'] = Product::STORAGE_STATUS_SOLD;
        $rawAttributes['sold_at'] = $sale->sold_at?->toDateString();
        $rawAttributes['sold_document_number'] = $sale->document_number;

        $item->forceFill([
            'availability' => app(NikolaCarsInventoryService::class)->availability(0),
            'raw_attributes' => $rawAttributes,
        ])->save();

        $productIds = collect([(int) data_get($rawAttributes, 'product_id')])
            ->filter()
            ->values();

        Product::query()
            ->where(function (Builder $query) use ($item, $productIds): void {
                $query->where('source_part_catalog_item_id', $item->id);

                if ($productIds->isNotEmpty()) {
                    $query->orWhereIn('id', $productIds->all());
                }
            })
            ->get()
            ->each(function (Product $product) use ($sale): void {
                $product->forceFill([
                    'storage_status' => Product::STORAGE_STATUS_SOLD,
                    'is_active' => false,
                ])->save();
                app(SoldProductStockAdjustmentService::class)->zeroStock($product->refresh(), [
                    'document_number' => $sale->document_number,
                    'comment' => 'NikolaCars sale import marked product sold.',
                ]);

                app(NikolaCarsProductInventorySyncService::class)->syncProduct($product->refresh());
            });
    }

    protected function whereJsonAttribute(Builder $builder, string $key, string $value): Builder
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return $builder->whereRaw("json_extract(raw_attributes, '$.{$key}') = ?", [$value]);
        }

        return $builder->whereRaw("json_unquote(json_extract(raw_attributes, '$.{$key}')) = ?", [$value]);
    }

    protected function catalogDonorVin(?PartCatalogItem $item): ?string
    {
        $rawAttributes = PartCatalogRawAttributes::from($item);

        $vin = trim((string) ($rawAttributes['donor_vin'] ?? ''));

        return $vin !== '' ? $vin : null;
    }

    protected function extractVin(string $text): ?string
    {
        if (preg_match('/\b[A-HJ-NPR-Z0-9]{17}\b/i', $text, $matches) !== 1) {
            return null;
        }

        return strtr(Str::upper($matches[0]), ['O' => '0', 'I' => '1']);
    }

    protected function normalizePartNumber(string $partNumber): string
    {
        $partNumber = Str::upper(str_replace(' ', '', trim($partNumber)));

        if (preg_match('/^(\d{7})([A-Z0-9]{2})([A-Z0-9])$/', $partNumber, $matches) === 1) {
            return "{$matches[1]}-{$matches[2]}-{$matches[3]}";
        }

        return $partNumber;
    }

    protected function decimal(mixed $value): ?float
    {
        $text = str_replace(["\xc2\xa0", ' '], '', $this->clean((string) $value));
        if (preg_match('/^\d{1,3}(,\d{3})+$/', $text) === 1) {
            $text = str_replace(',', '', $text);
        } else {
            $text = str_replace(',', '.', $text);
        }

        return is_numeric($text) ? round((float) $text, 3) : null;
    }

    protected function date(mixed $value): ?CarbonImmutable
    {
        $text = $this->clean((string) $value);
        if ($text === '') {
            return null;
        }

        foreach (['d.m.Y H:i:s', 'd.m.Y H:i', 'd.m.Y'] as $format) {
            $date = CarbonImmutable::createFromFormat($format, $text);
            if ($date !== false) {
                return $date->setTime(12, 0);
            }
        }

        return null;
    }

    protected function cleanBom(string $value): string
    {
        $value = TextEncodingNormalizer::normalize($value) ?? $value;

        return preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
    }

    protected function clean(?string $value): string
    {
        $value = TextEncodingNormalizer::normalize((string) $value) ?? '';

        return trim(html_entity_decode(preg_replace('/\s+/u', ' ', $value) ?: '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
