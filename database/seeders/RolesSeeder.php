<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The three roles and the permissions an ADMIN can be given.
 *
 * Idempotent, and run from a migration as well as by hand: the role
 * names are read from config and created with firstOrCreate, so running
 * it twice changes nothing and running it after a permission is added to
 * config/roles.php adds just that one.
 *
 * SUPER_ADMIN is created with no permissions attached, on purpose. Its
 * authority comes from the Gate::before in VigilServiceProvider, so a
 * permission invented next year is covered without anybody remembering
 * to come back here. Attaching them individually would mean the opposite:
 * a Super Admin silently missing whatever was added last.
 */
class RolesSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (array_keys((array) config('roles.permissions')) as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $superAdmin = Role::findOrCreate((string) config('roles.super_admin'), 'web');
        $admin = Role::findOrCreate((string) config('roles.admin'), 'web');
        Role::findOrCreate((string) config('roles.user'), 'web');

        // Deliberately empty. See the class docblock.
        $superAdmin->syncPermissions([]);

        /*
         * The ADMIN role itself carries the read-leaning defaults. An
         * individual administrator can be given more directly, which is
         * what the Admin Management page does - the role is a floor, not
         * a ceiling.
         */
        $admin->syncPermissions((array) config('roles.admin_defaults'));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
