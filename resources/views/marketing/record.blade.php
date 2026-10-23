<x-layouts.marketing title="Track record">
    <section class="relative isolate overflow-hidden border-b border-[var(--vg-line)]">
        <span aria-hidden="true" class="fx-grid"></span>
        <x-fx.section-glow class="left-1/2 top-0 h-72 w-[40rem] -translate-x-1/2 -translate-y-1/2" />

        <div class="relative mx-auto max-w-3xl px-6 py-20">
            <h1 class="font-[family-name:var(--font-display)] text-5xl font-bold leading-tight">
                The <span class="fx-gradient-text">record</span>, kept honestly.
            </h1>
            <p class="mt-5 max-w-xl text-[var(--vg-slate)]">
                Every published pick is written down before kickoff with its timestamp, its price,
                its book and the model probability behind it. The table rejects a row dated after
                kickoff, and rejects any edit to one already written.
            </p>
        </div>
    </section>

    <x-marketing.track-record-strip :record="$record" />

    <section class="mx-auto max-w-3xl px-6 py-16">
        <h2 class="font-[family-name:var(--font-display)] text-2xl font-bold">How this page will work</h2>

        <ul class="mt-6 space-y-4 text-[var(--vg-slate)]">
            <li class="flex gap-3">
                <span aria-hidden="true" class="mt-1 shrink-0 text-[var(--vg-edge)]">&check;</span>
                <span>Filterable by sport, market and month, with a permanent URL for every pick.</span>
            </li>
            <li class="flex gap-3">
                <span aria-hidden="true" class="mt-1 shrink-0 text-[var(--vg-edge)]">&check;</span>
                <span>Record, units, ROI and closing line value — each shown with its sample size.</span>
            </li>
            <li class="flex gap-3">
                <span aria-hidden="true" class="mt-1 shrink-0 text-[var(--vg-edge)]">&check;</span>
                <span>Paper results and backtests are never mixed into the same number as published picks.</span>
            </li>
        </ul>

        <p class="mt-8 font-[family-name:var(--font-mono)] text-xs text-[var(--vg-slate)]">
            Step 9 of the build.
        </p>
    </section>
</x-layouts.marketing>
