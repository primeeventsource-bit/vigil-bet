<x-layouts.marketing title="Contact"
                     description="How to reach {{ config('brand.name') }} about your account, billing or a problem with the product.">
    {{--
        A contact page, not a contact form.

        A form would need somewhere to deliver and somebody promising to
        read it, and neither of those is a thing this page can invent. The
        support address is real and configured; what this page adds is
        telling somebody which of their questions is already answered in
        writing, so the ones that reach a human are the ones that need
        one.

        What is deliberately absent: a response time, a phone number, a
        postal address, and an office. None of those are facts yet, and a
        contact page is the worst possible place to guess.
    --}}
    <section class="border-b border-[var(--vg-line)]">
        <div class="mx-auto max-w-5xl px-6 py-16 sm:py-20">
            <h1 class="font-[family-name:var(--font-display)] text-4xl font-bold sm:text-5xl">Contact</h1>
            <p class="mt-5 max-w-2xl text-lg text-[var(--vg-slate)]">
                One address, read by the people who build {{ config('brand.name') }}.
            </p>
        </div>
    </section>

    <section class="border-b border-[var(--vg-line)]">
        <div class="mx-auto max-w-5xl px-6 py-14">
            <div class="fx-panel fx-brushed p-8">
                <h2 class="font-[family-name:var(--font-display)] text-sm font-bold uppercase tracking-[0.18em] text-[var(--vg-muted)]">
                    Email
                </h2>

                <a href="mailto:{{ config('brand.company.support_email') }}"
                   class="mt-3 inline-block break-all font-[family-name:var(--font-mono)] text-xl font-bold text-[var(--vg-link)] underline decoration-[color-mix(in_srgb,var(--vg-link)_40%,transparent)] underline-offset-4 transition hover:decoration-[var(--vg-link)] sm:text-2xl">
                    {{ config('brand.company.support_email') }}
                </a>

                <p class="mt-5 max-w-xl text-sm leading-relaxed text-[var(--vg-slate)]">
                    Account problems, billing questions, a figure that looks wrong, or anything
                    the product did that it should not have. If you think you have found a bug in
                    the analysis, say which game and which market and we can look at the same row
                    you did.
                </p>
            </div>
        </div>
    </section>

    <section class="border-b border-[var(--vg-line)] bg-[var(--vg-navy)]">
        <div class="mx-auto max-w-5xl px-6 py-14">
            <h2 class="font-[family-name:var(--font-display)] text-2xl font-bold">
                Already answered in writing
            </h2>
            <div class="fx-rule mt-5 max-w-xs"></div>

            <ul role="list" class="mt-7 grid gap-4 sm:grid-cols-2">
                @foreach ([
                    ['label' => 'Cancelling, and what happens to the rest of the period', 'href' => route('legal.cancellation')],
                    ['label' => 'Refunds', 'href' => route('legal.cancellation').'#refunds'],
                    ['label' => 'What the data is used for', 'href' => route('legal.privacy')],
                    ['label' => 'What this product is and is not', 'href' => route('legal.disclaimer')],
                    ['label' => 'Taking a break, or stopping for good', 'href' => route('legal.responsible-gambling')],
                    ['label' => 'What each plan includes', 'href' => route('pricing')],
                ] as $link)
                    <li>
                        <a href="{{ $link['href'] }}"
                           class="group flex items-center justify-between gap-3 rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-5 py-4 text-sm text-[var(--vg-slate)] transition hover:border-[color-mix(in_srgb,var(--vg-decor)_45%,transparent)] hover:text-[var(--vg-paper)]">
                            {{ $link['label'] }}
                            <span aria-hidden="true" class="shrink-0 transition group-hover:translate-x-0.5">&rarr;</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    <section>
        <div class="mx-auto max-w-5xl px-6 py-14">
            {{--
                Said here as well as in the footer. Somebody who has
                come to this page to complain about a bet is owed the
                sentence before they write it, not underneath the reply.
            --}}
            <p class="max-w-2xl font-[family-name:var(--font-mono)] text-xs leading-relaxed text-[var(--vg-muted)]">
                {{ config('brand.name') }} is an analytics product. It does not take bets, hold
                funds, or settle wagers, so it cannot credit an account or overturn a bet placed
                with a sportsbook. {{ config('vigil.minimum_age') }}+ only.
            </p>
        </div>
    </section>
</x-layouts.marketing>
