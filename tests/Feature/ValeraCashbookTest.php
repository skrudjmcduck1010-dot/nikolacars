<?php

namespace Tests\Feature;

use App\Models\CashbookLabel;
use App\Models\CashTransaction;
use App\Models\DonorCar;
use App\Models\User;
use App\Models\ValeraCashbookTransfer;
use App\Models\ValeraCashTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ValeraCashbookTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_valera_cashbook_page(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        ValeraCashTransaction::query()->create([
            'operation_date' => '2026-04-30',
            'operation_type' => 'Приход',
            'amount_usd' => 2500,
            'amount_uah' => 0,
            'purpose' => 'Женя инкассо через Влада',
            'source' => 'xlsx',
            'source_sheet' => 'new',
            'source_row' => 304,
        ]);

        $this->actingAs($user)
            ->get(route('admin.valera-cashbook.index', ['from' => '2026-04-01', 'to' => '2026-04-30']))
            ->assertOk()
            ->assertSee('Касса Валера')
            ->assertSee('Женя инкассо через Влада')
            ->assertSee('2 500,00');
    }

    public function test_valera_cashbook_defaults_to_may_2023_through_today(): void
    {
        $this->travelTo('2026-05-01');

        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-default-dates@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        ValeraCashTransaction::query()->create([
            'operation_date' => '2023-04-30',
            'operation_type' => 'income',
            'amount_usd' => 100,
            'amount_uah' => 0,
            'purpose' => 'Before default range',
            'source' => 'xlsx',
            'source_sheet' => 'new',
            'source_row' => 101,
        ]);

        ValeraCashTransaction::query()->create([
            'operation_date' => '2023-05-01',
            'operation_type' => 'income',
            'amount_usd' => 200,
            'amount_uah' => 0,
            'purpose' => 'Default range start',
            'source' => 'xlsx',
            'source_sheet' => 'new',
            'source_row' => 102,
        ]);

        ValeraCashTransaction::query()->create([
            'operation_date' => '2026-05-01',
            'operation_type' => 'income',
            'amount_usd' => 300,
            'amount_uah' => 0,
            'purpose' => 'Default range today',
            'source' => 'xlsx',
            'source_sheet' => 'new',
            'source_row' => 103,
        ]);

        $this->actingAs($user)
            ->get(route('admin.valera-cashbook.index'))
            ->assertOk()
            ->assertSee('value="2023-05-01"', false)
            ->assertSee('value="2026-05-01"', false)
            ->assertSee('Default range start')
            ->assertSee('Default range today')
            ->assertDontSee('Before default range');
    }

    public function test_valera_cashbook_transaction_label_is_read_only(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-label-readonly@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        ValeraCashTransaction::query()->create([
            'operation_date' => '2026-04-30',
            'operation_type' => '',
            'amount_usd' => 0,
            'amount_uah' => -100,
            'purpose' => 'Ad payment',
            'label' => 'Сайт',
            'source' => 'xlsx',
            'source_sheet' => 'new',
            'source_row' => 306,
        ]);

        $this->actingAs($user)
            ->get(route('admin.valera-cashbook.index', ['from' => '2026-04-01', 'to' => '2026-04-30']))
            ->assertOk()
            ->assertSee('Сайт')
            ->assertDontSee('data-open-valera-cashbook-create="income"', false)
            ->assertDontSee('data-valera-cashbook-label-form', false)
            ->assertDontSee('admin.valera-cashbook.label.update');
    }

    public function test_admin_cannot_create_valera_cashbook_income_transaction_directly(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-valera-income@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        CashbookLabel::query()->updateOrCreate(
            ['name' => 'Manual income'],
            ['operation_type' => 'income'],
        );

        $this->actingAs($user)
            ->post(route('admin.valera-cashbook.store'), [
                'operation_date' => '2026-05-01',
                'transaction_type' => 'income',
                'label' => 'Manual income',
                'income_usd' => 100,
                'purpose' => 'Manual Valera income',
                'person' => 'Valera',
            ])
            ->assertSessionHasErrors('transaction_type');

        $this->assertDatabaseMissing('valera_cash_transactions', [
            'label' => 'Manual income',
            'purpose' => 'Manual Valera income',
        ]);
    }

    public function test_admin_can_create_valera_cashbook_expense_transaction(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-valera-expense@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        CashbookLabel::query()->updateOrCreate(
            ['name' => 'Manual expense'],
            ['operation_type' => 'expense'],
        );

        $this->actingAs($user)
            ->post(route('admin.valera-cashbook.store'), [
                'operation_date' => '2026-05-01',
                'transaction_type' => 'expense',
                'label' => 'Manual expense',
                'expense_uah' => 2500,
                'purpose' => 'Manual Valera expense',
            ])
            ->assertRedirect(route('admin.valera-cashbook.index'));

        $this->assertDatabaseHas('valera_cash_transactions', [
            'operation_date' => '2026-05-01 00:00:00',
            'label' => 'Manual expense',
            'income_uah' => 0,
            'expense_uah' => 2500,
            'amount_uah' => -2500,
            'purpose' => 'Manual Valera expense',
            'comment' => 'Manual Valera expense',
            'source' => 'manual',
        ]);
    }

    public function test_valera_cashbook_donor_expense_fields_are_rendered(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-valera-donor-form@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        CashbookLabel::query()->updateOrCreate(
            ['name' => 'Донор'],
            ['operation_type' => 'expense'],
        );

        DonorCar::query()->create([
            'vin' => '5YJSA1E41MF424298',
            'model' => 'Model S',
            'year' => 2021,
        ]);

        $this->actingAs($user)
            ->get(route('admin.valera-cashbook.index'))
            ->assertOk()
            ->assertSee('name="vehicle_vin"', false)
            ->assertSee('name="donor_expense_type"', false)
            ->assertSee('5YJSA1E41MF424298')
            ->assertSee('Цена покупки(со сборами)')
            ->assertSee('Растаможка');
    }

    public function test_valera_cashbook_donor_expense_updates_selected_donor_finance_field(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-valera-donor-expense@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        CashbookLabel::query()->updateOrCreate(
            ['name' => 'Донор'],
            ['operation_type' => 'expense'],
        );

        $donorCar = DonorCar::query()->create([
            'vin' => '5YJSA1E41MF424298',
            'model' => 'Model S',
            'year' => 2021,
        ]);

        $this->actingAs($user)
            ->post(route('admin.valera-cashbook.store'), [
                'operation_date' => '2026-05-01',
                'transaction_type' => 'expense',
                'label' => 'Донор',
                'vehicle_vin' => $donorCar->vin,
                'donor_expense_type' => 'customs_clearance',
                'expense_usd' => 1250,
                'purpose' => 'Customs donor expense',
            ])
            ->assertRedirect(route('admin.valera-cashbook.index'));

        $this->assertDatabaseHas('valera_cash_transactions', [
            'operation_date' => '2026-05-01 00:00:00',
            'label' => 'Донор',
            'vehicle_vin' => $donorCar->vin,
            'expense_usd' => 1250,
            'source' => 'manual',
        ]);

        $this->assertDatabaseHas('donor_cars', [
            'id' => $donorCar->id,
            'customs_clearance_price_usd' => 1250,
        ]);

        $this->assertSame(
            DonorCar::DONOR_EXPENSE_SOURCE_VALERA_CASHBOOK,
            $donorCar->refresh()->donor_expense_sources['customs_clearance_price_usd'] ?? null,
        );
    }

    public function test_admin_can_create_valera_cashbook_exchange_transaction(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-valera-exchange@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        CashbookLabel::query()->updateOrCreate(
            ['name' => 'Manual exchange'],
            ['operation_type' => 'exchange'],
        );

        $this->actingAs($user)
            ->post(route('admin.valera-cashbook.store'), [
                'operation_date' => '2026-05-01',
                'transaction_type' => 'exchange',
                'label' => 'Manual exchange',
                'income_uah' => 40000,
                'expense_usd' => 1000,
                'purpose' => 'Manual Valera exchange',
            ])
            ->assertRedirect(route('admin.valera-cashbook.index'));

        $this->assertDatabaseHas('valera_cash_transactions', [
            'operation_date' => '2026-05-01 00:00:00',
            'label' => 'Manual exchange',
            'income_uah' => 40000,
            'expense_usd' => 1000,
            'amount_uah' => 40000,
            'amount_usd' => -1000,
            'purpose' => 'Manual Valera exchange',
            'comment' => 'Manual Valera exchange',
            'source' => 'manual',
        ]);

        $this->actingAs($user)
            ->get(route('admin.valera-cashbook.index'))
            ->assertOk()
            ->assertSee('tag tag-exchange', false)
            ->assertSee('Курс: 40,00');
    }

    public function test_admin_can_delete_valera_cashbook_transaction(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-delete@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $transaction = ValeraCashTransaction::query()->create([
            'operation_date' => '2026-04-30',
            'operation_type' => '',
            'amount_usd' => 0,
            'amount_uah' => -1000,
            'purpose' => 'Test delete',
            'source' => 'xlsx',
            'source_sheet' => 'new',
            'source_row' => 305,
        ]);

        $this->actingAs($user)
            ->delete(route('admin.valera-cashbook.destroy', $transaction))
            ->assertRedirect();

        $this->assertDatabaseMissing('valera_cash_transactions', [
            'id' => $transaction->id,
        ]);
    }

    public function test_admin_cannot_delete_old_cashbook_transaction(): void
    {
        $this->travelTo('2026-05-01 12:00:00');

        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-old-cashbook-delete@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $transaction = CashTransaction::query()->create([
            'operation_date' => '2026-05-01',
            'label' => 'Old cashbook operation',
            'expense_cash_uah' => 100,
            'comment' => 'Old cashbook operation',
            'source' => 'manual',
        ]);

        $transaction->forceFill([
            'created_at' => now()->subDay()->subSecond(),
        ])->saveQuietly();

        $this->actingAs($user)
            ->delete(route('admin.cashbook.destroy', $transaction))
            ->assertRedirect(route('admin.cashbook.index'))
            ->assertSessionHas('status', 'Операцию старше 1 суток нельзя удалить.');

        $this->assertDatabaseHas('cash_transactions', [
            'id' => $transaction->id,
        ]);
    }

    public function test_admin_cannot_delete_old_valera_cashbook_transaction(): void
    {
        $this->travelTo('2026-05-01 12:00:00');

        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-old-valera-delete@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $transaction = ValeraCashTransaction::query()->create([
            'operation_date' => '2026-04-30',
            'operation_type' => '',
            'amount_usd' => 0,
            'amount_uah' => -1000,
            'purpose' => 'Old Valera operation',
            'source' => 'manual',
        ]);

        $transaction->forceFill([
            'created_at' => now()->subDay()->subSecond(),
        ])->saveQuietly();

        $this->actingAs($user)
            ->from(route('admin.valera-cashbook.index'))
            ->delete(route('admin.valera-cashbook.destroy', $transaction))
            ->assertRedirect(route('admin.valera-cashbook.index'))
            ->assertSessionHas('status', 'Операцию старше 1 суток нельзя удалить.');

        $this->assertDatabaseHas('valera_cash_transactions', [
            'id' => $transaction->id,
        ]);
    }

    public function test_deleting_valera_cashbook_transaction_recalculates_later_balances(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-recalc@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $first = ValeraCashTransaction::query()->create([
            'operation_date' => '2026-04-30',
            'operation_type' => 'Приход',
            'amount_usd' => 100,
            'amount_uah' => 1000,
            'balance_usd' => 100,
            'balance_uah' => 1000,
            'purpose' => 'Income to delete',
            'source' => 'xlsx',
            'source_sheet' => 'new',
            'source_row' => 10,
        ]);

        $second = ValeraCashTransaction::query()->create([
            'operation_date' => '2026-04-30',
            'operation_type' => 'Приход',
            'amount_usd' => 50,
            'amount_uah' => 500,
            'balance_usd' => 150,
            'balance_uah' => 1500,
            'purpose' => 'Later income',
            'source' => 'xlsx',
            'source_sheet' => 'new',
            'source_row' => 11,
        ]);

        $this->actingAs($user)
            ->delete(route('admin.valera-cashbook.destroy', $first))
            ->assertRedirect();

        $second->refresh();

        $this->assertSame(50.0, (float) $second->balance_usd);
        $this->assertSame(500.0, (float) $second->balance_uah);
    }

    public function test_latest_balance_ignores_pending_valera_cashbook_transfers(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-pending-balance@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        ValeraCashTransaction::query()->create([
            'operation_date' => '2026-05-01',
            'operation_type' => 'Приход',
            'amount_usd' => 100,
            'income_usd' => 100,
            'balance_usd' => 100,
            'purpose' => 'Initial income',
            'comment' => 'Initial income',
            'source' => 'manual',
            'source_row' => 1,
        ]);

        ValeraCashTransaction::query()->create([
            'operation_date' => '2026-05-01',
            'operation_type' => 'Приход',
            'amount_usd' => 50,
            'income_usd' => 50,
            'balance_usd' => 150,
            'purpose' => 'Pending transfer',
            'comment' => 'Pending transfer',
            'source' => 'xlsx',
            'source_row' => 2,
        ]);

        ValeraCashTransaction::query()->create([
            'operation_date' => '2026-05-01',
            'operation_type' => '',
            'amount_usd' => -20,
            'expense_usd' => 20,
            'balance_usd' => 130,
            'purpose' => 'Latest confirmed expense',
            'comment' => 'Latest confirmed expense',
            'source' => 'manual',
            'source_row' => 3,
        ]);

        $cashTransaction = CashTransaction::query()->create([
            'operation_date' => '2026-05-01',
            'label' => 'нкассо Валера',
            'expense_cash_usd' => 50,
            'comment' => 'Pending transfer',
            'source' => 'manual',
        ]);

        ValeraCashbookTransfer::query()->create([
            'cash_transaction_id' => $cashTransaction->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user)
            ->get(route('admin.valera-cashbook.index', ['from' => '2026-05-01', 'to' => '2026-05-01']))
            ->assertOk();

        $latestBalance = $response->viewData('latestBalance');

        $this->assertSame(80.0, (float) $latestBalance->balance_usd);
        $this->assertSame(0.0, (float) $latestBalance->balance_uah);
    }

    public function test_manual_valera_cashbook_transaction_is_latest_after_imported_rows_on_same_date(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-manual-latest-balance@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        ValeraCashTransaction::query()->create([
            'operation_date' => '2026-05-04',
            'operation_type' => 'Приход',
            'amount_usd' => 2000,
            'income_usd' => 2000,
            'balance_usd' => 2000,
            'purpose' => 'Imported income',
            'comment' => 'Imported income',
            'source' => 'xlsx',
            'source_row' => 20,
        ]);

        CashbookLabel::query()->updateOrCreate(
            ['name' => 'Manual expense'],
            ['operation_type' => 'expense'],
        );

        $this->actingAs($user)
            ->post(route('admin.valera-cashbook.store'), [
                'operation_date' => '2026-05-04',
                'transaction_type' => 'expense',
                'label' => 'Manual expense',
                'expense_usd' => 1650,
                'purpose' => 'Manual same-day expense',
            ])
            ->assertRedirect(route('admin.valera-cashbook.index'));

        $response = $this->actingAs($user)
            ->get(route('admin.valera-cashbook.index', [
                'from' => '2026-05-04',
                'to' => '2026-05-04',
            ]))
            ->assertOk();

        $latestBalance = $response->viewData('latestBalance');

        $this->assertSame(350.0, (float) $latestBalance->balance_usd);
        $this->assertSame(0.0, (float) $latestBalance->balance_uah);
    }

    public function test_admin_confirms_cashbook_valera_incasso_into_valera_cashbook(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-valera-transfer@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        CashbookLabel::query()->updateOrCreate(
            ['name' => 'Инкассо Валера'],
            ['operation_type' => 'expense'],
        );

        CashbookLabel::query()->updateOrCreate(
            ['name' => 'Приход из Кассы и работ'],
            ['operation_type' => 'income'],
        );

        $this->actingAs($user)
            ->post(route('admin.cashbook.store'), [
                'operation_date' => '2026-05-01',
                'label' => 'Инкассо Валера',
                'expense_cash_usd' => 2500,
                'expense_uah' => 0,
                'expense_payment_method' => 'cash',
                'comment' => 'Инкассо Валера через Влада',
                'source' => 'manual',
                'transaction_type' => 'expense',
            ])
            ->assertRedirect(route('admin.cashbook.index'));

        $cashTransaction = CashTransaction::query()->where('label', 'Инкассо Валера')->firstOrFail();
        $transfer = ValeraCashbookTransfer::query()->where('cash_transaction_id', $cashTransaction->id)->firstOrFail();
        $importedValeraTransaction = ValeraCashTransaction::query()->create([
            'operation_date' => '2026-05-01',
            'operation_type' => 'Приход',
            'amount_usd' => 2500,
            'amount_uah' => 0,
            'income_usd' => 2500,
            'income_uah' => 0,
            'purpose' => 'Женя инкассо через Влада',
            'comment' => 'Женя инкассо через Влада',
            'label' => 'Инкассо Женя',
            'source' => 'xlsx',
            'source_sheet' => 'new',
            'source_row' => 304,
        ]);

        $this->assertSame('pending', $transfer->status);

        $this->actingAs($user)
            ->get(route('admin.cashbook.index', [
                'from' => '2026-05-01',
                'to' => '2026-05-01',
            ]))
            ->assertOk()
            ->assertSee('Инкассо Валера')
            ->assertSee('Ожидает подтверждения');

        $response = $this->actingAs($user)
            ->get(route('admin.valera-cashbook.index'))
            ->assertOk()
            ->assertSee('Ожидают подтверждения')
            ->assertSee('Инкассо Валера через Влада')
            ->assertSee('2 500,00')
            ->assertSee('Показано: 0');

        $this->actingAs($user)
            ->post(route('admin.valera-cashbook.transfers.confirm', $transfer))
            ->assertRedirect(route('admin.valera-cashbook.index'));

        $transfer->refresh();

        $this->assertSame('confirmed', $transfer->status);
        $this->assertSame($importedValeraTransaction->id, $transfer->confirmed_valera_cash_transaction_id);

        $this->assertDatabaseHas('valera_cash_transactions', [
            'id' => $transfer->confirmed_valera_cash_transaction_id,
            'operation_type' => 'Приход',
            'amount_usd' => 2500,
            'amount_uah' => 0,
            'purpose' => 'Женя инкассо через Влада',
            'label' => 'Приход из Кассы и работ',
            'source' => 'xlsx',
        ]);

        $this->assertSame(1, ValeraCashTransaction::query()
            ->where('income_usd', 2500)
            ->where('label', 'Приход из Кассы и работ')
            ->count());

        $this->actingAs($user)
            ->get(route('admin.cashbook.index', [
                'from' => '2026-05-01',
                'to' => '2026-05-01',
            ]))
            ->assertOk()
            ->assertSee('Инкассо Валера')
            ->assertSee('Подтверждено')
            ->assertDontSee('Ожидает подтверждения')
            ->assertDontSee(route('admin.cashbook.edit', $cashTransaction), false)
            ->assertDontSee('action="'.route('admin.cashbook.destroy', $cashTransaction).'"', false);

        $this->actingAs($user)
            ->get(route('admin.cashbook.edit', $cashTransaction))
            ->assertNotFound();

        $this->actingAs($user)
            ->put(route('admin.cashbook.update', $cashTransaction))
            ->assertRedirect(route('admin.cashbook.index'));

        $this->actingAs($user)
            ->get(route('admin.valera-cashbook.index'))
            ->assertOk()
            ->assertDontSee('action="'.route('admin.valera-cashbook.destroy', $transfer->confirmed_valera_cash_transaction_id).'"', false);

        $this->actingAs($user)
            ->delete(route('admin.cashbook.destroy', $cashTransaction))
            ->assertRedirect(route('admin.cashbook.index'));

        $this->assertDatabaseHas('cash_transactions', [
            'id' => $cashTransaction->id,
        ]);

        $this->assertDatabaseHas('valera_cash_transactions', [
            'id' => $transfer->confirmed_valera_cash_transaction_id,
            'source' => 'xlsx',
        ]);

        $this->actingAs($user)
            ->get(route('admin.valera-cashbook.index'))
            ->assertOk()
            ->assertDontSee('Удалена из Кассы Валера');
    }

    public function test_admin_can_cancel_pending_valera_incasso(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-valera-cancel@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        CashbookLabel::query()->updateOrCreate(
            ['name' => 'Инкассо Валера'],
            ['operation_type' => 'expense'],
        );

        $this->actingAs($user)
            ->post(route('admin.cashbook.store'), [
                'operation_date' => '2026-05-01',
                'label' => 'Инкассо Валера',
                'expense_uah' => 1200,
                'expense_payment_method' => 'bank',
                'expense_cash_usd' => 50,
                'comment' => 'Инкассо Валера отменить',
                'source' => 'manual',
                'transaction_type' => 'expense',
            ])
            ->assertRedirect(route('admin.cashbook.index'));

        $cashTransaction = CashTransaction::query()->where('label', 'Инкассо Валера')->firstOrFail();
        $transfer = ValeraCashbookTransfer::query()->where('cash_transaction_id', $cashTransaction->id)->firstOrFail();

        $this->actingAs($user)
            ->get(route('admin.valera-cashbook.index'))
            ->assertOk()
            ->assertSee('Отмена');

        $this->actingAs($user)
            ->post(route('admin.valera-cashbook.transfers.cancel', $transfer))
            ->assertRedirect(route('admin.valera-cashbook.index'));

        $transfer->refresh();
        $cashTransaction->refresh();

        $this->assertSame('cancelled', $transfer->status);
        $this->assertNotNull($transfer->confirmed_valera_cash_transaction_id);
        $this->assertNotNull($transfer->cancelled_at);

        $this->assertSame(0.0, (float) $cashTransaction->income_bank_uah);
        $this->assertSame(0.0, (float) $cashTransaction->income_cash_uah);
        $this->assertSame(0.0, (float) $cashTransaction->income_cash_usd);
        $this->assertSame(0.0, (float) $cashTransaction->expense_bank_uah);
        $this->assertSame(0.0, (float) $cashTransaction->expense_cash_uah);
        $this->assertSame(0.0, (float) $cashTransaction->expense_cash_usd);
        $this->assertSame(1200.0, (float) $cashTransaction->cancelled_amount_uah);
        $this->assertSame(50.0, (float) $cashTransaction->cancelled_amount_usd);
        $this->assertNotNull($cashTransaction->cancelled_at);

        $this->assertDatabaseHas('valera_cash_transactions', [
            'id' => $transfer->confirmed_valera_cash_transaction_id,
            'operation_type' => 'Отменена',
            'amount_usd' => 0,
            'amount_uah' => 0,
            'income_usd' => 0,
            'income_uah' => 0,
            'expense_usd' => 0,
            'expense_uah' => 0,
            'cancelled_amount_usd' => 50,
            'cancelled_amount_uah' => 1200,
            'purpose' => 'Инкассо Валера отменить',
            'label' => 'Отменена инкассация Валера',
            'source' => 'cashbook_transfer_cancelled',
        ]);

        $this->actingAs($user)
            ->get(route('admin.cashbook.index', [
                'from' => '2026-05-01',
                'to' => '2026-05-01',
            ]))
            ->assertOk()
            ->assertSee('Отменена')
            ->assertSee('tag-archived', false)
            ->assertDontSee(route('admin.cashbook.edit', $cashTransaction), false)
            ->assertDontSee('<button type="submit" class="btn-small btn-danger">Удалить</button>', false)
            ->assertSee('1 200,00')
            ->assertSee('50,00')
            ->assertDontSee('Ожидает подтверждения');

        $this->actingAs($user)
            ->get(route('admin.cashbook.edit', $cashTransaction))
            ->assertNotFound();

        $this->actingAs($user)
            ->delete(route('admin.cashbook.destroy', $cashTransaction))
            ->assertRedirect(route('admin.cashbook.index'));

        $this->assertDatabaseHas('cash_transactions', [
            'id' => $cashTransaction->id,
            'cancelled_amount_uah' => 1200,
            'cancelled_amount_usd' => 50,
        ]);

        $this->actingAs($user)
            ->get(route('admin.valera-cashbook.index'))
            ->assertOk()
            ->assertDontSee('Ожидают подтверждения')
            ->assertSee('Отменена')
            ->assertSee('1 200,00')
            ->assertSee('50,00');
    }
}
