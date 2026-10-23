<x-layouts.admin title="Users">
    <form method="GET" action="{{ route('admin.users') }}" class="mt-5 flex flex-wrap items-end gap-3">
        <label class="block text-sm">
            <span class="text-[var(--vg-slate)]">Search name or email</span>
            <input name="q" value="{{ $search }}"
                   class="mt-1 w-64 rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
        </label>
        <button type="submit" class="rounded-lg border border-[var(--vg-line)] px-4 py-2 text-sm font-semibold">
            Search
        </button>
        @if ($search !== '')
            <a href="{{ route('admin.users') }}" class="text-sm text-[var(--vg-slate)] underline">Clear</a>
        @endif
    </form>

    <section class="panel mt-5 overflow-hidden">
        <div class="w-full overflow-x-auto">
            <table class="w-full min-w-[760px] text-left text-sm">
                <caption class="sr-only">Customer accounts</caption>
                <thead class="text-xs uppercase tracking-wide text-[var(--vg-slate)]">
                    <tr class="border-b border-[var(--vg-line)]">
                        <th scope="col" class="px-5 py-2.5 font-medium">Account</th>
                        <th scope="col" class="px-3 py-2.5 font-medium">Plan</th>
                        <th scope="col" class="px-3 py-2.5 font-medium">Status</th>
                        <th scope="col" class="px-3 py-2.5 font-medium">Confirmed</th>
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
                            <td class="px-3 py-3 text-xs">
                                {{ $user->subscription?->effectiveTier() ?? 'free' }}
                                @if ($user->is_admin)
                                    <span class="badge badge-edge ml-1">admin</span>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-xs">
                                {{ $user->subscription?->status ?? '—' }}
                            </td>
                            <td class="px-3 py-3 text-xs">
                                {{ $user->email_verified_at ? 'Yes' : 'No' }}
                            </td>
                            <td class="px-5 py-3 text-xs text-[var(--vg-slate)]">
                                {{ $user->created_at?->toFormattedDateString() }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="px-5 py-4">{{ $users->links() }}</div>
    </section>
</x-layouts.admin>
