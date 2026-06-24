<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\User;
use App\Services\ExchangeRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ExchangeRateTest extends TestCase
{
    use RefreshDatabase;

    public function test_fetch_command_stores_nbu_usd_rate_for_date(): void
    {
        Carbon::setTestNow('2026-05-01 09:15:00');
        Cache::flush();

        Http::fake([
            'bank.gov.ua/*' => Http::response([
                [
                    'cc' => 'USD',
                    'rate' => 40.123456,
                ],
            ]),
        ]);

        $this->artisan('exchange-rates:fetch --date=2026-04-29')
            ->assertSuccessful()
            ->expectsOutput('Stored USD rate for 2026-04-29: 40.123456');

        $exchangeRate = ExchangeRate::query()->firstOrFail();

        $this->assertSame('USD', $exchangeRate->currency);
        $this->assertSame('2026-04-29', $exchangeRate->rate_date->toDateString());
        $this->assertSame('nbu', $exchangeRate->source);
        $this->assertSame(40.123456, (float) $exchangeRate->rate);

        Carbon::setTestNow();
    }

    public function test_fetch_command_stores_monobank_usd_rate_from_switch_date(): void
    {
        Carbon::setTestNow('2026-06-22 09:15:00');
        Cache::flush();

        Http::fake([
            'api.monobank.ua/*' => Http::response([
                [
                    'currencyCodeA' => 840,
                    'currencyCodeB' => 980,
                    'rateBuy' => 40.1,
                    'rateSell' => 40.9,
                ],
            ]),
        ]);

        $this->artisan('exchange-rates:fetch --date=2026-06-22')
            ->assertSuccessful()
            ->expectsOutput('Stored USD rate for 2026-06-22: 40.900000');

        $exchangeRate = ExchangeRate::query()->firstOrFail();

        $this->assertSame('USD', $exchangeRate->currency);
        $this->assertSame('2026-06-22', $exchangeRate->rate_date->toDateString());
        $this->assertSame('monobank', $exchangeRate->source);
        $this->assertSame(40.9, (float) $exchangeRate->rate);

        Carbon::setTestNow();
    }

    public function test_fetch_command_does_not_duplicate_existing_rate(): void
    {
        Carbon::setTestNow('2026-05-01 09:15:00');
        Cache::flush();

        Http::fake([
            'bank.gov.ua/*' => Http::sequence()
                ->push([['cc' => 'USD', 'rate' => 40.1]])
                ->push([['cc' => 'USD', 'rate' => 40.2]]),
        ]);

        $this->artisan('exchange-rates:fetch --date=2026-04-29')->assertSuccessful();
        $this->artisan('exchange-rates:fetch --date=2026-04-29')->assertSuccessful();

        $this->assertSame(1, ExchangeRate::query()->count());
        $this->assertSame(40.1, (float) ExchangeRate::query()->firstOrFail()->rate);

        Carbon::setTestNow();
    }

    public function test_service_uses_stored_rate_for_requested_date(): void
    {
        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_date' => '2026-04-29',
            'rate' => 41.5,
            'source' => 'nbu',
            'fetched_at' => now(),
        ]);

        Http::fake();

        $rate = app(ExchangeRateService::class)->usdRateForDate('2026-04-29');

        $this->assertSame(41.5, $rate['rate']);
        $this->assertSame('nbu', $rate['source']);
        $this->assertSame('2026-04-29', $rate['date']);
        Http::assertNothingSent();
    }

    public function test_service_falls_back_when_nbu_request_fails(): void
    {
        Carbon::setTestNow('2026-05-01 09:15:00');
        Cache::flush();

        Http::fake([
            'bank.gov.ua/*' => fn () => throw new RuntimeException('NBU unavailable'),
        ]);

        $rate = app(ExchangeRateService::class)->usdRateForDate('2026-05-01');

        $this->assertSame(43.0, $rate['rate']);
        $this->assertSame('fallback', $rate['source']);
        $this->assertSame('2026-05-01', $rate['date']);

        Carbon::setTestNow();
    }

    public function test_service_ignores_manual_usd_rates(): void
    {
        Carbon::setTestNow('2026-04-29 10:00:00');
        Cache::flush();

        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_date' => '2026-04-29',
            'rate' => 43,
            'source' => 'manual',
            'fetched_at' => now(),
        ]);

        Http::fake([
            'bank.gov.ua/*' => Http::response([[
                'cc' => 'USD',
                'rate' => 40.5,
            ]]),
        ]);

        $rate = app(ExchangeRateService::class)->usdRateForDate('2026-04-29');

        $this->assertSame(40.5, $rate['rate']);
        $this->assertSame('nbu', $rate['source']);
        $this->assertSame(1, ExchangeRate::query()->count());
        $this->assertSame('nbu', ExchangeRate::query()->firstOrFail()->source);

        Carbon::setTestNow();
    }

    public function test_service_normalizes_catalog_uah_price_to_usd_without_fetching_rate(): void
    {
        Carbon::setTestNow('2026-05-05 10:00:00');

        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_date' => '2026-04-29',
            'rate' => 40,
            'source' => 'nbu',
            'fetched_at' => now(),
        ]);

        Http::fake();

        $price = app(ExchangeRateService::class)->catalogPriceToUsd(4000, 'UAH');

        $this->assertSame('100.00', $price);
        Http::assertNothingSent();
        Carbon::setTestNow();
    }

    public function test_admin_can_view_exchange_rates_page(): void
    {
        Carbon::setTestNow('2026-06-22 09:15:00');
        Cache::flush();

        $user = User::query()->create([
            'name' => 'Admin',
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_date' => '2026-06-22',
            'rate' => 43.963,
            'source' => 'monobank',
            'fetched_at' => now(),
        ]);
        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rate_date' => '2026-04-29',
            'rate' => 44.5,
            'source' => 'manual',
            'fetched_at' => now(),
        ]);
        Http::fake([
            'api.monobank.ua/*' => Http::response([[
                'currencyCodeA' => 840,
                'currencyCodeB' => 980,
                'rateSell' => 42.25,
            ]]),
        ]);

        $this->actingAs($user)
            ->get(route('admin.exchange-rates.index'))
            ->assertOk()
            ->assertSee('Курсы валют')
            ->assertSee('Курс на сегодня, 22.06.2026')
            ->assertSee('$ 43.96')
            ->assertSee('22.06.2026')
            ->assertSee('43.9630')
            ->assertSee('Загружен из Monobank')
            ->assertDontSee('MANUAL')
            ->assertDontSee('44.5000');

        Carbon::setTestNow();
    }

    public function test_successful_login_stores_today_usd_rate_when_missing(): void
    {
        Carbon::setTestNow('2026-05-01 09:15:00');
        Cache::flush();

        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Http::fake([
            'bank.gov.ua/*' => Http::response([
                [
                    'cc' => 'USD',
                    'rate' => 41.987654,
                ],
            ]),
        ]);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('admin.dashboard'));

        $exchangeRate = ExchangeRate::query()->firstOrFail();

        $this->assertSame('USD', $exchangeRate->currency);
        $this->assertSame('2026-05-01', $exchangeRate->rate_date->toDateString());
        $this->assertSame('nbu', $exchangeRate->source);
        $this->assertSame(41.987654, (float) $exchangeRate->rate);

        Http::assertSentCount(1);
        Carbon::setTestNow();
    }

    public function test_warehouse_worker_login_defaults_to_nikolacars_parts(): void
    {
        Carbon::setTestNow('2026-05-01 09:15:00');
        Cache::flush();

        $user = User::query()->create([
            'name' => 'Warehouse Worker',
            'email' => 'warehouse@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_WAREHOUSE_WORKER,
            'is_active' => true,
        ]);

        Http::fake([
            'bank.gov.ua/*' => Http::response([
                [
                    'cc' => 'USD',
                    'rate' => 41.987654,
                ],
            ]),
        ]);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('admin.zapchasti.index'));

        Carbon::setTestNow();
    }
}
