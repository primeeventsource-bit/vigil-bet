<x-layouts.marketing title="How it works">
    <section class="mx-auto max-w-3xl px-6 py-16">
        <h1 class="font-[family-name:var(--font-display)] text-3xl font-bold">How it works</h1>

        <div class="mt-8 space-y-7 text-[15px] leading-relaxed">
            <div>
                <h2 class="font-[family-name:var(--font-display)] text-xl font-semibold">The vig</h2>
                <p class="mt-2 text-[var(--vg-slate)]">
                    A book prices both sides so the implied chances add up to more than 100%.
                    That surplus is the vig, and it is why betting coin flips at -110 loses money
                    even when you are right half the time.
                </p>
            </div>

            <div>
                <h2 class="font-[family-name:var(--font-display)] text-xl font-semibold">The fair price</h2>
                <p class="mt-2 text-[var(--vg-slate)]">
                    Take the vig out across every book quoting a market and what is left is the
                    fair probability: the market's honest opinion with the house's cut removed.
                </p>
            </div>

            <div>
                <h2 class="font-[family-name:var(--font-display)] text-xl font-semibold">The edge</h2>
                <p class="mt-2 text-[var(--vg-slate)]">
                    The gap between the model's probability and the break-even rate at the price
                    actually on offer. Not the gap against the fair price — that says something
                    about the model rather than about this bet, and the two get confused often
                    enough that we keep them as separate numbers.
                </p>
            </div>

            <div>
                <h2 class="font-[family-name:var(--font-display)] text-xl font-semibold">Closing line value</h2>
                <p class="mt-2 text-[var(--vg-slate)]">
                    Whether the price you got beat the price the market finished at. Over enough
                    bets it is the most honest evidence that a method works, because it does not
                    depend on whether any particular bet happened to win.
                </p>
            </div>

            <div>
                <h2 class="font-[family-name:var(--font-display)] text-xl font-semibold">Proven, and not yet proven</h2>
                <p class="mt-2 text-[var(--vg-slate)]">
                    A market only produces picks once a model has beaten the no-vig closing line
                    on a holdout by more than chance explains, and is no worse calibrated than
                    that line. Everything else is labelled not yet proven and produces analysis
                    instead. Most markets, most of the time, are not yet proven.
                </p>
            </div>
        </div>
    </section>
</x-layouts.marketing>
