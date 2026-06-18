<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use App\Services\ExchangeRateService;
use App\Services\LoginActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request, ExchangeRateService $exchangeRateService, LoginActivityLogger $loginActivityLogger): RedirectResponse
    {
        $credentials = $request->validated();
        $attemptedUser = $this->userForLoginEmail($request->attemptedEmail());

        if ($request->isRateLimited()) {
            $loginActivityLogger->locked($request, $attemptedUser);
        }

        $request->ensureIsNotRateLimited();

        if (! Auth::attempt([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
            'is_active' => true,
        ], (bool) ($credentials['remember'] ?? false))) {
            $request->hitLoginRateLimiter();
            $loginActivityLogger->failed($request, $attemptedUser);

            return back()
                ->withErrors(['email' => 'Неверный логин или пароль.'])
                ->onlyInput('email');
        }

        $request->clearEmailRateLimiter();
        $request->session()->regenerate();

        try {
            $exchangeRateService->ensureTodayUsdRateStored();
        } catch (\Throwable $exception) {
            Log::warning('Could not ensure today USD exchange rate after login.', [
                'exception' => $exception,
            ]);
        }

        return redirect()->intended($this->defaultRedirectUrl($request->user()));
    }

    public function destroy(): RedirectResponse
    {
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function defaultRedirectUrl(?User $user): string
    {
        if ($user?->role === User::ROLE_WAREHOUSE_WORKER) {
            return route('admin.zapchasti.index');
        }

        return route('admin.dashboard');
    }

    private function userForLoginEmail(string $email): ?User
    {
        return User::query()
            ->where('email', $email)
            ->first();
    }
}
