{{--
    The public navigation.

    Two problems this replaces. It was four links, and it was
    `hidden sm:flex` with nothing behind it - so every phone got the
    logo, a theme toggle and a sign-up button, and no way to reach
    pricing or the record at all. The drawer below is the half that was
    missing.

    It reuses the app drawer's contract exactly: `data-nav-toggle`,
    `data-nav-panel`, `data-nav-backdrop`, and the open state is the rule
    in app.css scoped under `64rem`. resources/js/nav.js is untouched -
    the two layouts are never on the same page, and a second
    implementation of a focus-trapped drawer is a second thing to get
    wrong.

    On the gated items: five of these are real routes behind an
    entitlement, so a signed-out visitor who clicks one lands on the
    sign-in page. That is the funnel working, but only if the link said
    so first - an unmarked link that bounces you to a login screen reads
    as the site being broken. Hence the padlock, and hence it disappearing
    the moment it would be wrong, which is as soon as somebody holds the
    entitlement.
--}}
@php
    /*
     * Built here rather than in the controller because the marketing
     * layout wraps pages served by several controllers, and a nav that
     * depended on view data would be a nav that vanished on whichever
     * page forgot to pass it.
     *
     * `gate` is the entitlement the destination needs, or null for the
     * public ones. It is the key the route's own middleware checks, so
     * the padlock and the redirect cannot disagree.
     */
    $items = [
        ['label' => "Today's Games", 'route' => 'games', 'gate' => 'parlay.grade'],
        // The published opinions: the engine's own calls, on a public
        // page, with their graded history beside them. The honest
        // public answer to "AI Picks" - the member board needs an
        // entitlement, this does not.
        ['label' => 'AI Picks', 'route' => 'opinions', 'gate' => null],
        ['label' => 'Player IQ', 'route' => 'player-iq', 'gate' => 'playeriq'],
        ['label' => 'Odds', 'route' => 'odds', 'gate' => 'lineshopping'],
        ['label' => 'Parlay Builder', 'route' => 'parlays', 'gate' => 'parlay.grade'],
        ['label' => 'Results', 'route' => 'record', 'gate' => null],
        ['label' => 'Pricing', 'route' => 'pricing', 'gate' => null],
    ];

    /*
     * The record 404s while nothing has been published, so the link goes
     * with it rather than pointing at a missing page - the same condition
     * the route itself aborts on.
     */
    $recordExists = \App\Models\VlModel::anyMarketProven()
        || \App\Models\PublishedPick::anyPublished();

    $items = array_values(array_filter(
        $items,
        fn (array $item): bool => $item['route'] !== 'record' || $recordExists,
    ));

    $entitlements = app(\App\Services\Entitlements\Entitlements::class);
    $user = auth()->user();

    // Null gate is open to everyone; otherwise ask the one authority on
    // the question rather than guessing from the tier name.
    $locked = fn (?string $gate): bool => $gate !== null && $entitlements->cannot($user, $gate);

    $trialHref = auth()->check() ? route('trial') : route('register');
@endphp

<header class="sticky top-0 z-50 border-b border-[var(--vg-line)] bg-[color-mix(in_srgb,var(--vg-night)_88%,transparent)] backdrop-blur-md">
    <div class="mx-auto flex max-w-7xl items-center gap-4 px-6 py-3.5">
        {{-- The drawer's handle. Ships hidden; nav.js reveals it. --}}
        <button type="button"
                data-nav-toggle
                hidden
                aria-controls="marketing-nav"
                aria-expanded="false"
                class="-ml-2 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-[var(--vg-slate)] transition hover:bg-[var(--vg-slate-dark)] hover:text-[var(--vg-paper)] lg:hidden">
            <x-app.icon name="menu" class="h-5 w-5" />
            <span class="sr-only">Menu</span>
        </button>

        <a href="{{ route('home') }}" class="shrink-0" aria-label="{{ config('brand.name') }} home">
            <x-brand.lockup size="h-8" />
        </a>

        <nav aria-label="Main" class="ml-2 hidden min-w-0 flex-1 items-center gap-5 text-sm lg:flex">
            @foreach ($items as $item)
                <a href="{{ route($item['route']) }}"
                   class="group inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap text-[var(--vg-slate)] transition hover:text-[var(--vg-paper)]">
                    {{ $item['label'] }}
                    @if ($locked($item['gate']))
                        {{--
                            Decorative: the label beside it is the link,
                            and the words below say what the padlock
                            means once, for everyone.
                        --}}
                        <x-app.icon name="lock" aria-hidden="true"
                                    class="h-3 w-3 shrink-0 text-[var(--vg-muted)] transition group-hover:text-[var(--vg-slate)]" />
                        <span class="sr-only">(members only)</span>
                    @endif
                </a>
            @endforeach
        </nav>

        <div class="ml-auto flex shrink-0 items-center gap-3 text-sm">
            <x-theme-toggle />

            @auth
                <a class="rounded-lg bg-[var(--vg-green)] px-3.5 py-2 font-semibold text-[var(--vg-night)] transition hover:brightness-110"
                   href="{{ route('today') }}">Open {{ config('brand.name') }}</a>
            @else
                <a class="hidden text-[var(--vg-slate)] transition hover:text-[var(--vg-paper)] sm:block"
                   href="{{ route('login') }}">Login</a>

                {{--
                    Written in capitals rather than uppercased in CSS:
                    the specification names this string exactly, and a
                    reader checking the page against it should find the
                    same characters in the markup.
                --}}
                <a class="rounded-lg bg-[var(--vg-green)] px-3.5 py-2 text-xs font-bold tracking-wide text-[var(--vg-night)] transition hover:brightness-110 sm:text-sm"
                   href="{{ $trialHref }}">START FREE TRIAL</a>
            @endauth
        </div>
    </div>
</header>

{{-- Dimmed page behind the drawer. `lg:hidden` so it can never cover the desktop row. --}}
<div data-nav-backdrop hidden
     class="fixed inset-0 z-40 bg-black/70 backdrop-blur-sm lg:hidden"></div>

<div data-nav-panel id="marketing-nav"
     @class([
         'fixed inset-y-0 left-0 z-50 flex w-72 flex-col border-r border-[var(--vg-line)] bg-[var(--vg-navy)] lg:hidden',
         // Off-canvas until opened. The open state is the rule in
         // app.css - it has to beat this translate.
         '-translate-x-full transition-[translate] duration-200 ease-out',
     ])>
    <div class="flex items-center justify-between border-b border-[var(--vg-line)] px-5 py-4">
        <a href="{{ route('home') }}" aria-label="{{ config('brand.name') }} home">
            <x-brand.lockup size="h-7" />
        </a>
    </div>

    <nav aria-label="Main" class="flex-1 overflow-y-auto px-3 py-4">
        <ul role="list" class="space-y-0.5">
            @foreach ($items as $item)
                <li>
                    <a href="{{ route($item['route']) }}"
                       class="flex items-center gap-2 rounded-lg px-3 py-2.5 text-sm text-[var(--vg-slate)] transition hover:bg-[var(--vg-slate-dark)] hover:text-[var(--vg-paper)]">
                        {{ $item['label'] }}
                        @if ($locked($item['gate']))
                            <x-app.icon name="lock" aria-hidden="true" class="h-3 w-3 text-[var(--vg-muted)]" />
                            <span class="sr-only">(members only)</span>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>

        @guest
            {{--
                Said once, in words, for whoever the padlocks are not
                reaching: a screen reader announcing "members only" seven
                times, and anybody who does not read a padlock as a
                padlock.
            --}}
            <p class="mt-4 px-3 text-xs leading-relaxed text-[var(--vg-muted)]">
                A padlock means the page needs an account. Everything else is open.
            </p>
        @endguest
    </nav>

    <div class="space-y-2 border-t border-[var(--vg-line)] p-4">
        @auth
            <a class="block rounded-lg bg-[var(--vg-green)] px-4 py-2.5 text-center text-sm font-bold text-[var(--vg-night)]"
               href="{{ route('today') }}">Open {{ config('brand.name') }}</a>
        @else
            <a class="block rounded-lg bg-[var(--vg-green)] px-4 py-2.5 text-center text-sm font-bold text-[var(--vg-night)]"
               href="{{ $trialHref }}">START FREE TRIAL</a>
            <a class="block rounded-lg border border-[var(--vg-line)] px-4 py-2.5 text-center text-sm font-semibold text-[var(--vg-paper)]"
               href="{{ route('login') }}">Login</a>
        @endauth
    </div>
</div>
