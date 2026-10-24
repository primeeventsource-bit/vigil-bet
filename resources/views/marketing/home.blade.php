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
                {{ config('brand.tagline') }}
            </p>

            <h1 class="mt-7 max-w-3xl font-[family-name:var(--font-display)] text-5xl font-bold leading-[1.05] sm:text-7xl">
                Smarter bets.<br>
                <span class="fx-gradient-text">Sharper edges.</span>
            </h1>

            <p class="mt-6 max-w-xl text-lg leading-relaxed text-[var(--vg-slate)]">
                {{ config('brand.description') }}
            </p>

            <div class="mt-9 flex flex-wrap items-center gap-3">
                <a href="{{ route('register') }}"
                   class="group relative inline-flex items-center gap-2 rounded-lg bg-[var(--vg-paper)] px-6 py-3 font-bold text-[var(--vg-night)] shadow-[0_0_32px_-8px_color-mix(in_srgb,var(--vg-paper)_55%,transparent)] transition hover:shadow-[0_0_44px_-6px_color-mix(in_srgb,var(--vg-paper)_65%,transparent)]">
                    Start free
                    <span aria-hidden="true" class="transition group-hover:translate-x-0.5">&rarr;</span>
                </a>

                <a href="{{ route('how-it-works') }}"
                   class="rounded-lg border border-[var(--vg-line)] px-6 py-3 font-semibold text-[var(--vg-paper)] transition hover:border-[color-mix(in_srgb,var(--vg-decor)_45%,transparent)]">
                    How it works
                </a>
            </div>

            <p class="mt-5 font-[family-name:var(--font-mono)] text-xs text-[var(--vg-slate)]">
                No card needed &middot; {{ config('vigil.minimum_age') }}+ only &middot; cancel in one click
            </p>
            </div>

            <x-marketing.console class="fx-console-stage mt-4 lg:mt-0" />
        </div>
    </section>

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

    <x-marketing.track-record-strip :record="$record" />
    <x-marketing.tiers />
    <x-marketing.faq />
</x-layouts.marketing>
