{{--
    The member dashboard, laid out rather than claimed.

    The specification allows either real product components or explicitly
    labelled demonstration data. This takes the first for everything that
    is a fact - the navigation is the real navigation, the panel headings
    are the real headings - and refuses the second for anything that
    would be a figure.

    That refusal is the whole design. A dashboard mockup wants to be
    covered in numbers, and every one of them would be a claim: a win
    rate, units won, an average confidence. The live figures this product
    has are on the board further up the page, computed from the database
    with their sample size beside them. Inventing a second set here, even
    labelled, would have the illustration arguing with the page.

    So the figures are withheld and the caption says so in as many words.
    What is shown is true: this is the shape of the thing you get.
--}}
@php
    /*
     * The seven areas the specification names, each against the route
     * that serves it. Labels, not links - a marketing illustration that
     * sends a signed-out visitor to a login screen has spent the click
     * on nothing, which is the bargain the feature ribbon makes too.
     */
    $areas = [
        ['label' => "Today's Games", 'note' => 'Every fixture we hold a price for'],
        ['label' => 'AI Picks', 'note' => 'The calls, with the reasoning attached'],
        ['label' => 'Player IQ', 'note' => 'Ratings built from real box scores'],
        ['label' => 'My Picks', 'note' => 'The ones you saved'],
        ['label' => 'My Parlays', 'note' => 'Built, priced and graded'],
        ['label' => 'Performance', 'note' => 'Yours, including the losses'],
        ['label' => 'Subscription', 'note' => 'Plan, renewal, and one-click cancel'],
    ];
@endphp

<section id="dashboard"
         class="relative isolate scroll-mt-20 overflow-hidden border-b border-[var(--vg-line)]">
    <x-fx.section-glow class="left-1/2 top-0 h-72 w-[44rem] -translate-x-1/2 -translate-y-1/2 opacity-25" />

    <div class="relative mx-auto max-w-7xl px-6 py-20">
        <div class="max-w-2xl">
            <h2 class="font-[family-name:var(--font-display)] text-3xl font-bold sm:text-4xl">
                Inside the dashboard
            </h2>
            <div class="fx-rule mt-5 max-w-xs"></div>
            <p class="mt-5 text-[var(--vg-slate)]">
                What the product looks like once you are in it. The navigation and the panels are
                the real ones.
            </p>
        </div>

        <div class="mt-10 grid gap-5 lg:grid-cols-[minmax(0,17rem)_minmax(0,1fr)]">
            {{-- The sidebar, which is a fact about the product. --}}
            <div class="fx-panel fx-brushed p-5">
                <div class="flex items-center gap-2 border-b border-[var(--vg-line)] pb-4">
                    <x-brand.mark size="h-5" class="text-[var(--vg-paper)]" />
                    <span class="font-[family-name:var(--font-display)] text-xs font-bold uppercase tracking-[0.18em] text-[var(--vg-muted)]">
                        Member area
                    </span>
                </div>

                <ul role="list" class="mt-4 space-y-1">
                    @foreach ($areas as $i => $area)
                        <li @class([
                            'flex items-start gap-2.5 rounded-lg px-3 py-2.5',
                            // One row picked out, the way a current page is.
                            'bg-[color-mix(in_srgb,var(--vg-green)_12%,transparent)]' => $i === 1,
                        ])>
                            <span aria-hidden="true" @class([
                                'mt-1.5 h-1.5 w-1.5 shrink-0 rounded-sm',
                                'bg-[var(--vg-green)]' => $i === 1,
                                'bg-[var(--vg-muted)]' => $i !== 1,
                            ])></span>

                            <span class="min-w-0">
                                <span @class([
                                    'block text-sm font-semibold',
                                    'text-[var(--vg-paper)]' => $i === 1,
                                    'text-[var(--vg-slate)]' => $i !== 1,
                                ])>{{ $area['label'] }}</span>
                                <span class="mt-0.5 block text-[11px] leading-snug text-[var(--vg-muted)]">
                                    {{ $area['note'] }}
                                </span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="space-y-5">
                {{--
                    Stat tiles with their real labels and no values.
                    The bar is deliberately not a number: see the note at
                    the top of this file.
                --}}
                <div class="grid gap-4 sm:grid-cols-3">
                    @foreach (['Published', 'Live now', 'Graded'] as $label)
                        <div class="fx-panel p-5">
                            <span class="block font-[family-name:var(--font-mono)] text-[10px] uppercase tracking-[0.16em] text-[var(--vg-muted)]">
                                {{ $label }}
                            </span>
                            <span aria-hidden="true"
                                  class="mt-3 block h-3 w-16 rounded bg-[color-mix(in_srgb,var(--vg-paper)_22%,transparent)]"></span>
                        </div>
                    @endforeach
                </div>

                {{-- The board, with the column headings the product actually uses. --}}
                <div class="fx-panel overflow-hidden">
                    <div class="flex items-center gap-4 border-b border-[var(--vg-line)] px-5 py-3 font-[family-name:var(--font-mono)] text-[10px] uppercase tracking-[0.16em] text-[var(--vg-muted)]">
                        <span class="flex-1">Game</span>
                        <span class="w-16">Pick</span>
                        <span class="w-14 text-right">Odds</span>
                        <span class="w-20 text-right">Confidence</span>
                    </div>

                    @foreach ([[72, 74], [48, 62], [86, 69], [40, 57], [64, 66]] as [$game, $confidence])
                        <div class="flex items-center gap-4 border-b border-[var(--vg-line)] px-5 py-3.5 last:border-0">
                            <span class="flex-1"><span aria-hidden="true" class="block h-2 rounded bg-[var(--vg-line)]" style="width: {{ $game }}%"></span></span>
                            <span class="w-16"><span aria-hidden="true" class="block h-2 w-10 rounded bg-[var(--vg-line)]"></span></span>
                            <span class="w-14"><span aria-hidden="true" class="ml-auto block h-2 w-8 rounded bg-[var(--vg-line)]"></span></span>
                            <span class="w-20">
                                <span aria-hidden="true" class="ml-auto block h-2 w-full overflow-hidden rounded bg-[var(--vg-line)]">
                                    <span class="block h-full rounded bg-[color-mix(in_srgb,var(--vg-paper)_45%,transparent)]"
                                          style="width: {{ $confidence }}%"></span>
                                </span>
                            </span>
                        </div>
                    @endforeach
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    {{-- The same abstract trace used elsewhere: no axis, no scale. --}}
                    <div class="fx-panel p-5">
                        <span class="block font-[family-name:var(--font-mono)] text-[10px] uppercase tracking-[0.16em] text-[var(--vg-muted)]">
                            Closing line value
                        </span>
                        <svg viewBox="0 0 240 56" class="mt-3 h-14 w-full" fill="none" preserveAspectRatio="none" aria-hidden="true">
                            <path d="M0 44 L40 34 L80 39 L120 22 L160 29 L200 13 L240 18"
                                  stroke="var(--vg-chart-line)" stroke-width="1.5"
                                  stroke-linecap="round" stroke-linejoin="round" opacity="0.85" />
                            <path d="M0 44 L40 34 L80 39 L120 22 L160 29 L200 13 L240 18 L240 56 L0 56 Z"
                                  fill="var(--vg-chart-fill)" opacity="0.08" />
                        </svg>
                    </div>

                    {{--
                        Subscription status, which is the one panel here
                        that can carry real words: the trial length and
                        the renewal rule are facts, and they are the two
                        things somebody reads this section to find.
                    --}}
                    <div class="fx-panel p-5">
                        <span class="block font-[family-name:var(--font-mono)] text-[10px] uppercase tracking-[0.16em] text-[var(--vg-muted)]">
                            Subscription
                        </span>
                        <p class="mt-3 text-sm font-semibold text-[var(--vg-paper)]">
                            {{ config('plans.trial_hours') }}-hour trial
                        </p>
                        <p class="mt-1.5 text-xs leading-relaxed text-[var(--vg-slate)]">
                            Ends on its own. It does not become a subscription, and nothing is
                            charged when it stops.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        {{--
            The caption that makes the whole section honest, and the
            reason it is below rather than above: it explains the thing
            the reader has just looked at.
        --}}
        <p class="mt-8 max-w-3xl font-[family-name:var(--font-mono)] text-xs leading-relaxed text-[var(--vg-muted)]">
            Layout illustration. The navigation and panel headings are the product's own; the
            figures are withheld rather than invented, because a win rate printed on a marketing
            page is a claim. The real numbers are on the board above and on the published record,
            with their sample sizes beside them.
        </p>
    </div>
</section>
