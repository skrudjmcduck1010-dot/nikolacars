<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

#[Fillable([
    'currency',
    'rate_date',
    'rate',
    'source',
    'fetched_at',
])]
class ExchangeRate extends Model
{
    protected function casts(): array
    {
        return [
            'rate_date' => 'date',
            'rate' => 'decimal:6',
            'fetched_at' => 'datetime',
        ];
    }

    public function setRateDateAttribute(mixed $value): void
    {
        $this->attributes['rate_date'] = Carbon::parse($value)->toDateString();
    }
}
