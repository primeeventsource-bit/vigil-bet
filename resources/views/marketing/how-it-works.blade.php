<x-layouts.marketing title="How it works">
    <section class="relative isolate overflow-hidden border-b border-[var(--vg-line)]">
        <span aria-hidden="true" class="fx-grid"></span>
        <x-fx.section-glow class="left-1/2 top-0 h-[22rem] w-[46rem] -translate-x-1/2 -translate-y-1/2" />

        <div class="relative mx-auto max-w-3xl px-6 py-20">
            <h1 class="font-[family-name:var(--font-display)] text-5xl font-bold leading-tight">
                Four numbers,<br><span class="fx-gradient-text">plainly explained.</span>
            </h1>
            <p class="mt-5 max-w-xl text-[var(--vg-slate)]">
                Everything this product does rests on these. None of them is complicated; all of
                them are routinely misused by people selling picks.
            </p>
        </div>
    </section>

    <section class="mx-auto max-w-3xl px-6 py-16">
        <div class="space-y-5">
            @foreach ([
                ['01', 'The vig', 'A book prices both sides so the implied chances add up to more than 100%. That surplus is the vig, and it is why betting coin flips at -110 loses money even when you are right half the time.'],
                ['02', 'The fair price', 'Take the vig out across every book quoting a market and what is left is the fair probability — the market&rsquo;s honest opinion with the house&rsquo;s cut removed.'],
                ['03', 'The edge', 'The gap between the model&rsquo;s probability and the break-even rate at the price actually on offer. Not the gap against the fair price: that says something about the model rather than about this bet, and the two get confused often enough that we keep them as separate numbers.'],
                ['04', 'Closing line value', 'Whether the price you got beat the price the market finished at. Over enough bets it is the most honest evidence a method works, because it does not depend on whether any one bet happened to win.'],
            ] as [$n, $heading, $body])
                <article class="fx-panel fx-panel-lift p-6">
                    <span class="font-[family-name:var(--font-mono)] text-sm font-bold text-[var(--vg-edge)]" data-numeric>{{ $n }}</span>
                    <h2 class="mt-2 font-[family-name:var(--font-display)] text-xl font-semibold">{{ $heading }}</h2>
                    <p class="mt-2 leading-relaxed text-[var(--vg-slate)]">{!! $body !!}</p>
                </article>
            @endforeach
        </div>

        <div class="fx-panel mt-10 p-6">
            <h2 class="font-[family-name:var(--font-display)] text-xl font-semibold">Proven, and not yet proven</h2>
            <p class="mt-3 leading-relaxed text-[var(--vg-slate)]">
                A market only produces picks once a model has beaten the no-vig closing line on a
                holdout by more than chance explains, <em>and</em> is no worse calibrated than that
                line. Both conditions, because a sharper model with miscalibrated probabilities is
                more dangerous than a blunt one — stake sizing reads the probability literally.
            </p>
            <p class="mt-3 leading-relaxed text-[var(--vg-slate)]">
                Everything else is labelled not yet proven and produces analysis instead of picks.
                Most markets, most of the time, are not yet proven.
            </p>
        </div>
    </section>
</x-layouts.marketing>
