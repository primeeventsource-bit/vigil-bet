<x-layouts.admin title="Administrators">
    <p class="mt-3 max-w-3xl text-sm text-[var(--vg-slate)]">
        Everyone who can operate the product, and exactly what each of them may do.
        Permissions are per person rather than per role: a team usually needs one
        operator who can issue refunds and several who cannot, and expressing that
        as extra roles ends in a role per person named after the person.
    </p>

    <section class="panel mt-5 p-5">
        <h2 class="text-sm font-bold">{{ config('roles.super_admin') }}</h2>
        <p class="mt-1 max-w-3xl text-xs text-[var(--vg-muted)]">
            Holds every ability without any of them being ticked, so a permission added
            to the product next year is covered without somebody having to come back here.
            It cannot be created from these pages, cannot be edited, suspended, demoted or
            deleted by anybody including another {{ config('roles.super_admin') }},
            and is granted only by <span class="font-[family-name:var(--font-mono)]">php artisan vigil:super-admin</span>
            on the console, where the act leaves a record nobody can quietly undo.
        </p>
    </section>

    <section class="panel mt-5 overflow-hidden">
        <div class="w-full overflow-x-auto">
            <table class="w-full min-w-[820px] text-left text-sm">
                <caption class="sr-only">Administrators and their permissions</caption>
                <thead class="text-xs uppercase tracking-wide text-[var(--vg-slate)]">
                    <tr class="border-b border-[var(--vg-line)]">
                        <th scope="col" class="px-5 py-2.5 font-medium">Operator</th>
                        <th scope="col" class="px-3 py-2.5 font-medium">Role</th>
                        <th scope="col" class="px-3 py-2.5 font-medium">Second factor</th>
                        <th scope="col" class="px-5 py-2.5 font-medium">Last seen</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($admins as $admin)
                        <tr class="border-b border-[var(--vg-line)] last:border-0">
                            <td class="px-5 py-3">
                                <a class="font-medium underline hover:text-[var(--vg-paper)]"
                                   href="{{ route('admin.user', $admin) }}">{{ $admin->name }}</a>
                                <span class="block text-xs text-[var(--vg-slate)]">{{ $admin->email }}</span>
                            </td>
                            <td class="px-3 py-3 font-[family-name:var(--font-mono)] text-[11px]">
                                {{ $admin->roles->pluck('name')->sort()->implode(', ') }}
                            </td>
                            <td class="px-3 py-3 text-xs">
                                @if ($admin->hasTwoFactorEnabled())
                                    <span class="badge badge-edge">Enrolled</span>
                                @else
                                    {{-- Not a nag. Without it they cannot open /admin at all. --}}
                                    <span class="badge badge-warning">Not enrolled</span>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-xs text-[var(--vg-slate)]">
                                {{ $admin->last_login_at?->diffForHumans() ?? 'Never' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    {{--
        One panel per administrator rather than a single grid. A grid of
        ten operators by ten permissions is a hundred checkboxes and one
        Save button, and nobody reading it can tell what they changed.
    --}}
    @foreach ($admins as $admin)
        @continue($admin->isSuperAdmin())

        <section class="panel mt-5 p-5">
            <h2 class="text-sm font-bold">{{ $admin->name }}</h2>
            <p class="font-[family-name:var(--font-mono)] text-xs text-[var(--vg-slate)]">{{ $admin->email }}</p>

            @can('admin-management.grantPermissions', $admin)
                <form method="POST" action="{{ route('admin.admins.permissions', $admin) }}" class="mt-4">
                    @csrf

                    <fieldset>
                        <legend class="text-xs font-bold uppercase tracking-wide text-[var(--vg-slate)]">
                            Permissions
                        </legend>

                        <div class="mt-3 grid gap-2 sm:grid-cols-2">
                            @foreach ($permissions as $name => $description)
                                @php($held = $admin->permissions->contains('name', $name))
                                <label class="flex items-start gap-2 text-xs">
                                    <input type="checkbox" name="permissions[]" value="{{ $name }}"
                                           @checked($held)
                                           class="mt-0.5 rounded border-[var(--vg-line)] bg-[var(--vg-night)]">
                                    <span>
                                        <span class="font-[family-name:var(--font-mono)] text-[11px] text-[var(--vg-paper)]">{{ $name }}</span>
                                        <span class="block text-[var(--vg-muted)]">{{ $description }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>

                        <p class="mt-2 text-[11px] text-[var(--vg-muted)]">
                            Unticking withdraws. The form posts the complete set, so whatever is not
                            ticked here is taken away.
                        </p>
                    </fieldset>

                    <div class="mt-4 flex flex-wrap items-end gap-2">
                        <label class="block min-w-[14rem] flex-1 text-xs">
                            <span class="text-[var(--vg-slate)]">Reason (recorded)</span>
                            <input name="reason" required minlength="8" maxlength="200"
                                   placeholder="Why this is being done"
                                   class="mt-1 w-full rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
                        </label>
                        <button type="submit"
                                class="rounded-lg border border-[var(--vg-line)] px-4 py-2 text-xs font-semibold hover:text-[var(--vg-paper)]">
                            Save permissions
                        </button>
                    </div>
                </form>
            @endcan

            @can('admin-management.revoke', $admin)
                <div class="mt-4 border-t border-[var(--vg-line)] pt-4">
                    <p class="text-xs text-[var(--vg-muted)]">
                        Revoking leaves the account and its history intact; it simply stops being an
                        operator. Every open session ends immediately.
                    </p>
                    <x-admin.reasoned-form :action="route('admin.admins.revoke', $admin)"
                                           label="Revoke administrator access"
                                           confirm="Revoke administrator access and end their sessions?" />
                </div>
            @endcan
        </section>
    @endforeach
</x-layouts.admin>
