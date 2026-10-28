{{--
    The responsible-gambling line is not decoration and is not optional.
    It comes from config so no template can reword it, and it sits in the
    footer of every page, signed-in or not.
--}}
<footer class="mt-16 border-t border-[var(--vg-line)] bg-[var(--vg-navy)]">
    <div class="mx-auto max-w-6xl px-6 py-10">
        <div class="flex flex-col gap-8 sm:flex-row sm:justify-between">
            <div class="max-w-md">
                <x-brand.lockup size="h-7" />

                {{--
                    What the name stands for, under the mark, which is
                    where a full name belongs. Guarded rather than
                    assumed: a backronym is the kind of thing a brand
                    changes its mind about, and setting BRAND_EXPANSION
                    empty should remove it without touching markup.
                --}}
                {{--
                    Tracking is tight enough that the line survives the
                    column. At 0.14em it wrapped and left "& Logic"
                    stranded on a line of its own, which reads as a
                    mistake rather than as a name.
                --}}
                @if (filled(config('brand.expansion')))
                    <p class="mt-3 text-[11px] uppercase tracking-[0.08em] text-[var(--vg-muted)]">
                        {{ config('brand.expansion') }}
                    </p>
                @endif

                <p class="mt-3 text-sm text-[var(--vg-slate)]">{{ config('brand.description') }}</p>
            </div>

            <nav aria-label="Footer" class="grid grid-cols-2 gap-x-10 gap-y-2 text-sm">
                <a class="text-[var(--vg-slate)] hover:text-[var(--vg-paper)]" href="{{ route('about') }}">About</a>
                <a class="text-[var(--vg-slate)] hover:text-[var(--vg-paper)]" href="{{ route('features') }}">Features</a>
                <a class="text-[var(--vg-slate)] hover:text-[var(--vg-paper)]" href="{{ route('sports-analytics') }}">Sports analytics</a>
                <a class="text-[var(--vg-slate)] hover:text-[var(--vg-paper)]" href="{{ route('pricing') }}">Pricing</a>
                <a class="text-[var(--vg-slate)] hover:text-[var(--vg-paper)]" href="{{ route('how-it-works') }}">How it works</a>
                {{--
                    Linked here rather than left as section 4 of the
                    terms. Billing rules nobody can find before they buy
                    are rules written for after the argument starts.
                --}}
                <a class="text-[var(--vg-slate)] hover:text-[var(--vg-paper)]" href="{{ route('legal.cancellation') }}">Cancellation policy</a>
                <a class="text-[var(--vg-slate)] hover:text-[var(--vg-paper)]" href="{{ route('legal.terms') }}">Terms</a>
                <a class="text-[var(--vg-slate)] hover:text-[var(--vg-paper)]" href="{{ route('legal.privacy') }}">Privacy</a>
                <a class="text-[var(--vg-slate)] hover:text-[var(--vg-paper)]" href="{{ route('legal.responsible-gambling') }}">Responsible gambling</a>
                <a class="text-[var(--vg-slate)] hover:text-[var(--vg-paper)]" href="{{ route('legal.disclaimer') }}">Disclaimer</a>
                {{--
                    Straight to the heading rather than to a page of its
                    own - see the comment on it in the cancellation
                    policy for why there is only one copy of this rule.
                --}}
                <a class="text-[var(--vg-slate)] hover:text-[var(--vg-paper)]" href="{{ route('legal.cancellation') }}#refunds">Refund policy</a>
                <a class="text-[var(--vg-slate)] hover:text-[var(--vg-paper)]" href="{{ route('contact') }}">Contact</a>
            </nav>
        </div>

        <div class="mt-10 border-t border-[var(--vg-line)] pt-6 text-sm text-[var(--vg-slate)]">
            <p class="font-semibold text-[var(--vg-paper)]">
                {{ config('vigil.responsible_gambling_line') }}
            </p>

            {{--
                The prediction disclaimer, on every page.

                It belongs here rather than only on the disclaimer page
                because the product now publishes opinions from markets
                that have proved nothing. The gate used to do this work
                silently - a market that had not beaten the close could
                not produce a pick at all - and with that gone, the
                sentence has to be said rather than enforced.

                "Not guarantees of outcomes or profitability" is the
                load-bearing clause. Everything else here was already
                true and already said.
            --}}
            <p class="mt-3">
                {{ config('brand.name') }} provides AI-generated sports analytics, predictions and
                opinions for informational purposes. Predictions are not guarantees of outcomes or
                profitability, and past performance does not guarantee future results.
                {{ config('brand.name') }} does not accept or place wagers.
            </p>

            <p class="mt-3">
                {{ config('brand.name') }} sells information and tools. It does not take bets, hold
                funds, or run contests. You are responsible for knowing whether betting is legal
                where you are. {{ config('vigil.minimum_age') }}+ only.
            </p>

            <p class="mt-3">
                &copy; {{ now()->year }} {{ config('brand.company.legal_name') }}.
                @if (filled(config('brand.company.postal_address')))
                    <span class="ml-1">{{ config('brand.company.postal_address') }}</span>
                @endif
            </p>
        </div>
    </div>
</footer>
