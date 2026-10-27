<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Administering the administrators.
 *
 * Behind `super-admin` middleware, which 404s exactly as `admin` does -
 * so an ordinary ADMIN does not learn that this page exists, let alone
 * that they are not allowed to open it.
 *
 * The permissions here are per-person, not per-role. The ADMIN role
 * carries a read-leaning default set from config('roles.admin_defaults'),
 * and a Super Admin ticks additional boxes for an individual. That is
 * deliberate: a team usually needs one person who can issue refunds and
 * several who cannot, and expressing that as more roles ends in a role
 * per person named after the person.
 *
 * SUPER_ADMIN does not appear in the grid. Its authority comes from the
 * Gate::before in VigilServiceProvider rather than from a permission
 * list, so there is nothing here to tick - and rendering empty
 * checkboxes beside the most powerful role in the system would invite
 * somebody to tick them and conclude they had changed something.
 */
class AdminManagementController extends Controller
{
    public function index(): View
    {
        $this->authorize('admin-management.viewAny');

        return view('admin.admins', [
            'admins' => User::query()
                ->whereHas('roles', fn ($q) => $q->whereIn('name', [
                    config('roles.super_admin'),
                    config('roles.admin'),
                ]))
                ->with(['roles', 'permissions'])
                ->orderByDesc('is_admin')
                ->orderBy('name')
                ->get(),
            'permissions' => (array) config('roles.permissions'),
            'defaults' => (array) config('roles.admin_defaults'),
        ]);
    }

    /**
     * The roles and what each one carries, read-only.
     *
     * A reference page rather than an editor. Roles are defined in
     * config/roles.php and seeded from it, so editing them here would
     * create a second source of truth that a deploy would silently
     * overwrite the next time the seeder ran.
     */
    public function roles(): View
    {
        $this->authorize('admin-management.viewAny');

        return view('admin.roles', [
            'roles' => Role::with('permissions')->orderBy('name')->get(),
            'permissions' => (array) config('roles.permissions'),
            'superAdmin' => (string) config('roles.super_admin'),
        ]);
    }

    /**
     * Set exactly which permissions one administrator holds.
     *
     * A full sync rather than a grant: the form posts the complete set
     * of ticked boxes, so an unticked box is a withdrawal. Granting one
     * at a time would mean the page could only ever add.
     */
    public function permissions(Request $request, User $user): RedirectResponse
    {
        $this->authorize('admin-management.grantPermissions', $user);

        $data = $request->validate([
            'permissions' => ['array'],
            'permissions.*' => ['string', 'distinct'],
            'reason' => ['required', 'string', 'min:8', 'max:200'],
        ]);

        $allowed = array_keys((array) config('roles.permissions'));
        $wanted = array_values(array_intersect($data['permissions'] ?? [], $allowed));

        $before = $user->permissions->pluck('name')->sort()->values()->all();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user->syncPermissions(
            Permission::whereIn('name', $wanted)->get()
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Audit::write('admin.permissions_changed', $user, ['permissions' => $before], [
            'permissions' => $wanted,
        ], $data['reason']);

        return back()->with('status', 'Permissions updated for '.$user->email.'.');
    }

    /**
     * Take the ADMIN role away, leaving an ordinary account behind.
     *
     * Not a deletion. The person keeps their account, their bets and
     * their history; they simply stop being an operator. Their sessions
     * end immediately, because a withdrawn role that waits for somebody
     * to close their laptop is not withdrawn.
     */
    public function revoke(Request $request, User $user): RedirectResponse
    {
        $this->authorize('admin-management.revoke', $user);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:8', 'max:200'],
        ]);

        $before = $user->roles->pluck('name')->sort()->values()->all();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user->removeRole(config('roles.admin'));
        $user->syncPermissions([]);

        $user->load('roles');
        $user->syncAdminFlag();

        $killed = $user->revokeSessions();

        Audit::write('admin.revoked', $user, ['roles' => $before], [
            'roles' => $user->roles->pluck('name')->sort()->values()->all(),
            'sessions_ended' => $killed,
        ], $data['reason']);

        return back()->with('status', 'Administrator access revoked for '.$user->email.'.');
    }
}
