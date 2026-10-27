<x-layouts.admin title="Users">
    {{--
        Search and filters in one GET form, so a filtered list is a URL
        somebody can send to a colleague. The paginator carries the query
        string, which is why withQueryString() is on the controller side.
    --}}
    <form method="GET" action="{{ route('admin.users') }}" class="mt-5 flex flex-wrap items-end gap-3">
        <label class="block text-sm">
            <span class="text-[var(--vg-slate)]">Search name or email</span>
            <input name="q" value="{{ $search }}"
                   class="mt-1 w-56 rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
        </label>

        <label class="block text-sm">
            <span class="text-[var(--vg-slate)]">Role</span>
            <select name="role" class="mt-1 rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
                <option value="">Any</option>
                @foreach ($roles as $name)
                    <option value="{{ $name }}" @selected($role === $name)>{{ $name }}</option>
                @endforeach
            </select>
        </label>

        <label class="block text-sm">
            <span class="text-[var(--vg-slate)]">Plan</span>
            <select name="tier" class="mt-1 rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
                <option value="">Any</option>
                @foreach ($tiers as $key)
                    <option value="{{ $key }}" @selected($tier === $key)>{{ $key }}</option>
                @endforeach
            </select>
        </label>

        <label class="block text-sm">
            <span class="text-[var(--vg-slate)]">Status</span>
            <select name="status" class="mt-1 rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
                <option value="">Any</option>
                <option value="suspended" @selected($status === 'suspended')>Suspended</option>
                <option value="unverified" @selected($status === 'unverified')>Unverified</option>
            </select>
        </label>

        <button type="submit" class="rounded-lg border border-[var(--vg-line)] px-4 py-2 text-sm font-semibold">
            Filter
        </button>

        @if ($search !== '' || $role !== '' || $tier !== '' || $status !== '')
            <a href="{{ route('admin.users') }}" class="text-sm text-[var(--vg-slate)] underline">Clear</a>
        @endif
    </form>

    {{--
        Creating an account. No password is set here and none is shown:
        the new account gets an activation link and chooses its own, the
        same proof of identity every password reset already relies on.

        The role select offers ADMIN only to a Super Admin. The server
        checks it again - a select is a suggestion, a request body is
        whatever somebody sends.
    --}}
    @can('create', App\Models\User::class)
        <section class="panel mt-5 p-5">
            <h2 class="text-sm font-bold">Create an account</h2>
            <p class="mt-1 text-xs text-[var(--vg-muted)]">
                No password is set here. The address receives an activation link and chooses its own.
            </p>

            <x-admin.reasoned-form :action="route('admin.users.store')" label="Create and invite">
                <label class="block text-xs">
                    <span class="text-[var(--vg-slate)]">First name</span>
                    <input name="first_name" required maxlength="60" value="{{ old('first_name') }}"
                           class="mt-1 w-36 rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
                </label>

                <label class="block text-xs">
                    <span class="text-[var(--vg-slate)]">Last name</span>
                    <input name="last_name" required maxlength="60" value="{{ old('last_name') }}"
                           class="mt-1 w-36 rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
                </label>

                <label class="block text-xs">
                    <span class="text-[var(--vg-slate)]">Email</span>
                    <input name="email" type="email" required maxlength="255" value="{{ old('email') }}"
                           class="mt-1 w-56 rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
                </label>

                <label class="block text-xs">
                    <span class="text-[var(--vg-slate)]">Role</span>
                    <select name="role" class="mt-1 rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
                        <option value="{{ config('roles.user') }}">{{ config('roles.user') }}</option>
                        @can('admin-management.create')
                            <option value="{{ config('roles.admin') }}">{{ config('roles.admin') }}</option>
                        @endcan
                    </select>
                </label>
            </x-admin.reasoned-form>
        </section>
    @endcan

    <section class="panel mt-5 overflow-hidden">
        <div class="w-full overflow-x-auto">
            <table class="w-full min-w-[920px] text-left text-sm">
                <caption class="sr-only">Accounts</caption>
                <thead class="text-xs uppercase tracking-wide text-[var(--vg-slate)]">
                    <tr class="border-b border-[var(--vg-line)]">
                        <th scope="col" class="px-5 py-2.5 font-medium">Account</th>
                        <th scope="col" class="px-3 py-2.5 font-medium">Role</th>
                        <th scope="col" class="px-3 py-2.5 font-medium">Plan</th>
                        <th scope="col" class="px-3 py-2.5 font-medium">Status</th>
                        <th scope="col" class="px-3 py-2.5 font-medium">Confirmed</th>
                        <th scope="col" class="px-3 py-2.5 font-medium">Last seen</th>
                        <th scope="col" class="px-5 py-2.5 font-medium">Joined</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($users as $user)
                        <tr class="border-b border-[var(--vg-line)] last:border-0">
                            <td class="px-5 py-3">
                                <a class="font-medium underline hover:text-[var(--vg-paper)]"
                                   href="{{ route('admin.user', $user) }}">{{ $user->name }}</a>
                                <span class="block text-xs text-[var(--vg-slate)]">{{ $user->email }}</span>
                            </td>
                            <td class="px-3 py-3 font-[family-name:var(--font-mono)] text-[11px]">
                                {{ $user->roles->pluck('name')->sort()->implode(', ') ?: '—' }}
                            </td>
                            <td class="px-3 py-3 text-xs">
                                {{ $user->subscription?->effectiveTier() ?? 'free' }}
                            </td>
                            <td class="px-3 py-3 text-xs">
                                @if ($user->isSuspended())
                                    {{-- A word, not only a colour. --}}
                                    <span class="badge badge-loss">Suspended</span>
                                @else
                                    {{ $user->subscription?->status ?? '—' }}
                                @endif
                            </td>
                            <td class="px-3 py-3 text-xs">
                                {{ $user->email_verified_at ? 'Yes' : 'No' }}
                            </td>
                            <td class="px-3 py-3 text-xs text-[var(--vg-slate)]">
                                {{ $user->last_login_at?->diffForHumans() ?? 'Never' }}
                            </td>
                            <td class="px-5 py-3 text-xs text-[var(--vg-slate)]">
                                {{ $user->created_at?->toFormattedDateString() }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($users->isEmpty())
            <p class="px-5 py-6 text-sm text-[var(--vg-muted)]">No account matches those filters.</p>
        @endif

        <div class="px-5 py-4">{{ $users->links() }}</div>
    </section>
</x-layouts.admin>
