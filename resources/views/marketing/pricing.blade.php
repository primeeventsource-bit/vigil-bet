<x-layouts.marketing title="Pricing">
    <section class="relative isolate overflow-hidden border-b border-[var(--vg-line)]">
        <span aria-hidden="true" class="fx-grid"></span>
        <x-fx.section-glow class="left-1/2 top-0 h-72 w-[42rem] -translate-x-1/2 -translate-y-1/2" />

        <div class="relative mx-auto max-w-3xl px-6 py-20 text-center">
            <h1 class="font-[family-name:var(--font-display)] text-5xl font-bold leading-tight">
                Pay for the <span class="fx-gradient-text">answer</span>,<br>not the noise.
            </h1>
            <p class="mx-auto mt-5 max-w-xl text-[var(--vg-slate)]">
                One subscription. No upsells, no bonus to chase, no contest, and nothing that
                rewards you for betting more.
            </p>
        </div>
    </section>

    <x-marketing.tiers :compact="true" />

    <section class="mx-auto max-w-3xl px-6 py-16">
        <h2 class="font-[family-name:var(--font-display)] text-2xl font-bold">What you are actually buying</h2>

        <div class="mt-6 space-y-4 text-[var(--vg-slate)]">
            <p>
                Information and tools. {{ config('brand.name') }} never takes a bet, never holds
                your money and never pays anything out. You place bets at your own sportsbook.
            </p>
            <p>
                There are no refunds on results — a losing week is not a fault in the product.
                If the product was broken or unavailable, write to us and we will put it right.
            </p>
            <p class="font-semibold text-[var(--vg-paper)]">
                {{ config('vigil.responsible_gambling_line') }}
            </p>
        </div>
    </section>
</x-layouts.marketing>
