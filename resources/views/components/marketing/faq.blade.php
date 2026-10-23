@php
    $faqs = [
        ['Do you place bets for me?',
         'No. '.config('brand.name').' never takes a bet, holds money, or pays anything out. You place bets at your own sportsbook; this tells you which are worth placing and, far more often, which are not.'],
        ['Why are there no picks today?',
         'Because no market has earned one. A model has to beat the no-vig closing line by more than chance explains before it may produce a pick. Until it does you get the analysis, labelled plainly as not yet proven.'],
        ['Is this guaranteed to make money?',
         'No, and anyone telling you otherwise is selling something worse than this. Betting loses money for most people who do it. A positive edge is a statement about long-run expectation at a measured price, not about tonight.'],
        ['What is closing line value?',
         'Whether the price you got beat the price the market finished at. Over enough bets it is the most honest evidence a method works, because it does not depend on whether any one bet happened to win.'],
        ['Can I export the odds?',
         'You can export your own bets at any time. Odds are licensed for display inside the product and cannot be redistributed, so there is no odds export, no feed and no public API.'],
        ['How do I cancel?',
         'One click in Account. It takes effect at the end of the period you have paid for and you keep access until then. There is nothing in the way of it.'],
    ];
@endphp

<section class="relative isolate overflow-hidden">
    <x-fx.section-glow tone="signal" class="left-1/2 bottom-0 h-64 w-[40rem] -translate-x-1/2 translate-y-1/2 opacity-25" />

    <div class="relative mx-auto max-w-3xl px-6 py-20">
        <h2 class="font-[family-name:var(--font-display)] text-3xl font-bold">Questions</h2>
        <div class="fx-rule mt-5 max-w-xs"></div>

        <dl class="mt-10 space-y-3">
            @foreach ($faqs as [$question, $answer])
                {{-- Native details, so it works without JavaScript and is keyboard operable for free. --}}
                <details class="fx-panel group px-5 py-4">
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-4 font-semibold">
                        {{ $question }}
                        <span aria-hidden="true" class="shrink-0 text-[var(--vg-edge)] transition group-open:rotate-45">+</span>
                    </summary>
                    <p class="mt-3 text-sm leading-relaxed text-[var(--vg-slate)]">{{ $answer }}</p>
                </details>
            @endforeach
        </dl>
    </div>
</section>
