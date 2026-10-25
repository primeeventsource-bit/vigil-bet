@php
    /*
     * The nav, in the order the design draws it. `match` is the prefix a
     * route has to start with for the item to read as current - computed
     * here rather than in the markup so "current" is one rule rather than
     * eight copies of one.
     *
     * `feature` is the entitlement the item needs. An item the customer
     * cannot use is still shown, carrying a lock and pointing at the
     * upgrade page: hiding it would leave them wondering whether the
     * product has the feature at all. The route behind it is gated
     * separately, by middleware, because this list is decoration.
     */
    /*
     * What a bettor actually uses: the picks, what happened to them,
     * their own money, and the settings behind both.
     *
     * Everything that explains the engine to itself has come off this
     * list - the model lab, the decline breakdowns, the crossbook
     * tooling, the prop board that is empty because props are not
     * modelled. A customer paying for picks does not need the machinery
     * that produced them, and a nav full of pages that answer nothing is
     * worse than a short one: every empty page is a small promise the
     * product failed to keep.
     *
     * None of it is deleted. The routes still work and still carry their
     * own middleware; they are listed under $engine below and shown to
     * whoever runs the product. Deleting a working feature to tidy a
     * sidebar would be throwing away the thing rather than the link.
     */
    $items = [
        ['route' => 'today', 'label' => 'Dashboard', 'icon' => 'grid'],
        ['route' => 'live-odds', 'label' => 'Live Odds', 'icon' => 'activity', 'feature' => 'board.full'],
        ['route' => 'games', 'label' => "Today's Games", 'icon' => 'grid', 'feature' => 'parlay.grade'],
        ['route' => 'parlays', 'label' => 'Parlay Builder', 'icon' => 'layers', 'feature' => 'parlay.grade'],
        ['route' => 'my-parlays', 'label' => 'My Parlays', 'icon' => 'file', 'feature' => 'parlay.grade'],
        ['route' => 'opinions', 'label' => 'Results', 'icon' => 'file'],
        ['route' => 'bets', 'label' => 'My Bets', 'icon' => 'file'],
        ['route' => 'bankroll', 'label' => 'Bankroll', 'icon' => 'chart', 'feature' => 'bankroll'],
        ['route' => 'alerts', 'label' => 'Alerts', 'icon' => 'bell', 'feature' => 'alerts'],
        ['route' => 'account', 'label' => 'Settings', 'icon' => 'cog'],
    ];

    /*
     * The engine's own pages. Reachable, and not advertised to a
     * customer who did not buy a model lab.
     */
    $engine = [
        /*
         * The board is every candidate the engine judged, with the
         * reason it declined each one. That is a diagnostic, not a picks
         * page - on a normal day it is twenty thousand rows that all say
         * pass - and it was reaching customers under the label "AI
         * Picks", which is the one thing it is not.
         *
         * A customer's picks are on the dashboard, published and ready
         * to act on, with the record behind them under Results.
         */
        ['route' => 'picks', 'label' => 'Board', 'icon' => 'target', 'feature' => 'board.full'],
        ['route' => 'analytics', 'label' => 'Analytics', 'icon' => 'chart', 'feature' => 'bets.clv'],
        ['route' => 'models', 'label' => 'Models', 'icon' => 'cpu', 'feature' => 'modellab'],
        ['route' => 'props', 'label' => 'Props', 'icon' => 'target', 'feature' => 'props'],
        ['route' => 'tools', 'label' => 'Tools', 'icon' => 'search', 'feature' => 'tools.crossbook'],
        ['route' => 'reports', 'label' => 'Reports', 'icon' => 'file'],
    ];

    if (auth()->user()?->isAdmin()) {
        $items = array_merge($items, $engine);
    }

    /*
     * Admin, for whoever runs the product. Appended rather than listed
     * above so a customer's nav is not built around a link they will
     * never see - and the route 404s for them anyway.
     */
    if (auth()->user()?->isAdmin()) {
        $items[] = ['route' => 'admin.overview', 'label' => 'Admin', 'icon' => 'cog'];
    }
@endphp

<aside class="hidden w-56 shrink-0 flex-col border-r border-[var(--vg-line)] bg-[var(--vg-navy)] lg:flex">
    <div class="px-5 py-4">
        <a href="{{ route('today') }}" aria-label="{{ config('brand.name') }} home">
            <x-brand.lockup size="h-7" />
        </a>
    </div>

    <nav aria-label="Main" class="flex-1 px-3 pb-4">
        <ul class="space-y-1">
            @foreach ($items as $item)
                @php($current = Route::has($item['route']) && request()->routeIs($item['route']))
                @php($locked = isset($item['feature']) && Gate::denies($item['feature']))
                <li>
                    <a
                        @if ($locked)
                            href="{{ route('upgrade', ['feature' => $item['feature']]) }}"
                            title="Not on your plan"
                        @else
                            href="{{ Route::has($item['route']) ? route($item['route']) : '#' }}"
                        @endif
                        @if ($current) aria-current="page" @endif
                        @class([
                            'flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition',
                            'bg-[color-mix(in_srgb,var(--vg-edge)_16%,transparent)] font-semibold text-[var(--vg-edge)]' => $current,
                            'text-[var(--vg-slate)] hover:bg-[var(--vg-slate-dark)] hover:text-[var(--vg-paper)]' => ! $current,
                        ])>
                        <x-app.icon :name="$item['icon']" class="h-4 w-4 shrink-0" />
                        <span @class(['flex-1', 'opacity-60' => $locked])>{{ $item['label'] }}</span>
                        @if ($locked)
                            <x-app.icon name="lock" class="h-3.5 w-3.5 shrink-0 text-[var(--vg-slate)]" />
                            <span class="sr-only">— not on your plan</span>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>
</aside>
