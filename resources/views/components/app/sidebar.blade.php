@php
    /*
     * The nav, in the order the design draws it. `match` is the prefix a
     * route has to start with for the item to read as current - computed
     * here rather than in the markup so "current" is one rule rather than
     * eight copies of one.
     */
    $items = [
        ['route' => 'today', 'label' => 'Dashboard', 'icon' => 'grid'],
        ['route' => 'live-odds', 'label' => 'Live Odds', 'icon' => 'activity'],
        ['route' => 'picks', 'label' => 'AI Picks', 'icon' => 'target'],
        ['route' => 'parlays', 'label' => 'Parlays', 'icon' => 'layers'],
        ['route' => 'analytics', 'label' => 'Analytics', 'icon' => 'chart'],
        ['route' => 'models', 'label' => 'Models', 'icon' => 'cpu'],
        ['route' => 'reports', 'label' => 'Reports', 'icon' => 'file'],
        ['route' => 'account', 'label' => 'Settings', 'icon' => 'cog'],
    ];
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
                <li>
                    <a
                        href="{{ Route::has($item['route']) ? route($item['route']) : '#' }}"
                        @if ($current) aria-current="page" @endif
                        @class([
                            'flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition',
                            'bg-[color-mix(in_srgb,var(--vg-edge)_16%,transparent)] font-semibold text-[var(--vg-edge)]' => $current,
                            'text-[var(--vg-slate)] hover:bg-[var(--vg-slate-dark)] hover:text-[var(--vg-paper)]' => ! $current,
                        ])>
                        <x-app.icon :name="$item['icon']" class="h-4 w-4 shrink-0" />
                        {{ $item['label'] }}
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>
</aside>
