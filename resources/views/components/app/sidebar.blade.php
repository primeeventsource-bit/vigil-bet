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
     * own middleware; they are listed under the engine group below and
     * shown to whoever runs the product. Deleting a working feature to
     * tidy a sidebar would be throwing away the thing rather than the
     * link.
     */
    $platform = [
        ['route' => 'today', 'label' => 'Dashboard', 'icon' => 'grid'],
        ['route' => 'live-odds', 'label' => 'Live Odds', 'icon' => 'activity', 'feature' => 'board.full'],
        ['route' => 'games', 'label' => "Today's Games", 'icon' => 'grid', 'feature' => 'parlay.grade'],
        ['route' => 'parlays', 'label' => 'Parlay Builder', 'icon' => 'layers', 'feature' => 'parlay.grade'],
        ['route' => 'my-parlays', 'label' => 'My Parlays', 'icon' => 'file', 'feature' => 'parlay.grade'],
        /*
         * Odds comparison and Player IQ are customer pages, not engine
         * diagnostics, so they belong in this list rather than the one
         * below. Both carry a feature key, so a plan that does not
         * include them shows a lock pointing at the upgrade page -
         * which is this nav's existing bargain, not a new one.
         */
        ['route' => 'odds', 'label' => 'Odds Comparison', 'icon' => 'search', 'feature' => 'lineshopping'],
        ['route' => 'player-iq', 'label' => 'Player IQ', 'icon' => 'target', 'feature' => 'playeriq'],
        /*
         * Results was pointing at the public marketing page, which
         * showed the engine's record and never the customer's own. It
         * now points at the member page that shows both.
         */
        ['route' => 'performance', 'label' => 'Results', 'icon' => 'file'],
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
        ['route' => 'models', 'label' => 'Models', 'icon' => 'cpu', 'feature' => 'modellab'],
        ['route' => 'props', 'label' => 'Props', 'icon' => 'target', 'feature' => 'props'],
        ['route' => 'tools', 'label' => 'Tools', 'icon' => 'search', 'feature' => 'tools.crossbook'],
    ];

    /*
     * Grouped rather than run together, which the reference layout does
     * and which matters most for whoever runs the product: an admin's
     * nav is seventeen items, and a flat seventeen reads as a list to
     * search rather than a structure to navigate. A customer sees one
     * group and no headings they have to skip past.
     */
    $groups = [['label' => null, 'items' => $platform]];

    if (auth()->user()?->isAdmin()) {
        $groups[] = ['label' => 'Engine', 'items' => $engine];
        $groups[] = ['label' => 'Admin', 'items' => [
            ['route' => 'admin.overview', 'label' => 'Overview', 'icon' => 'cog'],
        ]];
    }

    /*
     * The plan, named rather than sold. The reference draws an upgrade
     * card here promising unlimited model signals and instant Telegram
     * alerts; this says which tier the customer is actually on and links
     * to the prices, because the first version would have been an
     * invented feature list sitting under the real one.
     */
    $tier = auth()->user()?->tier() ?? 'free';
    $tierName = str_replace(
        ':brand',
        (string) config('brand.name'),
        (string) config('plans.tiers.'.$tier.'.name', $tier),
    );
    $tiers = array_keys((array) config('plans.tiers'));
    $onTopTier = $tier === end($tiers);
@endphp

{{--
    Dimmed page behind the drawer. Hidden until the script opens it, and
    `lg:hidden` so it can never cover a desktop sidebar that is simply
    always there.
--}}
<div data-nav-backdrop hidden
     class="fixed inset-0 z-30 bg-black/70 backdrop-blur-sm lg:hidden"></div>

<aside data-nav-panel id="app-nav"
       @class([
           'fixed inset-y-0 left-0 z-40 flex w-64 shrink-0 flex-col border-r border-[var(--vg-line)] bg-[var(--vg-navy)]',
           // Off-canvas until opened, and permanently in place from `lg`.
           // The open state is a CSS rule in app.css, scoped below `lg`,
           // rather than a utility: it has to beat `-translate-x-full`.
           '-translate-x-full transition-transform duration-200 ease-out',
           'lg:static lg:translate-x-0',
       ])>
    <div class="flex items-center justify-between px-5 py-4">
        <a href="{{ route('today') }}" aria-label="{{ config('brand.name') }} home">
            <x-brand.lockup size="h-7" />
        </a>
    </div>

    <nav aria-label="Main" class="flex-1 overflow-y-auto px-3 pb-4">
        @foreach ($groups as $group)
            <div @class(['mt-5' => ! $loop->first])>
                @if ($group['label'])
                    <h2 class="px-3 pb-2 font-[family-name:var(--font-mono)] text-[10px] font-bold uppercase tracking-widest text-[var(--vg-muted)]">
                        {{ $group['label'] }}
                    </h2>
                @endif

                <ul class="space-y-1">
                    @foreach ($group['items'] as $item)
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
            </div>
        @endforeach
    </nav>

    <div class="border-t border-[var(--vg-line)] p-4">
        <div class="rounded-xl border border-[var(--vg-line)] bg-[var(--vg-slate-dark)] p-3">
            <p class="text-[10px] uppercase tracking-widest text-[var(--vg-muted)]">Your plan</p>
            <p class="mt-0.5 font-[family-name:var(--font-display)] text-sm font-bold">{{ $tierName }}</p>

            @unless ($onTopTier)
                <a href="{{ route('pricing') }}"
                   class="mt-2.5 block rounded-lg bg-[var(--vg-paper)] px-3 py-1.5 text-center text-[11px] font-bold text-[var(--vg-night)] transition hover:opacity-90">
                    Compare plans
                </a>
            @endunless
        </div>
    </div>
</aside>
