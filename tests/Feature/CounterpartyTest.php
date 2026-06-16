<?php

namespace Tests\Feature;

use App\Models\Counterparty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CounterpartyTest extends TestCase
{
    use RefreshDatabase;

    public function test_counterparties_cannot_be_deleted_from_admin(): void
    {
        $this->assertFalse(Route::has('admin.counterparties.destroy'));
    }

    public function test_counterparties_index_does_not_show_delete_form(): void
    {
        $user = $this->adminUser();

        $counterparty = Counterparty::query()->create([
            'type' => 'customer',
            'name' => 'Service Client',
            'phone' => '+380991112233',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.counterparties.index'))
            ->assertOk()
            ->assertDontSee('<input type="hidden" name="_method" value="DELETE">', false);

        $this->actingAs($user)
            ->delete(route('admin.counterparties.show', $counterparty))
            ->assertStatus(405);

        $this->assertDatabaseHas('counterparties', [
            'id' => $counterparty->id,
            'name' => 'Service Client',
        ]);
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
