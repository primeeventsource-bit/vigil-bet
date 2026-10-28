{{--
    The platform, as six things you can go and look at.

    The hero's "EXPLORE THE PLATFORM" lands here, which is the whole
    reason this section is clickable where the ribbon above it is not.
    The ribbon names what the product does in five words each and sends
    nobody anywhere; this one is the index.

    Status is derived, never typed. Each card asks the same authority the
    route's own middleware asks, so a card cannot advertise something the
    product would then refuse - and the one genuinely unfinished thing
    here, a proven market, is read off the gate rather than described
    from memory. The specification asks for features that are not
    operational to be identified clearly, and the only way that survives
    a release is if the page computes it.
--}}
@php
    $entitlements = app(\App\Services\Entitlements\Entitlements::class);
    $user = auth()->user();

    /*
     * Nothing has passed the verdict gate, so there are no picks - only
     * opinions, which are governed by calibration instead. That is a
     * real distinction a customer is entitled to before they click, and
     * it is the difference between this product and a tipster.
     */
    $proven = \App\Models\VlModel::anyMarketProven();

    $cards = [
        [
            'icon' => 'cpu',
            'title' => config('brand.name').' AI Predictions',
            'body' => $proven
                ? 'The engine reads every tracked book, strips the vig, and says pick or pass with its reasoning attached.'
                : 'The engine publishes its own opinions with the reasoning attached. No market has beaten the closing line yet, so nothing is sold as a proven pick.',
            'route' => 'opinions',
            'gate' => null,
            'note' => $proven ? null : 'No proven market yet',
        ],
        [
            'icon' => 'target',
            'title' => 'Player IQ Ratings',
            'body' => 'Per-player ratings built from real box scores, weighted by how much of a sample there is behind each rate.',
            'route' => 'player-iq',
            'gate' => 'playeriq',
        ],
        [
            'icon' => 'activity',
            'title' => 'Odds Comparison',
            'body' => 'The same market at every tracked book side by side, with the cost of taking the wrong one made explicit.',
            'route' => 'odds',
            'gate' => 'lineshopping',
        ],
        [
            'icon' => 'chart',
            'title' => 'Player Prop Analysis',
            'body' => 'Projections for what a player is likely to do, read against the number the book is offering on it.',
            'route' => 'props',
            'gate' => 'props',
        ],
        [
            'icon' => 'layers',
            'title' => 'Parlay Builder',
            'body' => 'Build a parlay leg by leg, see the combined price, and have it graded against what actually happened.',
            'route' => 'parlays',
            'gate' => 'parlay.grade',
        ],
        [
            'icon' => 'file',
            'title' => 'Personal Picks Tracker',
            'body' => 'Save a call, record a bet, and keep the whole history - including the ones that lost.',
            'route' => 'my-picks',
            'gate' => null,
            // Public route, but it is your own history: there is nothing
            // on it until there is an account behind it.
            'needsAccount' => true,
        ],
    ];
@endphp

<section id="platform"
         class="relative isolate scroll-mt-20 overflow-hidden border-b border-[var(--vg-line)] bg-[var(--vg-navy)]">
    <x-fx.section-glow class="left-1/4 top-0 h-72 w-[40rem] -translate-y-1/2 opacity-25" />

    <div class="relative mx-auto max-w-7xl px-6 py-20">
        <h2 class="font-[family-name:var(--font-display)] text-3xl font-bold sm:text-4xl">
            The platform
        </h2>
        <div class="fx-rule mt-5 max-w-xs"></div>
        <p class="mt-5 max-w-2xl text-[var(--vg-slate)]">
            Six tools, and an honest label on each. A padlock means it needs an account &mdash;
            not that it is coming soon.
        </p>

        <ul role="list" class="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($cards as $card)
                @php
                    $locked = $card['gate'] !== null && $entitlements->cannot($user, $card['gate']);
                    $needsAccount = ($card['needsAccount'] ?? false) && auth()->guest();
                @endphp

                <li>
                    {{--
                        The whole card is the link, so the target is the
                        card rather than a three-word "Learn more" at the
                        bottom of it. `h-full` so a longer body does not
                        leave a short card floating in its row.
                    --}}
                    <a href="{{ route($card['route']) }}"
                       class="fx-panel fx-panel-lift fx-brushed group flex h-full flex-col p-6 transition hover:border-[color-mix(in_srgb,var(--vg-green)_45%,transparent)]">
                        <div class="flex items-start justify-between gap-3">
                            <span aria-hidden="true"
                                  class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] text-[var(--vg-green)] transition group-hover:border-[color-mix(in_srgb,var(--vg-green)_45%,transparent)]">
                                <x-app.icon :name="$card['icon']" class="h-5 w-5" />
                            </span>

                            @if (! empty($card['note']))
                                {{--
                                    Muted, not green. This is the one
                                    label on the page that says something
                                    is unfinished, and painting it the
                                    colour that means "proven edge"
                                    elsewhere would be exactly backwards.
                                --}}
                                <span class="rounded-full border border-[var(--vg-line)] px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-[var(--vg-muted)]">
                                    {{ $card['note'] }}
                                </span>
                            @elseif ($locked || $needsAccount)
                                <span class="inline-flex items-center gap-1 rounded-full border border-[var(--vg-line)] px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-[var(--vg-muted)]">
                                    <x-app.icon name="lock" aria-hidden="true" class="h-2.5 w-2.5" />
                                    Members
                                </span>
                            @else
                                <span class="rounded-full border border-[color-mix(in_srgb,var(--vg-green)_40%,transparent)] px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-[var(--vg-green)]">
                                    Open
                                </span>
                            @endif
                        </div>

                        <h3 class="mt-5 font-[family-name:var(--font-display)] text-lg font-semibold">
                            {{ $card['title'] }}
                        </h3>

                        <p class="mt-2 flex-1 text-sm leading-relaxed text-[var(--vg-slate)]">
                            {{ $card['body'] }}
                        </p>

                        <span class="mt-5 inline-flex items-center gap-1.5 text-sm font-semibold text-[var(--vg-link)]">
                            {{ $locked || $needsAccount ? 'Sign in to open' : 'Open' }}
                            <span aria-hidden="true" class="transition group-hover:translate-x-0.5">&rarr;</span>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>

        {{--
            The thing that actually is not operational, said once and
            plainly rather than left for somebody to discover at a
            checkout that does not exist.
        --}}
        <p class="mt-8 max-w-3xl font-[family-name:var(--font-mono)] text-xs leading-relaxed text-[var(--vg-muted)]">
            Paid subscriptions are not on sale yet &mdash; checkout is not live and no card is
            accepted anywhere on this site. The {{ config('plans.trial_hours') }}-hour trial is
            the way in, and it needs an account and a verified email address, nothing more.
        </p>
    </div>
</section>
