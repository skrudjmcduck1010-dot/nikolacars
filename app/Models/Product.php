<?php

namespace App\Models;

use App\Models\Concerns\TracksUserStamps;
use App\Support\CatalogTextEncoding;
use App\Support\PartNumberNormalizer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'sku',
    'external_sku',
    'name',
    'slug',
    'category_id',
    'brand_id',
    'donor_car_id',
    'part_origin',
    'source_part_catalog_item_id',
    'is_auto_generated',
    'storage_status',
    'generated_at',
    'description',
    'compatibility',
    'model',
    'color',
    'generation',
    'side',
    'condition_type',
    'testing_status',
    'unit',
    'purchase_price',
    'selling_price',
    'currency',
    'barcode',
    'qr_code',
    'main_image',
    'images_json',
    'weight',
    'notes',
    'donor_damage_status_changed_by',
    'is_active',
    'created_by',
    'updated_by',
])]
class Product extends Model
{
    use HasFactory;
    use TracksUserStamps;

    public const SIDES = ['left', 'right', 'front', 'rear'];

    public const CONDITION_TYPES = ['new', 'used', 'restored'];

    public const CONDITION_TYPE_LABELS = [
        'used' => 'Б/У',
        'new' => 'Новое',
        'restored' => 'Восстановлен',
    ];

    public const TESTING_STATUSES = ['tested', 'not_tested'];

    public const UNITS = ['pcs', 'set', 'pair'];

    public const PART_ORIGIN_ORIGINAL = 'original';

    public const PART_ORIGIN_ANALOG = 'analog';

    public const PART_ORIGINS = [
        self::PART_ORIGIN_ORIGINAL => 'Оригинал',
        self::PART_ORIGIN_ANALOG => 'Аналог',
    ];

    public const STORAGE_STATUS_IN_STOCK = 'in_stock';

    public const STORAGE_STATUS_ON_DONOR = 'on_donor';

    public const STORAGE_STATUS_SOLD = 'sold';

    public const STORAGE_STATUS_WRITTEN_OFF = 'written_off';

    public const STORAGE_STATUSES = [
        self::STORAGE_STATUS_IN_STOCK => 'На складе',
        self::STORAGE_STATUS_ON_DONOR => 'На доноре',
        self::STORAGE_STATUS_SOLD => 'Продано',
        self::STORAGE_STATUS_WRITTEN_OFF => 'Списано',
    ];

    protected function casts(): array
    {
        return [
            'images_json' => AsArrayObject::class,
            'purchase_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'weight' => 'decimal:3',
            'is_active' => 'boolean',
            'is_auto_generated' => 'boolean',
            'generated_at' => 'datetime',
        ];
    }

    public function getStorageStatusLabelAttribute(): string
    {
        return CatalogTextEncoding::repair(self::STORAGE_STATUSES[$this->storage_status] ?? (string) $this->storage_status);
    }

    public function getNotesAttribute(?string $value): ?string
    {
        return CatalogTextEncoding::repair($value);
    }

    public function setNotesAttribute(mixed $value): void
    {
        if ($value === null) {
            $this->attributes['notes'] = null;

            return;
        }

        $normalized = CatalogTextEncoding::repair(is_string($value) ? $value : (string) $value);

        $this->attributes['notes'] = $this->repairQuestionOnlyDamageStatus($normalized ?? (string) $value);
    }

    protected function repairQuestionOnlyDamageStatus(string $value): string
    {
        if ($value === '' || preg_match('/^\?+$/', $value) !== 1) {
            return $value;
        }

        $unknown = "\u{041D}\u{0435}\u{0438}\u{0437}\u{0432}\u{0435}\u{0441}\u{0442}\u{043D}\u{043E}";
        $knownDamageStatuses = [
            $unknown,
            "\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}",
            "\u{041B}\u{0435}\u{0433}\u{043A}\u{0438}\u{0435} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{044F}",
            "\u{0421}\u{0438}\u{043B}\u{044C}\u{043D}\u{044B}\u{0435} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{044F}",
            "\u{0420}\u{0430}\u{0437}\u{0431}\u{0438}\u{0442}",
        ];

        foreach ($knownDamageStatuses as $damageStatus) {
            if (strlen($value) === strlen($damageStatus)) {
                return $damageStatus;
            }
        }

        return $value;
    }

    public function getPartOriginLabelAttribute(): string
    {
        return CatalogTextEncoding::repair(self::PART_ORIGINS[$this->part_origin] ?? (string) $this->part_origin);
    }

    public function isTeslaOfficialGenerated(): bool
    {
        if (! (bool) $this->is_auto_generated) {
            return false;
        }

        $sourceItem = $this->sourcePartCatalogItem;

        return $sourceItem?->source === 'tesla_official'
            || data_get($sourceItem?->raw_attributes, 'source_catalog_source') === 'tesla_official';
    }

    public function isProtectedAutoGeneratedDonorProduct(): bool
    {
        return (bool) $this->is_auto_generated
            || $this->generated_at !== null;
    }

    public function setExternalSkuAttribute(mixed $value): void
    {
        $this->attributes['external_sku'] = PartNumberNormalizer::normalize(is_string($value) ? $value : (string) $value);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function donorCar(): BelongsTo
    {
        return $this->belongsTo(DonorCar::class);
    }

    public function sourcePartCatalogItem(): BelongsTo
    {
        return $this->belongsTo(PartCatalogItem::class, 'source_part_catalog_item_id');
    }

    public function donorDamageStatusChanger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'donor_damage_status_changed_by');
    }

    public function stockItems(): HasMany
    {
        return $this->hasMany(StockItem::class);
    }

    public function stoWorkOrderParts(): HasMany
    {
        return $this->hasMany(StoWorkOrderPart::class);
    }

    public function purchaseItems(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(Movement::class);
    }
}
