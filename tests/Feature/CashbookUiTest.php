<?php

namespace Tests\Feature;

use App\Models\CashbookLabel;
use App\Models\CashTransaction;
use App\Models\DonorCar;
use App\Models\StoEmployee;
use App\Models\StoWorkOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CashbookUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_operation_type_filter_shows_expense_option_label(): void
    {
        $this->actingAs($this->adminUser())
            ->get(route('admin.cashbook.index'))
            ->assertOk()
            ->assertSee('<label for="operation_type">Тип операции</label>', false)
            ->assertSee('<option value="expense" >Расход</option>', false)
            ->assertSee('>Расход</a></th>', false);
    }

    public function test_cashbook_label_settings_show_expense_operation_label(): void
    {
        CashbookLabel::query()->create([
            'name' => 'Manual expense',
            'operation_type' => 'expense',
        ]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.cashbook-labels.index'))
            ->assertOk()
            ->assertSee('<option value="expense" >Расход</option>', false)
            ->assertSee('<option value="expense" selected>Расход</option>', false);
    }

    public function test_parts_purchase_expense_option_redirects_to_purchase_create_page(): void
    {
        CashbookLabel::query()->updateOrCreate(
            ['name' => 'Закупка ЗЧК'],
            ['operation_type' => 'expense'],
        );

        $this->actingAs($this->adminUser())
            ->get(route('admin.cashbook.index'))
            ->assertOk()
            ->assertSee('data-open-cashbook-create="expense"', false)
            ->assertSee('data-cashbook-label="Закупка ЗЧК"', false)
            ->assertSee('data-cashbook-redirect-url="'.route('admin.purchases.create').'"', false);
    }

    public function test_selected_expense_labels_hide_employee_field_in_create_modal(): void
    {
        foreach (['', '   ', '', ' '] as $label) {
            CashbookLabel::query()->updateOrCreate(
                ['name' => $label],
                ['operation_type' => 'expense'],
            );
        }

        $this->actingAs($this->adminUser())
            ->get(route('admin.cashbook.index'))
            ->assertOk()
            ->assertSee('data-cashbook-employee-field', false)
            ->assertSee('data-cashbook-hide-employee="1"', false)
            ->assertSee('value="Аренда"', false)
            ->assertSee('value="Возврат Запчасти и денег"', false)
            ->assertSee('value="Донор"', false)
            ->assertSee('value=" "', false)
            ->assertSee('selectedLabelOption?.dataset.cashbookHideEmployee === \'1\'', false)
            ->assertSee('modalEmployeeSelect.disabled = shouldHide', false)
            ->assertSee("modalEmployeeSelect.value = ''", false);
    }

    public function test_sto_expense_parent_opens_child_label_dropdown(): void
    {
        $this->actingAs($this->adminUser())
            ->get(route('admin.cashbook.index'))
            ->assertOk()
            ->assertSee('data-cashbook-parent-label="СТО"', false)
            ->assertSee('data-cashbook-parent-label="СТО"', false)
            ->assertSee('data-cashbook-has-children="1"', false)
            ->assertSee('value="Аренда"', false)
            ->assertSee('checkbox-dropdown-option checkbox-dropdown-option-child', false)
            ->assertSeeInOrder(['value="СТО"', 'value="Аренда"'], false)
            ->assertSee('value="Аренда"
                        data-cashbook-label-type="expense"
                        data-cashbook-parent-label="СТО"', false)
            ->assertSee('value="Инструмент"', false)
            ->assertSee('value="Коммунальные"', false)
            ->assertSee('value="Налоги"', false)
            ->assertSee('value="Продукты"', false)
            ->assertSee('value="Прочие"', false)
            ->assertSee('value=""', false)
            ->assertSee('value=""', false)
            ->assertSee('value=" "', false)
            ->assertSee('value="Сайт"', false)
            ->assertSee('value="Связь"', false)
            ->assertSee('value=" "', false)
            ->assertSee("modalLabelSelect.dataset.cashbookParentMode === 'СТО'", false)
            ->assertSee('option.dataset.cashbookParentLabel === parentLabel', false)
            ->assertSee('`${titles[mode] ?? titles.income} ${parentLabel}`', false);
    }

    public function test_sto_label_filter_includes_child_label_transactions(): void
    {
        $sto = CashbookLabel::query()->where('name', 'СТО')->firstOrFail();
        CashbookLabel::query()->updateOrCreate([
            'name' => 'Аренда',
        ], [
            'operation_type' => 'expense',
            'parent_id' => $sto->id,
        ]);
        CashbookLabel::query()->updateOrCreate([
            'name' => 'Донор',
        ], [
            'operation_type' => 'expense',
        ]);

        $stoTransaction = CashTransaction::query()->create([
            'operation_date' => '2026-05-01',
            'expense_cash_uah' => 500,
            'label' => 'Аренда',
            'comment' => 'Аренда СТО',
            'source' => 'manual',
        ]);
        $otherTransaction = CashTransaction::query()->create([
            'operation_date' => '2026-05-01',
            'expense_cash_uah' => 700,
            'label' => 'Донор',
            'comment' => 'Донор расход',
            'source' => 'manual',
        ]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.cashbook.index', [
                'from' => '2026-05-01',
                'to' => '2026-05-01',
                'label' => ['СТО'],
                'per_page' => '100',
            ]))
            ->assertOk()
            ->assertSee(route('admin.cashbook.show', $stoTransaction), false)
            ->assertDontSee(route('admin.cashbook.show', $otherTransaction), false)
            ->assertSee('СТО / Аренда')
            ->assertSee('Аренда СТО')
            ->assertDontSee('Донор расход');
    }

    public function test_exchange_cashbook_label_is_rendered_orange(): void
    {
        CashbookLabel::query()->create([
            'name' => 'Currency exchange',
            'operation_type' => 'exchange',
        ]);

        CashTransaction::query()->create([
            'operation_date' => '2026-05-01',
            'income_cash_uah' => 40000,
            'expense_cash_usd' => 1000,
            'label' => 'Currency exchange',
            'comment' => 'Exchange',
            'source' => 'manual',
        ]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.cashbook.index'))
            ->assertOk()
            ->assertSee('tag-exchange', false)
            ->assertSee('Курс: 40,00')
            ->assertDontSee('data-cashbook-label-select', false);
    }

    public function test_cancelled_valera_incasso_label_is_hidden_from_exchange_create_controls(): void
    {
        CashbookLabel::query()->create([
            'name' => 'Отменена инкассация Валера',
            'operation_type' => 'exchange',
        ]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.cashbook.index'))
            ->assertOk()
            ->assertDontSee('data-cashbook-label="Отменена инкассация Валера"', false)
            ->assertDontSee('<option value="Отменена инкассация Валера"', false);
    }

    public function test_donor_expense_vin_select_shows_car_details_sorted_by_purchase_date(): void
    {
        $oldDonor = DonorCar::query()->create([
            'vin' => '5YJSA1E41MF424298',
            'model' => 'Model S',
            'year' => 2021,
            'purchase_date' => '2026-04-10',
        ]);
        $recentDonor = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7PF123456',
            'model' => 'Model Y 01.2020 - 01.2025',
            'year' => 2023,
            'purchase_date' => '2026-04-30',
        ]);
        $undatedDonor = DonorCar::query()->create([
            'vin' => '5YJYGDEE1MF123456',
            'model' => 'Model Y',
            'year' => 2021,
        ]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.cashbook.index'))
            ->assertOk()
            ->assertSee($recentDonor->vin.' - Model Y - 30.04.2026')
            ->assertDontSee($recentDonor->vin.' - Model Y 01.2020 - 01.2025', false)
            ->assertSee($oldDonor->vin.' - Model S - 10.04.2026')
            ->assertSee($undatedDonor->vin.' - Model Y')
            ->assertSeeInOrder([$recentDonor->vin, $oldDonor->vin, $undatedDonor->vin]);
    }

    public function test_employee_filter_includes_repair_payments_by_work_order_employee(): void
    {
        CashbookLabel::query()->create([
            'name' => '+',
            'operation_type' => 'income',
        ]);

        $selectedEmployee = StoEmployee::query()->create([
            'cash_employee_name' => 'Обманщиков Евгений',
            'last_name' => 'Обманщиков',
            'first_name' => 'Евгений',
            'is_active' => true,
        ]);
        $otherEmployee = StoEmployee::query()->create([
            'cash_employee_name' => 'Другой Механик',
            'last_name' => 'Другой',
            'is_active' => true,
        ]);

        $selectedOrder = StoWorkOrder::query()->create([
            'number' => 'ЗН-20260430-0005',
            'status' => StoWorkOrder::STATUS_PAID,
            'client_name' => 'Клиент',
            'opened_at' => '2026-04-30',
        ]);
        $selectedOrder->works()->create([
            'sto_employee_id' => $selectedEmployee->id,
            'name' => 'Ремонт',
            'price_uah' => 1000,
        ]);

        $otherOrder = StoWorkOrder::query()->create([
            'number' => 'ЗН-20260430-0003',
            'status' => StoWorkOrder::STATUS_PAID,
            'client_name' => 'Другой клиент',
            'opened_at' => '2026-04-30',
        ]);
        $otherOrder->works()->create([
            'sto_employee_id' => $otherEmployee->id,
            'name' => 'Ремонт',
            'price_uah' => 1000,
        ]);

        $matchingTransaction = CashTransaction::query()->create([
            'operation_date' => '2026-04-30',
            'income_cash_uah' => 1000,
            'label' => '+',
            'comment' => 'Оплата заказ-наряда ЗН-20260430-0005',
            'source' => 'manual',
        ]);
        $otherTransaction = CashTransaction::query()->create([
            'operation_date' => '2026-04-30',
            'income_cash_uah' => 1000,
            'label' => '+',
            'comment' => 'Оплата заказ-наряда ЗН-20260430-0003',
            'source' => 'manual',
        ]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.cashbook.index', [
                'from' => '2026-04-01',
                'to' => '2026-05-10',
                'label' => ['+'],
                'employee' => 'Обманщиков Евгений',
                'per_page' => '100',
            ]))
            ->assertOk()
            ->assertSee(route('admin.cashbook.show', $matchingTransaction), false)
            ->assertDontSee(route('admin.cashbook.show', $otherTransaction), false)
            ->assertSee('Обманщиков Евгений');
    }

    public function test_donor_expense_vin_select_hides_donors_with_all_expenses_filled(): void
    {
        $availableDonor = DonorCar::query()->create([
            'vin' => '5YJSA1E41MF424298',
            'model' => 'РњРѕРґРµР»СЊ S',
            'year' => 2021,
            'estimated_cost_usd' => 10000,
        ]);
        $filledDonor = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7PF123456',
            'model' => 'Model 3',
            'year' => 2023,
            'estimated_cost_usd' => 10000,
            'usa_delivery_price_usd' => 900,
            'klaipeda_ukraine_delivery_price_usd' => 1200,
            'customs_clearance_price_usd' => 2500,
        ]);
        $filledOldDonorWithoutCustoms = DonorCar::query()->create([
            'vin' => '5YJYGDEE5MF081658',
            'model' => 'Model Y',
            'year' => 2021,
            'purchase_date' => '2024-12-23',
            'estimated_cost_usd' => 10000,
            'usa_delivery_price_usd' => 900,
            'klaipeda_ukraine_delivery_price_usd' => 1200,
        ]);
        $newDonorWithoutCustoms = DonorCar::query()->create([
            'vin' => '5YJ3E1EB7RF111111',
            'model' => 'Model 3 Highland',
            'year' => 2024,
            'purchase_date' => '2026-05-22',
            'estimated_cost_usd' => 10000,
            'usa_delivery_price_usd' => 900,
            'klaipeda_ukraine_delivery_price_usd' => 1200,
        ]);
        $leftoverDonor = DonorCar::query()->create([
            'vin' => 'TESLA Рњ3 2018 - 2023 Р·Р°Р»РёС€РєРё',
            'model' => 'Model 3',
            'year' => 2017,
        ]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.cashbook.index'))
            ->assertOk()
            ->assertSee($availableDonor->vin)
            ->assertSee('Модель S')
            ->assertDontSee('РњРѕРґРµР»СЊ S')
            ->assertSee('data-donor-filled-expense-types', false)
            ->assertSee('purchase_with_fees', false)
            ->assertSee($newDonorWithoutCustoms->vin)
            ->assertDontSee($filledDonor->vin)
            ->assertDontSee($filledOldDonorWithoutCustoms->vin)
            ->assertDontSee($leftoverDonor->vin);
    }

    public function test_donor_expense_updates_selected_donor_finance_field(): void
    {
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJSA1E41MF424298',
            'model' => 'Model S',
            'year' => 2021,
        ]);

        $this->actingAs($this->adminUser())
            ->post(route('admin.cashbook.store'), [
                'operation_date' => '2026-05-01',
                'transaction_type' => 'expense',
                'label' => 'Донор',
                'vehicle_vin' => $donorCar->vin,
                'donor_expense_type' => 'customs_clearance',
                'expense_cash_usd' => 1250,
                'expense_uah' => 0,
                'expense_payment_method' => 'cash',
                'comment' => ' ',
                'source' => 'manual',
                'exchange_rate' => 43,
            ])
            ->assertRedirect(route('admin.cashbook.index'));

        $this->assertDatabaseHas('donor_cars', [
            'id' => $donorCar->id,
            'customs_clearance_price_usd' => 1250,
        ]);

        $this->assertSame(
            DonorCar::DONOR_EXPENSE_SOURCE_CASHBOOK,
            $donorCar->refresh()->donor_expense_sources['customs_clearance_price_usd'] ?? null,
        );
    }

    public function test_deleting_donor_expense_removes_synced_donor_finance_field(): void
    {
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJSA1E41MF424298',
            'model' => 'Model S',
            'year' => 2021,
            'customs_clearance_price_usd' => 1250,
            'donor_expense_sources' => [
                'customs_clearance_price_usd' => DonorCar::DONOR_EXPENSE_SOURCE_CASHBOOK,
            ],
        ]);

        $transaction = CashTransaction::query()->create([
            'operation_date' => '2026-05-01',
            'expense_cash_usd' => 1250,
            'label' => 'Донор',
            'vehicle_vin' => $donorCar->vin,
            'comment' => 'Customs donor expense',
            'source' => 'manual',
            'exchange_rate' => 43,
            'created_at' => now(),
        ]);

        $this->actingAs($this->adminUser())
            ->delete(route('admin.cashbook.destroy', $transaction))
            ->assertRedirect(route('admin.cashbook.index'));

        $this->assertDatabaseMissing('cash_transactions', [
            'id' => $transaction->id,
        ]);

        $donorCar->refresh();

        $this->assertNull($donorCar->customs_clearance_price_usd);
        $this->assertNull($donorCar->donor_expense_sources);
    }

    public function test_donor_expense_in_uah_is_saved_to_donor_car_in_usd(): void
    {
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJSA1E41MF424298',
            'model' => 'Model S',
            'year' => 2021,
        ]);

        $this->actingAs($this->adminUser())
            ->post(route('admin.cashbook.store'), [
                'operation_date' => '2026-05-01',
                'transaction_type' => 'expense',
                'label' => 'Донор',
                'vehicle_vin' => $donorCar->vin,
                'donor_expense_type' => 'usa_delivery',
                'expense_uah' => 43000,
                'expense_payment_method' => 'cash',
                'expense_cash_usd' => 0,
                'comment' => 'Доставка донора',
                'source' => 'manual',
                'exchange_rate' => 43,
            ])
            ->assertRedirect(route('admin.cashbook.index'));

        $this->assertDatabaseHas('donor_cars', [
            'id' => $donorCar->id,
            'usa_delivery_price_usd' => 1000,
        ]);
    }

    public function test_donor_expense_cannot_update_already_filled_finance_field(): void
    {
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJSA1E41MF424298',
            'model' => 'Model S',
            'year' => 2021,
            'usa_delivery_price_usd' => 900,
        ]);

        $this->actingAs($this->adminUser())
            ->from(route('admin.cashbook.index'))
            ->post(route('admin.cashbook.store'), [
                'operation_date' => '2026-05-01',
                'transaction_type' => 'expense',
                'label' => 'Донор',
                'vehicle_vin' => $donorCar->vin,
                'donor_expense_type' => 'usa_delivery',
                'expense_cash_usd' => 1250,
                'expense_uah' => 0,
                'expense_payment_method' => 'cash',
                'comment' => 'Доставка донора',
                'source' => 'manual',
                'exchange_rate' => 43,
            ])
            ->assertRedirect(route('admin.cashbook.index'))
            ->assertSessionHasErrors('donor_expense_type');

        $this->assertDatabaseHas('donor_cars', [
            'id' => $donorCar->id,
            'usa_delivery_price_usd' => 900,
        ]);
    }

    public function test_donor_expense_type_dropdown_is_rendered(): void
    {
        CashbookLabel::query()->updateOrCreate(
            ['name' => 'Донор'],
            ['operation_type' => 'expense'],
        );

        $this->actingAs($this->adminUser())
            ->get(route('admin.cashbook.index'))
            ->assertOk()
            ->assertSee('name="donor_expense_type"', false)
            ->assertSee('for="modal_expense_uah">ГРН</label>', false)
            ->assertSee('Цена покупки(со сборами)')
            ->assertSee('Растаможка')
            ->assertDontSee('Цена покупки(со сборами) ($)')
            ->assertDontSee(' ($)');
    }

    public function test_cashbook_edit_form_does_not_allow_changing_label(): void
    {
        $transaction = CashTransaction::query()->create([
            'operation_date' => '2026-05-01',
            'expense_cash_uah' => 500,
            'label' => 'СТО',
            'comment' => '',
            'source' => 'manual',
        ]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.cashbook.edit', $transaction))
            ->assertOk()
            ->assertSee('value="СТО" disabled', false)
            ->assertSee('type="hidden" name="label" value="СТО"', false)
            ->assertDontSee('<select id="label" name="label"', false);
    }

    public function test_cashbook_update_keeps_existing_label_even_if_request_changes_it(): void
    {
        $transaction = CashTransaction::query()->create([
            'operation_date' => '2026-05-01',
            'expense_cash_uah' => 500,
            'label' => 'СТО',
            'comment' => '',
            'source' => 'manual',
        ]);

        $this->actingAs($this->adminUser())
            ->put(route('admin.cashbook.update', $transaction), [
                'operation_date' => '2026-05-02',
                'expense_uah' => 700,
                'expense_payment_method' => 'cash',
                'expense_cash_usd' => 0,
                'label' => 'Донор',
                'comment' => 'Обновленный расход',
                'source' => 'manual',
            ])
            ->assertRedirect(route('admin.cashbook.index'));

        $this->assertDatabaseHas('cash_transactions', [
            'id' => $transaction->id,
            'operation_date' => '2026-05-02 00:00:00',
            'expense_cash_uah' => 700,
            'label' => 'СТО',
            'comment' => 'Обновленный расход',
        ]);
    }

    public function test_old_cashbook_transaction_cannot_be_edited(): void
    {
        $this->travelTo('2026-05-01 12:00:00');

        $transaction = CashTransaction::query()->create([
            'operation_date' => '2026-05-01',
            'label' => 'Old operation',
            'expense_cash_uah' => 100,
            'comment' => 'Original comment',
            'source' => 'manual',
        ]);

        $transaction->forceFill([
            'created_at' => now()->subDay()->subSecond(),
        ])->saveQuietly();

        $user = $this->adminUser();

        $this->actingAs($user)
            ->get(route('admin.cashbook.index'))
            ->assertOk()
            ->assertSee(route('admin.cashbook.show', $transaction), false)
            ->assertDontSee(route('admin.cashbook.edit', $transaction), false);

        $this->actingAs($user)
            ->get(route('admin.cashbook.show', $transaction))
            ->assertOk()
            ->assertDontSee(route('admin.cashbook.edit', $transaction), false);

        $this->actingAs($user)
            ->get(route('admin.cashbook.edit', $transaction))
            ->assertRedirect(route('admin.cashbook.index'))
            ->assertSessionHas('status', 'Операцию старше 1 суток нельзя редактировать.');

        $this->actingAs($user)
            ->put(route('admin.cashbook.update', $transaction), [
                'operation_date' => '2026-05-01',
                'transaction_type' => 'expense',
                'label' => 'Old operation',
                'expense_uah' => 200,
                'expense_payment_method' => 'cash',
                'expense_cash_usd' => 0,
                'comment' => 'Changed comment',
                'source' => 'manual',
            ])
            ->assertRedirect(route('admin.cashbook.index'))
            ->assertSessionHas('status', 'Операцию старше 1 суток нельзя редактировать.');

        $this->assertDatabaseHas('cash_transactions', [
            'id' => $transaction->id,
            'expense_cash_uah' => 100,
            'comment' => 'Original comment',
        ]);
    }

    public function test_cashbook_summary_splits_uah_by_cash_and_bank(): void
    {
        CashTransaction::query()->create([
            'operation_date' => '2026-05-01',
            'income_cash_uah' => 1000,
            'income_bank_uah' => 2500,
            'expense_cash_uah' => 300,
            'expense_bank_uah' => 700,
            'label' => 'Summary split',
            'comment' => 'Summary split',
            'source' => 'manual',
        ]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.cashbook.index', [
                'from' => '2026-05-01',
                'to' => '2026-05-01',
            ]))
            ->assertOk()
            ->assertSee('Нал: 1 000,00 грн')
            ->assertSee('Безнал: 2 500,00 грн')
            ->assertSee('Нал: 300,00 грн')
            ->assertSee('Безнал: 700,00 грн')
            ->assertSee('Нал: 700,00 грн')
            ->assertSee('Безнал: 1 800,00 грн');
    }

    public function test_cashbook_operation_rows_show_uah_payment_method(): void
    {
        CashTransaction::query()->create([
            'operation_date' => '2026-05-01',
            'income_bank_uah' => 1500,
            'label' => 'Bank income',
            'comment' => 'Bank income',
            'source' => 'manual',
        ]);

        CashTransaction::query()->create([
            'operation_date' => '2026-05-01',
            'expense_cash_uah' => 400,
            'label' => 'Cash expense',
            'comment' => 'Cash expense',
            'source' => 'manual',
        ]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.cashbook.index', [
                'from' => '2026-05-01',
                'to' => '2026-05-01',
            ]))
            ->assertOk()
            ->assertSeeInOrder(['1 500,00 грн', 'cashbook-payment-method', 'Безнал'], false)
            ->assertSeeInOrder(['400,00 грн', 'cashbook-payment-method', 'Нал'], false);
    }

    private function adminUser(): User
    {
        return User::query()->create([
            'name' => 'Admin',
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
    }
}
