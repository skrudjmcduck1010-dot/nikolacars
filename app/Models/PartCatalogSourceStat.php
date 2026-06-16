<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'source',
    'total_count',
    'with_image_count',
    'without_image_count',
    'name_conflict_count',
    'missing_ru_count',
    'missing_ua_count',
    'rebuilt_at',
])]
class PartCatalogSourceStat extends Model
{
    protected $primaryKey = 'source';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'rebuilt_at' => 'datetime',
        ];
    }
}
