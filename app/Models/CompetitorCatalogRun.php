<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

#[Fillable([
    'source',
    'status',
    'progress_current',
    'progress_total',
    'message',
    'stats',
    'error',
    'started_at',
    'finished_at',
])]
class CompetitorCatalogRun extends Model
{
    public const REFRESH_COOLDOWN_HOURS = 24;

    public const STALE_AFTER_MINUTES = 10;

    protected function casts(): array
    {
        return [
            'stats' => AsArrayObject::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function isRunning(): bool
    {
        return in_array($this->status, ['pending', 'running'], true) && ! $this->isStopped();
    }

    public function isStopped(): bool
    {
        return in_array($this->status, ['pending', 'running'], true)
            && $this->updated_at !== null
            && $this->updated_at->lessThan(now()->subMinutes(self::STALE_AFTER_MINUTES));
    }

    public function scopeActive($query)
    {
        return $query
            ->whereIn('status', ['pending', 'running'])
            ->where('updated_at', '>=', now()->subMinutes(self::STALE_AFTER_MINUTES));
    }

    public function cooldownStartedAt(): ?Carbon
    {
        return $this->finished_at ?: $this->started_at;
    }

    public function cooldownAvailableAt(): ?Carbon
    {
        return $this->cooldownStartedAt()?->copy()->addHours(self::REFRESH_COOLDOWN_HOURS);
    }

    public function isInRefreshCooldown(?Carbon $now = null): bool
    {
        $availableAt = $this->cooldownAvailableAt();

        return $availableAt !== null && $availableAt->greaterThan($now ?? now());
    }

    public static function latestRefreshForCooldown(string $source): ?self
    {
        return self::query()
            ->where('source', $source)
            ->where('status', 'done')
            ->whereNotNull('started_at')
            ->latest('id')
            ->first();
    }

    public function getMessageAttribute(?string $value): ?string
    {
        return $this->normalizeEncoding($value);
    }

    public function setMessageAttribute(?string $value): void
    {
        $value = $this->normalizeEncoding($value);

        $this->attributes['message'] = $value === null ? null : str($value)->limit(255, '');
    }

    public function getErrorAttribute(?string $value): ?string
    {
        return $this->normalizeEncoding($value);
    }

    public function setErrorAttribute(?string $value): void
    {
        $this->attributes['error'] = $this->normalizeEncoding($value);
    }

    protected function normalizeEncoding(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (preg_match('/(?:Р|С)[\x{00A0}-\x{00BF}\x{0400}-\x{040F}\x{0450}-\x{045F}\x{201A}\x{201E}\x{2020}\x{2021}\x{02C6}\x{2030}\x{2039}\x{0152}\x{017D}]/u', $value) !== 1) {
            return $value;
        }

        $fixed = @mb_convert_encoding($value, 'Windows-1251', 'UTF-8');

        return is_string($fixed) && $fixed !== '' ? $fixed : $value;
    }
}
