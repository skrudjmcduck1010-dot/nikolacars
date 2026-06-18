<?php

namespace App\Services;

use App\Http\Requests\LoginRequest;
use App\Models\AdminActivityLog;
use App\Models\User;
use Throwable;

class LoginActivityLogger
{
    private const ACTION_FAILED = "\u{041d}\u{0435}\u{0443}\u{0434}\u{0430}\u{0447}\u{043d}\u{044b}\u{0439} \u{0432}\u{0445}\u{043e}\u{0434}";

    private const ACTION_LOCKED = "\u{0411}\u{043b}\u{043e}\u{043a}\u{0438}\u{0440}\u{043e}\u{0432}\u{043a}\u{0430} \u{0432}\u{0445}\u{043e}\u{0434}\u{0430}";

    public function failed(LoginRequest $request, ?User $user): void
    {
        $this->write($request, $user, self::ACTION_FAILED, 'invalid_credentials');
    }

    public function locked(LoginRequest $request, ?User $user): void
    {
        $this->write($request, $user, self::ACTION_LOCKED, 'too_many_attempts');
    }

    private function write(LoginRequest $request, ?User $user, string $action, string $reason): void
    {
        try {
            AdminActivityLog::query()->create([
                'user_id' => $user?->id,
                'action' => $action,
                'route_name' => (string) $request->route()?->getName(),
                'method' => $request->method(),
                'url' => $request->fullUrl(),
                'ip_address' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
                'status_code' => 302,
                'payload' => array_merge([
                    'reason' => $reason,
                    'attempted_email' => $request->attemptedEmail(),
                    'matched_user_active' => $user?->is_active,
                    'remember' => $request->boolean('remember'),
                ], $request->rateLimitState()),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
