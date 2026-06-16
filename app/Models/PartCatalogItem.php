<?php

namespace App\Models;

use App\Services\ExchangeRateService;
use App\Services\PartCatalogSourceStatsService;
use App\Services\TeslaOfficialLocalizedNameSyncService;
use App\Support\CatalogTextEncoding;
use App\Support\PartCatalogLocalizedNameCleaner;
use App\Support\PartCatalogRawAttributes;
use App\Support\PartNumberNormalizer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'part_catalog_category_id',
    'source',
    'source_url',
    'part_number',
    'name',
    'name_en',
    'name_ru',
    'name_ua',
    'name_ru_manually_locked_at',
    'name_ua_manually_locked_at',
    'scheme_number',
    'price_amount',
    'currency',
    'model_label',
    'model_name',
    'year_from',
    'year_to',
    'main_category_code',
    'main_category_name',
    'subcategory_code',
    'subcategory_name',
    'node_name',
    'compatibility_text',
    'notes_en',
    'notes_ru',
    'notes_ua',
    'condition',
    'quality',
    'availability',
    'raw_attributes',
    'source_updated_at',
])]
class PartCatalogItem extends Model
{
    use HasFactory;

    protected ?string $localizedNameOriginDetected = null;

    protected bool $localizedNameUsedConditionDetected = false;

    protected const ENCODING_REPAIRED_ATTRIBUTES = [
        'name',
        'name_en',
        'name_ru',
        'name_ua',
        'main_category_name',
        'subcategory_name',
        'node_name',
        'compatibility_text',
        'notes_en',
        'notes_ru',
        'notes_ua',
        'condition',
        'quality',
        'availability',
    ];

    protected function casts(): array
    {
        return [
            'price_amount' => 'decimal:2',
            'raw_attributes' => AsArrayObject::class,
            'source_updated_at' => 'datetime',
            'name_ru_manually_locked_at' => 'datetime',
            'name_ua_manually_locked_at' => 'datetime',
        ];
    }

    public function getAttributeValue($key): mixed
    {
        $value = parent::getAttributeValue($key);

        return is_string($value) && in_array($key, self::ENCODING_REPAIRED_ATTRIBUTES, true)
            ? CatalogTextEncoding::repair($value)
            : $value;
    }

    public function setNameAttribute(mixed $value): void
    {
        $this->rememberLocalizedNameOrigin($value);

        $this->attributes['name'] = $value;
    }

    public function setPartNumberAttribute(mixed $value): void
    {
        $this->attributes['part_number'] = PartNumberNormalizer::normalize(is_string($value) ? $value : (string) $value);
    }

    public function setNameRuAttribute(mixed $value): void
    {
        $this->rememberLocalizedNameOrigin($value);

        $this->attributes['name_ru'] = PartCatalogLocalizedNameCleaner::clean($value);
    }

    public function setNameUaAttribute(mixed $value): void
    {
        $this->rememberLocalizedNameOrigin($value);

        $this->attributes['name_ua'] = PartCatalogLocalizedNameCleaner::clean($value);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(PartCatalogCategory::class, 'part_catalog_category_id');
    }

    public function zones(): HasMany
    {
        return $this->hasMany(PartCatalogItemZone::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'source_part_catalog_item_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(PartSale::class);
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(PartCatalogItemOccurrence::class);
    }

    public function priceHistories(): HasMany
    {
        return $this->hasMany(ProductPriceHistory::class);
    }

    protected static function booted(): void
    {
        static::saving(function (PartCatalogItem $item): void {
            $item->cleanLocalizedNameAttributes();
        });

        static::saving(function (PartCatalogItem $item): void {
            if ($item->localizedNameOriginDetected === null) {
                return;
            }

            $label = $item->localizedNameOriginDetected === 'analog'
                ? PartCatalogLocalizedNameCleaner::analogLabel()
                : PartCatalogLocalizedNameCleaner::originalLabel();

            $item->mergeRawAttributes([
                'part_origin' => $item->localizedNameOriginDetected,
                'part_origin_label' => $label,
            ]);
        });

        static::saving(function (PartCatalogItem $item): void {
            if (! $item->localizedNameUsedConditionDetected) {
                return;
            }

            $item->condition = 'Б/у';
        });

        static::updating(function (PartCatalogItem $item): void {
            if (! $item->isDirty('price_amount')) {
                return;
            }

            $oldPrice = $item->getOriginal('price_amount');
            $newPrice = $item->price_amount;

            if ($oldPrice === null || $newPrice === null) {
                return;
            }

            if (abs(round((float) $newPrice, 2) - round((float) $oldPrice, 2)) <= 1.0) {
                return;
            }

            ProductPriceHistory::query()->create([
                'part_catalog_item_id' => $item->id,
                'source' => $item->source,
                'old_price' => $oldPrice,
                'new_price' => $newPrice,
                'currency' => $item->currency ?: 'USD',
                'changed_at' => now(),
            ]);
        });

        static::created(function (PartCatalogItem $item): void {
            app(PartCatalogSourceStatsService::class)->itemCreated($item);
            app(TeslaOfficialLocalizedNameSyncService::class)->syncAfterItemSaved($item, true);
        });

        static::updated(function (PartCatalogItem $item): void {
            app(PartCatalogSourceStatsService::class)->itemUpdated($item);
            app(TeslaOfficialLocalizedNameSyncService::class)->syncAfterItemSaved($item, false);
        });

        static::deleted(function (PartCatalogItem $item): void {
            app(PartCatalogSourceStatsService::class)->itemDeleted($item);
        });
    }

    protected function rememberLocalizedNameOrigin(mixed $value): void
    {
        if (PartCatalogLocalizedNameCleaner::hasAnalogMarker($value)) {
            $this->localizedNameOriginDetected = 'analog';

            return;
        }

        if (PartCatalogLocalizedNameCleaner::hasOriginalMarker($value)) {
            $this->localizedNameOriginDetected = 'original';
        }

        if (PartCatalogLocalizedNameCleaner::hasUsedMarker($value)) {
            $this->localizedNameUsedConditionDetected = true;
        }
    }

    protected function cleanLocalizedNameAttributes(): void
    {
        foreach (['name_ru', 'name_ua'] as $attribute) {
            if (! array_key_exists($attribute, $this->attributes)) {
                continue;
            }

            $this->attributes[$attribute] = PartCatalogLocalizedNameCleaner::clean($this->{$attribute});
        }

        $this->clearEnglishNameUaAttribute();
    }

    protected function clearEnglishNameUaAttribute(): void
    {
        $nameUa = trim((string) ($this->attributes['name_ua'] ?? ''));

        if ($nameUa === '' || ! $this->isLatinOnlyName($nameUa)) {
            return;
        }

        foreach (['name_en', 'name'] as $attribute) {
            $englishName = trim((string) ($this->{$attribute} ?? ''));

            if ($englishName !== '' && $nameUa === $englishName) {
                $this->attributes['name_ua'] = null;

                return;
            }
        }
    }

    protected function isLatinOnlyName(string $value): bool
    {
        return preg_match('/[A-Za-z]/', $value) === 1
            && preg_match('/\p{Cyrillic}/u', $value) !== 1;
    }

    protected function mergeRawAttributes(array $values): void
    {
        $this->raw_attributes = array_merge(PartCatalogRawAttributes::from($this), $values);
    }

    public function priceAmountUsd(?array $usdRate = null): ?float
    {
        if ($this->price_amount === null) {
            return null;
        }

        $currency = strtoupper((string) ($this->currency ?: 'USD'));
        $amount = (float) $this->price_amount;

        if ($currency === 'UAH') {
            $usdRate ??= app(ExchangeRateService::class)->displayUsdRate();
            $rate = (float) ($usdRate['rate'] ?? 0);

            return $rate > 0 ? round($amount / $rate, 2) : null;
        }

        return round($amount, 2);
    }

    public function priceAmountUah(?array $usdRate = null): ?float
    {
        if ($this->price_amount === null) {
            return null;
        }

        $currency = strtoupper((string) ($this->currency ?: 'USD'));
        $amount = (float) $this->price_amount;

        if ($currency === 'UAH') {
            return round($amount, 2);
        }

        $usdRate ??= app(ExchangeRateService::class)->displayUsdRate();
        $rate = (float) ($usdRate['rate'] ?? 0);

        return $rate > 0 ? round($amount * $rate, 2) : null;
    }
}
