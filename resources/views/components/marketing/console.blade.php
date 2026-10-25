@props(['class' => ''])

{{--
    The product, on an angle, behind glass.

    Decoration with one hard rule: there is not a single number on it.

    A hero mockup of a betting dashboard wants to be covered in figures -
    a win rate, units won, an average confidence - and every one of those
    would be a claim. §0.7 allows a win rate on a marketing page only when
    it is computed live from the public track record and shown with its
    sample size, and the record is currently empty because nothing has
    been proven yet. A mockup carrying invented results would be the
    product contradicting its own front page, which is the one thing this
    page is built to avoid.

    So the figures are redacted into bars. What is real is the shape: the
    navigation is the actual navigation, the columns are the actual
    columns, and the trace is the same abstract one used elsewhere on this
    page - no axis, no scale, no numbers.

    aria-hidden throughout, and the whole thing is skipped by a screen
    reader. The sentence beside it already says what the product does; a
    reader does not need a dozen unlabelled bars read out.
--}}
<div {{ $attributes->merge(['class' => 'pointer-events-none select-none '.$class]) }} aria-hidden="true">
    <div class="relative [perspective:1600px]">
        {{-- The lamp behind the glass. --}}
        <span class="fx-glow fx-glow-edge absolute left-1/2 top-1/2 h-[26rem] w-[40rem] -translate-x-1/2 -translate-y-1/2"></span>

        <div class="fx-console relative rounded-2xl border border-[var(--vg-line)] bg-[var(--vg-navy)] p-3 shadow-[0_40px_80px_-32px_color-mix(in_srgb,var(--vg-night)_90%,transparent)]">
            {{-- Title bar --}}
            <div class="flex items-center gap-2 px-2 pb-3 pt-1">
                <x-brand.mark size="h-3.5" class="text-[var(--vg-paper)]" />
                <span class="h-1.5 w-10 rounded-full bg-[var(--vg-line)]"></span>
                <span class="ml-auto h-1.5 w-6 rounded-full bg-[var(--vg-line)]"></span>
                <span class="h-1.5 w-6 rounded-full bg-[var(--vg-line)]"></span>
            </div>

            <div class="flex gap-3 rounded-xl bg-[var(--vg-night)] p-3">
                {{-- The real navigation, which is a fact rather than a claim. --}}
                <nav class="hidden w-28 shrink-0 flex-col gap-2.5 sm:flex">
                    {{--
                        The actual customer navigation, which is the
                        point of drawing it at all. It used to list Board
                        and Model Lab; those are the engine's own pages
                        and no longer appear to a customer, so a mockup
                        showing them would be a picture of a product
                        nobody is sold.
                    --}}
                    @foreach (['Today', 'AI Picks', 'Live Odds', 'Parlays', 'Results', 'Bankroll'] as $i => $item)
                        <span @class([
                            'flex items-center gap-2 rounded-md px-2 py-1.5 text-[10px] font-semibold tracking-wide',
                            'bg-[color-mix(in_srgb,var(--vg-paper)_12%,transparent)] text-[var(--vg-paper)]' => $i === 1,
                            'text-[var(--vg-slate)]' => $i !== 1,
                        ])>
                            <span class="h-1.5 w-1.5 rounded-sm bg-current opacity-60"></span>
                            {{ $item }}
                        </span>
                    @endforeach
                </nav>

                <div class="min-w-0 flex-1 space-y-3">
                    {{-- Stat tiles: the labels are true, the figures are withheld. --}}
                    <div class="grid grid-cols-3 gap-2">
                        @foreach (['Published', 'Live now', 'Graded'] as $label)
                            <div class="rounded-lg border border-[var(--vg-line)] bg-[var(--vg-navy)] p-2.5">
                                <span class="block text-[9px] uppercase tracking-[0.14em] text-[var(--vg-slate)]">{{ $label }}</span>
                                <span class="mt-1.5 block h-2.5 w-10 rounded bg-[color-mix(in_srgb,var(--vg-paper)_30%,transparent)]"></span>
                            </div>
                        @endforeach
                    </div>

                    {{-- The board, with its real column headings. --}}
                    <div class="overflow-hidden rounded-lg border border-[var(--vg-line)] bg-[var(--vg-navy)]">
                        <div class="flex items-center gap-3 border-b border-[var(--vg-line)] px-3 py-2 text-[9px] uppercase tracking-[0.14em] text-[var(--vg-slate)]">
                            <span class="flex-1">Game</span>
                            <span class="w-12">Pick</span>
                            <span class="w-10 text-right">Odds</span>
                            <span class="w-14 text-right">Confidence</span>
                        </div>

                        {{--
                            The confidence column is a bar and not a
                            figure, for the same reason nothing else here
                            is: a percentage printed on a hero is a claim,
                            and the only honest confidence figures this
                            product has are the live ones further down the
                            page. The widths vary because real ones do.
                        --}}
                        @foreach ([[68, 78], [44, 64], [82, 71], [36, 59]] as [$width, $confidence])
                            <div class="flex items-center gap-3 border-b border-[var(--vg-line)] px-3 py-2 last:border-0">
                                <span class="flex-1"><span class="block h-1.5 rounded bg-[var(--vg-line)]" style="width: {{ $width }}%"></span></span>
                                <span class="w-12"><span class="block h-1.5 w-8 rounded bg-[var(--vg-line)]"></span></span>
                                <span class="w-10"><span class="ml-auto block h-1.5 w-6 rounded bg-[var(--vg-line)]"></span></span>
                                <span class="w-14">
                                    <span class="ml-auto block h-1.5 w-full overflow-hidden rounded bg-[var(--vg-line)]">
                                        <span class="block h-full rounded bg-[color-mix(in_srgb,var(--vg-paper)_55%,transparent)]"
                                              style="width: {{ $confidence }}%"></span>
                                    </span>
                                </span>
                            </div>
                        @endforeach
                    </div>

                    {{-- The same abstract trace used behind the hero: no axis, no scale. --}}
                    <div class="rounded-lg border border-[var(--vg-line)] bg-[var(--vg-navy)] p-3">
                        <span class="block text-[9px] uppercase tracking-[0.14em] text-[var(--vg-slate)]">Closing line value</span>
                        <svg viewBox="0 0 240 48" class="mt-2 h-12 w-full" fill="none" preserveAspectRatio="none">
                            <path d="M0 38 L40 30 L80 34 L120 20 L160 26 L200 12 L240 16"
                                  stroke="var(--vg-chart-line)" stroke-width="1.5"
                                  stroke-linecap="round" stroke-linejoin="round" opacity="0.85" />
                            <path d="M0 38 L40 30 L80 34 L120 20 L160 26 L200 12 L240 16 L240 48 L0 48 Z"
                                  fill="var(--vg-chart-fill)" opacity="0.08" />
                        </svg>
                    </div>
                </div>
            </div>
        </div>

        {{-- Floating cards, offset in front of the glass. --}}
        <div class="fx-console-card absolute -left-14 top-28 hidden w-36 rounded-xl border border-[var(--vg-line)] bg-[color-mix(in_srgb,var(--vg-navy)_88%,transparent)] p-3 backdrop-blur-sm lg:block">
            {{--
                "Not yet proven" used to sit here, from when the product's
                whole story was that most days the answer is pass. It
                publishes picks every day now, and a hero card saying the
                model is unproven beside a headline saying the engine
                makes the call was the illustration arguing with the
                words.
            --}}
            <span class="block text-[9px] uppercase tracking-[0.14em] text-[var(--vg-slate)]">Today's pick</span>
            <span class="mt-2 block h-1.5 w-20 rounded bg-[color-mix(in_srgb,var(--vg-paper)_45%,transparent)]"></span>
            <span class="mt-2 block h-1 w-full overflow-hidden rounded bg-[var(--vg-line)]">
                <span class="block h-full w-[74%] rounded bg-[color-mix(in_srgb,var(--vg-paper)_55%,transparent)]"></span>
            </span>
        </div>

        <div class="fx-console-card absolute -right-10 -bottom-6 hidden w-40 rounded-xl border border-[var(--vg-line)] bg-[color-mix(in_srgb,var(--vg-navy)_88%,transparent)] p-3 backdrop-blur-sm lg:block">
            <span class="block text-[9px] uppercase tracking-[0.14em] text-[var(--vg-slate)]">Why this pick</span>
            @foreach ([100, 76, 88] as $w)
                <span class="mt-1.5 block h-1 rounded bg-[var(--vg-line)]" style="width: {{ $w }}%"></span>
            @endforeach
        </div>
    </div>
</div>
