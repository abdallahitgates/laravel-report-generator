<?php

namespace Abdalla\ReportGenerator\Permissions;

use Abdalla\ReportGenerator\Contracts\PermissionStore;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\PermissionRegistrar;

/**
 * Report permissions as spatie/laravel-permission permissions, on the guard
 * set in report-generator.permissions.guard (default: the default auth guard).
 * Works with spatie/laravel-permission 5 and 6.
 */
final class SpatiePermissionStore implements PermissionStore
{
    public function __construct(private readonly ?string $guard = null)
    {
    }

    public function roles(): array
    {
        return $this->roleModel()::query()
            ->where('guard_name', $this->guard())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Model $role) => ['id' => (string) $role->getKey(), 'name' => (string) $role->name])
            ->all();
    }

    public function ensure(string $permission): void
    {
        $this->permissionModel()::findOrCreate($permission, $this->guard());
        $this->forgetCache();
    }

    public function syncRoles(string $permission, array $roles): void
    {
        $model = $this->permissionModel()::findOrCreate($permission, $this->guard());
        $ids = $this->roleModel()::query()
            ->where('guard_name', $this->guard())
            ->whereIn('name', $roles)
            ->pluck((new ($this->roleModel())())->getKeyName())
            ->all();

        $model->roles()->sync($ids);
        $this->forgetCache();
    }

    public function rolesWith(string $permission): array
    {
        $model = $this->permissionModel()::query()
            ->where('name', $permission)
            ->where('guard_name', $this->guard())
            ->first();

        return $model ? $model->roles()->orderBy('name')->pluck('name')->map(fn ($n) => (string) $n)->all() : [];
    }

    private function guard(): string
    {
        return $this->guard ?? (string) config('auth.defaults.guard', 'web');
    }

    /** @return class-string<Model> */
    private function permissionModel(): string
    {
        // From config rather than PermissionRegistrar: v5 returns an instance there, v6 a class name.
        return config('permission.models.permission', \Spatie\Permission\Models\Permission::class);
    }

    /** @return class-string<Model> */
    private function roleModel(): string
    {
        return config('permission.models.role', \Spatie\Permission\Models\Role::class);
    }

    private function forgetCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
