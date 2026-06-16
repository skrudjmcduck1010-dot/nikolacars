<?php

namespace App\Models;

use App\Models\Concerns\TracksUserStamps;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'is_active', 'extra_permissions', 'created_by', 'updated_by'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable;
    use TracksUserStamps;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_STO_MANAGER = 'sto_manager';

    public const ROLE_WAREHOUSE_WORKER = 'warehouse_worker';

    public const ROLE_WAREHOUSE_LIMITED = 'warehouse_limited';

    public const ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_STO_MANAGER,
        self::ROLE_WAREHOUSE_WORKER,
        self::ROLE_WAREHOUSE_LIMITED,
        'manager',
        'storekeeper',
        'picker',
        'viewer',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'extra_permissions' => 'array',
            'password' => 'hashed',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(self::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(self::class, 'updated_by');
    }

    public function stoEmployee(): HasOne
    {
        return $this->hasOne(StoEmployee::class);
    }

    public function hasPermission(string $permission): bool
    {
        $permissions = $this->allPermissions();

        if ($this->hasLegacyPermissionAlias($permissions, $permission)) {
            return true;
        }

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    /**
     * @return array<int, string>
     */
    public function rolePermissions(): array
    {
        return config("permissions.roles.{$this->role}.permissions", []);
    }

    /**
     * @return array<int, string>
     */
    public function allPermissions(): array
    {
        return array_values(array_unique(array_merge(
            $this->rolePermissions(),
            $this->extra_permissions ?? [],
        )));
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function hasLegacyPermissionAlias(array $permissions, string $permission): bool
    {
        if (in_array('warehouse.manage', $permissions, true)) {
            $warehousePermissions = [
                'mobile_parts.manage',
                'warehouses.manage',
                'locations.manage',
                'stock_items.manage',
                'purchases.manage',
                'movements.view',
                'reservations.manage',
                'stock_actions.manage',
            ];

            if (in_array($permission, $warehousePermissions, true)) {
                return true;
            }
        }

        if (in_array('catalog.manage', $permissions, true)) {
            $catalogPermissions = [
                'nikolacars_catalog.manage',
                'nikolacars_sales.view',
                'categories.manage',
                'brands.manage',
                'products.manage',
                'tesla_catalog.view',
                'part_catalog.view',
                'teslapartsukraine_catalog.view',
                'tsk_catalog.view',
                'stock_tesla_catalog.view',
                'competitors_ru.view',
                'driveparts_catalog.view',
                'dkparts_catalog.view',
                'erazborka_catalog.view',
                'toprazborka_catalog.view',
                'teslawestparts_catalog.view',
                'teslacompany_catalog.view',
            ];

            if (in_array($permission, $catalogPermissions, true)) {
                return true;
            }
        }

        return false;
    }

    public function roleLabel(): string
    {
        return config("permissions.roles.{$this->role}.label", $this->role);
    }
}
