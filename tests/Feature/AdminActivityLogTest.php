<?php

namespace Tests\Feature;

use App\Models\AdminActivityLog;
use App\Models\PartCatalogItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_write_actions_are_logged(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->post(route('admin.categories.store'), [
            'name' => 'Bumpers',
            'slug' => 'bumpers',
            'description' => 'Visible in activity log',
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('admin.categories.index'));

        $this->assertDatabaseHas('admin_activity_logs', [
            'user_id' => $user->id,
            'route_name' => 'admin.categories.store',
            'method' => 'POST',
            'status_code' => 302,
        ]);

        $this->assertSame(
            'Bumpers',
            AdminActivityLog::query()->firstOrFail()->payload['name'],
        );
    }

    public function test_admin_view_actions_are_not_logged(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.categories.index'))
            ->assertOk();

        $this->assertDatabaseCount('admin_activity_logs', 0);
    }

    public function test_admin_log_page_is_available(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.activity-logs.index'))
            ->assertOk()
            ->assertSee('Журнал действий')
            ->assertSee('(0)');
    }

    public function test_admin_log_sidebar_shows_action_count(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        AdminActivityLog::query()->create([
            'user_id' => $user->id,
            'action' => 'Создание',
            'route_name' => 'admin.categories.store',
            'method' => 'POST',
            'url' => 'http://localhost/admin/categories',
            'status_code' => 302,
            'payload' => [],
        ]);

        AdminActivityLog::query()->create([
            'user_id' => $user->id,
            'action' => 'Изменение',
            'route_name' => 'admin.categories.update',
            'method' => 'PATCH',
            'url' => 'http://localhost/admin/categories/1',
            'status_code' => 302,
            'payload' => [],
        ]);

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Журнал действий')
            ->assertSee('(2)');
    }

    public function test_tesla_official_status_separates_remaining_and_error_counts(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/us/en/p/checked',
            'part_number' => 'CHECKED-1',
            'name' => 'Checked',
            'raw_attributes' => [
                'tesla_part_search_checked_at' => '2026-05-26T10:00:00+00:00',
                'official_part_match_status' => 'exact',
            ],
        ]);

        PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/us/en/p/api-error',
            'part_number' => 'ERROR-1',
            'name' => 'API error',
            'raw_attributes' => [
                'tesla_part_search_checked_at' => '2026-05-26T10:01:00+00:00',
                'official_part_match_status' => 'api_error',
            ],
        ]);

        $prettyJsonItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/us/en/p/security-blocked',
            'part_number' => 'SECURITY-1',
            'name' => 'Security blocked',
        ]);
        DB::table('part_catalog_items')
            ->where('id', $prettyJsonItem->id)
            ->update([
                'raw_attributes' => json_encode([
                    'tesla_part_search_checked_at' => '2026-05-26T10:02:00+00:00',
                    'official_part_match_status' => 'security_blocked',
                ], JSON_PRETTY_PRINT),
            ]);

        PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/us/en/p/unchecked',
            'part_number' => 'UNCHECKED-1',
            'name' => 'Unchecked',
        ]);

        $this->actingAs($user)
            ->getJson(route('admin.activity-logs.tesla-official.status'))
            ->assertOk()
            ->assertJsonPath('summary.total', 4)
            ->assertJsonPath('summary.checked', 1)
            ->assertJsonPath('summary.checked_total', 3)
            ->assertJsonPath('summary.unchecked', 1)
            ->assertJsonPath('summary.api_error', 1)
            ->assertJsonPath('summary.security_blocked', 1);
    }
}
