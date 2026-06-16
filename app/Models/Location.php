<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['warehouse_id', 'floor', 'zone', 'row', 'shelf', 'cell', 'full_code', 'is_active'])]
class Location extends Model
{
    use HasFactory;

    public const ZONES = [
        'A' => 'A',
        'B' => 'B',
        'C' => 'C',
    ];

    public const FLOORS = [
        'floor_1' => 'Этаж 1',
        'floor_2' => 'Этаж 2',
    ];

    public static function floorsForCount(?int $floorCount): array
    {
        $floorCount = max(1, (int) ($floorCount ?: 1));

        return collect(range(1, $floorCount))
            ->mapWithKeys(fn (int $floor) => ["floor_{$floor}" => "Этаж {$floor}"])
            ->all();
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function floorLabel(): string
    {
        if (is_string($this->floor) && preg_match('/^floor_(\d+)$/', $this->floor, $matches)) {
            return "Этаж {$matches[1]}";
        }

        return '—';
    }

    public function stockItems(): HasMany
    {
        return $this->hasMany(StockItem::class);
    }
}
