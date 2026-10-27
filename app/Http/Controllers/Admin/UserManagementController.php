<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creating accounts, changing what they are, and locking them.
 *
 * Built to the same bargain as AdminActionController, because it is the
 * same kind of power: every write takes a typed reason of at least eight
 * characters and writes an audit row. An account suspended with no note
 * is indistinguishable from a bug a month later.
 *
 * Authorization is through the policies, not through the middleware that
 * got you to the page. `admin` middleware says you may see this area;
 * UserPolicy says whether you may do this thing to this person. The
 * difference matters most for the rule the spec cares about: an ADMIN
 * reaches these routes and is still refused every move against a
 * SUPER_ADMIN.
 *
 * Two capabilities are missing on purpose and have no route:
 *
 * - Deletion. An account carries bets, a public record and a
 *   self-exclusion history. Deleting one destroys evidence, so the
 *   product suspends instead, which is reversible and leaves the trail.
 *
 * - Impersonation. On a gambling product it means an operator can place
 *   a bet the audit log will attribute to a customer.
 *
 * UserPolicy returns false for both, so even a Super Admin is refused.
 */
class UserManagementController extends Controller
{
    /**
     * Create an account and invite it to set its own password.
     *
     * No password is set here and none is shown. Ownership of the
     * mailbox is the proof of identity, the same proof every reset in
     * the product already relies on - and a password typed by an
     * operator is one two people know.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', User::class);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:60'],
            'last_name' => ['required', 'string', 'max:60'],
            // Unique, cited explicitly because the spec asks for it and
            // because the column's own unique index would otherwise
            // surface as a 500 rather than a field error.
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::in([config('roles.admin'), config('roles.user')])],
            'reason' => ['required', 'string', 'min:8', 'max:200'],
        ]);

        $wantsAdmin = $data['role'] === config('roles.admin');

        /*
         * Creating an administrator is a Super Admin act, even though
         * creating a customer is not. Checked here rather than left to
         * the role select, because a select is a suggestion and a
         * request body is whatever somebody sends.
         */
        if ($wantsAdmin) {
            abort_unless($request->user()->can('admin-management.create'), 404);
        }

        $user = new User(['name' => trim($data['first_name'].' '.$data['last_name'])]);
        $user->email = mb_strtolower(trim($data['email']));
        // Unguessable and never communicated. The invitation is the only
        // way in; a null here would be read as "no password set" by some
        // code paths and as an unmatchable hash by others.
        $user->password = Str::password(40);
        $user->save();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user->assignRole(config('roles.user'));

        if ($wantsAdmin) {
            $user->assignRole(config('roles.admin'));
        }

        $user->load('roles');
        $user->syncAdminFlag();

        Password::sendResetLink(['email' => $user->email]);

        Audit::write('user.created', $user, null, [
            'email' => $user->email,
            'role' => $data['role'],
        ], $data['reason']);

        return redirect()->route('admin.user', $user)
            ->with('status', 'Account created. An activation link has been sent to '.$user->email.'.');
    }

    /** Name and email, which is all an operator has any business editing. */
    public function update(Request $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:60'],
            'last_name' => ['required', 'string', 'max:60'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->getKey())],
            'reason' => ['required', 'string', 'min:8', 'max:200'],
        ]);

        $before = ['name' => $user->name, 'email' => $user->email];

        $user->name = trim($data['first_name'].' '.$data['last_name']);
        $email = mb_strtolower(trim($data['email']));

        /*
         * Changing the address un-verifies it. The old verification
         * proved the old mailbox; carrying it over would mean an
         * operator could point an account at an address nobody has
         * proved they own and leave it looking verified.
         */
        if ($email !== $user->email) {
            $user->email = $email;
            $user->email_verified_at = null;
        }

        $user->save();

        Audit::write('user.updated', $user, $before, [
            'name' => $user->name,
            'email' => $user->email,
        ], $data['reason']);

        return back()->with('status', 'Account updated.');
    }

    /**
     * Change somebody's role. Super Admin only, enforced by the policy.
     */
    public function role(Request $request, User $user): RedirectResponse
    {
        $this->authorize('assignRole', $user);

        $data = $request->validate([
            'role' => ['required', Rule::in([config('roles.admin'), config('roles.user')])],
            'reason' => ['required', 'string', 'min:8', 'max:200'],
        ]);

        $before = $user->roles->pluck('name')->sort()->values()->all();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // USER is kept whatever else changes - a Super Admin is still a
        // person with an account, and code that reads the member role
        // should always find one.
        $user->syncRoles(array_values(array_unique(
            [config('roles.user'), ...($data['role'] === config('roles.admin') ? [config('roles.admin')] : [])]
        )));

        $user->load('roles');
        $user->syncAdminFlag();

        /*
         * A demotion must not leave an open session holding the admin
         * pages. The flag is read per request so the next one would be
         * refused anyway - but "the next request" is whenever they
         * choose, and a withdrawn role should take effect now.
         */
        if ($data['role'] !== config('roles.admin')) {
            $user->revokeSessions();
        }

        Audit::write('user.role_changed', $user, ['roles' => $before], [
            'roles' => $user->roles->pluck('name')->sort()->values()->all(),
        ], $data['reason']);

        return back()->with('status', 'Role updated.');
    }

    /** Lock an account out, and end every session it has open. */
    public function suspend(Request $request, User $user): RedirectResponse
    {
        $this->authorize('suspend', $user);

        $data = $request->validate([
            // Shown to the person on the sign-in page, so it has to read
            // as a sentence rather than as an internal note.
            'reason' => ['required', 'string', 'min:8', 'max:200'],
        ]);

        $user->forceFill([
            'suspended_at' => now(),
            'suspended_reason' => $data['reason'],
            'suspended_by' => $request->user()->getKey(),
        ])->save();

        $killed = $user->revokeSessions();

        Audit::write('user.suspended', $user, ['suspended' => false], [
            'suspended' => true,
            'sessions_ended' => $killed,
        ], $data['reason']);

        return back()->with('status', 'Account suspended and '.$killed.' session(s) ended.');
    }

    public function reinstate(Request $request, User $user): RedirectResponse
    {
        $this->authorize('suspend', $user);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:8', 'max:200'],
        ]);

        $was = $user->suspended_reason;

        $user->forceFill([
            'suspended_at' => null,
            'suspended_reason' => null,
            'suspended_by' => null,
        ])->save();

        Audit::write('user.reinstated', $user, ['suspended' => true, 'was' => $was], [
            'suspended' => false,
        ], $data['reason']);

        return back()->with('status', 'Account reinstated.');
    }

    /** Sign somebody out everywhere, without suspending them. */
    public function revokeSessions(Request $request, User $user): RedirectResponse
    {
        $this->authorize('revokeSessions', $user);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:8', 'max:200'],
        ]);

        $killed = $user->revokeSessions();

        Audit::write('user.sessions_revoked', $user, null, [
            'sessions_ended' => $killed,
        ], $data['reason']);

        return back()->with('status', $killed.' session(s) ended.');
    }

    /**
     * Send a reset link. Never set a password on somebody's behalf.
     */
    public function sendReset(Request $request, User $user): RedirectResponse
    {
        $this->authorize('resetPassword', $user);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:8', 'max:200'],
        ]);

        $status = Password::sendResetLink(['email' => $user->email]);

        Audit::write('user.reset_sent', $user, null, [
            'status' => $status,
        ], $data['reason']);

        return back()->with('status', $status === Password::RESET_LINK_SENT
            ? 'Reset link sent to '.$user->email.'.'
            : 'Reset link not sent: '.$status);
    }
}
