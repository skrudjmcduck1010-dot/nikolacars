<?php

namespace App\Models;

use App\Support\CatalogTextEncoding;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'parent_id',
    'source',
    'source_url',
    'preview_image_url',
    'depth',
    'code',
    'name',
    'name_en',
    'name_ru',
    'name_ua',
    'model_label',
    'model_name',
    'year_from',
    'year_to',
    'sort_order',
    'children_scanned_at',
    'products_scanned_at',
])]
class PartCatalogCategory extends Model
{
    use HasFactory;

    protected const CODE_NORMALIZED_NAME_ATTRIBUTES = [
        'name',
        'name_en',
        'name_ru',
        'name_ua',
    ];

    protected const ENCODING_REPAIRED_ATTRIBUTES = [
        'name',
        'name_en',
        'name_ru',
        'name_ua',
        'model_label',
        'model_name',
    ];

    public static function modelOptions(?string $selected = null): array
    {
        $models = collect()
            ->merge(self::query()
                ->whereNotNull('model_label')
                ->where('model_label', '!=', '')
                ->distinct()
                ->pluck('model_label'))
            ->merge(PartCatalogItem::query()
                ->whereNotNull('model_label')
                ->where('model_label', '!=', '')
                ->distinct()
                ->pluck('model_label'))
            ->when($selected, fn ($collection) => $collection->push($selected))
            ->map(fn (?string $model): string => trim((string) $model))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($models === []) {
            $models = ['Model S', 'Model 3', 'Model X', 'Model Y'];
        }

        sort($models, SORT_NATURAL);

        return $models;
    }

    public static function stripRepeatedCodePrefix(?string $value, mixed $code): ?string
    {
        if ($value === null) {
            return null;
        }

        $displayCode = trim((string) $code);

        if ($displayCode === '') {
            return $value;
        }

        return preg_match('/^\s*'.preg_quote($displayCode, '/').'\s*[-–—:]\s*(.+)$/u', $value, $matches) === 1
            ? trim($matches[1])
            : $value;
    }

    protected static function booted(): void
    {
        static::saving(function (self $category): void {
            $category->normalizeRepeatedCodePrefixes();
        });
    }

    protected function casts(): array
    {
        return [
            'children_scanned_at' => 'datetime',
            'products_scanned_at' => 'datetime',
        ];
    }

    public function getAttributeValue($key): mixed
    {
        $value = parent::getAttributeValue($key);

        return is_string($value) && in_array($key, self::ENCODING_REPAIRED_ATTRIBUTES, true)
            ? CatalogTextEncoding::repair($value)
            : $value;
    }

    protected function normalizeRepeatedCodePrefixes(): void
    {
        $code = $this->attributes['code'] ?? null;

        foreach (self::CODE_NORMALIZED_NAME_ATTRIBUTES as $attribute) {
            if (! array_key_exists($attribute, $this->attributes)) {
                continue;
            }

            $this->attributes[$attribute] = self::stripRepeatedCodePrefix($this->attributes[$attribute], $code);
        }
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PartCatalogItem::class);
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(PartCatalogItemOccurrence::class, 'part_catalog_category_id');
    }
}
