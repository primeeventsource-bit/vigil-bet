<x-layouts.marketing title="About">
    <section class="border-b border-[var(--vg-line)]">
        <div class="mx-auto max-w-4xl px-6 py-16 sm:py-20">
            <h1 class="font-[family-name:var(--font-display)] text-4xl font-bold sm:text-5xl">
                About {{ config('brand.name') }}
            </h1>
            <p class="mt-5 max-w-2xl text-lg text-[var(--vg-slate)]">
                A prediction engine that reads the whole market, prices it without the vig,
                and says pick or pass. Most of the time it says pass.
            </p>
        </div>
    </section>

    <section class="border-b border-[var(--vg-line)]">
        <div class="mx-auto max-w-4xl px-6 py-14">
            <h2 class="font-[family-name:var(--font-display)] text-2xl font-bold">What it does</h2>

            <div class="mt-6 space-y-5 text-[var(--vg-slate)]">
                <p>
                    Odds are pulled from every tracked book, several times an hour as kickoff
                    approaches. The market's implied probabilities are stripped of their margin
                    to get a fair price, and a model trained on historical results produces its
                    own. The gap between the two is the edge, and whether that gap is worth
                    anything depends entirely on whether the model has been proved right before.
                </p>
                <p>
                    That proof is the gate. A market's model has to demonstrate that its stated
                    confidence matches reality &mdash; that when it says 60%, the thing happens
                    about 60% of the time &mdash; before a single opinion from it is published.
                    Markets that have not cleared it produce nothing a customer ever sees.
                </p>
                <p>
                    {{ $sports === [] ? 'No leagues are currently enabled.' : 'Coverage is '.strtoupper(implode(', ', $sports)).'.' }}
                    Anything the feeds do not publish is not covered, and the product does not
                    pretend otherwise.
                </p>
            </div>
        </div>
    </section>

    <section class="border-b border-[var(--vg-line)] bg-[var(--vg-navy)]">
        <div class="mx-auto max-w-4xl px-6 py-14">
            <h2 class="font-[family-name:var(--font-display)] text-2xl font-bold">How it behaves</h2>

            <div class="mt-6 grid gap-6 sm:grid-cols-2">
                @foreach ($principles as $principle)
                    <div>
                        <h3 class="font-semibold text-[var(--vg-paper)]">{{ $principle['heading'] }}</h3>
                        <p class="mt-1.5 text-sm text-[var(--vg-slate)]">{{ $principle['body'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{--
        The record, linked rather than summarised in adjectives.

        /record 404s until a market has been proven, so the link is
        conditional - the same thing the header nav does. A prominent
        link to a page that does not exist is worse than no link.
    --}}
    <section class="border-b border-[var(--vg-line)]">
        <div class="mx-auto max-w-4xl px-6 py-14">
            <h2 class="font-[family-name:var(--font-display)] text-2xl font-bold">The record</h2>
            <p class="mt-3 max-w-2xl text-[var(--vg-slate)]">
                Every published opinion is graded in public, winners and losers alike, and the
                page is open to anybody without an account. It is the only claim this product
                makes about itself that is worth anything.
            </p>

            <div class="mt-6 flex flex-wrap gap-3">
                <a href="{{ route('opinions') }}"
                   class="rounded-lg border border-[var(--vg-line)] px-5 py-2.5 text-sm font-semibold transition hover:text-[var(--vg-paper)]">
                    See the opinion record
                </a>
                @if ($proven)
                    <a href="{{ route('record') }}"
                       class="rounded-lg border border-[var(--vg-line)] px-5 py-2.5 text-sm font-semibold transition hover:text-[var(--vg-paper)]">
                        See the pick record
                    </a>
                @endif
            </div>
        </div>
    </section>

    {{--
        The company section.

        Left empty rather than filled. The facts a page like this
        normally carries - a legal entity, an address, who works here -
        are facts about a business, and nothing on this site states one
        that nobody has supplied. An About page is exactly where that
        rule is most tempting to bend, and the usual sentences about
        passion and innovation would be filling the gap with words
        chosen to sound like information.
    --}}
    <section>
        <div class="mx-auto max-w-4xl px-6 py-14">
            <h2 class="font-[family-name:var(--font-display)] text-2xl font-bold">The company</h2>
            <p class="mt-3 max-w-2xl text-[var(--vg-slate)]">
                Company details are published on the
                <a href="{{ route('legal.terms') }}" class="underline hover:text-[var(--vg-paper)]">terms</a>
                page as they are confirmed. For anything not answered there, write to
                <a href="mailto:{{ config('brand.company.support_email') }}"
                   class="underline hover:text-[var(--vg-paper)]">{{ config('brand.company.support_email') }}</a>.
            </p>

            <p class="mt-6 font-[family-name:var(--font-mono)] text-xs text-[var(--vg-muted)]">
                {{ config('brand.name') }} is an analytics product. It does not take bets, hold
                funds, or process a wager. {{ config('vigil.minimum_age') }}+ only.
            </p>
        </div>
    </section>
</x-layouts.marketing>
