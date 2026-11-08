<?php

namespace Abdalla\ReportGenerator\Contracts;

/**
 * Where published reports' permissions live. The package creates one
 * permission per published report and gives it to the chosen roles; who
 * holds it is then checked through the Gate like any other ability.
 *
 * spatie/laravel-permission is supported out of the box (SpatiePermissionStore);
 * other systems implement this and set report-generator.permissions.store.
 */
interface PermissionStore
{
    /** @return list<array{id: string, name: string}> roles a report can be given to */
    public function roles(): array;

    /** Creates the permission if it does not exist. */
    public function ensure(string $permission): void;

    /**
     * Makes exactly these roles hold the permission.
     *
     * @param  list<string>  $roles  role names
     */
    public function syncRoles(string $permission, array $roles): void;

    /** @return list<string> names of the roles holding the permission */
    public function rolesWith(string $permission): array;
}
