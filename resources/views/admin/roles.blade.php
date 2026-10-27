<x-layouts.admin title="Roles and permissions">
    <p class="mt-3 max-w-3xl text-sm text-[var(--vg-slate)]">
        A reference, not an editor. Roles are defined in
        <span class="font-[family-name:var(--font-mono)] text-xs">config/roles.php</span>
        and seeded from it, so editing them here would create a second source of truth
        that the next deploy would silently overwrite.
    </p>

    {{--
        The distinction that matters most on this page, said before the
        table rather than after it.
    --}}
    <section class="panel mt-5 p-5">
        <h2 class="text-sm font-bold">What these do not control</h2>
        <p class="mt-1 max-w-3xl text-xs text-[var(--vg-muted)]">
            Nothing here grants a plan feature. These permissions answer who may operate the
            product; what a subscription bought is answered separately by
            <span class="font-[family-name:var(--font-mono)]">config/plans.php</span>.
            An operator on the free tier sees exactly what a free customer sees, which is the
            point &mdash; an administrator looking at a different board from the person they are
            helping is an administrator who cannot help them.
        </p>
    </section>

    <section class="panel mt-5 overflow-hidden">
        <div class="w-full overflow-x-auto">
            <table class="w-full min-w-[640px] text-left text-sm">
                <caption class="sr-only">Roles and the permissions each carries</caption>
                <thead class="text-xs uppercase tracking-wide text-[var(--vg-slate)]">
                    <tr class="border-b border-[var(--vg-line)]">
                        <th scope="col" class="px-5 py-2.5 font-medium">Role</th>
                        <th scope="col" class="px-3 py-2.5 font-medium">Holders</th>
                        <th scope="col" class="px-5 py-2.5 font-medium">Carries</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($roles as $role)
                        <tr class="border-b border-[var(--vg-line)] align-top last:border-0">
                            <td class="px-5 py-3 font-[family-name:var(--font-mono)] text-xs text-[var(--vg-paper)]">
                                {{ $role->name }}
                            </td>
                            <td class="px-3 py-3 text-xs text-[var(--vg-slate)]">
                                {{ $role->users()->count() }}
                            </td>
                            <td class="px-5 py-3 text-xs">
                                @if ($role->name === $superAdmin)
                                    <span class="text-[var(--vg-muted)]">
                                        Everything, without listing anything. Granted by a gate rather than
                                        a permission list, so a new permission is covered the day it exists.
                                    </span>
                                @elseif ($role->permissions->isEmpty())
                                    <span class="text-[var(--vg-muted)]">Nothing administrative.</span>
                                @else
                                    <ul class="space-y-1">
                                        @foreach ($role->permissions->sortBy('name') as $permission)
                                            <li>
                                                <span class="font-[family-name:var(--font-mono)] text-[11px] text-[var(--vg-paper)]">{{ $permission->name }}</span>
                                                <span class="block text-[var(--vg-muted)]">
                                                    {{ $permissions[$permission->name] ?? '' }}
                                                </span>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel mt-5 p-5">
        <h2 class="text-sm font-bold">Every permission</h2>
        <p class="mt-1 text-xs text-[var(--vg-muted)]">
            The role sets a floor. A {{ $superAdmin }} may grant any of these to an individual
            operator from the Administrators page.
        </p>

        <ul class="mt-3 grid gap-2 sm:grid-cols-2">
            @foreach ($permissions as $name => $description)
                <li class="text-xs">
                    <span class="font-[family-name:var(--font-mono)] text-[11px] text-[var(--vg-paper)]">{{ $name }}</span>
                    <span class="block text-[var(--vg-muted)]">{{ $description }}</span>
                </li>
            @endforeach
        </ul>
    </section>
</x-layouts.admin>
