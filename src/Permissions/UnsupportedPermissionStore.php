<?php

namespace Abdalla\ReportGenerator\Permissions;

use Abdalla\ReportGenerator\Contracts\PermissionStore;
use Abdalla\ReportGenerator\Query\QueryException;

/** Used when no permission system is configured: publishing is unavailable. */
final class UnsupportedPermissionStore implements PermissionStore
{
    public function roles(): array
    {
        return [];
    }

    public function ensure(string $permission): void
    {
        $this->fail();
    }

    public function syncRoles(string $permission, array $roles): void
    {
        $this->fail();
    }

    public function rolesWith(string $permission): array
    {
        return [];
    }

    private function fail(): never
    {
        throw new QueryException('PUBLISHING_UNAVAILABLE', 'Publishing needs spatie/laravel-permission or a configured report-generator.permissions.store.', status: 422);
    }
}
