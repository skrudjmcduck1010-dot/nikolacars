<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_restricted_sections(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $this->actingAs($user)->get(route('admin.activity-logs.index'))->assertOk();
        $this->actingAs($user)->get(route('admin.users.index'))->assertOk();
        $this->actingAs($user)->get(route('admin.valera-cashbook.index'))->assertOk();
    }

    public function test_sto_manager_has_sto_sections_except_valera_cashbook_and_log(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_STO_MANAGER,
            'is_active' => true,
        ]);

        $this->actingAs($user)->get(route('admin.cashbook.index'))->assertOk();
        $this->actingAs($user)->get(route('admin.sto-work-orders.index'))->assertOk();
        $this->actingAs($user)->get(route('admin.sto-employees.index'))->assertOk();
        $this->actingAs($user)->get(route('admin.reports.monthly'))->assertOk();

        $this->actingAs($user)->get(route('admin.valera-cashbook.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.activity-logs.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(route('admin.cashbook.index'), false)
            ->assertSee(route('admin.sto-work-orders.index'), false)
            ->assertDontSee(route('admin.valera-cashbook.index'), false)
            ->assertDontSee(route('admin.users.index'), false)
            ->assertDontSee(route('admin.activity-logs.index'), false);
    }

    public function test_warehouse_worker_can_open_orders_and_competitors_but_not_sto_or_cash_sections(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_WAREHOUSE_WORKER,
            'is_active' => true,
        ]);

        $this->actingAs($user)->get(route('admin.customer-orders.index'))->assertOk();

        foreach ([
            'admin.part-catalog.index',
            'admin.teslacompany-catalog.index',
            'admin.teslapartsukraine-catalog.index',
            'admin.tsk-catalog.index',
            'admin.stock-tesla-catalog.index',
            'admin.driveparts-catalog.index',
            'admin.dkparts-catalog.index',
            'admin.erazborka-catalog.index',
            'admin.toprazborka-catalog.index',
            'admin.teslawestparts-catalog.index',
            'admin.tesla-official-catalog.index',
            'admin.competitors-ru.index',
        ] as $routeName) {
            $this->actingAs($user)->get(route($routeName))->assertOk();
        }

        $this->assertFalse($user->hasPermission('competitor_refresh.manage'));
        $this->actingAs($user)
            ->get(route('admin.teslacompany-catalog.index'))
            ->assertOk()
            ->assertDontSee('data-tcars-refresh-panel', false)
            ->assertDontSee('competitor-refresh/teslacompany', false);
        $this->actingAs($user)
            ->getJson(route('admin.part-catalog.source-competitor-refresh.status', ['source' => 'teslacompany']))
            ->assertForbidden();
        $this->actingAs($user)
            ->postJson(route('admin.part-catalog.source-competitor-refresh.start', ['source' => 'teslacompany']))
            ->assertForbidden();

        $this->actingAs($user)->get(route('admin.cashbook.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.cashbook-labels.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.valera-cashbook.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.sto-work-orders.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.sto-employees.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.reports.monthly'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.activity-logs.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(route('admin.warehouses.index'), false)
            ->assertSee(route('admin.customer-orders.index'), false)
            ->assertSee(route('admin.part-catalog.index'), false)
            ->assertSee(route('admin.teslacompany-catalog.index'), false)
            ->assertSee(route('admin.teslapartsukraine-catalog.index'), false)
            ->assertSee(route('admin.tsk-catalog.index'), false)
            ->assertSee(route('admin.stock-tesla-catalog.index'), false)
            ->assertSee(route('admin.driveparts-catalog.index'), false)
            ->assertSee(route('admin.dkparts-catalog.index'), false)
            ->assertSee(route('admin.erazborka-catalog.index'), false)
            ->assertSee(route('admin.toprazborka-catalog.index'), false)
            ->assertSee(route('admin.teslawestparts-catalog.index'), false)
            ->assertSee(route('admin.tesla-official-catalog.index'), false)
            ->assertSee(route('admin.competitors-ru.index'), false)
            ->assertDontSee(route('admin.cashbook.index'), false)
            ->assertDontSee(route('admin.valera-cashbook.index'), false)
            ->assertDontSee(route('admin.sto-work-orders.index'), false)
            ->assertDontSee(route('admin.sto-employees.index'), false)
            ->assertDontSee(route('admin.reports.monthly'), false)
            ->assertDontSee(route('admin.users.index'), false)
            ->assertDontSee(route('admin.activity-logs.index'), false);
    }

    public function test_limited_warehouse_worker_cannot_open_orders_sales_or_nikolacars_parts(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_WAREHOUSE_LIMITED,
            'is_active' => true,
        ]);

        $this->assertFalse($user->hasPermission('customer_orders.manage'));
        $this->assertFalse($user->hasPermission('nikolacars_sales.view'));
        $this->assertFalse($user->hasPermission('nikolacars_catalog.manage'));

        $this->actingAs($user)->get(route('admin.customer-orders.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.nikolacars-sales.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.zapchasti.index'))->assertForbidden();

        $this->actingAs($user)->get(route('admin.stock-items.index'))->assertOk();
        $this->actingAs($user)->get(route('admin.part-catalog.index'))->assertOk();

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(route('admin.warehouses.index'), false)
            ->assertSee(route('admin.part-catalog.index'), false)
            ->assertDontSee(route('admin.customer-orders.index'), false)
            ->assertDontSee(route('admin.nikolacars-sales.index'), false)
            ->assertDontSee(route('admin.zapchasti.index'), false);
    }

    public function test_admin_can_grant_personal_section_access(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $viewer = User::factory()->create([
            'role' => 'viewer',
            'is_active' => true,
        ]);

        $this->actingAs($viewer)->get(route('admin.cashbook.index'))->assertForbidden();

        $this->actingAs($admin)
            ->patch(route('admin.users.update', $viewer), [
                'role' => 'viewer',
                'is_active' => '1',
                'extra_permissions' => ['cashbook.manage'],
            ])
            ->assertRedirect(route('admin.users.index'));

        $viewer->refresh();

        $this->assertTrue($viewer->hasPermission('cashbook.manage'));
        $this->actingAs($viewer)->get(route('admin.cashbook.index'))->assertOk();
    }
}
