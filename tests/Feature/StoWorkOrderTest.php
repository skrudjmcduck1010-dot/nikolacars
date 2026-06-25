<?php

namespace Tests\Feature;

use App\Models\CashbookLabel;
use App\Models\CashTransaction;
use App\Models\Counterparty;
use App\Models\DonorCar;
use App\Models\Location;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockItem;
use App\Models\StoEmployee;
use App\Models\StoWorkOrder;
use App\Models\StoWorkOrderWork;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StoWorkOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeNbuUsdRate(43);
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    public function test_work_orders_index_defaults_to_orders_outside_archive(): void
    {
        $user = $this->adminUser();

        StoWorkOrder::query()->create([
            'number' => 'ЗН-20260430-0001',
            'status' => 'in_work',
            'client_name' => 'Рабочий клиент',
            'opened_at' => '2026-04-30',
        ]);

        StoWorkOrder::query()->create([
            'number' => 'ЗН-20260430-0002',
            'status' => 'completed',
            'client_name' => 'Завершенный клиент',
            'opened_at' => '2026-04-29',
        ]);

        StoWorkOrder::query()->create([
            'number' => 'ЗН-20260430-0003',
            'status' => 'cancelled',
            'client_name' => 'Отмененный клиент',
            'opened_at' => '2026-04-28',
        ]);

        StoWorkOrder::query()->create([
            'number' => 'ЗН-20260430-0005',
            'status' => 'archived',
            'client_name' => 'Архивный клиент',
            'opened_at' => '2026-04-26',
        ]);

        $this->actingAs($user)
            ->get(route('admin.sto-work-orders.index'))
            ->assertOk()
            ->assertSee('Рабочий клиент')
            ->assertSee('Завершенный клиент')
            ->assertSee('Отмененный клиент')
            ->assertDontSee('Архивный клиент');
    }

    public function test_work_orders_index_shows_work_started_and_completed_dates(): void
    {
        $user = $this->adminUser();

        StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0001',
            'status' => StoWorkOrder::STATUS_COMPLETED,
            'client_name' => 'Service Client',
            'opened_at' => '2026-04-30',
            'work_started_at' => '2026-04-28 09:15:00',
            'completed_at' => '2026-04-29 17:45:00',
        ]);

        $response = $this->actingAs($user)
            ->get(route('admin.sto-work-orders.index'))
            ->assertOk();

        $response->assertSee('Начало работ: 28.04.2026 09:15')
            ->assertSee('Завершен: 29.04.2026 17:45')
            ->assertDontSee('Запись: 30.04.2026');
    }

    public function test_work_orders_index_shows_appointment_date_only_for_appointment_status(): void
    {
        $user = $this->adminUser();

        StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0001',
            'status' => StoWorkOrder::STATUS_APPOINTMENT,
            'client_name' => 'Appointment Client',
            'opened_at' => '2026-05-02',
            'appointment_time' => '11:30',
        ]);

        $response = $this->actingAs($user)
            ->get(route('admin.sto-work-orders.index'))
            ->assertOk()
            ->assertSee('Запись: 02.05.2026');

        $ordersTableHtml = str($response->getContent())->after('<!-- sto-calendar-end -->')->toString();

        $this->assertStringNotContainsString('11:30', $ordersTableHtml);
    }

    public function test_work_orders_index_links_client_to_counterparty_page(): void
    {
        $user = $this->adminUser();
        $client = Counterparty::query()->create([
            'type' => 'customer',
            'name' => 'Linked Client',
            'phone' => '+380991112233',
            'is_active' => true,
        ]);

        StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0001',
            'status' => 'in_work',
            'counterparty_id' => $client->id,
            'client_name' => 'Linked Client',
            'opened_at' => '2026-04-30',
        ]);

        $this->actingAs($user)
            ->get(route('admin.sto-work-orders.index'))
            ->assertOk()
            ->assertSee('href="'.route('admin.counterparties.show', $client).'"', false);
    }

    public function test_work_orders_index_defaults_to_in_work_then_nearest_appointments(): void
    {
        $user = $this->adminUser();

        StoWorkOrder::query()->create([
            'number' => 'ЗН-20260430-0001',
            'status' => 'appointment',
            'client_name' => 'Дальняя запись',
            'opened_at' => '2026-05-10',
            'appointment_time' => '11:00',
        ]);

        StoWorkOrder::query()->create([
            'number' => 'ЗН-20260430-0002',
            'status' => 'in_work',
            'client_name' => 'Клиент в работе',
            'opened_at' => '2026-04-30',
        ]);

        StoWorkOrder::query()->create([
            'number' => 'ЗН-20260430-0003',
            'status' => 'appointment',
            'client_name' => 'Ближняя запись',
            'opened_at' => '2026-05-02',
            'appointment_time' => '09:30',
        ]);

        StoWorkOrder::query()->create([
            'number' => 'ЗН-20260430-0004',
            'status' => 'completed',
            'client_name' => 'Завершенный клиент',
            'opened_at' => '2026-05-01',
        ]);

        $this->actingAs($user)
            ->get(route('admin.sto-work-orders.index'))
            ->assertOk()
            ->assertSeeInOrder(['Клиент в работе', 'Ближняя запись', 'Дальняя запись'])
            ->assertSee('Завершенный клиент');
    }

    public function test_work_orders_index_shows_current_week_appointment_calendar(): void
    {
        $user = $this->adminUser();

        $this->travelTo('2026-04-30 12:00:00');

        StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0001',
            'status' => 'appointment',
            'client_name' => 'This week appointment',
            'car_model' => 'Model 3',
            'opened_at' => '2026-04-30',
            'appointment_time' => '09:30',
        ]);

        StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0002',
            'status' => 'appointment',
            'client_name' => 'No time appointment',
            'license_plate' => 'AA1234AA',
            'opened_at' => '2026-05-02',
        ]);

        StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0003',
            'status' => 'completed',
            'client_name' => 'Completed appointment',
            'opened_at' => '2026-04-30',
            'appointment_time' => '10:00',
        ]);

        $response = $this->actingAs($user)
            ->get(route('admin.sto-work-orders.index'))
            ->assertOk()
            ->assertSee('Календарь записи на СТО')
            ->assertSee('27.04.2026')
            ->assertSee('03.05.2026')
            ->assertSee('This week appointment')
            ->assertSee('09:30')
            ->assertSee('No time appointment')
            ->assertSee('Без времени');

        $calendarHtml = str($response->getContent())
            ->after('<div class="sto-week-grid">')
            ->before('<div class="panel">')
            ->toString();

        $this->assertStringNotContainsString('Completed appointment', $calendarHtml);
    }

    public function test_calendar_only_shows_work_orders_with_appointment_status(): void
    {
        $user = $this->adminUser();

        $this->travelTo('2026-04-30 12:00:00');

        StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0001',
            'status' => 'appointment',
            'client_name' => 'Calendar appointment client',
            'opened_at' => '2026-04-30',
            'appointment_time' => '09:30',
        ]);

        StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0002',
            'status' => 'in_work',
            'client_name' => 'In work client',
            'opened_at' => '2026-04-30',
        ]);

        $response = $this->actingAs($user)
            ->get(route('admin.sto-work-orders.index'))
            ->assertOk()
            ->assertSee('In work client');

        $calendarHtml = str($response->getContent())
            ->after('<div class="sto-week-grid">')
            ->before('<div class="panel">')
            ->toString();

        $this->assertStringContainsString('Calendar appointment client', $calendarHtml);
        $this->assertStringNotContainsString('In work client', $calendarHtml);
    }

    public function test_work_orders_index_can_show_selected_calendar_week(): void
    {
        $user = $this->adminUser();

        $this->travelTo('2026-04-30 12:00:00');

        StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0001',
            'status' => 'appointment',
            'client_name' => 'Current week appointment',
            'opened_at' => '2026-04-30',
            'appointment_time' => '09:30',
        ]);

        StoWorkOrder::query()->create([
            'number' => 'ZN-20260504-0001',
            'status' => 'appointment',
            'client_name' => 'Next week appointment',
            'opened_at' => '2026-05-04',
            'appointment_time' => '11:00',
        ]);

        $response = $this->actingAs($user)
            ->get(route('admin.sto-work-orders.index', ['week_start' => '2026-05-04']))
            ->assertOk()
            ->assertSee('04.05.2026')
            ->assertSee('10.05.2026')
            ->assertSee('Next week appointment')
            ->assertSee('11:00')
            ->assertSee('week_start=2026-04-27', false)
            ->assertSee('week_start=2026-05-11', false);

        $calendarHtml = str($response->getContent())
            ->after('<div class="sto-week-grid">')
            ->before('<div class="panel">')
            ->toString();

        $this->assertStringNotContainsString('Current week appointment', $calendarHtml);
    }

    public function test_calendar_day_number_links_to_create_appointment_for_that_date(): void
    {
        $user = $this->adminUser();

        $this->travelTo(Carbon::parse('2026-04-30 12:00:00', 'Europe/Kyiv'));

        $this->actingAs($user)
            ->get(route('admin.sto-work-orders.index', ['week_start' => '2026-05-04']))
            ->assertOk()
            ->assertSee('Добавить запись')
            ->assertSee('href="'.route('admin.sto-work-orders.create', ['opened_at' => '2026-05-06']).'"', false);
    }

    public function test_calendar_does_not_link_past_days_to_create_appointment(): void
    {
        $user = $this->adminUser();

        $this->travelTo(Carbon::parse('2026-04-30 12:00:00', 'Europe/Kyiv'));

        $this->actingAs($user)
            ->get(route('admin.sto-work-orders.index', ['week_start' => '2026-04-20']))
            ->assertOk()
            ->assertDontSee(route('admin.sto-work-orders.create', ['opened_at' => '2026-04-22']), false)
            ->assertDontSee('Добавить запись');
    }

    public function test_calendar_does_not_allow_creating_today_appointment_after_working_hours(): void
    {
        $user = $this->adminUser();

        $this->travelTo(Carbon::parse('2026-04-30 19:01:00', 'Europe/Kyiv'));

        $response = $this->actingAs($user)
            ->get(route('admin.sto-work-orders.index', ['week_start' => '2026-04-27']))
            ->assertOk();

        $response->assertDontSee(route('admin.sto-work-orders.create', ['opened_at' => '2026-04-30']), false);
        $response->assertSee(route('admin.sto-work-orders.create', ['opened_at' => '2026-05-01']), false);
    }

    public function test_work_orders_index_filters_by_multiple_status_checkboxes(): void
    {
        $user = $this->adminUser();

        StoWorkOrder::query()->create([
            'number' => 'ЗН-20260430-0001',
            'status' => 'appointment',
            'client_name' => 'Клиент запись',
            'opened_at' => '2026-05-02',
        ]);

        StoWorkOrder::query()->create([
            'number' => 'ЗН-20260430-0002',
            'status' => 'completed',
            'client_name' => 'Клиент завершен',
            'opened_at' => '2026-05-01',
        ]);

        StoWorkOrder::query()->create([
            'number' => 'ЗН-20260430-0003',
            'status' => 'in_work',
            'client_name' => 'Клиент в работе',
            'opened_at' => '2026-04-30',
        ]);

        $this->actingAs($user)
            ->get(route('admin.sto-work-orders.index', ['statuses' => ['appointment', 'completed']]))
            ->assertOk()
            ->assertSee('Клиент запись')
            ->assertSee('Клиент завершен')
            ->assertDontSee('Клиент в работе');
    }

    public function test_work_orders_index_shows_payment_button_for_completed_orders_without_open_button(): void
    {
        $user = $this->adminUser();

        $order = StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0001',
            'status' => 'completed',
            'client_name' => 'Completed Client',
            'opened_at' => '2026-04-30',
            'completed_at' => '2026-04-30',
            'total_cost_uah' => 1500,
        ]);

        $this->actingAs($user)
            ->get(route('admin.sto-work-orders.index'))
            ->assertOk()
            ->assertSee('ZN-20260430-0001')
            ->assertSee(route('admin.sto-work-orders.show', $order), false)
            ->assertSee('Подтвердить оплату')
            ->assertSee(route('admin.sto-work-orders.payment.confirm', $order), false)
            ->assertDontSee('Открыть');
    }

    public function test_work_orders_index_shows_print_icon_for_completed_and_paid_orders(): void
    {
        $user = $this->adminUser();
        $completedOrder = StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0001',
            'status' => 'completed',
            'client_name' => 'Completed Client',
            'opened_at' => '2026-04-30',
            'completed_at' => '2026-04-30',
        ]);
        $paidOrder = StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0002',
            'status' => 'paid',
            'client_name' => 'Paid Client',
            'opened_at' => '2026-04-30',
            'completed_at' => '2026-04-30',
            'paid_amount_uah' => 1000,
        ]);
        $inWorkOrder = StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0003',
            'status' => 'in_work',
            'client_name' => 'Active Client',
            'opened_at' => '2026-04-30',
        ]);

        $response = $this->actingAs($user)
            ->get(route('admin.sto-work-orders.index'))
            ->assertOk()
            ->assertSee(route('admin.sto-work-orders.print', $completedOrder), false)
            ->assertSee(route('admin.sto-work-orders.print', $paidOrder), false)
            ->assertSee('Печать заказ-наряда ZN-20260430-0001', false)
            ->assertSee('Печать заказ-наряда ZN-20260430-0002', false);

        $this->assertStringNotContainsString(
            route('admin.sto-work-orders.print', $inWorkOrder),
            $response->getContent()
        );
    }

    public function test_work_orders_index_shows_orders_when_all_status_checkboxes_are_selected(): void
    {
        $user = $this->adminUser();

        StoWorkOrder::query()->create([
            'number' => 'ЗН-20260430-0001',
            'status' => 'appointment',
            'client_name' => 'Клиент запись',
            'opened_at' => '2026-05-02',
        ]);

        StoWorkOrder::query()->create([
            'number' => 'ЗН-20260430-0002',
            'status' => 'completed',
            'client_name' => 'Клиент завершен',
            'opened_at' => '2026-05-01',
        ]);

        $this->actingAs($user)
            ->get(route('admin.sto-work-orders.index', ['statuses' => [...StoWorkOrder::STATUSES, 'all']]))
            ->assertOk()
            ->assertSee('Клиент запись')
            ->assertSee('Клиент завершен');
    }

    public function test_admin_can_create_work_order(): void
    {
        $user = $this->adminUser();

        $response = $this->actingAs($user)->post(route('admin.sto-work-orders.store'), [
            'status' => 'in_work',
            'client_name' => 'Иван Петров',
            'client_phone' => '+380991112233',
            'opened_at' => '2026-04-30',
            'customer_request' => 'Диагностика подвески',
            'labor_cost_uah' => '1200',
            'parts_cost_uah' => '800',
            'discount_uah' => '100',
        ]);

        $order = StoWorkOrder::query()->firstOrFail();

        $response->assertRedirect(route('admin.sto-work-orders.show', $order));

        $this->assertDatabaseHas('sto_work_orders', [
            'client_name' => 'Иван Петров',
            'status' => 'in_work',
            'total_cost_uah' => 1900,
        ]);
    }

    public function test_create_work_order_prefills_opened_at_from_calendar_date(): void
    {
        $user = $this->adminUser();

        $this->travelTo(Carbon::parse('2026-04-30 12:00:00', 'Europe/Kyiv'));

        $this->actingAs($user)
            ->get(route('admin.sto-work-orders.create', ['opened_at' => '2026-05-06']))
            ->assertOk()
            ->assertSee('name="opened_at" value="2026-05-06"', false)
            ->assertSee('name="status" value="appointment"', false)
            ->assertSee('name="calendar_appointment" value="1"', false)
            ->assertDontSee('<select name="status" data-order-status>', false);
    }

    public function test_create_work_order_uses_working_hours_select_for_appointment_time(): void
    {
        $user = $this->adminUser();

        $this->actingAs($user)
            ->get(route('admin.sto-work-orders.create'))
            ->assertOk()
            ->assertSee('name="appointment_time"', false)
            ->assertSee('data-appointment-time', false)
            ->assertSee('<option value="09:00"', false)
            ->assertSee('<option value="19:00"', false)
            ->assertDontSee('type="time" name="appointment_time"', false)
            ->assertDontSee('<option value="08:45"', false)
            ->assertDontSee('<option value="19:15"', false);
    }

    public function test_calendar_appointment_cannot_be_created_with_non_appointment_status(): void
    {
        $user = $this->adminUser();

        $this->travelTo(Carbon::parse('2026-04-30 12:00:00', 'Europe/Kyiv'));

        $this->actingAs($user)
            ->from(route('admin.sto-work-orders.create', ['opened_at' => '2026-05-06']))
            ->post(route('admin.sto-work-orders.store'), [
                'calendar_appointment' => '1',
                'status' => 'in_work',
                'client_name' => 'Calendar client',
                'opened_at' => '2026-05-06',
            ])
            ->assertSessionHasErrors('status');

        $this->assertDatabaseCount('sto_work_orders', 0);
    }

    public function test_appointment_cannot_be_created_in_the_past_by_kyiv_date(): void
    {
        $user = $this->adminUser();

        $this->travelTo(Carbon::parse('2026-05-01 12:00:00', 'Europe/Kyiv'));

        $this->actingAs($user)
            ->from(route('admin.sto-work-orders.create'))
            ->post(route('admin.sto-work-orders.store'), [
                'status' => 'appointment',
                'client_name' => 'Past appointment',
                'opened_at' => '2026-04-30',
                'appointment_time' => '10:00',
            ])
            ->assertSessionHasErrors('opened_at');

        $this->assertDatabaseCount('sto_work_orders', 0);
    }

    public function test_today_appointment_cannot_be_created_after_working_hours(): void
    {
        $user = $this->adminUser();

        $this->travelTo(Carbon::parse('2026-04-30 19:01:00', 'Europe/Kyiv'));

        $this->actingAs($user)
            ->from(route('admin.sto-work-orders.create', ['opened_at' => '2026-04-30']))
            ->post(route('admin.sto-work-orders.store'), [
                'status' => 'appointment',
                'client_name' => 'Late today appointment',
                'opened_at' => '2026-04-30',
                'appointment_time' => '19:00',
            ])
            ->assertSessionHasErrors('opened_at');

        $this->assertDatabaseCount('sto_work_orders', 0);
    }

    public function test_today_appointment_cannot_be_created_with_past_time(): void
    {
        $user = $this->adminUser();

        $this->travelTo(Carbon::parse('2026-04-30 12:10:00', 'Europe/Kyiv'));

        $this->actingAs($user)
            ->from(route('admin.sto-work-orders.create', ['opened_at' => '2026-04-30']))
            ->post(route('admin.sto-work-orders.store'), [
                'status' => 'appointment',
                'client_name' => 'Past time appointment',
                'opened_at' => '2026-04-30',
                'appointment_time' => '12:00',
            ])
            ->assertSessionHasErrors('appointment_time');

        $this->assertDatabaseCount('sto_work_orders', 0);
    }

    public function test_appointment_time_must_be_within_sto_working_hours(): void
    {
        $user = $this->adminUser();

        $this->travelTo(Carbon::parse('2026-04-30 12:00:00', 'Europe/Kyiv'));

        foreach (['08:59', '19:01', null] as $appointmentTime) {
            $payload = [
                'status' => 'appointment',
                'client_name' => 'Outside hours appointment',
                'opened_at' => '2026-05-02',
                'appointment_time' => $appointmentTime,
            ];

            if ($appointmentTime === null) {
                unset($payload['appointment_time']);
            }

            $this->actingAs($user)
                ->from(route('admin.sto-work-orders.create'))
                ->post(route('admin.sto-work-orders.store'), $payload)
                ->assertSessionHasErrors('appointment_time');
        }

        $this->assertDatabaseCount('sto_work_orders', 0);
    }

    public function test_admin_can_create_appointment_without_vehicle_details(): void
    {
        $user = $this->adminUser();

        $this->travelTo(Carbon::parse('2026-04-30 12:00:00', 'Europe/Kyiv'));

        $client = Counterparty::query()->create([
            'type' => 'customer',
            'name' => 'Анна Клиент',
            'phone' => '+380991110000',
            'car_model' => 'Model 3',
            'car_year' => 2021,
            'drive_type' => 'all',
            'vin' => '5YJ3E1EA7NF000001',
            'license_plate' => 'AA7777AA',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->post(route('admin.sto-work-orders.store'), [
            'status' => 'appointment',
            'counterparty_id' => $client->id,
            'opened_at' => '2026-05-02',
            'appointment_time' => '09:30',
            'customer_request' => 'Запис на диагностику',
        ]);

        $order = StoWorkOrder::query()->firstOrFail();

        $response->assertRedirect(route('admin.sto-work-orders.show', $order));

        $this->assertDatabaseHas('sto_work_orders', [
            'client_name' => 'Анна Клиент',
            'status' => 'appointment',
            'appointment_time' => '09:30',
            'drive_type' => null,
            'vin' => null,
            'mileage' => null,
        ]);
    }

    public function test_work_order_number_sequence_restarts_for_new_month(): void
    {
        $user = $this->adminUser();

        StoWorkOrder::query()->create([
            'number' => 'ЗН-20260430-0002',
            'status' => 'in_work',
            'client_name' => 'April client',
            'opened_at' => '2026-04-30',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-05-01 09:00:00'));

        try {
            $this->actingAs($user)->post(route('admin.sto-work-orders.store'), [
                'status' => 'in_work',
                'client_name' => 'May client',
                'opened_at' => '2026-05-01',
            ])->assertRedirect();
        } finally {
            Carbon::setTestNow();
        }

        $this->assertDatabaseHas('sto_work_orders', [
            'client_name' => 'May client',
            'number' => 'ЗН-20260501-0001',
        ]);
    }

    public function test_client_search_returns_matches_from_first_letter(): void
    {
        $user = $this->adminUser();

        Counterparty::query()->create([
            'type' => 'customer',
            'name' => 'Иван Петров',
            'phone' => '+380991112233',
            'vin' => '5YJSA1E26HF000001',
            'license_plate' => 'AA1234BC',
            'is_active' => true,
        ]);

        Counterparty::query()->create([
            'type' => 'supplier',
            'name' => 'Иван Поставщик',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->getJson(route('admin.sto-work-orders.clients.search', ['q' => 'И']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment([
                'name' => 'Иван Петров',
                'license_plate' => 'AA1234BC',
            ]);
    }

    public function test_client_search_matches_cyrillic_case_when_typing_more_letters(): void
    {
        $user = $this->adminUser();

        Counterparty::query()->create([
            'type' => 'customer',
            'name' => 'Валерий Оверчук',
            'phone' => '+380991112244',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->getJson(route('admin.sto-work-orders.clients.search', ['q' => 'ва']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment([
                'name' => 'Валерий Оверчук',
            ]);
    }

    public function test_part_search_returns_only_available_donor_or_purchase_parts(): void
    {
        $user = $this->adminUser();
        $donorProduct = $this->inventoryProduct(source: 'donor', availableQuantity: 2);
        $plainProduct = $this->inventoryProduct(source: 'plain', availableQuantity: 2);
        $emptyPurchaseProduct = $this->inventoryProduct(source: 'purchase', availableQuantity: 0);

        $this->actingAs($user)
            ->getJson(route('admin.sto-work-orders.parts.search', ['q' => 'Cab']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment([
                'id' => $donorProduct->id,
                'name' => $donorProduct->name,
                'source_label' => 'Донор',
                'available_stock' => 2,
            ])
            ->assertJsonMissing(['id' => $plainProduct->id])
            ->assertJsonMissing(['id' => $emptyPurchaseProduct->id]);
    }

    public function test_part_search_matches_cyrillic_case_from_first_letter(): void
    {
        $user = $this->adminUser();
        $product = $this->inventoryProduct(source: 'donor', availableQuantity: 1, name: 'Бампер передний');

        $this->actingAs($user)
            ->getJson(route('admin.sto-work-orders.parts.search', ['q' => 'б']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment([
                'id' => $product->id,
                'name' => 'Бампер передний',
            ]);
    }

    public function test_part_search_converts_usd_selling_price_to_uah_with_today_rate(): void
    {
        $user = $this->adminUser();
        $this->fakeNbuUsdRate(40.5);
        $product = $this->inventoryProduct(
            source: 'donor',
            availableQuantity: 1,
            name: 'Бампер передний',
            sellingPrice: 160,
            currency: 'USD',
        );

        $this->actingAs($user)
            ->getJson(route('admin.sto-work-orders.parts.search', ['q' => 'б']))
            ->assertOk()
            ->assertJsonFragment([
                'id' => $product->id,
                'unit_price_uah' => 6480,
                'currency' => 'USD',
            ])
            ->assertJsonPath('0.exchange_rate.rate', 40.5)
            ->assertJsonPath('0.exchange_rate.source', 'monobank');
    }

    public function test_work_search_matches_done_works_case_insensitively(): void
    {
        $user = $this->adminUser();

        $order = StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0001',
            'status' => 'completed',
            'client_name' => 'Client',
            'opened_at' => '2026-04-30',
        ]);

        StoWorkOrderWork::query()->create([
            'sto_work_order_id' => $order->id,
            'name' => 'Диагностика батареи',
            'price_uah' => 1200,
        ]);

        StoWorkOrderWork::query()->create([
            'sto_work_order_id' => $order->id,
            'name' => '-',
            'price_uah' => 900,
        ]);

        $this->actingAs($user)
            ->getJson(route('admin.sto-work-orders.works.search', ['q' => 'диаг']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment([
                'name' => 'Диагностика батареи',
                'price_uah' => 1200,
            ])
            ->assertJsonMissing([
                'name' => '-',
            ]);
    }

    public function test_selected_client_data_is_used_when_creating_work_order(): void
    {
        $user = $this->adminUser();
        $client = Counterparty::query()->create([
            'type' => 'customer',
            'name' => 'Иван Петров',
            'phone' => '+380991112233',
            'car_model' => 'Model S',
            'car_year' => 2020,
            'drive_type' => 'all',
            'vin' => '5YJSA1E26HF000001',
            'license_plate' => 'AA1234BC',
            'is_active' => true,
        ]);

        $this->actingAs($user)->post(route('admin.sto-work-orders.store'), [
            'status' => 'in_work',
            'counterparty_id' => $client->id,
            'opened_at' => '2026-04-30',
        ])->assertRedirect();

        $this->assertDatabaseHas('sto_work_orders', [
            'counterparty_id' => $client->id,
            'client_name' => 'Иван Петров',
            'client_phone' => '+380991112233',
            'car_model' => 'Model S',
            'car_year' => 2020,
            'drive_type' => 'all',
            'vin' => '5YJSA1E26HF000001',
            'license_plate' => 'AA1234BC',
        ]);
    }

    public function test_stale_client_id_does_not_block_manual_work_order_creation(): void
    {
        $user = $this->adminUser();

        $this->actingAs($user)->post(route('admin.sto-work-orders.store'), [
            'status' => 'in_work',
            'counterparty_id' => 999999,
            'client_name' => 'Новый клиент',
            'client_phone' => '+380991112233',
            'opened_at' => '2026-04-30',
            'car_model' => 'Model 3',
            'car_year' => 2022,
            'drive_type' => 'rear',
            'vin' => '5YJ3E1EA7NF000001',
            'license_plate' => 'BC4321AA',
        ])->assertRedirect();

        $this->assertDatabaseHas('sto_work_orders', [
            'counterparty_id' => null,
            'client_name' => 'Новый клиент',
            'car_model' => 'Model 3',
            'drive_type' => 'rear',
            'vin' => '5YJ3E1EA7NF000001',
            'license_plate' => 'BC4321AA',
        ]);
    }

    public function test_admin_can_cancel_work_order_instead_of_deleting_it(): void
    {
        $user = $this->adminUser();
        $order = StoWorkOrder::query()->create([
            'number' => 'ЗН-20260430-0001',
            'status' => 'in_work',
            'client_name' => 'Иван Петров',
            'opened_at' => '2026-04-30',
        ]);

        $this->actingAs($user)
            ->post(route('admin.sto-work-orders.status.update', $order), [
                'status' => 'cancelled',
            ])
            ->assertRedirect(route('admin.sto-work-orders.show', $order));

        $this->assertDatabaseHas('sto_work_orders', [
            'id' => $order->id,
            'status' => 'cancelled',
        ]);

        $this->actingAs($user)
            ->get(route('admin.sto-work-orders.show', $order))
            ->assertOk()
            ->assertSee('Отменен')
            ->assertDontSee('Удалить заказ-наряд')
            ->assertDontSee('Восстановить');
    }

    public function test_admin_can_add_parts_and_works_to_work_order(): void
    {
        $user = $this->adminUser();
        $product = $this->inventoryProduct(source: 'donor', availableQuantity: 2);
        $employee = StoEmployee::query()->create([
            'cash_employee_name' => 'Mechanic One',
            'last_name' => 'Mechanic One',
            'is_active' => true,
        ]);
        $order = StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0001',
            'status' => 'in_work',
            'client_name' => 'Service Client',
            'opened_at' => '2026-04-30',
            'discount_uah' => 100,
        ]);

        $this->actingAs($user)
            ->post(route('admin.sto-work-orders.parts.store', $order), [
                'product_id' => $product->id,
                'quantity' => '2',
                'unit_price_uah' => '350',
                'note' => 'OEM',
            ])
            ->assertRedirect(route('admin.sto-work-orders.show', $order));

        $this->actingAs($user)
            ->post(route('admin.sto-work-orders.works.store', $order), [
                'name' => 'Filter replacement',
                'sto_employee_id' => $employee->id,
                'price_uah' => '500',
                'note' => 'Front cabin',
            ])
            ->assertRedirect(route('admin.sto-work-orders.show', $order));

        $this->assertDatabaseHas('sto_work_order_parts', [
            'sto_work_order_id' => $order->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'total_price_uah' => 700,
        ]);

        $this->assertDatabaseHas('stock_items', [
            'product_id' => $product->id,
            'quantity' => 0,
            'available_quantity' => 0,
        ]);

        $this->assertDatabaseHas('sto_work_order_works', [
            'sto_work_order_id' => $order->id,
            'sto_employee_id' => $employee->id,
            'name' => 'Filter replacement',
            'price_uah' => 500,
        ]);

        $this->assertDatabaseHas('sto_work_orders', [
            'id' => $order->id,
            'parts_cost_uah' => 700,
            'labor_cost_uah' => 500,
            'total_cost_uah' => 1100,
        ]);
    }

    public function test_work_order_show_hides_add_line_item_forms_for_closed_orders(): void
    {
        $user = $this->adminUser();

        foreach (['completed', 'cancelled', 'paid', 'archived'] as $state) {
            $order = StoWorkOrder::query()->create([
                'number' => 'ZN-20260430-'.$state,
                'status' => $state,
                'client_name' => 'Service Client '.$state,
                'opened_at' => '2026-04-30',
            ]);

            $this->actingAs($user)
                ->get(route('admin.sto-work-orders.show', $order))
                ->assertOk()
                ->assertDontSee(route('admin.sto-work-orders.parts.store', $order), false)
                ->assertDontSee(route('admin.sto-work-orders.works.store', $order), false);
        }
    }

    public function test_work_order_show_hides_delete_line_item_buttons_for_paid_and_completed_orders(): void
    {
        $user = $this->adminUser();

        foreach (['completed', 'paid'] as $status) {
            $order = StoWorkOrder::query()->create([
                'number' => 'ZN-20260430-'.$status,
                'status' => $status,
                'client_name' => 'Service Client '.$status,
                'opened_at' => '2026-04-30',
            ]);
            $part = $order->parts()->create([
                'name' => 'Cabin filter',
                'quantity' => 1,
                'unit_price_uah' => 350,
                'total_price_uah' => 350,
            ]);
            $work = $order->works()->create([
                'name' => 'Filter replacement',
                'price_uah' => 500,
            ]);

            $this->actingAs($user)
                ->get(route('admin.sto-work-orders.show', $order))
                ->assertOk()
                ->assertDontSee(route('admin.sto-work-orders.parts.destroy', [$order, $part]), false)
                ->assertDontSee(route('admin.sto-work-orders.works.destroy', [$order, $work]), false);
        }
    }

    public function test_admin_cannot_add_parts_or_works_to_completed_or_cancelled_work_order(): void
    {
        $user = $this->adminUser();
        $product = $this->inventoryProduct(source: 'donor', availableQuantity: 2);
        $employee = StoEmployee::query()->create([
            'cash_employee_name' => 'Mechanic One',
            'last_name' => 'Mechanic One',
            'is_active' => true,
        ]);

        foreach (['completed', 'cancelled'] as $status) {
            $order = StoWorkOrder::query()->create([
                'number' => 'ZN-20260430-'.$status,
                'status' => $status,
                'client_name' => 'Service Client '.$status,
                'opened_at' => '2026-04-30',
            ]);

            $this->actingAs($user)
                ->from(route('admin.sto-work-orders.show', $order))
                ->post(route('admin.sto-work-orders.parts.store', $order), [
                    'product_id' => $product->id,
                    'quantity' => '1',
                    'unit_price_uah' => '350',
                ])
                ->assertRedirect(route('admin.sto-work-orders.show', $order))
                ->assertSessionHasErrors('status');

            $this->actingAs($user)
                ->from(route('admin.sto-work-orders.show', $order))
                ->post(route('admin.sto-work-orders.works.store', $order), [
                    'name' => 'Filter replacement',
                    'sto_employee_id' => $employee->id,
                    'price_uah' => '500',
                ])
                ->assertRedirect(route('admin.sto-work-orders.show', $order))
                ->assertSessionHasErrors('status');
        }

        $this->assertDatabaseCount('sto_work_order_parts', 0);
        $this->assertDatabaseCount('sto_work_order_works', 0);
    }

    public function test_removing_work_order_part_returns_it_to_stock(): void
    {
        $user = $this->adminUser();
        $product = $this->inventoryProduct(source: 'donor', availableQuantity: 2);
        $order = StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0001',
            'status' => 'in_work',
            'client_name' => 'Service Client',
            'opened_at' => '2026-04-30',
        ]);

        $this->actingAs($user)
            ->post(route('admin.sto-work-orders.parts.store', $order), [
                'product_id' => $product->id,
                'quantity' => '1',
                'unit_price_uah' => '350',
            ])
            ->assertRedirect(route('admin.sto-work-orders.show', $order));

        $part = $order->parts()->firstOrFail();

        $this->assertDatabaseHas('stock_items', [
            'product_id' => $product->id,
            'quantity' => 1,
            'available_quantity' => 1,
        ]);

        $this->actingAs($user)
            ->delete(route('admin.sto-work-orders.parts.destroy', [$order, $part]))
            ->assertRedirect(route('admin.sto-work-orders.show', $order));

        $this->assertDatabaseMissing('sto_work_order_parts', [
            'id' => $part->id,
        ]);
        $this->assertDatabaseHas('stock_items', [
            'product_id' => $product->id,
            'quantity' => 2,
            'available_quantity' => 2,
        ]);
    }

    public function test_cancelling_work_order_removes_line_items_and_returns_parts_to_stock(): void
    {
        $user = $this->adminUser();
        $product = $this->inventoryProduct(source: 'donor', availableQuantity: 2);
        $employee = StoEmployee::query()->create([
            'cash_employee_name' => 'Mechanic One',
            'last_name' => 'Mechanic One',
            'is_active' => true,
        ]);
        $order = StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0001',
            'status' => 'in_work',
            'client_name' => 'Service Client',
            'opened_at' => '2026-04-30',
        ]);

        $this->actingAs($user)
            ->post(route('admin.sto-work-orders.parts.store', $order), [
                'product_id' => $product->id,
                'quantity' => '2',
                'unit_price_uah' => '350',
            ])
            ->assertRedirect(route('admin.sto-work-orders.show', $order));

        $this->actingAs($user)
            ->post(route('admin.sto-work-orders.works.store', $order), [
                'name' => 'Filter replacement',
                'sto_employee_id' => $employee->id,
                'price_uah' => '500',
            ])
            ->assertRedirect(route('admin.sto-work-orders.show', $order));

        $this->actingAs($user)
            ->post(route('admin.sto-work-orders.status.update', $order), [
                'status' => StoWorkOrder::STATUS_CANCELLED,
            ])
            ->assertRedirect(route('admin.sto-work-orders.show', $order));

        $this->assertDatabaseCount('sto_work_order_parts', 0);
        $this->assertDatabaseCount('sto_work_order_works', 0);
        $this->assertDatabaseHas('stock_items', [
            'product_id' => $product->id,
            'quantity' => 2,
            'available_quantity' => 2,
        ]);
        $this->assertDatabaseHas('sto_work_orders', [
            'id' => $order->id,
            'status' => StoWorkOrder::STATUS_CANCELLED,
            'parts_cost_uah' => 0,
            'labor_cost_uah' => 0,
            'total_cost_uah' => 0,
        ]);
    }

    public function test_completing_work_order_consumes_legacy_unallocated_parts_from_stock(): void
    {
        $user = $this->adminUser();
        $product = $this->inventoryProduct(source: 'donor', availableQuantity: 1, name: 'Front bumper');
        $stockItem = $product->stockItems()->firstOrFail();
        $order = StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0003',
            'status' => 'in_work',
            'client_name' => 'Service Client',
            'opened_at' => '2026-04-30',
        ]);

        $order->parts()->create([
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => 1,
            'unit_price_uah' => 350,
            'total_price_uah' => 350,
        ]);

        $this->actingAs($user)
            ->post(route('admin.sto-work-orders.status.update', $order), [
                'status' => StoWorkOrder::STATUS_COMPLETED,
            ])
            ->assertRedirect(route('admin.sto-work-orders.show', $order));

        $this->assertDatabaseHas('sto_work_order_parts', [
            'sto_work_order_id' => $order->id,
            'product_id' => $product->id,
            'stock_item_id' => $stockItem->id,
        ]);
        $this->assertDatabaseHas('stock_items', [
            'id' => $stockItem->id,
            'quantity' => 0,
            'available_quantity' => 0,
        ]);
        $this->assertDatabaseHas('movements', [
            'product_id' => $product->id,
            'stock_item_id' => $stockItem->id,
            'type' => 'sale',
            'quantity' => 1,
            'document_number' => 'ZN-20260430-0003',
        ]);
    }

    public function test_work_order_delete_route_is_not_available(): void
    {
        $user = $this->adminUser();
        $order = StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0001',
            'status' => 'in_work',
            'client_name' => 'Service Client',
            'opened_at' => '2026-04-30',
        ]);

        $this->actingAs($user)
            ->delete('/admin/sto-work-orders/'.$order->id)
            ->assertMethodNotAllowed();

        $this->assertDatabaseHas('sto_work_orders', [
            'id' => $order->id,
            'status' => 'in_work',
            'deleted_at' => null,
        ]);
    }

    public function test_admin_cannot_add_part_without_warehouse_stock_to_work_order(): void
    {
        $user = $this->adminUser();
        $product = $this->inventoryProduct(source: 'donor', availableQuantity: 0);
        $order = StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0001',
            'status' => 'in_work',
            'client_name' => 'Service Client',
            'opened_at' => '2026-04-30',
        ]);

        $this->actingAs($user)
            ->from(route('admin.sto-work-orders.show', $order))
            ->post(route('admin.sto-work-orders.parts.store', $order), [
                'product_id' => $product->id,
                'quantity' => '1',
                'unit_price_uah' => '350',
            ])
            ->assertRedirect(route('admin.sto-work-orders.show', $order))
            ->assertSessionHasErrors('product_id');

        $this->assertDatabaseCount('sto_work_order_parts', 0);
    }

    public function test_admin_cannot_add_part_that_is_not_from_donor_or_purchase(): void
    {
        $user = $this->adminUser();
        $product = $this->inventoryProduct(source: 'plain', availableQuantity: 1);
        $order = StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0001',
            'status' => 'in_work',
            'client_name' => 'Service Client',
            'opened_at' => '2026-04-30',
        ]);

        $this->actingAs($user)
            ->from(route('admin.sto-work-orders.show', $order))
            ->post(route('admin.sto-work-orders.parts.store', $order), [
                'product_id' => $product->id,
                'quantity' => '1',
                'unit_price_uah' => '350',
            ])
            ->assertRedirect(route('admin.sto-work-orders.show', $order))
            ->assertSessionHasErrors('product_id');

        $this->assertDatabaseCount('sto_work_order_parts', 0);
    }

    public function test_admin_cannot_add_more_parts_than_available_stock(): void
    {
        $user = $this->adminUser();
        $product = $this->inventoryProduct(source: 'purchase', availableQuantity: 1);
        $order = StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0001',
            'status' => 'in_work',
            'client_name' => 'Service Client',
            'opened_at' => '2026-04-30',
        ]);

        $this->actingAs($user)
            ->from(route('admin.sto-work-orders.show', $order))
            ->post(route('admin.sto-work-orders.parts.store', $order), [
                'product_id' => $product->id,
                'quantity' => '2',
                'unit_price_uah' => '350',
            ])
            ->assertRedirect(route('admin.sto-work-orders.show', $order))
            ->assertSessionHasErrors('quantity');

        $this->assertDatabaseCount('sto_work_order_parts', 0);
    }

    public function test_admin_cannot_add_part_without_positive_unit_price(): void
    {
        $user = $this->adminUser();
        $product = $this->inventoryProduct(source: 'donor', availableQuantity: 1);
        $order = StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0001',
            'status' => 'in_work',
            'client_name' => 'Service Client',
            'opened_at' => '2026-04-30',
        ]);

        foreach ([null, '0', '0.001', '-10'] as $price) {
            $payload = [
                'product_id' => $product->id,
                'quantity' => '1',
            ];

            if ($price !== null) {
                $payload['unit_price_uah'] = $price;
            }

            $this->actingAs($user)
                ->from(route('admin.sto-work-orders.show', $order))
                ->post(route('admin.sto-work-orders.parts.store', $order), $payload)
                ->assertRedirect(route('admin.sto-work-orders.show', $order))
                ->assertSessionHasErrors('unit_price_uah');
        }

        $this->assertDatabaseCount('sto_work_order_parts', 0);
    }

    public function test_admin_cannot_add_work_without_positive_price(): void
    {
        $user = $this->adminUser();
        $employee = StoEmployee::query()->create([
            'cash_employee_name' => 'Mechanic One',
            'last_name' => 'Mechanic One',
            'is_active' => true,
        ]);
        $order = StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0001',
            'status' => 'in_work',
            'client_name' => 'Service Client',
            'opened_at' => '2026-04-30',
        ]);

        foreach ([null, '0', '0.001', '-10'] as $price) {
            $payload = [
                'name' => 'Filter replacement',
                'sto_employee_id' => $employee->id,
            ];

            if ($price !== null) {
                $payload['price_uah'] = $price;
            }

            $this->actingAs($user)
                ->from(route('admin.sto-work-orders.show', $order))
                ->post(route('admin.sto-work-orders.works.store', $order), $payload)
                ->assertRedirect(route('admin.sto-work-orders.show', $order))
                ->assertSessionHasErrors('price_uah');
        }

        $this->assertDatabaseCount('sto_work_order_works', 0);
    }

    public function test_admin_can_add_usd_part_with_selected_converted_price(): void
    {
        $user = $this->adminUser();
        $this->fakeNbuUsdRate(40.5);
        $product = $this->inventoryProduct(
            source: 'donor',
            availableQuantity: 1,
            name: 'Бампер передний',
            sellingPrice: 160,
            currency: 'USD',
        );
        $order = StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0001',
            'status' => 'in_work',
            'client_name' => 'Service Client',
            'opened_at' => '2026-04-30',
        ]);

        $this->actingAs($user)
            ->post(route('admin.sto-work-orders.parts.store', $order), [
                'product_id' => $product->id,
                'quantity' => '1',
                'unit_price_uah' => '6480',
            ])
            ->assertRedirect(route('admin.sto-work-orders.show', $order));

        $this->assertDatabaseHas('sto_work_order_parts', [
            'sto_work_order_id' => $order->id,
            'product_id' => $product->id,
            'unit_price_uah' => 6480,
            'total_price_uah' => 6480,
        ]);
    }

    public function test_admin_can_update_sto_comment_from_work_order_page(): void
    {
        $user = $this->adminUser();
        $order = StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0001',
            'status' => 'in_work',
            'client_name' => 'Service Client',
            'opened_at' => '2026-04-30',
        ]);

        $this->actingAs($user)
            ->post(route('admin.sto-work-orders.sto-comment.update', $order), [
                'sto_comment' => 'Client asked to check noise after test drive.',
            ])
            ->assertRedirect(route('admin.sto-work-orders.show', $order));

        $this->assertDatabaseHas('sto_work_orders', [
            'id' => $order->id,
            'sto_comment' => 'Client asked to check noise after test drive.',
        ]);
    }

    public function test_work_order_show_has_print_link(): void
    {
        $user = $this->adminUser();
        $order = StoWorkOrder::query()->create([
            'number' => 'ЗН-20260430-0001',
            'status' => 'in_work',
            'client_name' => 'Иван Петров',
            'opened_at' => '2026-04-30',
        ]);

        $this->actingAs($user)
            ->get(route('admin.sto-work-orders.show', $order))
            ->assertOk()
            ->assertSee('Печать заказ-наряда')
            ->assertSee(route('admin.sto-work-orders.print', $order), false);
    }

    public function test_admin_can_print_work_order(): void
    {
        $user = $this->adminUser();
        $employee = StoEmployee::query()->create([
            'cash_employee_name' => 'Mechanic One',
            'last_name' => 'Mechanic One',
            'is_active' => true,
        ]);
        $order = StoWorkOrder::query()->create([
            'number' => 'ЗН-20260430-0001',
            'status' => 'in_work',
            'client_name' => 'Иван Петров',
            'client_phone' => '+380991112233',
            'car_model' => 'Model 3',
            'opened_at' => '2026-04-30',
        ]);
        $order->parts()->create([
            'name' => 'Бампер передний',
            'quantity' => 1,
            'unit_price_uah' => 350,
            'total_price_uah' => 350,
        ]);
        $order->works()->create([
            'sto_employee_id' => $employee->id,
            'name' => 'Диагностика',
            'price_uah' => 500,
        ]);

        $this->actingAs($user)
            ->get(route('admin.sto-work-orders.print', $order))
            ->assertOk()
            ->assertSee('Заказ-наряд № ЗН-20260430-0001')
            ->assertSee('Диагностика')
            ->assertDontSee('Mechanic One')
            ->assertSee('Бампер передний')
            ->assertSee('500,00')
            ->assertSee('350,00');
    }

    public function test_work_order_show_status_dropdown_lists_other_statuses(): void
    {
        $user = $this->adminUser();
        $order = StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0001',
            'status' => 'in_work',
            'client_name' => 'Service Client',
            'opened_at' => '2026-04-30',
        ]);

        $this->actingAs($user)
            ->get(route('admin.sto-work-orders.show', $order))
            ->assertOk()
            ->assertSee('В работе')
            ->assertSee('value="appointment"', false)
            ->assertSee('value="waiting_parts"', false)
            ->assertSee('value="paused"', false)
            ->assertSee('value="completed"', false)
            ->assertSee('value="cancelled"', false)
            ->assertDontSee('value="in_work"', false);
    }

    public function test_work_order_show_status_dropdown_hides_waiting_and_paused_when_order_is_not_in_work(): void
    {
        $user = $this->adminUser();
        $order = StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0001',
            'status' => 'appointment',
            'client_name' => 'Service Client',
            'opened_at' => '2026-04-30',
        ]);

        $this->actingAs($user)
            ->get(route('admin.sto-work-orders.show', $order))
            ->assertOk()
            ->assertSee('value="in_work"', false)
            ->assertSee('value="completed"', false)
            ->assertSee('value="cancelled"', false)
            ->assertDontSee('value="waiting_parts"', false)
            ->assertDontSee('value="paused"', false);
    }

    public function test_admin_can_mark_in_work_order_as_waiting_for_parts(): void
    {
        $user = $this->adminUser();
        $order = StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0001',
            'status' => 'in_work',
            'client_name' => 'Service Client',
            'opened_at' => '2026-04-30',
        ]);

        $this->actingAs($user)
            ->post(route('admin.sto-work-orders.status.update', $order), [
                'status' => 'waiting_parts',
            ])
            ->assertRedirect(route('admin.sto-work-orders.show', $order));

        $this->assertDatabaseHas('sto_work_orders', [
            'id' => $order->id,
            'status' => 'waiting_parts',
        ]);
    }

    public function test_admin_can_mark_in_work_order_as_paused(): void
    {
        $user = $this->adminUser();
        $order = StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0001',
            'status' => 'in_work',
            'client_name' => 'Service Client',
            'opened_at' => '2026-04-30',
        ]);

        $this->actingAs($user)
            ->post(route('admin.sto-work-orders.status.update', $order), [
                'status' => 'paused',
            ])
            ->assertRedirect(route('admin.sto-work-orders.show', $order));

        $this->assertDatabaseHas('sto_work_orders', [
            'id' => $order->id,
            'status' => 'paused',
        ]);
    }

    public function test_admin_cannot_mark_not_in_work_order_as_waiting_for_parts_or_paused(): void
    {
        $user = $this->adminUser();

        foreach (['waiting_parts', 'paused'] as $status) {
            $order = StoWorkOrder::query()->create([
                'number' => 'ZN-20260430-'.$status,
                'status' => 'appointment',
                'client_name' => 'Service Client',
                'opened_at' => '2026-04-30',
            ]);

            $this->actingAs($user)
                ->from(route('admin.sto-work-orders.show', $order))
                ->post(route('admin.sto-work-orders.status.update', $order), [
                    'status' => $status,
                ])
                ->assertRedirect(route('admin.sto-work-orders.show', $order))
                ->assertSessionHasErrors('status');

            $this->assertDatabaseHas('sto_work_orders', [
                'id' => $order->id,
                'status' => 'appointment',
            ]);
        }
    }

    public function test_completed_work_order_cannot_be_moved_to_cancelled_or_appointment(): void
    {
        $user = $this->adminUser();
        $order = StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0001',
            'status' => 'completed',
            'client_name' => 'Service Client',
            'opened_at' => '2026-04-30',
            'completed_at' => '2026-04-30',
        ]);

        $this->actingAs($user)
            ->get(route('admin.sto-work-orders.show', $order))
            ->assertOk()
            ->assertSee('value="in_work"', false)
            ->assertDontSee('value="appointment"', false)
            ->assertDontSee('value="cancelled"', false);

        foreach (['appointment', 'cancelled'] as $status) {
            $this->actingAs($user)
                ->from(route('admin.sto-work-orders.show', $order))
                ->post(route('admin.sto-work-orders.status.update', $order), [
                    'status' => $status,
                ])
                ->assertRedirect(route('admin.sto-work-orders.show', $order))
                ->assertSessionHasErrors('status');

            $this->assertDatabaseHas('sto_work_orders', [
                'id' => $order->id,
                'status' => 'completed',
            ]);
        }
    }

    public function test_admin_can_close_work_order_from_work_order_page(): void
    {
        $user = $this->adminUser();
        $order = StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0001',
            'status' => 'in_work',
            'client_name' => 'Service Client',
            'opened_at' => '2026-04-30',
        ]);

        $this->travelTo('2026-04-30 12:00:00');

        $this->actingAs($user)
            ->post(route('admin.sto-work-orders.status.update', $order), [
                'status' => 'completed',
            ])
            ->assertRedirect(route('admin.sto-work-orders.show', $order));

        $this->assertDatabaseHas('sto_work_orders', [
            'id' => $order->id,
            'status' => 'completed',
            'completed_at' => '2026-04-30 12:00:00',
        ]);
    }

    public function test_moving_work_order_to_in_work_sets_current_opened_date_and_first_work_started_date(): void
    {
        $user = $this->adminUser();
        $order = StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0001',
            'status' => 'appointment',
            'client_name' => 'Service Client',
            'opened_at' => '2026-05-02',
        ]);

        $this->travelTo('2026-04-30 12:00:00');

        $this->actingAs($user)
            ->post(route('admin.sto-work-orders.status.update', $order), [
                'status' => StoWorkOrder::STATUS_IN_WORK,
            ])
            ->assertRedirect(route('admin.sto-work-orders.show', $order));

        $this->assertDatabaseHas('sto_work_orders', [
            'id' => $order->id,
            'status' => StoWorkOrder::STATUS_IN_WORK,
            'opened_at' => '2026-04-30 00:00:00',
            'work_started_at' => '2026-04-30 12:00:00',
        ]);
    }

    public function test_moving_work_order_back_to_in_work_keeps_first_work_started_date(): void
    {
        $user = $this->adminUser();
        $order = StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0001',
            'status' => StoWorkOrder::STATUS_COMPLETED,
            'client_name' => 'Service Client',
            'opened_at' => '2026-04-28',
            'work_started_at' => '2026-04-28',
            'completed_at' => '2026-04-29',
        ]);

        $this->travelTo('2026-04-30 12:00:00');

        $this->actingAs($user)
            ->post(route('admin.sto-work-orders.status.update', $order), [
                'status' => StoWorkOrder::STATUS_IN_WORK,
            ])
            ->assertRedirect(route('admin.sto-work-orders.show', $order));

        $this->assertDatabaseHas('sto_work_orders', [
            'id' => $order->id,
            'status' => StoWorkOrder::STATUS_IN_WORK,
            'opened_at' => '2026-04-30 00:00:00',
            'work_started_at' => '2026-04-28 00:00:00',
        ]);
    }

    public function test_moving_work_order_to_completed_updates_last_completed_date(): void
    {
        $user = $this->adminUser();
        $order = StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0001',
            'status' => StoWorkOrder::STATUS_IN_WORK,
            'client_name' => 'Service Client',
            'opened_at' => '2026-04-28',
            'work_started_at' => '2026-04-28',
            'completed_at' => '2026-04-29',
        ]);

        $this->travelTo('2026-04-30 12:00:00');

        $this->actingAs($user)
            ->post(route('admin.sto-work-orders.status.update', $order), [
                'status' => StoWorkOrder::STATUS_COMPLETED,
            ])
            ->assertRedirect(route('admin.sto-work-orders.show', $order));

        $this->assertDatabaseHas('sto_work_orders', [
            'id' => $order->id,
            'status' => StoWorkOrder::STATUS_COMPLETED,
            'completed_at' => '2026-04-30 12:00:00',
        ]);
    }

    public function test_admin_can_confirm_full_work_order_payment_and_archive_it(): void
    {
        $user = $this->adminUser();
        $order = StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0001',
            'status' => 'completed',
            'client_name' => 'Service Client',
            'vin' => 'VIN123',
            'opened_at' => '2026-04-30',
            'completed_at' => '2026-04-30',
            'total_cost_uah' => 1500,
        ]);

        $this->travelTo('2026-04-30 12:00:00');

        $this->actingAs($user)
            ->get(route('admin.sto-work-orders.show', $order))
            ->assertOk()
            ->assertSee('Подтвердить оплату')
            ->assertDontSee('value="paid"', false)
            ->assertDontSee('value="archived"', false);

        $this->actingAs($user)
            ->post(route('admin.sto-work-orders.payment.confirm', $order), [
                'payment_method' => 'bank_uah',
                'amount' => '1500',
            ])
            ->assertRedirect(route('admin.sto-work-orders.show', $order));

        $this->assertDatabaseHas('sto_work_orders', [
            'id' => $order->id,
            'status' => 'paid',
            'paid_bank_uah' => 1500,
            'paid_amount_uah' => 1500,
        ]);

        $this->assertDatabaseHas('cash_transactions', [
            'operation_date' => '2026-04-30 00:00:00',
            'income_bank_uah' => 1500,
            'label' => '+',
            'vehicle_vin' => 'VIN123',
            'source' => 'sto_work_order_payment',
        ]);

        $this->actingAs($user)
            ->get(route('admin.sto-work-orders.show', $order->refresh()))
            ->assertOk()
            ->assertSee('Архив')
            ->assertDontSee('Подтвердить оплату');

        $this->actingAs($user)
            ->post(route('admin.sto-work-orders.archive', $order))
            ->assertRedirect(route('admin.sto-work-orders.index'));

        $this->assertDatabaseHas('sto_work_orders', [
            'id' => $order->id,
            'status' => 'archived',
        ]);
    }

    public function test_work_order_payment_can_be_split_between_payment_methods(): void
    {
        $user = $this->adminUser();
        $order = StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0012',
            'status' => 'completed',
            'client_name' => 'Service Client',
            'vin' => 'VIN123',
            'opened_at' => '2026-04-30',
            'completed_at' => '2026-04-30',
            'total_cost_uah' => 1500,
        ]);

        $this->travelTo('2026-04-30 12:00:00');

        $this->actingAs($user)
            ->post(route('admin.sto-work-orders.payment.confirm', $order), [
                'payments' => [
                    [
                        'payment_method' => 'cash_uah',
                        'amount' => '500',
                    ],
                    [
                        'payment_method' => 'bank_uah',
                        'amount' => '1000',
                    ],
                ],
            ])
            ->assertRedirect(route('admin.sto-work-orders.show', $order));

        $this->assertDatabaseHas('sto_work_orders', [
            'id' => $order->id,
            'status' => 'paid',
            'paid_cash_uah' => 500,
            'paid_bank_uah' => 1000,
            'paid_amount_uah' => 1500,
        ]);

        $this->assertDatabaseHas('cash_transactions', [
            'operation_date' => '2026-04-30 00:00:00',
            'income_cash_uah' => 500,
            'label' => '+',
            'source' => 'sto_work_order_payment',
        ]);
        $this->assertDatabaseHas('cash_transactions', [
            'operation_date' => '2026-04-30 00:00:00',
            'income_bank_uah' => 1000,
            'label' => '+',
            'source' => 'sto_work_order_payment',
        ]);
    }

    public function test_work_order_payment_creates_cash_transactions_by_part_source(): void
    {
        $user = $this->adminUser();
        $donorProduct = $this->inventoryProduct(source: 'donor', availableQuantity: 1);
        $purchaseProduct = $this->inventoryProduct(source: 'purchase', availableQuantity: 1);
        $order = StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0001',
            'status' => 'completed',
            'client_name' => 'Service Client',
            'opened_at' => '2026-04-30',
            'completed_at' => '2026-04-30',
            'labor_cost_uah' => 500,
            'parts_cost_uah' => 500,
            'total_cost_uah' => 1000,
        ]);

        $order->parts()->create([
            'product_id' => $donorProduct->id,
            'name' => $donorProduct->name,
            'quantity' => 1,
            'unit_price_uah' => 300,
            'total_price_uah' => 300,
        ]);
        $order->parts()->create([
            'product_id' => $purchaseProduct->id,
            'name' => $purchaseProduct->name,
            'quantity' => 1,
            'unit_price_uah' => 200,
            'total_price_uah' => 200,
        ]);

        $this->actingAs($user)
            ->post(route('admin.sto-work-orders.payment.confirm', $order), [
                'payment_method' => 'bank_uah',
                'amount' => '1000',
            ])
            ->assertRedirect(route('admin.sto-work-orders.show', $order));

        $this->assertDatabaseHas('cash_transactions', [
            'income_bank_uah' => 500,
            'label' => '+',
            'comment' => 'Оплата заказ-наряда ZN-20260430-0001',
            'source' => 'sto_work_order_payment',
        ]);
        $this->assertDatabaseHas('cash_transactions', [
            'income_bank_uah' => 300,
            'label' => '  ',
            'comment' => 'Оплата заказ-наряда ZN-20260430-0001',
            'source' => 'sto_work_order_payment',
        ]);
        $this->assertDatabaseHas('cash_transactions', [
            'income_bank_uah' => 200,
            'label' => 'Продажа ЗЧК',
            'comment' => 'Оплата заказ-наряда ZN-20260430-0001',
            'source' => 'sto_work_order_payment',
        ]);
        $this->assertDatabaseHas('cashbook_labels', [
            'name' => '  ',
            'operation_type' => 'income',
        ]);
        $this->assertDatabaseHas('cashbook_labels', [
            'name' => 'Продажа ЗЧК',
            'operation_type' => 'income',
        ]);
    }

    public function test_payment_confirmation_from_index_redirects_back_to_index(): void
    {
        $user = $this->adminUser();
        $order = StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0001',
            'status' => 'completed',
            'client_name' => 'Service Client',
            'opened_at' => '2026-04-30',
            'completed_at' => '2026-04-30',
            'total_cost_uah' => 1500,
        ]);

        $returnUrl = route('admin.sto-work-orders.index', ['statuses' => ['completed']]);

        $this->actingAs($user)
            ->post(route('admin.sto-work-orders.payment.confirm', $order), [
                'payment_method' => 'cash_uah',
                'amount' => '1500',
                'return_url' => $returnUrl,
            ])
            ->assertRedirect($returnUrl);

        $this->assertDatabaseHas('sto_work_orders', [
            'id' => $order->id,
            'status' => 'paid',
            'paid_cash_uah' => 1500,
        ]);
    }

    public function test_work_order_payment_cash_transaction_cannot_be_deleted(): void
    {
        $user = $this->adminUser();
        $order = StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0001',
            'status' => 'paid',
            'client_name' => 'Service Client',
            'opened_at' => '2026-04-30',
            'total_cost_uah' => 1200,
        ]);
        $transaction = CashTransaction::query()->create([
            'operation_date' => '2026-04-30',
            'income_cash_uah' => 1200,
            'label' => 'СТО',
            'comment' => 'Оплата заказ-наряда ZN-20260430-0001',
            'source' => CashTransaction::SOURCE_STO_WORK_ORDER_PAYMENT,
        ]);

        $this->actingAs($user)
            ->delete(route('admin.cashbook.destroy', $transaction))
            ->assertRedirect(route('admin.cashbook.index'));

        $this->assertDatabaseHas('cash_transactions', [
            'id' => $transaction->id,
            'source' => CashTransaction::SOURCE_STO_WORK_ORDER_PAYMENT,
        ]);

        $this->actingAs($user)
            ->get(route('admin.cashbook.index', ['from' => '2026-04-30', 'to' => '2026-04-30']))
            ->assertOk()
            ->assertSee('href="'.route('admin.sto-work-orders.show', $order).'"', false)
            ->assertDontSee(route('admin.cashbook.edit', $transaction), false)
            ->assertDontSee('<button type="submit" class="btn-small btn-danger">', false);

        $this->actingAs($user)
            ->get(route('admin.cashbook.show', $transaction))
            ->assertOk()
            ->assertDontSee(route('admin.cashbook.edit', $transaction), false)
            ->assertDontSee('<button type="submit" class="btn-danger">', false);

        $this->actingAs($user)
            ->get(route('admin.cashbook.edit', $transaction))
            ->assertNotFound();

        $this->actingAs($user)
            ->put(route('admin.cashbook.update', $transaction))
            ->assertRedirect(route('admin.cashbook.index'));
    }

    public function test_cashbook_comment_links_to_referenced_work_order(): void
    {
        $user = $this->adminUser();
        $order = StoWorkOrder::query()->create([
            'number' => 'ЗН-20260430-0002',
            'status' => 'paid',
            'client_name' => 'Service Client',
            'opened_at' => '2026-04-30',
            'total_cost_uah' => 1500,
        ]);

        $transaction = CashTransaction::query()->create([
            'operation_date' => '2026-04-30',
            'income_cash_uah' => 1500,
            'income_cash_usd' => 0,
            'income_bank_uah' => 0,
            'expense_cash_uah' => 0,
            'expense_cash_usd' => 0,
            'expense_bank_uah' => 0,
            'label' => '+',
            'comment' => 'Оплата заказ-наряда ЗН-20260430-0002',
            'source' => 'sto_work_order_payment',
        ]);

        $this->actingAs($user)
            ->get(route('admin.cashbook.index', ['from' => '2026-04-30', 'to' => '2026-04-30']))
            ->assertOk()
            ->assertSee('<a href="'.route('admin.sto-work-orders.show', $order).'">ЗН-20260430-0002</a>', false)
            ->assertSee('href="'.route('admin.cashbook.show', $transaction).'">Открыть</a>', false);

        $this->actingAs($user)
            ->get(route('admin.cashbook.show', $transaction))
            ->assertOk()
            ->assertSee('<a href="'.route('admin.sto-work-orders.show', $order).'">ЗН-20260430-0002</a>', false);
    }

    public function test_cashbook_repair_work_order_row_shows_work_employee(): void
    {
        $user = $this->adminUser();
        $employee = StoEmployee::query()->create([
            'cash_employee_name' => 'Mechanic One',
            'last_name' => 'Mechanic One',
            'is_active' => true,
        ]);
        $order = StoWorkOrder::query()->create([
            'number' => 'ЗН-20260430-0003',
            'status' => 'paid',
            'client_name' => 'Service Client',
            'opened_at' => '2026-04-30',
            'total_cost_uah' => 1500,
        ]);
        $order->works()->create([
            'sto_employee_id' => $employee->id,
            'name' => 'Diagnostics',
            'price_uah' => 1500,
        ]);

        CashTransaction::query()->create([
            'operation_date' => '2026-04-30',
            'income_cash_uah' => 1500,
            'income_cash_usd' => 0,
            'income_bank_uah' => 0,
            'expense_cash_uah' => 0,
            'expense_cash_usd' => 0,
            'expense_bank_uah' => 0,
            'label' => '+',
            'employee' => 'Cash Employee',
            'comment' => 'Оплата заказ-наряда ЗН-20260430-0003',
            'source' => 'sto_work_order_payment',
        ]);

        $this->actingAs($user)
            ->get(route('admin.cashbook.index', ['from' => '2026-04-30', 'to' => '2026-04-30']))
            ->assertOk()
            ->assertSee('<td>Mechanic One</td>', false);
    }

    public function test_paid_work_order_cannot_be_returned_to_work(): void
    {
        $user = $this->adminUser();
        $order = StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0001',
            'status' => 'paid',
            'client_name' => 'Service Client',
            'opened_at' => '2026-04-30',
            'completed_at' => '2026-04-30',
            'total_cost_uah' => 1500,
            'paid_cash_uah' => 500,
            'paid_cash_usd' => 10,
            'paid_bank_uah' => 570,
            'paid_amount_uah' => 1500,
            'payment_confirmed_at' => '2026-04-30 12:00:00',
        ]);

        CashTransaction::query()->create([
            'operation_date' => '2026-04-30',
            'income_cash_uah' => 500,
            'income_cash_usd' => 0,
            'income_bank_uah' => 0,
            'expense_cash_uah' => 0,
            'expense_cash_usd' => 0,
            'expense_bank_uah' => 0,
            'label' => '+',
            'comment' => 'Оплата заказ-наряда ZN-20260430-0001',
            'source' => 'sto_work_order_payment',
        ]);

        CashTransaction::query()->create([
            'operation_date' => '2026-04-30',
            'income_cash_uah' => 1,
            'income_cash_usd' => 0,
            'income_bank_uah' => 0,
            'expense_cash_uah' => 0,
            'expense_cash_usd' => 0,
            'expense_bank_uah' => 0,
            'label' => '+',
            'comment' => 'Другая операция',
            'source' => 'sto_work_order_payment',
        ]);

        $this->actingAs($user)
            ->get(route('admin.sto-work-orders.show', $order))
            ->assertOk()
            ->assertDontSee('value="in_work"', false)
            ->assertDontSee('value="completed"', false);

        $this->actingAs($user)
            ->from(route('admin.sto-work-orders.show', $order))
            ->post(route('admin.sto-work-orders.status.update', $order), [
                'status' => 'in_work',
            ])
            ->assertRedirect(route('admin.sto-work-orders.show', $order))
            ->assertSessionHasErrors('status');

        $this->assertDatabaseHas('sto_work_orders', [
            'id' => $order->id,
            'status' => 'paid',
            'paid_cash_uah' => 500,
            'paid_cash_usd' => 10,
            'paid_bank_uah' => 570,
            'paid_amount_uah' => 1500,
        ]);

        $this->assertDatabaseHas('cash_transactions', [
            'comment' => 'Оплата заказ-наряда ZN-20260430-0001',
            'source' => 'sto_work_order_payment',
        ]);

        $this->assertDatabaseHas('cash_transactions', [
            'comment' => 'Другая операция',
            'source' => 'sto_work_order_payment',
        ]);
    }

    public function test_paid_work_order_cannot_be_moved_to_other_statuses_through_status_update(): void
    {
        $user = $this->adminUser();
        $order = StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0001',
            'status' => 'paid',
            'client_name' => 'Service Client',
            'opened_at' => '2026-04-30',
            'completed_at' => '2026-04-30',
            'total_cost_uah' => 1500,
            'paid_amount_uah' => 1500,
        ]);

        foreach (['appointment', 'in_work', 'completed', 'cancelled'] as $status) {
            $this->actingAs($user)
                ->from(route('admin.sto-work-orders.show', $order))
                ->post(route('admin.sto-work-orders.status.update', $order), [
                    'status' => $status,
                ])
                ->assertRedirect(route('admin.sto-work-orders.show', $order))
                ->assertSessionHasErrors('status');
        }

        $this->assertDatabaseHas('sto_work_orders', [
            'id' => $order->id,
            'status' => 'paid',
            'paid_amount_uah' => 1500,
        ]);
    }

    public function test_partial_work_order_payment_keeps_order_completed(): void
    {
        $user = $this->adminUser();
        $order = StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0001',
            'status' => 'completed',
            'client_name' => 'Service Client',
            'opened_at' => '2026-04-30',
            'completed_at' => '2026-04-30',
            'total_cost_uah' => 2000,
        ]);

        $this->actingAs($user)
            ->post(route('admin.sto-work-orders.payment.confirm', $order), [
                'payment_method' => 'cash_usd',
                'amount' => '10',
            ])
            ->assertRedirect(route('admin.sto-work-orders.show', $order));

        $this->assertDatabaseHas('sto_work_orders', [
            'id' => $order->id,
            'status' => 'completed',
            'paid_cash_usd' => 10,
            'paid_amount_uah' => 430,
        ]);

        $this->assertDatabaseHas('cash_transactions', [
            'income_cash_usd' => 10,
            'exchange_rate' => 43,
            'source' => 'sto_work_order_payment',
        ]);
    }

    public function test_cancelled_work_order_can_be_archived_and_is_hidden_by_default(): void
    {
        $user = $this->adminUser();
        $order = StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0001',
            'status' => 'cancelled',
            'client_name' => 'Cancelled Client',
            'opened_at' => '2026-04-30',
        ]);

        $this->actingAs($user)
            ->post(route('admin.sto-work-orders.archive', $order))
            ->assertRedirect(route('admin.sto-work-orders.index'));

        $this->assertDatabaseHas('sto_work_orders', [
            'id' => $order->id,
            'status' => 'archived',
        ]);

        $this->actingAs($user)
            ->get(route('admin.sto-work-orders.index'))
            ->assertOk()
            ->assertDontSee('Cancelled Client');

        $this->actingAs($user)
            ->get(route('admin.sto-work-orders.index', ['status' => 'archived']))
            ->assertOk()
            ->assertSee('Cancelled Client');
    }

    public function test_admin_can_remove_parts_and_works_from_work_order(): void
    {
        $user = $this->adminUser();
        $order = StoWorkOrder::query()->create([
            'number' => 'ZN-20260430-0001',
            'status' => 'in_work',
            'client_name' => 'Service Client',
            'opened_at' => '2026-04-30',
        ]);
        $part = $order->parts()->create([
            'name' => 'Cabin filter',
            'quantity' => 1,
            'unit_price_uah' => 350,
            'total_price_uah' => 350,
        ]);
        $work = $order->works()->create([
            'name' => 'Filter replacement',
            'price_uah' => 500,
        ]);

        $this->actingAs($user)
            ->delete(route('admin.sto-work-orders.parts.destroy', [$order, $part]))
            ->assertRedirect(route('admin.sto-work-orders.show', $order));

        $this->actingAs($user)
            ->delete(route('admin.sto-work-orders.works.destroy', [$order, $work]))
            ->assertRedirect(route('admin.sto-work-orders.show', $order));

        $this->assertDatabaseMissing('sto_work_order_parts', [
            'id' => $part->id,
        ]);
        $this->assertDatabaseMissing('sto_work_order_works', [
            'id' => $work->id,
        ]);
        $this->assertDatabaseHas('sto_work_orders', [
            'id' => $order->id,
            'parts_cost_uah' => 0,
            'labor_cost_uah' => 0,
            'total_cost_uah' => 0,
        ]);
    }

    public function test_paid_and_completed_work_orders_cannot_remove_parts_or_works(): void
    {
        $user = $this->adminUser();

        foreach (['completed', 'paid'] as $index => $status) {
            $order = StoWorkOrder::query()->create([
                'number' => 'ZN-20260430-LOCK-'.$index,
                'status' => $status,
                'client_name' => 'Locked Service Client',
                'opened_at' => '2026-04-30',
            ]);
            $part = $order->parts()->create([
                'name' => 'Cabin filter',
                'quantity' => 1,
                'unit_price_uah' => 350,
                'total_price_uah' => 350,
            ]);
            $work = $order->works()->create([
                'name' => 'Filter replacement',
                'price_uah' => 500,
            ]);

            $this->actingAs($user)
                ->from(route('admin.sto-work-orders.show', $order))
                ->delete(route('admin.sto-work-orders.parts.destroy', [$order, $part]))
                ->assertRedirect(route('admin.sto-work-orders.show', $order))
                ->assertSessionHasErrors('status');

            $this->actingAs($user)
                ->from(route('admin.sto-work-orders.show', $order))
                ->delete(route('admin.sto-work-orders.works.destroy', [$order, $work]))
                ->assertRedirect(route('admin.sto-work-orders.show', $order))
                ->assertSessionHasErrors('status');

            $this->assertDatabaseHas('sto_work_order_parts', [
                'id' => $part->id,
            ]);
            $this->assertDatabaseHas('sto_work_order_works', [
                'id' => $work->id,
            ]);
        }
    }

    public function test_cashbook_excludes_valera_transfer_income_transactions(): void
    {
        $user = $this->adminUser();

        CashbookLabel::query()->updateOrCreate(
            ['name' => 'Инкассо Женя'],
            ['operation_type' => 'income'],
        );

        CashTransaction::query()->create([
            'operation_date' => '2026-05-01',
            'income_cash_usd' => 2500,
            'label' => 'Инкассо Женя',
            'comment' => 'Visible operation comment',
            'source' => 'manual',
        ]);

        $this->actingAs($user)
            ->get(route('admin.cashbook.index'))
            ->assertOk()
            ->assertDontSee('Visible operation comment')
            ->assertDontSee('Инкассо Женя');
    }

    public function test_cashbook_hides_dividends_label_from_filter(): void
    {
        $user = $this->adminUser();

        CashbookLabel::query()->updateOrCreate(
            ['name' => 'Дивиденды'],
            ['operation_type' => 'expense'],
        );

        $this->actingAs($user)
            ->get(route('admin.cashbook.index', ['from' => '2026-04-30', 'to' => '2026-04-30']))
            ->assertOk()
            ->assertDontSee('data-cashbook-label="Дивиденды"', false)
            ->assertDontSee('<option value="Дивиденды"', false);
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

    private function inventoryProduct(
        string $source,
        int $availableQuantity,
        string $name = 'Cabin filter',
        float $sellingPrice = 350,
        string $currency = 'UAH',
    ): Product {
        $warehouse = Warehouse::query()->firstOrCreate(
            ['name' => 'Main warehouse'],
            ['type' => 'main', 'is_active' => true],
        );
        $location = Location::query()->firstOrCreate(
            ['full_code' => 'MAIN-A1'],
            ['warehouse_id' => $warehouse->id, 'is_active' => true],
        );
        $donorCar = $source === 'donor'
            ? DonorCar::query()->create([
                'vin' => fake()->unique()->bothify('5YJ###########'),
                'model' => 'Model 3',
            ])
            : null;

        $product = Product::query()->create([
            'sku' => fake()->unique()->bothify('PART-####'),
            'name' => $name,
            'slug' => fake()->unique()->slug(),
            'donor_car_id' => $donorCar?->id,
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => $sellingPrice,
            'currency' => $currency,
            'is_active' => true,
        ]);

        $stockItem = StockItem::query()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'location_id' => $location->id,
            'quantity' => $availableQuantity,
            'reserved_quantity' => 0,
            'testing_status' => 'not_tested',
        ]);

        if ($source === 'purchase') {
            $purchase = Purchase::query()->create([
                'warehouse_id' => $warehouse->id,
                'purchase_date' => '2026-04-30',
                'status' => 'posted',
                'currency' => 'UAH',
            ]);

            $purchase->items()->create([
                'product_id' => $product->id,
                'stock_item_id' => $stockItem->id,
                'warehouse_id' => $warehouse->id,
                'location_id' => $location->id,
                'quantity' => max(1, $availableQuantity),
                'purchase_price' => 100,
                'selling_price' => $sellingPrice,
                'currency' => $currency,
            ]);
        }

        return $product;
    }

    private function fakeNbuUsdRate(float $rate): void
    {
        Cache::put('exchange_rate_usd_nbu_latest', $rate, now()->addHours(6));
        Cache::put('exchange_rate_usd_monobank_latest', $rate, now()->addHours(6));
        Http::fake([
            'bank.gov.ua/*' => Http::response([
                [
                    'cc' => 'USD',
                    'rate' => $rate,
                ],
            ]),
            'api.monobank.ua/*' => Http::response([
                [
                    'currencyCodeA' => 840,
                    'currencyCodeB' => 980,
                    'rateSell' => $rate,
                ],
            ]),
        ]);
    }
}
