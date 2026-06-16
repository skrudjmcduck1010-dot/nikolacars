<?php

namespace Tests\Feature;

use App\Models\PartCatalogItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CompetitorCatalogTableLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_competitor_catalog_table_shows_product_url_link(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-competitor-link@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        PartCatalogItem::query()->create([
            'source' => 'tsk',
            'source_url' => 'tsk-epc:e15b78f24c16675e353e57cbbdc5de56',
            'part_number' => '1466720-00-A',
            'name' => 'Carrier',
            'raw_attributes' => [
                'product_url' => 'https://tsk.ua/1466720-00-a/',
            ],
        ]);

        $this->actingAs($user)
            ->get(route('admin.tsk-catalog.index'))
            ->assertOk()
            ->assertSee('>https://tsk.ua/1466720-00-a/<', false)
            ->assertSee('href="https://tsk.ua/1466720-00-a/"', false)
            ->assertDontSee('href="tsk-epc:e15b78f24c16675e353e57cbbdc5de56"', false);
    }
}
