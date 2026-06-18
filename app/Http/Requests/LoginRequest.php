<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 600;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ];
    }

    public function ensureIsNotRateLimited(): void
    {
        if (! $this->isRateLimited()) {
            return;
        }

        $seconds = $this->rateLimitWaitSeconds();
        $wait = $this->availableInLabel($seconds);

        throw ValidationException::withMessages([
            'email' => "\u{0421}\u{043B}\u{0438}\u{0448}\u{043A}\u{043E}\u{043C} \u{043C}\u{043D}\u{043E}\u{0433}\u{043E} \u{043F}\u{043E}\u{043F}\u{044B}\u{0442}\u{043E}\u{043A} \u{0432}\u{0445}\u{043E}\u{0434}\u{0430}. \u{041F}\u{043E}\u{043F}\u{0440}\u{043E}\u{0431}\u{0443}\u{0439}\u{0442}\u{0435} \u{0441}\u{043D}\u{043E}\u{0432}\u{0430} \u{0447}\u{0435}\u{0440}\u{0435}\u{0437} {$wait}.",
        ]);
    }

    public function hitLoginRateLimiter(): void
    {
        foreach ($this->throttleKeys() as $key) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
        }
    }

    public function clearEmailRateLimiter(): void
    {
        RateLimiter::clear($this->emailThrottleKey());
    }

    public function isRateLimited(): bool
    {
        return collect($this->throttleKeys())
            ->contains(fn (string $key): bool => RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS));
    }

    public function rateLimitState(): array
    {
        $emailKey = $this->emailThrottleKey();
        $ipKey = $this->ipThrottleKey();

        return [
            'email_attempts' => RateLimiter::attempts($emailKey),
            'ip_attempts' => RateLimiter::attempts($ipKey),
            'blocked_by_email' => RateLimiter::tooManyAttempts($emailKey, self::MAX_ATTEMPTS),
            'blocked_by_ip' => RateLimiter::tooManyAttempts($ipKey, self::MAX_ATTEMPTS),
            'retry_after_seconds' => $this->rateLimitWaitSeconds(),
        ];
    }

    public function attemptedEmail(): string
    {
        return Str::lower(trim((string) $this->input('email')));
    }

    public function throttleKeys(): array
    {
        return [
            $this->emailThrottleKey(),
            $this->ipThrottleKey(),
        ];
    }

    private function emailThrottleKey(): string
    {
        return 'login:email:'.Str::transliterate($this->attemptedEmail());
    }

    private function ipThrottleKey(): string
    {
        return 'login:ip:'.$this->ip();
    }

    private function availableInLabel(int $seconds): string
    {
        if ($seconds >= 60) {
            $minutes = (int) ceil($seconds / 60);

            return $minutes.' '.$this->minuteWord($minutes);
        }

        return $seconds.' сек.';
    }

    private function rateLimitWaitSeconds(): int
    {
        return collect($this->throttleKeys())
            ->filter(fn (string $key): bool => RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS))
            ->map(fn (string $key): int => RateLimiter::availableIn($key))
            ->max() ?? 0;
    }

    private function minuteWord(int $minutes): string
    {
        if ($minutes % 10 === 1 && $minutes % 100 !== 11) {
            return 'минуту';
        }

        if (in_array($minutes % 10, [2, 3, 4], true) && ! in_array($minutes % 100, [12, 13, 14], true)) {
            return 'минуты';
        }

        return 'минут';
    }
}
