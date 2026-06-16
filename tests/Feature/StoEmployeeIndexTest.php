<?php

namespace Tests\Feature;

use App\Models\StoEmployee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StoEmployeeIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_founders_are_rendered_in_separate_bottom_table(): void
    {
        StoEmployee::query()->create([
            'cash_employee_name' => 'Mechanic Test',
            'last_name' => 'Mechanic Test',
            'position' => 'Механик',
            'is_active' => true,
        ]);
        $founder = StoEmployee::query()->create([
            'cash_employee_name' => 'Founder Test',
            'last_name' => 'Founder Test',
            'position' => 'Основатель',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->adminUser())
            ->get(route('admin.sto-employees.index'))
            ->assertOk()
            ->assertSeeInOrder(['Сотрудники', 'Mechanic Test', 'Основатели', 'Founder Test']);

        $html = $response->getContent();
        $foundersHeadingPosition = strpos($html, 'Основатели');

        $this->assertNotFalse($foundersHeadingPosition);
        $this->assertStringNotContainsString('Founder Test', substr($html, 0, $foundersHeadingPosition));
        $regularTableHtml = substr($html, 0, $foundersHeadingPosition);
        $founderTableHtml = substr($html, $foundersHeadingPosition);

        $this->assertStringContainsString('<th>#</th>', $regularTableHtml);
        $this->assertStringNotContainsString('<th>#</th>', $founderTableHtml);
        $this->assertStringContainsString('<td><a href="'.route('admin.sto-employees.show', $founder).'">Founder Test</a></td>', $founderTableHtml);
    }

    public function test_employee_can_be_linked_to_access_account(): void
    {
        $employee = StoEmployee::query()->create([
            'cash_employee_name' => 'Access Link Test',
            'last_name' => 'Access Link Test',
            'position' => 'Sales',
            'is_active' => true,
        ]);
        $accessUser = User::factory()->create([
            'name' => 'Access User',
            'email' => 'access-user@example.test',
            'role' => User::ROLE_WAREHOUSE_WORKER,
            'is_active' => true,
        ]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.sto-employees.edit', $employee))
            ->assertOk()
            ->assertSee('Аккаунт доступа')
            ->assertSee('access-user@example.test');

        $this->actingAs($this->adminUser())
            ->put(route('admin.sto-employees.update', $employee), [
                'cash_employee_name' => $employee->cash_employee_name,
                'position' => $employee->position,
                'rate' => null,
                'bonus_calculation' => null,
                'start_date' => null,
                'is_active' => '1',
                'user_id' => $accessUser->id,
            ])
            ->assertRedirect(route('admin.sto-employees.index'));

        $this->assertDatabaseHas('sto_employees', [
            'id' => $employee->id,
            'user_id' => $accessUser->id,
        ]);
    }

    public function test_employee_index_shows_access_account_role_under_email(): void
    {
        $accessUser = User::factory()->create([
            'name' => 'Warehouse Access User',
            'email' => 'warehouse-access@example.test',
            'role' => User::ROLE_WAREHOUSE_WORKER,
            'is_active' => true,
        ]);
        StoEmployee::query()->create([
            'cash_employee_name' => 'Access Role List Test',
            'last_name' => 'Access Role List Test',
            'position' => 'Sales',
            'is_active' => true,
            'user_id' => $accessUser->id,
        ]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.sto-employees.index'))
            ->assertOk()
            ->assertSeeInOrder([
                'warehouse-access@example.test',
                $accessUser->roleLabel(),
            ])
            ->assertDontSee('Warehouse Access User');
    }

    public function test_employee_card_shows_access_login_hidden_password_and_role(): void
    {
        $accessUser = User::factory()->create([
            'name' => 'Access User',
            'email' => 'access-user@example.test',
            'role' => User::ROLE_WAREHOUSE_WORKER,
            'is_active' => true,
        ]);
        $employee = StoEmployee::query()->create([
            'cash_employee_name' => 'Access Card Test',
            'last_name' => 'Access Card Test',
            'position' => 'Sales',
            'is_active' => true,
            'user_id' => $accessUser->id,
        ]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.sto-employees.show', $employee))
            ->assertOk()
            ->assertSee('Логин')
            ->assertSee('access-user@example.test')
            ->assertSee('data-access-login-edit', false)
            ->assertSee('data-access-login-dialog', false)
            ->assertSee('Пароль')
            ->assertSee('••••••••')
            ->assertSee('data-access-password-edit', false)
            ->assertSee('data-access-password-dialog', false)
            ->assertSee('Изменить пароль')
            ->assertSee('Роль доступа')
            ->assertSee($accessUser->roleLabel())
            ->assertSee('Редактировать');
    }

    public function test_employee_access_login_can_be_changed(): void
    {
        $accessUser = User::factory()->create([
            'email' => 'old-login@example.test',
            'role' => User::ROLE_WAREHOUSE_WORKER,
            'is_active' => true,
        ]);
        $employee = StoEmployee::query()->create([
            'cash_employee_name' => 'Access Login Test',
            'last_name' => 'Access Login Test',
            'is_active' => true,
            'user_id' => $accessUser->id,
        ]);

        $this->actingAs($this->adminUser())
            ->patch(route('admin.sto-employees.access-login.update', $employee), [
                'email' => 'new-login@example.test',
            ])
            ->assertRedirect(route('admin.sto-employees.show', $employee));

        $this->assertSame('new-login@example.test', $accessUser->refresh()->email);
    }

    public function test_employee_access_password_can_be_changed(): void
    {
        $accessUser = User::factory()->create([
            'password' => Hash::make('old-password'),
            'role' => User::ROLE_WAREHOUSE_WORKER,
            'is_active' => true,
        ]);
        $employee = StoEmployee::query()->create([
            'cash_employee_name' => 'Access Password Test',
            'last_name' => 'Access Password Test',
            'is_active' => true,
            'user_id' => $accessUser->id,
        ]);

        $this->actingAs($this->adminUser())
            ->patch(route('admin.sto-employees.access-password.update', $employee), [
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])
            ->assertRedirect(route('admin.sto-employees.show', $employee));

        $this->assertTrue(Hash::check('new-password-123', $accessUser->refresh()->password));
    }

    private function adminUser(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
    }
}
