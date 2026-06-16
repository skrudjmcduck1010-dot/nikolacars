<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Auth;

trait TracksUserStamps
{
    protected static function bootTracksUserStamps(): void
    {
        static::creating(function ($model): void {
            $userId = Auth::id();

            if ($userId && empty($model->created_by) && self::hasColumn($model, 'created_by')) {
                $model->created_by = $userId;
            }

            if ($userId && empty($model->updated_by) && self::hasColumn($model, 'updated_by')) {
                $model->updated_by = $userId;
            }
        });

        static::updating(function ($model): void {
            $userId = Auth::id();

            if ($userId && self::hasColumn($model, 'updated_by')) {
                $model->updated_by = $userId;
            }
        });
    }

    protected static function hasColumn($model, string $column): bool
    {
        return array_key_exists($column, $model->getAttributes()) || $model->isFillable($column);
    }
}
