<header class="flex items-center gap-4 border-b border-[var(--vg-line)] bg-[var(--vg-navy)] px-6 py-3">
    <a href="{{ route('today') }}" class="lg:hidden" aria-label="{{ config('brand.name') }} home">
        <x-brand.lockup size="h-6" />
    </a>

    <form role="search" class="relative hidden min-w-0 flex-1 md:block" action="{{ route('today') }}">
        <label for="site-search" class="sr-only">Search teams, leagues or games</label>
        <x-app.icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--vg-slate)]" />
        <input id="site-search" name="q" type="search"
               placeholder="Search teams, leagues, or games..."
               class="w-full rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] py-2 pl-9 pr-3 text-sm text-[var(--vg-paper)] placeholder:text-[var(--vg-slate)]">
    </form>

    <div class="ml-auto flex items-center gap-3">
        {{--
            The live badge reflects the sync, not a decoration. It only
            says Live when the data behind the page actually is.
        --}}
        @php($stale = app(App\Services\EdgeLab\SyncHealth::class)->anyStale(['board']))

        <span @class([
            'badge',
            'badge-edge' => ! $stale,
            'badge-warning' => $stale,
        ])>
            <span @class([
                'inline-block h-1.5 w-1.5 rounded-full',
                'bg-[var(--vg-edge)]' => ! $stale,
                'bg-[var(--vg-warning)]' => $stale,
            ])></span>
            {{ $stale ? 'Delayed' : 'Live' }}
        </span>

        <x-theme-toggle />

        <a href="{{ route('alerts') }}" class="text-[var(--vg-slate)] hover:text-[var(--vg-paper)]" aria-label="Alerts">
            <x-app.icon name="bell" class="h-5 w-5" />
        </a>

        <a href="{{ route('account') }}"
           class="flex h-8 w-8 items-center justify-center rounded-full bg-[var(--vg-slate-dark)] text-xs font-bold"
           aria-label="Account"
           title="{{ auth()->user()?->email }}">
            {{ str(auth()->user()?->name ?? '')->explode(' ')->take(2)->map(fn ($p) => str($p)->substr(0, 1))->implode('') ?: 'VG' }}
        </a>

        {{--
            Sign out, visible on every page rather than buried in
            Account. A product that takes a customer two clicks to leave
            is being rude about it, and on a gambling product that is
            worse than rude.

            A POST, because a GET that logs somebody out can be fired by
            any image tag on any page they visit.
        --}}
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit"
                    class="rounded-lg border border-[var(--vg-line)] px-3 py-1.5 text-xs font-semibold text-[var(--vg-slate)] transition hover:text-[var(--vg-paper)]">
                Sign out
            </button>
        </form>
    </div>
</header>
