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
     * The first nine are the reference design's nav, in its order.
     * "Results" there is this product's opinion record: every published
     * opinion and how it turned out, which is the page that makes the
     * picks worth reading.
     *
     * The rest follow because the product has them and the reference
     * simply did not draw them. Dropping a working feature to match a
     * mockup would be matching the picture rather than the design.
     */
    $items = [
        ['route' => 'today', 'label' => 'Dashboard', 'icon' => 'grid'],
        ['route' => 'live-odds', 'label' => 'Live Odds', 'icon' => 'activity', 'feature' => 'board.full'],
        ['route' => 'picks', 'label' => 'AI Picks', 'icon' => 'target', 'feature' => 'board.full'],
        ['route' => 'parlays', 'label' => 'Parlays', 'icon' => 'layers', 'feature' => 'parlay.grade'],
        ['route' => 'bankroll', 'label' => 'Bankroll', 'icon' => 'chart', 'feature' => 'bankroll'],
        ['route' => 'analytics', 'label' => 'Analytics', 'icon' => 'chart', 'feature' => 'bets.clv'],
        ['route' => 'models', 'label' => 'Models', 'icon' => 'cpu', 'feature' => 'modellab'],
        ['route' => 'opinions', 'label' => 'Results', 'icon' => 'file'],
        ['route' => 'account', 'label' => 'Settings', 'icon' => 'cog'],

        ['route' => 'props', 'label' => 'Props', 'icon' => 'target', 'feature' => 'props'],
        ['route' => 'tools', 'label' => 'Tools', 'icon' => 'search', 'feature' => 'tools.crossbook'],
        ['route' => 'bets', 'label' => 'My Bets', 'icon' => 'file'],
        ['route' => 'alerts', 'label' => 'Alerts', 'icon' => 'bell', 'feature' => 'alerts'],
        ['route' => 'reports', 'label' => 'Reports', 'icon' => 'file'],
    ];

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
