<?php

namespace Tests\Feature;

use App\Models\AdminActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_email_is_throttled_after_five_failed_attempts_across_ip_addresses(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $this->clearThrottleKeys($user->email, ['127.0.0.1', '203.0.113.10']);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this
                ->from(route('login'))
                ->post(route('login.store'), [
                    'email' => $user->email,
                    'password' => 'wrong-password',
                ])
                ->assertRedirect(route('login'))
                ->assertSessionHasErrors('email');
        }

        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->from(route('login'))
            ->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'password',
            ])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertStringContainsString(
            '10 минут',
            $response->getSession()->get('errors')->first('email'),
        );
        $this->assertGuest();

        $log = AdminActivityLog::query()->orderByDesc('id')->firstOrFail();

        $this->assertSame($user->id, $log->user_id);
        $this->assertSame('login.store', $log->route_name);
        $this->assertSame('too_many_attempts', $log->payload['reason']);
        $this->assertSame($user->email, $log->payload['attempted_email']);
        $this->assertSame(5, $log->payload['email_attempts']);
        $this->assertSame(0, $log->payload['ip_attempts']);
        $this->assertTrue($log->payload['blocked_by_email']);
        $this->assertFalse($log->payload['blocked_by_ip']);
    }

    public function test_login_ip_is_throttled_after_five_failed_attempts_across_different_logins(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $failedEmails = collect(range(1, 5))
            ->map(fn (int $attempt): string => "missing{$attempt}@example.com");
        $this->clearThrottleKeys($user->email);
        $failedEmails->each(fn (string $email) => $this->clearThrottleKeys($email));

        foreach ($failedEmails as $email) {
            $this
                ->from(route('login'))
                ->post(route('login.store'), [
                    'email' => $email,
                    'password' => 'wrong-password',
                ])
                ->assertRedirect(route('login'))
                ->assertSessionHasErrors('email');
        }

        $response = $this
            ->from(route('login'))
            ->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'password',
            ])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertStringContainsString(
            '10 ',
            $response->getSession()->get('errors')->first('email'),
        );
        $this->assertGuest();

        $log = AdminActivityLog::query()->orderByDesc('id')->firstOrFail();

        $this->assertSame($user->id, $log->user_id);
        $this->assertSame('login.store', $log->route_name);
        $this->assertSame('too_many_attempts', $log->payload['reason']);
        $this->assertSame($user->email, $log->payload['attempted_email']);
        $this->assertSame(0, $log->payload['email_attempts']);
        $this->assertSame(5, $log->payload['ip_attempts']);
        $this->assertFalse($log->payload['blocked_by_email']);
        $this->assertTrue($log->payload['blocked_by_ip']);
    }

    public function test_successful_login_clears_failed_attempt_counter(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $throttleKey = $this->emailThrottleKey($user->email);
        $this->clearThrottleKeys($user->email);
        Http::fake([
            'bank.gov.ua/*' => Http::response([[
                'cc' => 'USD',
                'rate' => 42.25,
            ]]),
        ]);

        $this
            ->from(route('login'))
            ->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertSame(1, RateLimiter::attempts($throttleKey));

        $log = AdminActivityLog::query()->firstOrFail();

        $this->assertSame($user->id, $log->user_id);
        $this->assertSame('login.store', $log->route_name);
        $this->assertSame('POST', $log->method);
        $this->assertSame(302, $log->status_code);
        $this->assertSame('invalid_credentials', $log->payload['reason']);
        $this->assertSame($user->email, $log->payload['attempted_email']);
        $this->assertSame(1, $log->payload['email_attempts']);
        $this->assertSame(1, $log->payload['ip_attempts']);
        $this->assertArrayNotHasKey('password', $log->payload);

        $this
            ->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'password',
            ])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertSame(0, RateLimiter::attempts($throttleKey));
    }

    private function clearThrottleKeys(string $email, array $ips = ['127.0.0.1']): void
    {
        RateLimiter::clear($this->emailThrottleKey($email));

        foreach ($ips as $ip) {
            RateLimiter::clear($this->ipThrottleKey($ip));
        }
    }

    private function emailThrottleKey(string $email): string
    {
        return 'login:email:'.Str::transliterate(Str::lower($email));
    }

    private function ipThrottleKey(string $ip): string
    {
        return 'login:ip:'.$ip;
    }
}
