<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Give every existing account the role it already had in practice
|--------------------------------------------------------------------------
|
| Roles arrive after three accounts already exist, so the deploy that
| introduces them must not be the deploy that locks their owner out.
|
| The mapping is the one the data already implies: everybody is a USER,
| and anybody carrying is_admin is additionally an ADMIN. Nothing becomes
| a SUPER_ADMIN here - that is the console command's job, and a migration
| that mints the most powerful account in the system is a migration
| nobody can audit after the fact.
|
| Running the seeder from inside a migration is unusual and is the point:
| the roles have to exist before the assignment in the same deploy, and
| `migrate --force` is the only thing that runs on Laravel Cloud.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        (new RolesSeeder)->run();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = (string) config('roles.user');
        $admin = (string) config('roles.admin');

        User::query()->chunkById(200, function ($users) use ($user, $admin): void {
            foreach ($users as $account) {
                if (! $account->hasRole($user)) {
                    $account->assignRole($user);
                }

                // The flag is what the product has been using all along,
                // so it is the truth being migrated from - not a guess.
                if ($account->is_admin && ! $account->hasRole($admin)) {
                    $account->assignRole($admin);
                }
            }
        });
    }

    public function down(): void
    {
        // Roles are removed with the tables by the Spatie migration's own
        // rollback. Stripping assignments here would leave the flag and
        // the roles disagreeing for however long the rollback took.
    }
};
