<x-layouts.marketing>
    {{-- Hero --}}
    <section class="relative isolate overflow-hidden border-b border-[var(--vg-line)]">
        <span aria-hidden="true" class="fx-grid"></span>
        <x-fx.circuit class="opacity-70" />
        <x-fx.section-glow class="left-1/2 top-0 h-[26rem] w-[52rem] -translate-x-1/2 -translate-y-1/2" />
        <x-fx.section-glow tone="signal" class="right-0 top-40 h-72 w-[34rem] translate-x-1/4" />

        <div class="relative mx-auto grid max-w-6xl items-center gap-16 px-6 py-24 sm:py-32 lg:grid-cols-[minmax(0,1fr)_minmax(0,28rem)]">
            <div>
            <p class="inline-flex items-center gap-2 rounded-full border border-[color-mix(in_srgb,var(--vg-decor)_35%,transparent)] bg-[color-mix(in_srgb,var(--vg-decor)_10%,transparent)] px-3 py-1 text-xs font-bold uppercase tracking-[0.18em] text-[var(--vg-slate)]">
                <span class="relative inline-flex h-1.5 w-1.5 text-[var(--vg-slate)]">
                    <span class="fx-pulse absolute inset-0 rounded-full"></span>
                    <span class="relative inline-block h-1.5 w-1.5 rounded-full bg-current"></span>
                </span>
                AI-driven sports intelligence
            </p>

            {{--
                The brand is uppercased in CSS rather than written in
                capitals, so renaming the product still renames it here.
                A hardcoded "VIGIL" would quietly keep saying the old
                name, which a test already watches for.
            --}}
            <h1 class="mt-7 max-w-4xl font-[family-name:var(--font-display)] text-5xl font-bold leading-[1.03] sm:text-6xl lg:text-[4.2rem]">
                The data speaks.<br>
                <span class="fx-gradient-text">
                    <span class="uppercase">{{ config('brand.name') }}</span> makes the call.
                </span>
            </h1>

            {{--
                The product's own claim, in its own words. Worth noticing
                what it does not say: it identifies opportunities and
                measures itself against what happened, and it promises
                nobody a win. The copy stays on the right side of §0.7
                without having to be edited into doing so.
            --}}
            <p class="mt-6 max-w-xl text-lg leading-relaxed text-[var(--vg-slate)]">
                {{ config('brand.name') }} doesn't chase picks. It creates its own. A proprietary
                prediction engine reads real-time odds, historical results, team and player
                performance and statistical trends to identify potential betting opportunities.
            </p>

            <p class="mt-4 max-w-xl text-sm leading-relaxed text-[var(--vg-slate)]">
                Every prediction is backed by data, measured against actual results, and evaluated
                to improve the models behind tomorrow's picks.
            </p>

            <p class="mt-6 font-[family-name:var(--font-display)] text-sm font-semibold uppercase tracking-[0.14em] text-[var(--vg-paper)]">
                Our engine. Our analysis. Our picks.
            </p>

            <div class="mt-9 flex flex-wrap items-center gap-3">
                {{--
                    Straight to the picks rather than to a sign-up form.
                    The page argues that this product shows its work, and
                    a primary button asking for a card before showing
                    anything would be arguing the opposite.
                --}}
                <a href="#todays-picks"
                   class="group relative inline-flex items-center gap-2 rounded-lg bg-[var(--vg-paper)] px-6 py-3 font-bold text-[var(--vg-night)] shadow-[0_0_32px_-8px_color-mix(in_srgb,var(--vg-paper)_55%,transparent)] transition hover:shadow-[0_0_44px_-6px_color-mix(in_srgb,var(--vg-paper)_65%,transparent)]">
                    View today's picks
                    <span aria-hidden="true" class="transition group-hover:translate-x-0.5">&rarr;</span>
                </a>

                <a href="{{ route('how-it-works') }}"
                   class="rounded-lg border border-[var(--vg-line)] px-6 py-3 font-semibold text-[var(--vg-paper)] transition hover:border-[color-mix(in_srgb,var(--vg-decor)_45%,transparent)]">
                    Explore {{ config('brand.name') }} AI
                </a>
            </div>

            <p class="mt-5 font-[family-name:var(--font-mono)] text-xs text-[var(--vg-slate)]">
                No card needed &middot; {{ config('vigil.minimum_age') }}+ only &middot; cancel in one click
            </p>
            </div>

            <x-marketing.console class="fx-console-stage mt-4 lg:mt-0" />
        </div>
    </section>

    {{--
        The brand band, from the supplied asset pack.

        Used whole rather than as a background plate. At 801x174 it is a
        4.6:1 strip, and object-cover inside a tall hero would crop away
        both the helmet and the tagline, which is most of what the image
        is. As a band the composition arrives as it was drawn.

        801px is its native size in the master reference - the supplied
        crops are 1:1, so there is no larger version to be had. It spans
        the viewport, which means an upscale on a wide screen; the 2x
        file softens that and the image is dark and atmospheric rather
        than detailed, so the cost is small. A sharper band would need
        new artwork, not a better scaler.
    --}}
    <section aria-label="{{ config('brand.name') }}" class="relative isolate overflow-hidden border-b border-[var(--vg-line)]">
        <img src="{{ asset('images/hero/hero-banner.jpg') }}"
             srcset="{{ asset('images/hero/hero-banner.jpg') }} 801w, {{ asset('images/hero/hero-banner@2x.jpg') }} 1602w"
             sizes="100vw"
             alt=""
             aria-hidden="true"
             loading="lazy"
             decoding="async"
             {{--
                 Its own aspect rather than a fixed height. The strip is
                 4.6:1, and any height that does not match crops the
                 helmet or the tagline - the two things it is for.
             --}}
             class="aspect-[801/174] w-full object-cover object-center">

        {{--
            Seams at both ends. The band is darkest in its corners but not
            quite the page colour, so without these the join reads as a
            slightly wrong black rather than as no join at all.
        --}}
        <span aria-hidden="true"
              class="pointer-events-none absolute inset-y-0 left-0 w-24 bg-[linear-gradient(90deg,var(--vg-night),transparent)]"></span>
        <span aria-hidden="true"
              class="pointer-events-none absolute inset-y-0 right-0 w-24 bg-[linear-gradient(270deg,var(--vg-night),transparent)]"></span>
    </section>
    {{--
        The day's picks, which the hero's primary button lands on.

        Shown to everybody, signed in or not. A picks site that will not
        say what it picked until you pay is asking to be taken on faith,
        which is the opposite of what the rest of this page argues.

        Every figure here is live. The asset notes say not to present
        placeholder statistics as real results, and the way to honour
        that is not to label invented numbers carefully - it is for there
        to be no invented numbers anywhere near this page.
    --}}
    {{--
        Only once a market has passed the gate. Everything in this
        section is the engine's own selection, and the comment above is
        the reason it is wrapped rather than left to render an empty
        shell: a heading that says what the engine likes today, over
        nothing, is still a claim about what the engine likes today.
    --}}
    @if (\App\Models\VlModel::anyMarketProven())
    <section id="todays-picks" class="relative scroll-mt-24 border-b border-[var(--vg-line)]">
        <div class="mx-auto max-w-6xl px-6 py-20">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p class="text-xs uppercase tracking-[0.2em] text-[var(--vg-slate)]">
                        Today's {{ config('brand.name') }} picks
                    </p>

                    <h2 class="mt-3 font-[family-name:var(--font-display)] text-3xl font-bold sm:text-4xl">
                        What the engine likes today.
                    </h2>
                </div>

                <a href="{{ route('opinions') }}"
                   class="text-sm text-[var(--vg-slate)] underline transition hover:text-[var(--vg-paper)]">
                    How they have done &rarr;
                </a>
            </div>

            <p class="mt-4 max-w-2xl text-sm leading-relaxed text-[var(--vg-slate)]">
                Published before kickoff, with the confidence the engine actually holds, from the
                markets whose probabilities have been measured against real results. They are
                <strong class="text-[var(--vg-paper)]">{{ config('brand.name') }}'s opinion, not a
                proven edge</strong> &mdash; and every one is graded in public, including the losers.
            </p>

            @if ($opinions->isEmpty())
                {{--
                    A real answer, not a gap. §4 and the asset notes both
                    say the product must be able to publish no pick rather
                    than manufacture one to fill a page.
                --}}
                <div class="panel mt-8 px-6 py-12 text-center">
                    <p class="font-[family-name:var(--font-display)] text-lg font-semibold">
                        No picks right now.
                    </p>
                    <p class="mx-auto mt-2 max-w-md text-sm text-[var(--vg-slate)]">
                        Nothing on today's board met the engine's requirements, or everything that
                        did has already started. We would rather show you none than invent one.
                    </p>
                </div>
            @else
                <ul class="mt-8 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($opinions as $opinion)
                        <li class="panel p-5">
                            <p class="text-[11px] uppercase tracking-wide text-[var(--vg-slate)]">
                                {{ strtoupper((string) $opinion->sport) }}
                            </p>

                            <p class="mt-2 font-[family-name:var(--font-display)] text-lg font-semibold">
                                {{ $opinion->selection }}
                            </p>

                            <p class="mt-0.5 truncate text-xs text-[var(--vg-slate)]">{{ $opinion->matchup }}</p>

                            <div class="mt-4 flex items-end justify-between gap-3">
                                <div>
                                    <p class="text-[11px] uppercase tracking-wide text-[var(--vg-slate)]">
                                        Confidence
                                    </p>
                                    <p class="font-[family-name:var(--font-mono)] text-2xl font-bold" data-numeric>
                                        {{ $opinion->confidencePercent() }}%
                                    </p>
                                </div>

                                <p class="font-[family-name:var(--font-mono)] text-sm text-[var(--vg-slate)]" data-numeric>
                                    {{ $opinion->price_american > 0 ? '+' : '' }}{{ $opinion->price_american }}
                                </p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif

            {{--
                The record, under the picks and on the same page. A picks
                list with no record beside it is the shape of every tout
                site there has ever been - and the rates read as a dash
                until something is graded, because an unmeasured figure
                and a measured zero are different claims.
            --}}
            <dl class="mt-8 grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-[var(--vg-line)] bg-[var(--vg-line)] sm:grid-cols-4">
                @foreach ([
                    ['Published', number_format($opinionRecord['published']), 'all time'],
                    ['Graded', number_format($opinionRecord['graded']), 'games finished'],
                    [
                        'Win rate',
                        $opinionRecord['win_rate'] === null ? '—' : number_format($opinionRecord['win_rate'] * 100, 1).'%',
                        $opinionRecord['graded'] > 0 ? 'n = '.number_format($opinionRecord['graded']) : 'nothing graded yet',
                    ],
                    [
                        'Units',
                        $opinionRecord['units'] === null ? '—' : ($opinionRecord['units'] > 0 ? '+' : '').number_format($opinionRecord['units'], 2),
                        $opinionRecord['graded'] > 0 ? 'at one unit a pick' : 'nothing graded yet',
                    ],
                ] as [$label, $value, $sample])
                    <div class="bg-[var(--vg-navy)] px-5 py-4">
                        <dt class="text-[11px] uppercase tracking-wide text-[var(--vg-slate)]">{{ $label }}</dt>
                        <dd class="mt-1 font-[family-name:var(--font-mono)] text-xl font-bold" data-numeric>{{ $value }}</dd>
                        <dd class="mt-0.5 text-[11px] text-[var(--vg-muted)]">{{ $sample }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </section>
    @endif

    {{-- The honest counter-pitch, which is the product's actual edge. --}}
    <section class="relative border-b border-[var(--vg-line)] bg-[var(--vg-navy)]">
        <div class="mx-auto max-w-6xl px-6 py-16">
            <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)] lg:items-center">
                <div>
                    <h2 class="font-[family-name:var(--font-display)] text-3xl font-bold">
                        Most days the answer is <span class="text-[var(--vg-slate)]">pass</span>.
                    </h2>
                    <p class="mt-4 text-[var(--vg-slate)]">
                        A market only produces a pick once a model has beaten the no-vig closing line
                        by more than chance explains, and is no worse calibrated than that line.
                        Everything else is labelled <em>not yet proven</em> and gives you the analysis
                        instead.
                    </p>
                    <p class="mt-4 text-[var(--vg-slate)]">
                        Right now, nothing is proven. The product says so on its own front page,
                        which is the whole reason to trust it the day something is.
                    </p>
                </div>

                <ul class="grid gap-3 sm:grid-cols-2">
                    @foreach ([
                        ['Never takes a bet', 'No wallet, no deposit, no payout. You bet at your own book.'],
                        ['Never invents a number', 'Every edge and verdict is read from the engine, not recomputed.'],
                        ['Never hides a pass', 'You see what was declined, and why.'],
                        ['Never a rate without its n', 'A win rate with no sample size is not evidence.'],
                    ] as [$title, $body])
                        <li class="fx-panel fx-panel-lift p-5">
                            <p class="font-semibold">{{ $title }}</p>
                            <p class="mt-1.5 text-sm text-[var(--vg-slate)]">{{ $body }}</p>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </section>

    {{-- Three steps --}}
    <section class="relative isolate overflow-hidden border-b border-[var(--vg-line)]">
        <x-fx.section-glow class="left-1/2 top-0 h-64 w-[36rem] -translate-x-1/2 -translate-y-1/2 opacity-30" />

        <div class="relative mx-auto max-w-6xl px-6 py-20">
            <h2 class="font-[family-name:var(--font-display)] text-3xl font-bold">How it works</h2>
            <div class="fx-rule mt-5 max-w-xs"></div>

            <ol class="mt-10 grid gap-5 md:grid-cols-3">
                @foreach ($steps as $i => $step)
                    <li class="fx-panel fx-panel-lift relative p-6">
                        <span class="font-[family-name:var(--font-mono)] text-sm font-bold text-[var(--vg-slate)]" data-numeric>
                            {{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}
                        </span>
                        <h3 class="mt-3 font-[family-name:var(--font-display)] text-lg font-semibold">
                            {{ $step['heading'] }}
                        </h3>
                        <p class="mt-2 text-sm leading-relaxed text-[var(--vg-slate)]">{{ $step['body'] }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    @if (\App\Models\VlModel::anyMarketProven())
        <x-marketing.track-record-strip :record="$record" />
    @endif
    <x-marketing.tiers />
    <x-marketing.faq />
</x-layouts.marketing>
