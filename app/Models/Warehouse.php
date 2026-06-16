<?php

namespace App\Models;

use App\Support\CatalogTextEncoding;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'type', 'floor_count', 'is_active'])]
class Warehouse extends Model
{
    use HasFactory;

    public const TYPE_MAIN = 'main';

    public const TYPE_DONOR = 'donor';

    public const DONOR_WAREHOUSE_NAME = 'На доноре';

    protected function casts(): array
    {
        return [
            'floor_count' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function getNameAttribute(?string $value): ?string
    {
        return CatalogTextEncoding::repair($value);
    }

    public function availableFloors(): array
    {
        return Location::floorsForCount($this->floor_count);
    }

    public function hasMultipleFloors(): bool
    {
        return $this->floor_count > 1;
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function stockItems(): HasMany
    {
        return $this->hasMany(StockItem::class);
    }
}
